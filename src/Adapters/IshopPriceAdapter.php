<?php
declare(strict_types=1);

namespace Grube\Price30\Adapters;

use Grube\Price30\Support\Http;
use Grube\Price30\Support\Money;

/**
 * Die einzige Preisquelle: der Shop.
 *
 * **Gelesen wird der Object Storage, und zwar zwei Felder an zwei MCS-Längen** —
 * `promotionPrices`/`netPromotionPrices` am langen Schlüssel (mit `channel`, `language`,
 * `store`) für den angewendeten Preis, `prices`/`netPrices` am kurzen für den
 * Listenpreis, wo keine Aktion läuft. Siehe `allePreise()` für Begründung und Messung.
 *
 * **Zwei naheliegende Endpunkte sind blind für Aktionen und dürfen nicht benutzt
 * werden** (beide gemessen am 18.08.2026 an Artikel 3049187041, für den DE eine
 * 20-%-Aktion führt — der Shop kassiert 159,20 €):
 *
 * | Endpunkt | Antwort | |
 * |---|---|---|
 * | `/admin/pssoverview/prices/shop/get/…` | **199,00** | heißt „shop", ist es aber nicht |
 * | `/admin/pssoverview/prices/activeCache/get/…` | 199,00 | trägt `excludePromotions: true` |
 *
 * Der erste sah lange nach der richtigen Quelle aus, weil sein Name das nahelegt und
 * seine Antwort genau die Form hat, die man braucht. Er liefert den Listenpreis. Für
 * einzelne Artikel wird deshalb `os/info` gelesen (siehe `preis()`).
 *
 * **Der PSS ist Ziel, niemals Quelle.** Er führt zu einem Artikel dutzende
 * konkurrierende Einträge (212 allein für 3049187041) über Kundengruppen und Kanäle
 * hinweg, aus denen erst der Shop den einen gültigen bildet.
 *
 * **Kein Aktionskennzeichen wird gelesen**, auch wenn der Shop unter
 * `/admin/publishedPromotions` eines führen mag: Aktionen stammen aus verschiedenen
 * Stellen, ein Kennzeichen aus nur einer davon ließe alle übrigen still durchfallen
 * (Entscheidung GRUBE, 18.08.2026). Gelesen wird der angewendete Preis, sonst nichts.
 */
final class IshopPriceAdapter
{
    public function __construct(
        private readonly Http $http,
        private readonly string $customerGroup = 'DEFAULT',
        private readonly string $customer = '0',
    ) {
    }

    /**
     * Alle Artikelnummern des Shops — in **einer** Anfrage.
     *
     * Der naheliegende Weg wäre, Produkte zu suchen und je Produkt `os/info` zu holen,
     * um an die Artikel zu kommen. Gemessen am 18.08.2026 kostet das 0,27 s und
     * **389 KB pro Produkt** — für 1.000 Artikel rund fünf Minuten und 270 MB, nur um
     * Nummern zu erfahren.
     *
     * Artikel sind aber selbst suchbar: `com.novomind.ishop.core.Item.sku EXISTS`
     * liefert 35.641 Artikel in 1,8 s und 8 MB. Und weil die Ergebnistabelle den Wert
     * des gesuchten Attributs als eigene Spalte führt, steht die Artikelnummer direkt
     * darin — kein zweiter Abruf nötig.
     *
     * `negate=POS` ist Pflicht: Ohne den Parameter sucht der Object Storage das
     * GEGENTEIL und liefert eine sauber formatierte, aber falsche Trefferliste.
     *
     * @return string[]
     */
    public function skus(int $limit = 0): array
    {
        $html = $this->http->get('/admin/os/overview', [
            'searchType' => 'search_attr',
            'searchEntries[0].negate' => 'POS',
            'searchEntries[0].name'   => 'com.novomind.ishop.core.Item.sku',
            'searchEntries[0].comp'   => 'EXISTS',
            'searchEntries[0].value'  => '',
            'onlyValid' => 'true',
        ])['body'];

        // Zeilenaufbau: # | ID (Item#…) | MCS | Wert des gesuchten Attributs (= sku).
        \preg_match_all(
            '/Item%23\d+"[^>]*>.*?<\/a>\s*<\/td>\s*<td[^>]*>.*?<\/td>\s*<td[^>]*>\s*([^<\s]+)\s*<\/td>/s',
            $html, $m);

        $skus = [];
        foreach ($m[1] as $sku) {
            $sku = \trim($sku);
            if ($sku !== '' && \preg_match('/^[0-9A-Za-z._-]{4,64}$/', $sku)) {
                $skus[$sku] = true;
                if ($limit > 0 && \count($skus) >= $limit) {
                    break;
                }
            }
        }
        // `array_keys` macht aus rein numerischen Schluesseln INTEGER — aus der
        // Artikelnummer wuerde eine Zahl. Das kostet nicht nur die Typsicherheit,
        // sondern bei fuehrenden Nullen auch die Nummer selbst.
        return \array_map(\strval(...), \array_keys($skus));
    }

