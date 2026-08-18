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
        $roh = \trim(\preg_replace('/^.*\|\s*/', '', $roh) ?? '');
        // Interne Anhaengsel des Object Storage entfernen — `Maindata Reference:A15768275`
        // ist eine Verwaltungsnummer und hat auf einem Nachweisdokument nichts verloren.
        $roh = \preg_replace('/\\s*Maindata Reference:\\S*/', '', $roh) ?? $roh;
        $roh = \preg_replace('/\\s*\\[value=.*?\\]/', '', $roh) ?? $roh;
        $roh = \trim(\preg_replace('/\s+/', ' ', $roh) ?? '', " ,;\t\n");
        return $roh !== '' ? \mb_substr($roh, 0, 255) : null;
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
