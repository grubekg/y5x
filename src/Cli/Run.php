<?php
declare(strict_types=1);

namespace Grube\Price30\Cli;

use Grube\Price30\Adapters\IshopPriceAdapter;
use Grube\Price30\Adapters\PssPriceAdapter;
use Grube\Price30\Calc\EventJournal;
use Grube\Price30\Calc\PriceEvent;
use Grube\Price30\Calc\PriceWindow;
use Grube\Price30\Calc\PromoState;
use Grube\Price30\Calc\PromoStateMachine;
use Grube\Price30\Calc\ReferenceCalculator;
use Grube\Price30\Support\Db;
use Grube\Price30\Support\Money;

/**
 * Ein Tageslauf für einen Markt: abrufen → Journal fortschreiben → rechnen → protokollieren.
 *
 * **Die Reihenfolge ist nicht beliebig.** Erst wird das Preisintervall des heutigen Tages
 * festgeschrieben, dann gerechnet — sonst kennt die Aktionserkennung den heutigen Preis
 * noch nicht und der Vergleich mit gestern liefe ins Leere.
 *
 * Der Schreibschritt in den PSS fehlt hier absichtlich und vollständig: Solange die
 * Upsert-Semantik nicht geklärt ist (TODO(setup) 2), gibt es keinen halben Adapter, der
 * versehentlich doch etwas schreibt.
 */
final class Run
{
    public function __construct(
        private readonly Db $db,
        private readonly IshopPriceAdapter $shop,
        private readonly array $app,
        private readonly array $markets,
        private readonly ?PssPriceAdapter $pss = null,
    ) {
    }

    /**
     * Der Sammelabzug für MEHRERE Märkte — einmal laden, einmal zerlegen.
     *
     * Je Markt zu laden wäre der naheliegende Weg und wäre falsch: Dieselben zwei Dateien
     * (je 191 MB) enthalten **alle** Märkte. Acht Läufe daraus zu machen hieße 3 GB statt
     * 382 MB und achtmal dasselbe zu zerlegen.
     *
     * @param array<string,string> $maerkte  Marktcode => Währung
     * @return array<string, array<string, array{gross:string, net:string, quelle:string}>>
     *         Marktcode => sku => Preise
     */
    public function sammelPreise(array $maerkte, bool $ausfuehrlich = false): array
    {
        $spec = [];
        foreach ($maerkte as $code => $waehrung) {
            $spec[$code] = $this->mcsPaar($code, $waehrung);
        }
        $t = \microtime(true);
        $d = $this->shop->allePreise($spec);
        if ($ausfuehrlich) {
            \printf("Sammelabzug: %d Märkte in %.0f s\n", \count($d), \microtime(true) - $t);
            foreach ($d as $code => $artikel) {
                $aktion = 0;
                foreach ($artikel as $p) {
                    if (($p['quelle'] ?? '') === 'aktion') { $aktion++; }
                }
                // NICHT "in Aktion": Der Shop fuehrt den angewendeten Preis fuer fast
                // jeden Artikel unter `promotionPrices`, auch wenn er dem Listenpreis
                // entspricht. Die Zahl sagt, WOHER der Wert kam, nicht ob er reduziert
                // ist — "davon Aktionspreis: 100 %" waere schlicht falsch gelesen.
                \printf("  %-4s %9s Artikel · davon aus promotionPrices: %s\n", $code,
                    \number_format(\count($artikel), 0, ',', '.'),
                    \number_format($aktion, 0, ',', '.'));
            }
        }
        return $d;
    }

    /** Auf einen einzelnen Artikel eingrenzen — fuer gezielte Nachlaeufe. */
    public function nurSku(?string $sku): void
    {
        $this->nurSku = $sku;
    }

    private ?string $nurSku = null;

    /**
     * Der Markt, den der Einzel-Endpunkt bedient.
     *
     * `/admin/pssoverview/prices/shop/get/…` kennt keinen Markt-Parameter und antwortet
     * immer fuer den Standard-Shop. Nur fuer den darf der schnelle Weg benutzt werden.
     */
    public string $standardMarkt = 'DE';