    /**
     * **Alle Preise aller Märkte in vier Anfragen** statt einer je Artikel.
     *
     * Der Einzelweg (`/admin/pssoverview/prices/shop/get/…`) fragt je Artikel und kennt
     * **keinen Markt-Parameter** — er liefert nur den Standard-Shop. Für acht Märkte war
     * er damit nicht bloß langsam, sondern untauglich.
     *
     * Der Object Storage führt die Preise dagegen als Attribute am Artikel, und die
     * Sammelsuche gibt sie **je MCS** aus:
     *
     * | | Einzelabruf | Sammelabzug |
     * |---|---|---|
     * | Anfragen | 35.641 je Markt | **4 insgesamt** |
     * | Dauer | ~108 min (gedrosselt) | ~40 s laden, ~4 min zerlegen |
     * | Märkte | nur der Standard-Shop | **alle acht** |
     * | Ratenbegrenzung | kritisch | belanglos |
     *
     * ## Zwei Felder, zwei MCS-Längen — und beide werden gebraucht
     *
     * Der Shop führt den Preis eines Artikels an **zwei** getrennten Stellen, und keine
     * davon allein ergibt den Preis, den der Kunde zahlt (Auskunft GRUBE, 18.08.2026):
     *
     * | Feld | MCS | Inhalt |
     * |---|---|---|
     * | `prices` / `netPrices` | **nur kurz** `[brand=… country=… currency=…]` | Listenpreis |
     * | `promotionPrices` / `netPromotionPrices` | **nur lang** (mit `channel`, `language`, `store`) | angewendeter Preis |
     *
     * Gelesen wird deshalb **beides**: Gibt es am langen Standard-MCS
     * (`channel=web`, Sprache des Marktes, `store` leer) einen Promotionspreis, ist das
     * der Preis des Shops. Fehlt er, gilt der Listenpreis vom kurzen MCS.
     *
     * **Genau das war der Fehler, der diesem Werkzeug seine Grundlage entzogen hätte.**
     * Gelesen wurde ausschließlich `prices` am kurzen MCS. Für Artikel 3049187041 mit
     * 20 % Aktion auf DE stand dort 199,00 €, während der Shop 159,20 € kassierte — der
     * Tracker hätte 199,00 € als „unveränderten" Preis über Wochen fortgeschrieben und
     * genau die Aussage belegt, die falsch ist. Der Fehler war lautlos: Beide Zahlen
     * sehen richtig aus, und `promotionPrices` am **kurzen** MCS zeigt ebenfalls 199,00.
     * Erst der lange MCS führt die Aktion.
     *
     * **Nachgemessen gegen den Affiliate-Export** (`/affiliateExport/preisschreiber_de/`,
     * der die Preise ausspielt, mit denen geworben wird): 34.551 von 34.551 Artikeln
     * brutto identisch, **null Abweichungen** — und dieselben 3.331 Artikel in Aktion,
     * die der alte Weg allesamt übersah (rund 10 % des Sortiments).
     *
     * Netto kommt aus `netPromotionPrices` bzw. `netPrices` — also **aus derselben
     * Quelle wie Brutto**, nie gerechnet. Das ist keine Bequemlichkeit, sondern die
     * Konsistenzregel aus § 6.1: Ein Paar aus zwei verschiedenen Ereignissen wäre ein
     * Referenzpreis, den es so nie gegeben hat.
     *
     * ## Die Auflösung ist der heikle Teil und wurde deshalb geprüft
     *
     * Der Abzug enthält rohe Eintragslisten mit Zeitfenstern, Preisgruppen und
     * Staffelmengen; welcher Eintrag gilt, entscheidet sonst der Shop.
     *
     * - `prices`: gefiltert auf `priceGroup`, `customer='0'`, `amount=0` und ein
     *   Gültigkeitsfenster, das heute enthält. Gegen den Einzel-Endpunkt gemessen
     *   (200 zufällige Artikel): 200 von 200 übereinstimmend.
     * - `promotionPrices`: Die Zelle ist eine Staffelkarte `{0=[…], 10=[…]}`. **Nur
     *   Schlüssel 0 ist die Grundmenge**; Schlüssel 10 ist der Mengenpreis ab zehn Stück
     *   und lag bei 1.692 Artikeln darunter — ungefiltert wäre ein Mengenrabatt als
     *   Endkundenpreis in den Nachweis gewandert. Innerhalb von Schlüssel 0 wurde über
     *   alle 34.866 DE-Artikel **kein einziger** Fall mit zwei gleichzeitig gültigen
     *   Einträgen gefunden; die Auflösung ist eindeutig.
     *
     * Die Marke `dominicus` bleibt außen vor — das ist der B2B-Shop (Auskunft GRUBE).
     *
     * @param array<string, array{mcs_kurz:string, mcs_lang:string, gruppe:string}> $maerkte
     * @return array<string, array<string, array{gross:string, net:string, quelle:string, vat:?string}>>
     *         Marktcode => sku => Preise
     */
    public function allePreise(array $maerkte): array
    {
        $promo = [];
        $liste = [];
        foreach ($maerkte as $code => $_) {
            $promo[$code] = [];
            $liste[$code] = [];
        }

        foreach ([['promotionPrices', 'gross'], ['netPromotionPrices', 'net']] as [$attribut, $feld]) {
            $this->abzug($attribut, $maerkte, 'mcs_lang', $feld, $promo);
        }
        foreach ([['prices', 'gross'], ['netPrices', 'net']] as [$attribut, $feld]) {
            $this->abzug($attribut, $maerkte, 'mcs_kurz', $feld, $liste);
        }

        // Zusammenfuehren. Ein halbes Paar ist wertlos, weil die Konsistenzregel beide
        // Werte aus DEMSELBEN Ereignis verlangt — deshalb wird nie ueber die Quellen
        // hinweg gemischt: entweder beide aus der Aktion oder beide aus der Liste.
        $out = [];
        foreach ($maerkte as $code => $_) {
            $out[$code] = [];
            $skus = $promo[$code] + $liste[$code];
            foreach (\array_keys($skus) as $sku) {
                $l = $liste[$code][$sku] ?? [];
                // `vatRate` steht nur an der Listenzeile, gilt aber fuer den Artikel —
                // also auch dann, wenn der Preis selbst aus der Aktion stammt.
                $vat = $l['vat'] ?? null;
                $p = $promo[$code][$sku] ?? [];
                if (isset($p['gross'], $p['net'])) {
                    $out[$code][$sku] = ['gross' => $p['gross'], 'net' => $p['net'],
                                         'quelle' => 'aktion', 'vat' => $vat];
                    continue;
                }
                if (isset($l['gross'], $l['net'])) {
                    $out[$code][$sku] = ['gross' => $l['gross'], 'net' => $l['net'],
                                         'quelle' => 'liste', 'vat' => $vat];
                }
            }
        }
        return $out;
    }

