<?php
declare(strict_types=1);

namespace Grube\Price30\Cli;

use Grube\Price30\Adapters\IshopPriceAdapter;
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
    ) {
    }

    public function fuerMarkt(string $markt, int $limit, bool $ausfuehrlich = false): array
    {
        $heute = new \DateTimeImmutable('today');
        $m = $this->markets[$markt] ?? [];
        $waehrung = (string) ($m['currency'] ?? 'EUR');
        $lauf = $this->laufBeginnen($markt, $heute);

        $fehlerarten = [];
        $anomalien = [];
        $zaehler = ['gelesen' => 0, 'ohne_preis' => 0, 'anomalien' => 0,
                    'neu' => 0, 'geaendert' => 0, 'unveraendert' => 0,
                    'in_aktion' => 0, 'fenster_voll' => 0, 'fehler' => 0];

        $skus = $this->shop->skus($limit);
        $this->melden(\sprintf('%d Artikelnummern aus dem Object Storage', \count($skus)), $ausfuehrlich);

        // Bezeichnungen einmal je Lauf in einer Anfrage (2,9 s fuer 29.316) statt bei
        // jeder Anzeige einzeln. Sie sind Anzeigehilfe, keine Beweisgrundlage — schlaegt
        // der Abruf fehl, laeuft der Lauf ohne sie weiter.
        try {
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
            $this->melden('Bezeichnungen nicht abrufbar: ' . $e->getMessage(), $ausfuehrlich);
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
                $p = $this->shop->preis($sku);
            } catch (\Throwable $e) {
                // Ein Lauf, der nur "1000 Fehler" meldet, ist wertlos. Die Meldungen
                // werden nach Art gezaehlt und landen in der Notiz des run_log.
                $zaehler['fehler']++;
                $art = \get_class($e) . ': ' . $e->getMessage();
                $fehlerarten[$art] = ($fehlerarten[$art] ?? 0) + 1;
                continue;
            }
            $zaehler['gelesen']++;

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
            if ($net === null || $gross === null) {
                $zaehler['ohne_preis']++;
                continue;
            }

            // Objekte, nicht rohe Zeilen: Das Journal entscheidet ueber Geldbetraege,
            // und die duerfen nur durch `Money` gehen.
            $plan = $journal->plan($this->zuEvents($this->events($sku, $markt)),
                $net, $gross, $waehrung, $heute);
            $this->planAusfuehren($sku, $markt, $plan, $heute);
            $zaehler[$plan['action']] = ($zaehler[$plan['action']] ?? 0) + 1;

            $ref = $rechner->calculate(
                $this->zuEvents($this->events($sku, $markt)),   // frisch, inkl. heute
                $this->zustand($sku, $markt), $heute, $waehrung);
            $this->zustandSchreiben($sku, $markt, $ref);

            if ($ref->state->isPromo()) { $zaehler['in_aktion']++; }
            if ($ref->windowComplete)   { $zaehler['fenster_voll']++; }

            if ($ausfuehrlich && ($i + 1) % 100 === 0) {
                $this->melden(\sprintf('  %d/%d Artikel', $i + 1, \count($skus)), true);
            }
        }

        \arsort($fehlerarten);
        $this->laufBeenden($lauf, $zaehler, $fehlerarten, $anomalien);
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
                                array $anomalien = []): void
    {
        // Notizen in ordentlichem Deutsch: Sie stehen auf der Statusseite und im
        // Zweifel in einem Schriftsatz. utf8mb4 traegt Umlaute und € fehlerfrei durch
        // Verbindung, Spalte und Rücklesen — geprüft am 18.08.2026.
        $notiz = 'kein PSS-Write (Schreib-Adapter noch nicht gebaut)';
        if ($anomalien !== []) {
            $notiz .= ' | verworfen: ' . \implode('; ', \array_slice($anomalien, 0, 10))
                . (\count($anomalien) > 10 ? \sprintf(' … (+%d weitere)', \count($anomalien) - 10) : '');
        }
        foreach (\array_slice($fehlerarten, 0, 3, true) as $art => $n) {
            $notiz .= \sprintf(' | %dx %s', $n, \mb_substr((string) $art, 0, 120));
        }
        $this->db->execute(
            'UPDATE {p}run_log SET finished_at = NOW(), items_fetched = ?, price_changes = ?,
                pss_writes = 0, anomalies = ?, errors = ?, status = ?, note = ? WHERE id = ?',
            [$z['gelesen'], ($z['neu'] ?? 0) + ($z['geaendert'] ?? 0), $z['anomalien'],
             $z['fehler'], $z['fehler'] > 0 ? 'partial' : 'ok',
             $notiz, $id]);
    }

    private function melden(string $text, bool $an): void
    {
        if ($an) {
            echo $text, "\n";
        }
    }
}