    public function fuerMarkt(string $markt, int $limit, bool $ausfuehrlich = false,
                             bool $abruf = true, ?array $sammelVorab = null): array
    {
        $heute = new \DateTimeImmutable('today');
        $m = $this->markets[$markt] ?? [];
        $waehrung = (string) ($m['currency'] ?? 'EUR');
        $lauf = $this->laufBeginnen($markt, $heute);

        $fehlerarten = [];
        $anomalien = [];
        $zaehler = ['gelesen' => 0, 'ohne_preis' => 0, 'anomalien' => 0,
                    'neu' => 0, 'geaendert' => 0, 'unveraendert' => 0,
                    'in_aktion' => 0, 'fenster_voll' => 0, 'fehler' => 0,
                    'writes' => 0, 'write_fehler' => 0];

        // Drei Bedingungen muessen ZUSAMMEN erfuellt sein, damit geschrieben wird. Jede
        // einzelne davon hat schon einmal jemanden davor bewahrt, in ein Produktivsystem
        // zu schreiben, das er nicht meinte.
        $trocken = (bool) ($this->app['dry_run'] ?? true);
        $marktFrei = (bool) ($m['write_enabled'] ?? false);
        $schreiben = $this->pss !== null && !$trocken && $marktFrei;
        // Der Modus wird MITGESCHRIEBEN, nicht spaeter aus der Konfiguration erschlossen:
        // `--write` steht auf der Kommandozeile des Triggers, `dry_run: true` bleibt in
        // der app.yml der Auslieferungszustand. Wer den Modus hinterher aus der Datei
        // liest, bekommt zuverlaessig die falsche Antwort. `gesperrt` steht vor
        // `trocken`, weil es die dauerhafte Aussage ist: Fuer CH aendert ein
        // Scharfschalten nichts, solange Legal nicht entschieden hat.
        $schreibmodus = $schreiben ? 'scharf' : (!$marktFrei ? 'gesperrt' : 'trocken');
        $this->melden(\sprintf('Schreiben: %s', $schreiben ? 'AKTIV' : \sprintf(
            'aus (%s)', $this->pss === null ? 'kein Adapter'
                : ($trocken ? 'Trockenmodus' : 'write_enabled=false fuer ' . $markt))),
            $ausfuehrlich);

        // Preise fuer den ganzen Markt in zwei Anfragen holen — siehe
        // IshopPriceAdapter::allePreise(). Der Einzelabruf bleibt als Rueckfall fuer
        // einzelne Artikel, ist aber fuer einen Lauf ueber das Sortiment untauglich:
        // 35.641 Anfragen je Markt, und die anderen sieben Maerkte kaeme er gar nicht.
        $sammel = null;
        $mcs = $this->mcs($markt, $waehrung);
        $gruppe = (string) ($m['price_group'] ?? 'DEFAULT');
        $mcsLang = $this->mcsPaar($markt, $waehrung)['mcs_lang'];
        // Fuer einen einzelnen Artikel lohnt der Sammelabzug nicht: 382 MB laden und
        // zerlegen kostet zwei Minuten, der Einzel-Endpunkt eine Zehntelsekunde. Er
        // liefert allerdings nur den Standard-Shop — fuer andere Maerkte bleibt der
        // Sammelweg auch bei einem Artikel der einzige.
        $einzelweg = $this->nurSku !== null && $markt === ($this->standardMarkt ?? 'DE');
        if ($abruf && $einzelweg) {
            $sammel = null;
        } elseif ($abruf && $sammelVorab !== null) {
            $sammel = $sammelVorab[$markt] ?? [];
        } elseif ($abruf) {
            try {
                $t0 = \microtime(true);
                // Die Preisgruppe MUSS auch hier mit: Ohne sie lief der Einzelmarkt-Lauf
                // fuer Schweden auf `DEFAULT` und fand nichts.
                $sammel = $this->shop->allePreise(
                    [$markt => $this->mcsPaar($markt, $waehrung)])[$markt] ?? [];
                $this->melden(\sprintf('%s Preise fuer %s (%s, Gruppe %s) in %.0f s',
                    \number_format(\count($sammel), 0, ',', '.'), $markt, $mcsLang, $gruppe,
                    \microtime(true) - $t0), $ausfuehrlich);
            } catch (\Throwable $e) {
                $this->melden('Sammelabzug nicht moeglich (' . $e->getMessage()
                    . ') — es wird einzeln abgerufen', true);
                $sammel = null;
            }
        }

        // Ein Markt ohne EINEN einzigen Preis ist kein leeres Sortiment, sondern ein
        // Fehler. Beim ersten Volllauf blieb Schweden genau so still leer, weil dort die
        // Preisgruppe `1` statt `DEFAULT` heisst — 3 Sekunden Laufzeit, Status „ok",
        // null Artikel. Solche Laeufe duerfen nicht als Erfolg durchgehen.
        if ($abruf && !$einzelweg && $sammel !== null && $sammel === []) {
            $zaehler['fehler']++;
            $this->melden(\sprintf(
                'FEHLER %s: kein einziger Preis im Sammelabzug fuer %s (Gruppe %s) — '
                . 'stimmen `language` und `price_group` in markets.yml?',
                $markt, $mcsLang, $gruppe), true);
        }

        // Woher die Artikelliste kommt — GENAU EINE der drei Quellen.
        //
        // Diese Kette stand einmal als `if ($abruf) {…} if (nurSku) {…} else {…}` da, und
        // das `else` gehoerte damit zu `nurSku`, nicht zu `$abruf`: Ohne `--sku` wurde die
        // frisch geholte Shop-Liste IMMER wieder durch den Datenbankbestand ersetzt.
        // Solange die Datenbank gefuellt war, fiel das nicht auf — beide Listen enthalten
        // dann fast dieselben Artikel. Nach dem Leeren der Historie lief derselbe Aufruf
        // ueber 23 Demo-Artikel statt ueber 34.866 und meldete trotzdem „ok".
        if ($this->nurSku !== null) {
            // Auf einen Artikel eingrenzen — ohne den Sammelabzug zu umgehen, damit der
            // Nachlauf genau dasselbe rechnet wie der Tageslauf.
            $skus = [$this->nurSku];
            $this->melden('eingegrenzt auf Artikel ' . $this->nurSku, $ausfuehrlich);
        } elseif ($abruf) {
            $skus = $this->shop->skus($limit);
            $this->melden(\sprintf('%d Artikelnummern aus dem Object Storage', \count($skus)), $ausfuehrlich);
        } else {
            // Ohne Abruf: nur rechnen und schreiben, auf dem vorhandenen Bestand. Nuetzlich
            // fuer eine Wiederholung nach einem Schreibfehler, ohne den Shop zu belasten.
            $skus = \array_column($this->db->query(
                'SELECT sku FROM {p}price_state WHERE market = ? ORDER BY sku'
                . ($limit > 0 ? ' LIMIT ' . (int) $limit : ''), [$markt]), 'sku');
            $this->melden(\sprintf('%d Artikel aus dem Bestand (kein Shop-Abruf)', \count($skus)), $ausfuehrlich);
        }

        // Bezeichnungen einmal je Lauf in einer Anfrage (2,9 s fuer 29.316) statt bei
        // jeder Anzeige einzeln. Sie sind Anzeigehilfe, keine Beweisgrundlage — schlaegt
        // der Abruf fehl, laeuft der Lauf ohne sie weiter.
        try {
            if (!$abruf) { throw new \RuntimeException('kein Abruf'); }
            $namen = $this->shop->namen();
            $gesetzt = 0;
            foreach (\array_chunk($skus, 500) as $block) {
                $werte = [];
                $args = [];
                foreach ($block as $sku) {
                    if (!isset($namen[$sku])) { continue; }
                    $werte[] = '(?,?,?,NOW())';
                    $args[] = $sku; $args[] = $markt; $args[] = $namen[$sku];
                    $gesetzt++;
                }
                if ($werte !== []) {
                    $this->db->execute(
                        'INSERT INTO {p}article_meta (sku, market, name, fetched_at) VALUES '
                        . \implode(',', $werte)
                        . ' ON DUPLICATE KEY UPDATE name = VALUES(name), fetched_at = NOW()', $args);
                }
            }
            $this->melden(\sprintf('%d Bezeichnungen übernommen', $gesetzt), $ausfuehrlich);
        } catch (\Throwable $e) {
            if ($abruf) { $this->melden('Bezeichnungen nicht abrufbar: ' . $e->getMessage(), $ausfuehrlich); }
        }

        $journal = new EventJournal();
        $fenster = new PriceWindow((int) ($this->app['window_days'] ?? 30));
        $rechner = new ReferenceCalculator(
            $fenster,
            new PromoStateMachine($fenster, (int) ($this->app['permanent_after_days'] ?? 60)),
            (string) ($this->app['calculation_mode'] ?? 'frozen'),
            (bool) ($this->app['prev_price_enabled'] ?? false),
            (int) ($this->app['prev_price_max_days'] ?? 42));

        foreach ($skus as $i => $sku) {
            try {
                if (!$abruf) {
                    $p = ['net' => null, 'gross' => null];
                } elseif ($sammel !== null) {
                    $p = $sammel[$sku] ?? null;      // null = im Markt nicht gefuehrt
                } else {
                    $p = $this->shop->preis($sku, $this->mcsPaar($markt, $waehrung));
                }
            } catch (\Throwable $e) {
                // Ein Lauf, der nur "1000 Fehler" meldet, ist wertlos. Die Meldungen
                // werden nach Art gezaehlt und landen in der Notiz des run_log.
                $zaehler['fehler']++;
                $art = \get_class($e) . ': ' . $e->getMessage();
                $fehlerarten[$art] = ($fehlerarten[$art] ?? 0) + 1;
                continue;
            }
            if ($abruf) { $zaehler['gelesen']++; }

            $net = $p['net'] ?? null;
            $gross = $p['gross'] ?? null;

            // Anomalie-Filter am Eingang (§ 3). Verworfene Datensaetze kommen NICHT in
            // die Beweisgrundlage — ein Nullpreis darf niemals als Minimum enden.
            $grund = ($net !== null && $gross !== null) ? $this->unplausibel($net, $gross) : null;
            if ($grund !== null) {
                // Verworfen heisst: kommt NICHT in die Beweisgrundlage. Aber es muss
                // nachvollziehbar bleiben, WELCHER Artikel und WARUM — eine blosse Zahl
                // "1 Anomalie" laesst sich spaeter niemandem erklaeren.
                $zaehler['anomalien']++;
                $anomalien[] = \sprintf('%s (%s: netto %s / brutto %s)', $sku, $grund, $net, $gross);
                $net = $gross = null;
            }
            if ($abruf && ($net === null || $gross === null)) {
                $zaehler['ohne_preis']++;
                continue;
            }

            if ($abruf) {
                // Objekte, nicht rohe Zeilen: Das Journal entscheidet ueber Geldbetraege,
                // und die duerfen nur durch `Money` gehen.
                $plan = $journal->plan($this->zuEvents($this->events($sku, $markt)),
                    $net, $gross, $waehrung, $heute);
                $this->planAusfuehren($sku, $markt, $plan, $heute);
                $zaehler[$plan['action']] = ($zaehler[$plan['action']] ?? 0) + 1;
            }

            $ref = $rechner->calculate(
                $this->zuEvents($this->events($sku, $markt)),   // frisch, inkl. heute
                $this->zustand($sku, $markt), $heute, $waehrung);
            $vorher = $this->zustand2($sku, $markt);
            $this->zustandSchreiben($sku, $markt, $ref);

            if ($schreiben) {
                [$ok, $fehl] = $this->pssSchreiben($sku, $markt, $waehrung, $ref, $vorher,
                    $p['vat'] ?? null);
                $zaehler['writes'] += $ok;
                $zaehler['write_fehler'] += $fehl;
            }

            if ($ref->state->isPromo()) { $zaehler['in_aktion']++; }
            if ($ref->windowComplete)   { $zaehler['fenster_voll']++; }

            if ($ausfuehrlich && ($i + 1) % 100 === 0) {
                $this->melden(\sprintf('  %d/%d Artikel', $i + 1, \count($skus)), true);
            }
        }

        // Was nach dem letzten Artikel noch im Puffer liegt, muss hinaus — sonst
        // verschwiegen die Zahlen des Laufs genau den letzten, unvollstaendigen Block.
        [$ok, $fehl] = $this->pssLeeren();
        $zaehler['writes'] += $ok;
        $zaehler['write_fehler'] += $fehl;

        \arsort($fehlerarten);
        $this->laufBeenden($lauf, $zaehler, $fehlerarten, $anomalien, $schreibmodus);
        foreach (\array_slice($anomalien, 0, 5) as $a) {
            $this->melden('  VERWORFEN  ' . $a, true);
        }
        foreach (\array_slice($fehlerarten, 0, 3, true) as $art => $n) {
            $this->melden(\sprintf('  FEHLER %5dx  %s', $n, \mb_substr((string) $art, 0, 170)), true);
        }
        return $zaehler;
    }