    /**
     * Einen Sammelabzug laden und streamend in `$ziel` einsortieren.
     *
     * @param array<string, array{mcs_kurz:string, mcs_lang:string, gruppe:string}> $maerkte
     * @param string $mcsFeld  welcher MCS des Marktes gesucht wird (`mcs_kurz`|`mcs_lang`)
     */
    private function abzug(string $attribut, array $maerkte, string $mcsFeld, string $feld,
                           array &$ziel): void
    {
        $datei = \sys_get_temp_dir() . '/y5x-' . $attribut . '-' . \getmypid() . '.html';
        try {
            $this->http->herunterladen('/admin/os/overview', [
                'searchType' => 'search_attr',
                'searchEntries[0].negate' => 'POS',
                'searchEntries[0].name'   => $attribut,
                'searchEntries[0].comp'   => 'EXISTS',
                'searchEntries[0].value'  => '',
                'onlyValid' => 'true',
            ], $datei, 900);
            $this->zerlege($datei, $maerkte, $mcsFeld, $feld, $ziel,
                \str_contains($attribut, 'romotion'));
        } finally {
            @\unlink($datei);
        }
    }

    /** Streamend zerlegen — die Dateien sind bis 310 MB gross und passen nicht in den Speicher. */
    private function zerlege(string $datei, array $maerkte, string $mcsFeld, string $feld,
                             array &$out, bool $istAktion): void
    {
        $fp = \fopen($datei, 'r');
        if ($fp === false) {
            return;
        }
        // Suchindex MCS => Marktcode. Ohne ihn kostete jede Zeile eine Schleife ueber
        // alle Maerkte; bei 1,2 Millionen Zeilen ist das messbar.
        $index = [];
        foreach ($maerkte as $code => $m) {
            $index[$m[$mcsFeld]] = $code;
        }
        $rest = '';
        $jetzt = \time();
        while (!\feof($fp)) {
            $rest .= (string) \fread($fp, 4_194_304);
            while (($a = \strpos($rest, '<tr>')) !== false
                && ($b = \strpos($rest, '</tr>', $a)) !== false) {
                $zeile = \substr($rest, $a, $b - $a + 5);
                $rest = \substr($rest, $b + 5);

                $treffer = null;
                foreach ($index as $mcs => $code) {
                    if (\str_contains($zeile, $mcs)) { $treffer = $code; break; }
                }
                if ($treffer === null || !\preg_match('~Item%23(\d+)~', $zeile, $s)) {
                    continue;
                }
                \preg_match_all('~<td[^>]*>(.*?)</td>~s', $zeile, $c);
                $wert = \html_entity_decode(\strip_tags($c[1][3] ?? ''));
                if ($istAktion) {
                    $preis = $this->ausAktion($wert, $jetzt);
                } else {
                    $preis = $this->ausListe($wert, $maerkte[$treffer]['gruppe'], $jetzt);
                    // Der Steuersatz gehoert zum Artikel und steht hier ohnehin schon da.
                    // Ihn stattdessen je Artikel beim PSS zu erfragen kostete einen
                    // eigenen Abruf pro Artikel — beim Erstlauf 35.641 Anfragen, die
                    // nichts liefern, was nicht schon in dieser Datei steht.
                    if ($preis !== null && \preg_match('~vatRate=([\d.]+)~', $wert, $v)) {
                        $out[$treffer][$s[1]]['vat'] = $v[1];
                    }
                }
                if ($preis !== null) {
                    $out[$treffer][$s[1]][$feld] = $preis;
                }
            }
            if (\strlen($rest) > 20_000_000) {
                $rest = \substr($rest, -1_000_000);
            }
        }
        \fclose($fp);
    }

