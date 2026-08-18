<?php
declare(strict_types=1);

namespace Grube\Price30\Adapters;

use Grube\Price30\Support\Http;

/**
 * Der Schreibweg in den PSS — das einzige Werkzeug im Projekt, das etwas verändert.
 *
 * **Semantik am 18.08.2026 auf der Integrationsumgebung ermittelt und belegt**, nicht
 * angenommen. Der Endpunkt erlaubt `GET, HEAD, PUT, DELETE, PATCH`; eine
 * API-Beschreibung gibt es nicht (alle üblichen Pfade antworten mit 500).
 *
 * | Erkenntnis | Beleg |
 * |---|---|
 * | **`PATCH` ist ein echter Upsert** | derselbe Eintrag zweimal geschrieben → eine Zeile, neuer Wert |
 * | **`PATCH` lässt alles andere unberührt** | 96 Zeilen vorher, 97 nachher, 0 verschwunden |
 * | **`DELETE` entfernt genau einen Eintrag** | danach Fingerabdruck exakt wie vorher |
 * | **Neue priceTypes brauchen keine Anmeldung** | `30_GROSS` wurde ohne Vorbereitung angenommen |
 * | mehrere Einträge je Aufruf | ein `PATCH` mit zwei Einträgen setzte beide |
 *
 * **Warum nicht `PUT`:** Es ist erlaubt, aber bei einer Sammlung ist die naheliegende
 * Lesart „ersetze den Bestand". Ausprobiert wurde es nie an echten Daten — `PATCH` tut
 * nachweislich das Richtige, und für einen Versuch mit unklarem Ausgang gibt es bei
 * einem Preissystem keinen Anlass.
 *
 * Der Schlüssel eines Eintrags ist die Kombination aus `sku`, `priceType`, `customer`,
 * `customerGroup`, `amount` und `mcs`. Genau diese Felder gehen beim Löschen mit.
 */
final class PssPriceAdapter
{
    public function __construct(
        private readonly Http $http,
        private readonly int $maxVersuche = 3,
    ) {
    }

    /**
     * Einen Preiseintrag bauen — strukturgleich zu dem, was der PSS selbst liefert.
     *
     * `vatRate` und `priceUnit` werden aus einem vorhandenen Eintrag desselben Artikels
     * übernommen, statt sie zu erfinden: Der Mehrwertsteuersatz gehört zum Artikel, nicht
     * zu unserem Referenzwert.
     */
    public static function eintrag(string $sku, string $priceType, string $wert, string $mcs,
                                   ?float $vatRate = null, ?string $priceUnit = null): array
    {
        $e = [
            'sku'           => $sku,
            'priceType'     => $priceType,
            'price'         => (float) $wert,
            'customer'      => '0',
            'customerGroup' => 'DEFAULT',
            'amount'        => 0,
            'mcs'           => $mcs,
            'validFrom'     => \date('Y-m-d\TH:i:s', \strtotime('today')),
            'validTo'       => '9999-12-31T23:59:59',
        ];
        if ($vatRate !== null)   { $e['vatRate'] = $vatRate; }
        if ($priceUnit !== null) { $e['priceUnit'] = $priceUnit; }
        return $e;
    }

    /** Der Schlüssel eines Eintrags — für das Löschen. */
    public static function schluessel(string $sku, string $priceType, string $mcs): array
    {
        return ['sku' => $sku, 'priceType' => $priceType, 'customer' => '0',
                'customerGroup' => 'DEFAULT', 'amount' => 0, 'mcs' => $mcs];
    }

    /**
     * Einträge schreiben (Upsert). Gibt HTTP-Status und Antwortauszug zurück.
     *
     * @param array<int,array> $eintraege
     * @return array{status:int, ok:bool, antwort:string, versuche:int}
     */
    public function schreiben(array $eintraege): array
    {
        return $this->senden('PATCH', $eintraege);
    }

    /** Einträge entfernen — die Leerung des Vorstufen-Ankers. */
    public function loeschen(array $schluessel): array
    {
        return $this->senden('DELETE', $schluessel);
    }

    /**
     * Mit Wiederholung und wachsender Wartezeit.
     *
     * Ein fehlgeschlagener Schreibvorgang wird **nicht** als Erfolg gebucht: `price_state`
     * bleibt unverändert, und der nächste Lauf versucht es von selbst erneut, weil die
     * Delta-Erkennung dann wieder anschlägt. Deshalb genügen hier drei Versuche — der
     * eigentliche Wiederholungsmechanismus ist der Tageslauf.
     */
    private function senden(string $methode, array $rumpf): array
    {
        $letzte = ['status' => 0, 'ok' => false, 'antwort' => '', 'versuche' => 0];
        for ($v = 1; $v <= $this->maxVersuche; $v++) {
            $letzte['versuche'] = $v;
            try {
                $r = $this->http->sende($methode, '', $rumpf);
                $letzte['status'] = $r['status'];
                $letzte['antwort'] = \mb_substr($r['body'], 0, 500);
                // 204 ist die Erfolgsantwort des PSS; 2xx allgemein akzeptiert.
                if ($r['status'] >= 200 && $r['status'] < 300) {
                    $letzte['ok'] = true;
                    return $letzte;
                }
            } catch (\Throwable $e) {
                $letzte['antwort'] = \mb_substr($e->getMessage(), 0, 500);
            }
            if ($v < $this->maxVersuche) {
                \sleep($v * 2);
            }
        }
        return $letzte;
    }
}