    /**
     * § 3: Was am Eingang verworfen wird — und mit welcher Begründung.
     *
     * Gibt `null` zurück, wenn der Datensatz in Ordnung ist. Beim ersten Echtlauf über
     * 1.000 Artikel (18.08.2026) griff genau ein Fall: Artikel 1147934587 mit
     * 0,00 € netto und brutto. Ohne diesen Filter wäre er als Minimum durchgelaufen und
     * hätte eine Ersparnis von 100 % ausgewiesen.
     */
    private function unplausibel(string $net, string $gross): ?string
    {
        if (!Money::isPositive($gross)) { return 'Bruttopreis nicht positiv'; }
        if (!Money::isPositive($net))   { return 'Nettopreis nicht positiv'; }
        if (Money::compare($net, $gross) > 0) { return 'netto größer als brutto'; }
        return null;
    }

    private function events(string $sku, string $markt): array
    {
        return $this->db->query(
            'SELECT * FROM {p}price_events WHERE sku = ? AND market = ? ORDER BY valid_from',
            [$sku, $markt]);
    }

    /** @return PriceEvent[] */
    private function zuEvents(array $zeilen): array
    {
        $out = [];
        foreach ($zeilen as $z) {
            $out[] = new PriceEvent(
                new \DateTimeImmutable($z['valid_from']),
                $z['valid_to'] !== null ? new \DateTimeImmutable($z['valid_to']) : null,
                $z['net'], $z['gross'], $z['currency']);
        }
        return $out;
    }