    /**
     * Der gueltige Aktionspreis der Grundmenge aus einer `promotionPrices`-Zelle.
     *
     * Aufbau: `{0=[PromotionPriceEntry{price=… , startDate=…, endDate=…}], 10=[…]}`.
     * Der Schluessel ist die Staffelmenge — **nur 0 ist die Grundmenge.**
     */
    private function ausAktion(string $wert, int $jetzt): ?string
    {
        $wert = \trim($wert);
        if ($wert === '' || $wert === '{}' || $wert[0] !== '{') {
            return null;
        }
        // Schluessel 0 auf oberster Ebene herausschneiden, bis zum naechsten Schluessel.
        if (!\preg_match('~(?:^\{|,\s*)0=\[(.*?)\](?:,\s*\d+=\[|\}$)~s', $wert, $m)) {
            return null;
        }
        foreach (\preg_split('~(?<=\}),\s*~', $m[1]) ?: [] as $eintrag) {
            if (!\preg_match('~price=([\d.]+)~', $eintrag, $p)) {
                continue;
            }
            if (!$this->imFenster($eintrag, $jetzt)) {
                continue;
            }
            return $p[1];
        }
        return null;
    }

    /**
     * Der gueltige Listenpreis der Grundmenge aus einer `prices`-Zelle.
     *
     * Die Preisgruppe des anonymen Standardkunden ist NICHT ueberall `DEFAULT`: In
     * Schweden heisst sie `1`. Fest verdrahtet liess das den ganzen Markt leer
     * durchlaufen, ohne dass etwas nach Fehler aussah.
     */
    private function ausListe(string $wert, string $gruppe, int $jetzt): ?string
    {
        foreach (\preg_split('~(?<=\}),\s*~', \trim($wert, "[] \n")) ?: [] as $eintrag) {
            if (!\str_contains($eintrag, "priceGroup='" . $gruppe . "'")
                || !\str_contains($eintrag, "customer='0'")
                || !\str_contains($eintrag, 'amount=0,')) {
                continue;
            }
            if (!\preg_match('~price=([\d.]+)~', $eintrag, $p)) {
                continue;
            }
            if (!$this->imFenster($eintrag, $jetzt)) {
                continue;
            }
            return $p[1];
        }
        return null;
    }

