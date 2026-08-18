<?php
declare(strict_types=1);

namespace Grube\Price30\Adapters;

use Grube\Price30\Support\Http;
use Grube\Price30\Support\Money;

/**
 * Die einzige Preisquelle: der Shop.
 *
 * **Gefunden am 18.08.2026 durch die Routenliste unter `/admin/`** — sie liefert alle
 * 253 Admin-Endpunkte als JSON, was das Raten überflüssig macht. Der maßgebliche
 * Endpunkt ist:
 *
 *     GET /admin/pssoverview/prices/shop/get/{sku}/{customerGroup}/{customer}
 *     -> {"prices": {"prices": {"0": "949,00 "}, "prices_net": {"0": "797,48 "}}}
 *
 * `prices.prices` ist brutto, `prices.prices_net` netto, der Schlüssel `"0"` ist die
 * Menge (`amount`) — genau die Grundmenge, die das Briefing verlangt. Rund 0,12 s je
 * Abruf.
 *
 * **Bewusst NICHT `/admin/pssoverview/prices/activeCache/get/...`:** Der liefert die
 * rohen PSS-Einträge mitsamt `provider: {"excludePromotions": true}` — also den Preis
 * OHNE Aktionen. `.../shop/get/...` ist die Sicht des Shops.
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
     * **Alle Preise aller Märkte in zwei Anfragen** statt einer je Artikel.
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
     * | Anfragen | 35.641 je Markt | **2 insgesamt** |
     * | Dauer | ~108 min (gedrosselt) | ~10 s laden, ~115 s zerlegen |
     * | Märkte | nur der Standard-Shop | **alle acht** |
     * | Ratenbegrenzung | kritisch | belanglos |
     *
     * **Die Auflösung ist der heikle Teil und wurde deshalb geprüft.** Der Abzug enthält
     * rohe `PriceEntry`-Listen mit Zeitfenstern, Preisgruppen und mehreren konkurrierenden
     * Einträgen; welcher gilt, entscheidet sonst der Shop. Gefiltert wird auf
     * `priceGroup='DEFAULT'`, `customer='0'`, `amount=0` und ein Gültigkeitsfenster, das
     * heute enthält. Gegen den Einzel-Endpunkt gemessen (18.08.2026, 200 zufällige
     * Artikel): **200 von 200 übereinstimmend, null Abweichungen.**
     *
     * Die Marke `dominicus` bleibt außen vor — das ist der B2B-Shop (Auskunft GRUBE).
     *
     * @param string[] $mcsListe  gesuchte MCS-Schlüssel
     * @return array<string, array<string, array{gross:string, net:string}>>  mcs => sku => Preise
     */
    public function allePreise(array $mcsListe, array $preisgruppen = []): array
    {
        $out = [];
        foreach ($mcsListe as $mcs) { $out[$mcs] = []; }

        foreach ([['prices', 'gross'], ['netPrices', 'net']] as [$attribut, $feld]) {
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
                $this->zerlege($datei, $mcsListe, $feld, $out, $preisgruppen);
            } finally {
                @\unlink($datei);
            }
        }
        // Nur Artikel behalten, für die BEIDE Werte vorliegen — ein halbes Paar wäre
        // wertlos, weil die Konsistenzregel beide aus demselben Ereignis verlangt.
        foreach ($out as $mcs => $artikel) {
            foreach ($artikel as $sku => $p) {
                if (!isset($p['gross'], $p['net'])) {
                    unset($out[$mcs][$sku]);
                }
            }
        }
        return $out;
    }

    /** Streamend zerlegen — 191 MB je Datei passen nicht in den Arbeitsspeicher. */
    private function zerlege(string $datei, array $mcsListe, string $feld, array &$out,
                             array $preisgruppen = []): void
    {
        $fp = \fopen($datei, 'r');
        if ($fp === false) {
            return;
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
                foreach ($mcsListe as $mcs) {
                    if (\str_contains($zeile, $mcs)) { $treffer = $mcs; break; }
                }
                if ($treffer === null || !\preg_match('~Item%23(\d+)~', $zeile, $s)) {
                    continue;
                }
                \preg_match_all('~<td[^>]*>(.*?)</td>~s', $zeile, $c);
                $wert = \html_entity_decode(\strip_tags($c[1][3] ?? ''));
                // Die Preisgruppe des anonymen Standardkunden ist NICHT ueberall
                // `DEFAULT`: In Schweden heisst sie `1`. Fest verdrahtet liess das den
                // ganzen Markt leer durchlaufen, ohne dass etwas nach Fehler aussah.
                $gruppe = $preisgruppen[$treffer] ?? 'DEFAULT';
                foreach (\preg_split('~(?<=\}),\s*~', \trim($wert, "[] \n")) ?: [] as $eintrag) {
                    if (!\str_contains($eintrag, "priceGroup='" . $gruppe . "'")
                        || !\str_contains($eintrag, "customer='0'")
                        || !\str_contains($eintrag, 'amount=0,')) {
                        continue;
                    }
                    if (!\preg_match('~price=([\d.]+)~', $eintrag, $p)) {
                        continue;
                    }
                    if (\preg_match('~startDate=([^,]+),~', $eintrag, $sd)
                        && \strtotime(\trim($sd[1])) > $jetzt) {
                        continue;
                    }
                    if (\preg_match('~endDate=([^,]+),~', $eintrag, $ed)) {
                        $bis = \strtotime(\trim($ed[1]));
                        if ($bis !== false && $bis < $jetzt) { continue; }
                    }
                    $out[$treffer][$s[1]][$feld] = $p[1];
                }
            }
            if (\strlen($rest) > 20_000_000) {
                $rest = \substr($rest, -1_000_000);
            }
        }
        \fclose($fp);
    }

    /**
     * Brutto- und Nettopreis der Grundmenge für einen Artikel.
     *
     * @return array{net:string, gross:string}|null  null = kein Preis im Shop
     */
    public function preis(string $sku): ?array
    {
        $d = $this->http->json(\sprintf('/admin/pssoverview/prices/shop/get/%s/%s/%s',
            \rawurlencode($sku), \rawurlencode($this->customerGroup), \rawurlencode($this->customer)));
        $p = $d['prices'] ?? null;
        if (!\is_array($p)) {
            return null;
        }
        $gross = $this->betrag($p['prices']['0'] ?? null);
        $net   = $this->betrag($p['prices_net']['0'] ?? null);
        if ($gross === null || $net === null) {
            return null;
        }
        return ['net' => $net, 'gross' => $gross];
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