    private function planAusfuehren(string $sku, string $markt, array $plan, \DateTimeImmutable $heute): void
    {
        if ($plan['action'] === 'nichts') {
            return;
        }
        if ($plan['close_at'] !== null) {
            // Nur das aktuelle Intervall (juengstes valid_from) wird fortgeschrieben.
            $this->db->execute(
                'UPDATE {p}price_events SET valid_to = ? WHERE sku = ? AND market = ?
                 ORDER BY valid_from DESC LIMIT 1', [$plan['close_at'], $sku, $markt]);
        }
        if ($plan['open'] !== null) {
            $e = $plan['open'];
            $this->db->execute(
                'INSERT INTO {p}price_events (sku, market, currency, net, gross, valid_from, valid_to)
                 VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE net = VALUES(net), gross = VALUES(gross),
                     currency = VALUES(currency), valid_to = VALUES(valid_to)',
                [$sku, $markt, $e->currency, $e->net, $e->gross,
                 $e->validFrom->format('Y-m-d'), $e->validTo?->format('Y-m-d')]);
        }
    }

    private function zustand(string $sku, string $markt): PromoState
    {
        $z = $this->db->one('SELECT * FROM {p}price_state WHERE sku = ? AND market = ?', [$sku, $markt]);
        if ($z === null) {
            return new PromoState();
        }
        $tag = static fn(?string $d): ?\DateTimeImmutable
            => $d !== null ? new \DateTimeImmutable($d) : null;
        return new PromoState((string) $z['mode'], $tag($z['promo_started']),
            $z['pre_promo_gross'], $z['pre_promo_net'], $tag($z['last_reduction_at']),
            $z['frozen_ref_net'], $z['frozen_ref_gross'], (string) ($z['last_transition'] ?? ''));
    }

    private function zustandSchreiben(string $sku, string $markt, $ref): void
    {
        $s = $ref->state;
        // `last_written_*` wird hier NICHT gesetzt: Es dokumentiert erfolgreiche
        // PSS-Writes, und geschrieben wird noch nicht.
        $this->db->execute(
            'INSERT INTO {p}price_state (sku, market, mode, promo_started, last_reduction_at,
                pre_promo_gross, pre_promo_net, frozen_ref_net, frozen_ref_gross,
                window_complete, last_transition, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE mode = VALUES(mode), promo_started = VALUES(promo_started),
                last_reduction_at = VALUES(last_reduction_at),
                pre_promo_gross = VALUES(pre_promo_gross), pre_promo_net = VALUES(pre_promo_net),
                frozen_ref_net = VALUES(frozen_ref_net), frozen_ref_gross = VALUES(frozen_ref_gross),
                window_complete = VALUES(window_complete), last_transition = VALUES(last_transition),
                updated_at = NOW()',
            [$sku, $markt, $s->mode, $s->promoStarted?->format('Y-m-d'),
             $s->lastReductionAt?->format('Y-m-d'), $s->prePromoGross, $s->prePromoNet,
             $s->frozenRefNet, $s->frozenRefGross, $ref->windowComplete ? 1 : 0,
             \mb_substr($s->lastTransition, 0, 160)]);
    }

    /** Rohzeile aus `price_state` — für den Vergleich vor dem Überschreiben. */
    private function zustand2(string $sku, string $markt): array
    {
        return $this->db->one('SELECT * FROM {p}price_state WHERE sku = ? AND market = ?',
            [$sku, $markt]) ?? [];
    }

    /**
     * Referenzwerte in den PSS übertragen — **gepuffert**, nicht Artikel für Artikel.
     *
     * `PATCH` ist ein echter Upsert und nimmt beliebig viele Einträge in einem Aufruf
     * entgegen. Genau das ist hier der Unterschied zwischen brauchbar und unbrauchbar:
     *
     * | | je Artikel | gepuffert |
     * |---|---|---|
     * | Aufrufe beim Erstlauf | ~231.000 | **~950** |
     * | dazu Steuersatz-Abrufe | ~231.000 | **0** (siehe `steuerAngaben()`) |
     *
     * Ein Tageslauf schreibt danach ohnehin nur noch Deltas — aber der Erstlauf muss
     * einmal durch, und über eine halbe Million Einzelaufrufe wären dafür mehr als zwölf
     * Stunden gewesen.
     *
     * **Der Preis dafür ist die Fehlerauflösung:** Scheitert ein Block, gilt er für alle
     * darin enthaltenen Einträge als gescheitert. Deshalb wird jeder Eintrag weiterhin
     * einzeln protokolliert (mit dem Status seines Blocks), und `last_written_*` wird
     * nur bei Erfolg gesetzt — ein gescheiterter Block wird beim nächsten Lauf schlicht
     * erneut geschrieben, weil das Delta dann immer noch offen ist.
     *
     * **Paarweise bleibt paarweise:** `30_GROSS` und `30_NET` gehen gemeinsam oder gar
     * nicht (§ 6.1). Innerhalb eines Blocks ist das gegeben, weil beide zusammen
     * hineingelegt werden; scheitert der Block, scheitern beide.
     *
     * **`null` heißt löschen**, nicht „unverändert lassen": Ein abgelaufener
     * Vorstufen-Anker muss aus dem Frontend verschwinden.
     *
     * @return array{0:int, 1:int} [geschrieben, fehlgeschlagen] — beim Puffern stets [0,0]
     */
    private function pssSchreiben(string $sku, string $markt, string $waehrung,
                                  $ref, array $vorher, ?string $vat): array
    {
        $mcs = $this->mcs($markt, $waehrung);
        $extra = $this->steuerAngaben($vat);

        $felder = [
            ['30_GROSS',   $ref->gross,     'last_written_30_gross'],
            ['30_NET',     $ref->net,       'last_written_30_net'],
            ['PREV_GROSS', $ref->prevGross, 'last_written_prev_gross'],
            ['PREV_NET',   $ref->prevNet,   'last_written_prev_net'],
        ];
        foreach ($felder as [$typ, $neu, $spalte]) {
            $alt = $vorher[$spalte] ?? null;
            $gleich = ($neu === null && $alt === null)
                || ($neu !== null && $alt !== null && Money::equals((string) $neu, (string) $alt));
            if ($gleich) {
                continue;                       // Delta-Write: nichts zu tun
            }
            $eintrag = $neu !== null
                ? PssPriceAdapter::eintrag($sku, $typ, (string) $neu, $mcs,
                    $extra['vatRate'], $extra['priceUnit'])
                : PssPriceAdapter::schluessel($sku, $typ, $mcs);
            $this->puffer[$neu !== null ? 'set' : 'del'][] = $eintrag;
            $this->pufferLog[$neu !== null ? 'set' : 'del'][] =
                [$sku, $markt, $typ, $alt, $neu, $waehrung, $spalte];
        }

        if (\count($this->pufferLog['set'] ?? []) + \count($this->pufferLog['del'] ?? [])
            >= $this->blockGroesse) {
            return $this->pssLeeren();
        }
        return [0, 0];
    }

    /**
     * Einträge je Aufruf. 500 ist bewusst konservativ gewählt: Der Endpunkt nahm im Test
     * deutlich mehr an, aber ein Block ist auch die Einheit, die im Fehlerfall gemeinsam
     * scheitert — je größer, desto gröber die Auflösung.
     */
    private int $blockGroesse = 500;

    /** @var array{set?:array<int,array>, del?:array<int,array>} */
    private array $puffer = [];
    /** @var array{set?:array<int,array>, del?:array<int,array>} */
    private array $pufferLog = [];

    /**
     * Den Puffer übertragen und protokollieren.
     *
     * @return array{0:int, 1:int} [geschrieben, fehlgeschlagen]
     */
    private function pssLeeren(): array
    {
        $ok = 0;
        $fehl = 0;
        foreach (['set', 'del'] as $art) {
            $eintraege = $this->puffer[$art] ?? [];
            if ($eintraege === []) {
                continue;
            }
            $ergebnis = $art === 'set'
                ? $this->pss->schreiben($eintraege)
                : $this->pss->loeschen($eintraege);

            foreach ($this->pufferLog[$art] as [$sku, $markt, $typ, $alt, $neu, $waehrung, $spalte]) {
                $this->db->execute(
                    'INSERT INTO {p}pss_write_log (sku, market, price_type, old_value, new_value,
                        currency, written_at, http_status, success, attempt, response_excerpt)
                     VALUES (?,?,?,?,?,?,NOW(),?,?,?,?)',
                    [$sku, $markt, $typ, $alt, $neu ?? 0, $waehrung,
                     $ergebnis['status'], $ergebnis['ok'] ? 1 : 0, $ergebnis['versuche'],
                     ($neu === null ? 'geleert (DELETE) ' : '')
                     . \sprintf('[Block %d] ', \count($eintraege))
                     . \mb_substr($ergebnis['antwort'], 0, 180)]);
                if ($ergebnis['ok']) {
                    $this->db->execute(
                        "UPDATE {p}price_state SET $spalte = ?, last_written_at = NOW()
                         WHERE sku = ? AND market = ?", [$neu, $sku, $markt]);
                    $ok++;
                } else {
                    $fehl++;
                }
            }
        }
        $this->puffer = [];
        $this->pufferLog = [];
        return [$ok, $fehl];
    }

    /**
     * Der MCS-Schlüssel des Marktes — `[brand=grube country=de currency=EUR]`.
     *
     * Er entscheidet, in welchem Shop der Eintrag gilt. Ein falscher MCS schriebe den
     * Wert in den falschen Markt, ohne dass irgendetwas nach Fehler aussähe.
     */
    private function mcs(string $markt, string $waehrung): string
    {
        $m = $this->markets[$markt] ?? [];
        return \sprintf('[brand=%s country=%s currency=%s]',
            (string) ($m['shop_brand'] ?? 'grube'), \strtolower($markt), $waehrung);
    }

    /**
     * Beide MCS-Formen des Marktes — die zum Lesen und die zum Schreiben.
     *
     * Der Shop führt Listenpreis und Aktionspreis an unterschiedlich langen Schlüsseln,
     * und **der PSS kennt nur die kurze Form** (Auskunft GRUBE, 18.08.2026; nachgesehen
     * an 212 Einträgen des Artikels 3049187041 — kein einziger langer MCS darunter):
     *
     * | | Schlüssel | wofür |
     * |---|---|---|
     * | `mcs_kurz` | `[brand=grube country=de currency=EUR]` | `prices`, und **alles Schreiben** |
     * | `mcs_lang` | `[brand=grube channel=web country=de currency=EUR language=de store=]` | `promotionPrices` |
     *
     * Die lange Form ist der **Standard-Kanal** `web` mit leerem `store`. `webapp` und
     * `webwhitelabel` gibt es nur für einzelne Märkte und sie führten in der Stichprobe
     * denselben Preis; `web` ist der gemeinsame Nenner aller acht Märkte.
     *
     * `language` folgt NICHT dem Marktkürzel und steht deshalb in `markets.yml`:
     * Österreich und die Schweiz lesen `de`, Dänemark `da`, Schweden `sv`. Ein aus dem
     * Marktkürzel gebauter Schlüssel fände für diese Märkte gar nichts — und ein Markt
     * ohne Treffer sieht aus wie ein Markt ohne Aktionen, nicht wie ein Fehler.
     *
     * @return array{mcs_kurz:string, mcs_lang:string, gruppe:string}
     */
    private function mcsPaar(string $markt, string $waehrung): array
    {
        $m = $this->markets[$markt] ?? [];
        $marke = (string) ($m['shop_brand'] ?? 'grube');
        $land  = \strtolower($markt);
        $sprache = (string) ($m['language'] ?? $land);
        return [
            'mcs_kurz' => $this->mcs($markt, $waehrung),
            'mcs_lang' => \sprintf('[brand=%s channel=web country=%s currency=%s language=%s store=]',
                $marke, $land, $waehrung, $sprache),
            'gruppe'   => (string) ($m['price_group'] ?? 'DEFAULT'),
        ];
    }

    /**
     * `vatRate` und `priceUnit` für einen zu schreibenden Eintrag.
     *
     * Beide gehören zum Artikel, nicht zu unserem Referenzwert — erfunden wird deshalb
     * keiner von beiden. Sie kommen aber **ohne einen einzigen zusätzlichen Abruf**
     * zustande:
     *
     * - `priceUnit` ist im gesamten Sortiment `STCK` (Auskunft GRUBE, 18.08.2026:
     *   „STCK ist fix, das ist keine Variable"). Nachgesehen an allen 212 PSS-Einträgen
     *   des Artikels 3049187041: 212 von 212 tragen `STCK`.
     * - `vatRate` steht bereits im Sammelabzug an jeder `prices`-Zeile und wird dort
     *   mitgelesen.
     *
     * **Vorher wurde beides je Artikel beim PSS erfragt.** Das war der teuerste Teil des
     * Schreibwegs: ein eigener Abruf pro Artikel und Markt, beim Erstlauf also rund
     * 231.000 Anfragen, die nichts lieferten, was nicht schon vorlag.
     *
     * @return array{vatRate: ?float, priceUnit: ?string}
     */
    private function steuerAngaben(?string $vat): array
    {
        return ['vatRate' => $vat !== null ? (float) $vat : null, 'priceUnit' => 'STCK'];
    }
    private function laufBeginnen(string $markt, \DateTimeImmutable $heute): int
    {
        // Ein Lauf, der nie abgeschlossen wurde, bleibt auf 'laeuft' stehen. Der
        // naechste Lauf desselben Marktes schliesst ihn ehrlich als abgebrochen ab —
        // damit ist der Unterschied zwischen "arbeitet gerade" und "abgestuerzt"
        // belegt und nicht aus dem Alter geraten.
        $this->db->execute(
            "UPDATE {p}run_log SET status = 'failed', finished_at = NOW(),
                note = CONCAT(COALESCE(note,''), ' | abgebrochen — kein Abschluss protokolliert')
             WHERE market = ? AND status = 'laeuft'", [$markt]);

        $this->db->execute(
            "INSERT INTO {p}run_log (run_date, market, started_at, status, note)
             VALUES (?,?,NOW(),'laeuft',?)",
            [$heute->format('Y-m-d'), $markt, 'Erfassung läuft']);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function laufBeenden(int $id, array $z, array $fehlerarten = [],
                                array $anomalien = [], string $schreibmodus = 'unbekannt'): void
    {
        // Notizen in ordentlichem Deutsch: Sie stehen auf der Statusseite und im
        // Zweifel in einem Schriftsatz. utf8mb4 traegt Umlaute und € fehlerfrei durch
        // Verbindung, Spalte und Rücklesen — geprüft am 18.08.2026.
        //
        // Bis zum 19.08.2026 stand hier fest 'kein PSS-Write (Schreib-Adapter noch nicht
        // gebaut)' und `pss_writes = 0` — ein Rest aus der Zeit vor dem Schreibadapter.
        // Am 19.08.2026 gingen 391.968 Saetze fehlerfrei an den PSS, waehrend das
        // Protokoll das Gegenteil behauptete. Ein Werkzeug, das eine Beweiskette traegt,
        // darf ueber die eigene Arbeit nicht falsch berichten.
        $schreibsaetze = (int) ($z['writes'] ?? 0);
        $schreibfehler = (int) ($z['write_fehler'] ?? 0);
        $notiz = match ($schreibmodus) {
            'scharf'   => \sprintf('scharf geschrieben: %s Schreibsätze',
                              \number_format($schreibsaetze, 0, ',', '.')),
            'gesperrt' => 'nicht geschrieben — write_enabled ist für diesen Markt aus',
            'trocken'  => 'nicht geschrieben — Trockenmodus',
            default    => 'Schreibmodus nicht festgehalten',
        };
        if ($schreibfehler > 0) {
            $notiz .= \sprintf(' | %d Schreibfehler', $schreibfehler);
        }
        if ($anomalien !== []) {
            $notiz .= ' | verworfen: ' . \implode('; ', \array_slice($anomalien, 0, 10))
                . (\count($anomalien) > 10 ? \sprintf(' … (+%d weitere)', \count($anomalien) - 10) : '');
        }
        foreach (\array_slice($fehlerarten, 0, 3, true) as $art => $n) {
            $notiz .= \sprintf(' | %dx %s', $n, \mb_substr((string) $art, 0, 120));
        }
        // Ein Schreibfehler macht den Lauf `partial`, genau wie ein Lesefehler: Beides
        // heisst, dass der Tag nicht vollstaendig belegt ist.
        $status = ($z['fehler'] > 0 || $schreibfehler > 0) ? 'partial' : 'ok';
        $this->db->execute(
            'UPDATE {p}run_log SET finished_at = NOW(), items_fetched = ?, price_changes = ?,
                pss_writes = ?, write_mode = ?, write_errors = ?, anomalies = ?, errors = ?,
                status = ?, note = ? WHERE id = ?',
            [$z['gelesen'], ($z['neu'] ?? 0) + ($z['geaendert'] ?? 0), $schreibsaetze,
             $schreibmodus, $schreibfehler, $z['anomalien'], $z['fehler'], $status,
             $notiz, $id]);
    }

    private function melden(string $text, bool $an): void
    {
        if ($an) {
            echo $text, "\n";
        }
    }
}