    /** Gilt der Eintrag heute? Fehlende Grenzen gelten als offen. */
    private function imFenster(string $eintrag, int $jetzt): bool
    {
        if (\preg_match('~startDate=([^,]+),~', $eintrag, $sd)
            && \strtotime(\trim($sd[1])) > $jetzt) {
            return false;
        }
        if (\preg_match('~endDate=([^,}]+)~', $eintrag, $ed)) {
            $bis = \strtotime(\trim($ed[1]));
            if ($bis !== false && $bis < $jetzt) {
                return false;
            }
        }
        return true;
    }

    /**
     * Brutto- und Nettopreis der Grundmenge für **einen** Artikel in **einem** Abruf.
     *
     * Gedacht für gezielte Nachläufe. Der naheliegende Weg —
     * `/admin/pssoverview/prices/shop/get/{sku}/{gruppe}/{kunde}` — sieht harmlos aus
     * und war es nicht: Er antwortet in 0,13 s, nennt sich „shop" und liefert
     * trotzdem den **Listenpreis ohne Aktion**. Für Artikel 3049187041 gab er 199,00 €
     * zurück, während der Shop 159,20 € kassierte (gemessen 18.08.2026). Ein Nachlauf
     * über diesen Weg hätte den falschen Preis in den Nachweis geschrieben — und zwar
     * nur für den nachgelaufenen Artikel, also besonders schwer zu bemerken.
     *
     * `os/info` führt dagegen dieselben Felder wie der Sammelabzug, mitsamt allen MCS:
     * rund 320 KB und 2 s je Artikel. Aufgelöst wird exakt wie im Sammelweg — gleiche
     * Vorrangregel, gleiche Filter, damit ein Nachlauf nicht anders rechnet als der
     * Tageslauf.
     *
     * @param array{mcs_kurz:string, mcs_lang:string, gruppe:string} $mcs
     * @return array{net:string, gross:string, quelle:string}|null  null = kein Preis im Shop
     */
    public function preis(string $sku, array $mcs): ?array
    {
        $html = $this->http->get('/admin/os/info', [
            'id' => 'com.novomind.ishop.core.Item#' . $sku,
            'page' => '0', 'pageSize' => '4000',
        ])['body'];

        $jetzt = \time();
        $felder = ['promotionPrices' => [], 'netPromotionPrices' => [],
                   'prices' => [], 'netPrices' => []];
        $feld = '';
        \preg_match_all('~<tr[^>]*>(.*?)</tr>~is', $html, $zeilen);
        foreach ($zeilen[1] as $zeile) {
            \preg_match_all('~<t[dh][^>]*>(.*?)</t[dh]>~is', $zeile, $c);
            $z = \array_map(
                static fn ($x) => \trim(\html_entity_decode(\strip_tags($x), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')),
                $c[1]);
            if (\count($z) < 6) {
                continue;
            }
            if ($z[1] !== '') { $feld = $z[1]; }
            if (!isset($felder[$feld])) {
                continue;
            }
            $felder[$feld][$z[2]] = \implode(' ', \array_slice($z, 5));
        }

        // Vorrang wie im Sammelweg: Aktion am langen MCS schlaegt Liste am kurzen, und
        // Brutto und Netto kommen immer aus DERSELBEN Quelle (Konsistenzregel § 6.1).
        $gross = $this->ausAktion($felder['promotionPrices'][$mcs['mcs_lang']] ?? '', $jetzt);
        $net   = $this->ausAktion($felder['netPromotionPrices'][$mcs['mcs_lang']] ?? '', $jetzt);
        if ($gross !== null && $net !== null) {
            return ['net' => $net, 'gross' => $gross, 'quelle' => 'aktion'];
        }
        $gross = $this->ausListe($felder['prices'][$mcs['mcs_kurz']] ?? '', $mcs['gruppe'], $jetzt);
        $net   = $this->ausListe($felder['netPrices'][$mcs['mcs_kurz']] ?? '', $mcs['gruppe'], $jetzt);
        if ($gross !== null && $net !== null) {
            return ['net' => $net, 'gross' => $gross, 'quelle' => 'liste'];
        }
        return null;
    }

    /**
     * **Alle** Artikelbezeichnungen in einer Anfrage.
     *
     * Derselbe Weg wie bei den Artikelnummern: `import:E0074 EXISTS` liefert die Items,
     * und die Ergebnistabelle führt den Wert des gesuchten Attributs als eigene Spalte.
     * Gemessen am 18.08.2026: **29.316 Bezeichnungen in 2,9 s und 7,2 MB.**
     *
     * Einzeln abgerufen wären das 0,27 s und 389 KB je Artikel — für eine Liste mit
     * hundert Zeilen unbrauchbar, für den ganzen Bestand undenkbar. Deshalb wird der
     * Name einmal je Lauf gesammelt und nicht bei jeder Anzeige geholt.
     *
     * Rund 6.000 Artikel führen kein E0074 und tauchen hier nicht auf — die bleiben ohne
     * Bezeichnung, was ehrlicher ist als ein erfundener Platzhalter.
     *
     * @return array<string,string> Artikelnummer => Bezeichnung
     */
    public function namen(): array
    {
        $html = $this->http->get('/admin/os/overview', [
            'searchType' => 'search_attr',
            'searchEntries[0].negate' => 'POS',
            'searchEntries[0].name'   => 'import:E0074',
            'searchEntries[0].comp'   => 'EXISTS',
            'searchEntries[0].value'  => '',
            'onlyValid' => 'true',
        ], [], 300)['body'];

        \preg_match_all(
            '~Item%23(\d+)"[^>]*>.*?</a>\s*</td>\s*<td[^>]*>.*?</td>\s*<td[^>]*>(.*?)</td>~s',
            $html, $m);

        $out = [];
        foreach ($m[1] as $i => $sku) {
            $name = $this->saubererName(\strip_tags($m[2][$i] ?? ''));
            if ($name !== null) {
                $out[(string) $sku] = $name;
            }
        }
        return $out;
    }

    /**
     * Bezeichnung eines Artikels — `import:E0074` am Item-Objekt.
     *
     * Bewusst getrennt vom Preisabruf und nur bei Bedarf: Der Name ist Anzeigehilfe,
     * keine Beweisgrundlage. Ihn bei jedem Tageslauf für 35.000 Artikel mitzuziehen
     * wäre 0,27 s und 389 KB je Artikel — für eine Zeile Text.
     */
    public function name(string $sku): ?string
    {
        $html = $this->http->get('/admin/os/info', [
            'id' => 'com.novomind.ishop.core.Item#' . $sku, 'page' => 0, 'pageSize' => 3000,
        ])['body'];
        if (!\preg_match('/import:E0074<\/td>(.*?)<\/tr>/s', $html, $m)) {
            return null;
        }
        \preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $m[1], $z);
        $roh = \trim(\strip_tags(\end($z[1]) ?: ''));
        return $this->saubererName($roh);
    }

    /**
     * Bezeichnung von internen Anhängseln befreien.
     *
     * `Maindata Reference:A15768275` ist eine Verwaltungsnummer und hat auf einem
     * Nachweisdokument nichts verloren; der abschließende Beistrich („Erdbohrgerät
     * Vertex G250, EUROII,") stammt aus dem Import. Einzel- und Sammelabruf putzen über
     * dieselbe Methode — sonst sähe derselbe Artikel je nach Weg anders aus.
     */
    private function saubererName(string $roh): ?string
    {
        $t = \trim(\preg_replace('/^.*\\|\\s*/', '', $roh) ?? '');
        $t = \preg_replace('/\\s*Maindata Reference:\\S*/', '', $t) ?? $t;
        $t = \preg_replace('/\\s*\\[value=.*?\\]/', '', $t) ?? $t;
        $t = \trim(\preg_replace('/\\s+/', ' ', $t) ?? '', " ,;\t\n");
        return $t !== '' ? \mb_substr($t, 0, 255) : null;
    }

    /**
     * `"949,00 "` -> `"949.0000"`.
     *
     * Deutsche Schreibweise mit angehängtem Leerzeichen. Ein stiller Rückfall auf 0 wäre
     * hier der teuerste Fehler des ganzen Werkzeugs — deshalb `null` bei allem, was nicht
     * eindeutig ein Betrag ist, und der Aufrufer verwirft den Datensatz.
     */
    private function betrag(mixed $roh): ?string
    {
        if (!\is_string($roh) && !\is_numeric($roh)) {
            return null;
        }
        $t = \trim((string) $roh);
        $t = \str_replace(["\u{00a0}", ' '], '', $t);
        $t = \preg_replace('/[^0-9,.\-]/', '', $t) ?? '';
        if ($t === '') {
            return null;
        }
        // Deutsche Schreibweise: Punkt ist Tausender-, Komma ist Dezimaltrennzeichen.
        if (\str_contains($t, ',')) {
            $t = \str_replace('.', '', $t);
            $t = \str_replace(',', '.', $t);
        }
        if (!\preg_match('/^-?\d+(\.\d+)?$/', $t)) {
            return null;
        }
        try {
            return Money::normalize($t);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
