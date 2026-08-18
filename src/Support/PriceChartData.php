<?php
declare(strict_types=1);

namespace Grube\Price30\Support;

/**
 * PriceChartData — übersetzt DB-nahe Zeilen in die Eingabe von {@see PriceChart}.
 *
 * **Herkunft: fertig geliefert von GRUBE am 18.08.2026.** Übernommen mit Namensraum und
 * einer sachlichen Ergänzung, die der Betriebszustand erzwingt:
 *
 * > **Die Referenz-Treppe kommt aus `pss_write_log` — im Trockenmodus ist der leer.**
 * > Solange nicht geschrieben wird, gäbe es also nie eine Referenzlinie, obwohl der
 * > Wert längst berechnet ist. Deshalb nimmt `build()` über `refWrites` auch eine
 * > **nachgerechnete** Reihe entgegen. Sie wird im Diagramm anders beschriftet
 * > („Referenz (berechnet)"), damit auf keinem Ausdruck der Eindruck entsteht, ein Wert
 * > sei an den PSS übertragen worden, der es nicht wurde.
 */
final class PriceChartData
{
    /**
     * @param array  $events   price_events: gross, net, valid_from, valid_to (null = offen)
     * @param array  $writeLog pss_write_log: price_type, new_value (null = geleert),
     *                         written_at, success
     * @param array  $state    price_state: mode, promo_started, last_reduction_at
     * @param array  $opts     marker ['date','label'] · prevMaxDays (42) · windowDays (30)
     *                         · ariaLabel · refWrites (nachgerechnet, s. o.)
     *                         · prevSegments (nachgerechnet)
     */
    public static function build(array $events, array $writeLog, array $state,
                                 string $today, array $opts = []): array
    {
        $ok = \array_values(\array_filter($writeLog,
            static fn($w) => (int) ($w['success'] ?? 1) === 1));
        \usort($ok, static fn($a, $b) => \strcmp((string) $a['written_at'], (string) $b['written_at']));

        $refWrites = [];
        foreach ($ok as $w) {
            if ($w['price_type'] === '30_GROSS' && $w['new_value'] !== null) {
                $refWrites[] = ['date' => \substr((string) $w['written_at'], 0, 10),
                                'value' => (float) $w['new_value']];
            }
        }

        $prevSegments = [];
        $open = null;
        foreach ($ok as $w) {
            if ($w['price_type'] !== 'PREV_GROSS') { continue; }
            $d = \substr((string) $w['written_at'], 0, 10);
            if ($w['new_value'] !== null && (float) $w['new_value'] > 0) {
                $open = ['from' => $d, 'to' => null, 'value' => (float) $w['new_value']];
            } elseif ($open !== null) {
                $open['to'] = \date('Y-m-d', \strtotime($d . ' 12:00') - 86400);
                $prevSegments[] = $open;
                $open = null;
            }
        }
        if ($open !== null) { $prevSegments[] = $open; }

        // Fehlt das Schreibprotokoll (Trockenmodus), tritt die Nachrechnung an seine
        // Stelle — ausdrücklich als solche beschriftet.
        $berechnet = false;
        if ($refWrites === [] && !empty($opts['refWrites'])) {
            $refWrites = $opts['refWrites'];
            $berechnet = true;
        }
        if ($prevSegments === [] && !empty($opts['prevSegments'])) {
            $prevSegments = $opts['prevSegments'];
        }

        $promoBands = \array_map(
            static fn($p) => ['from' => $p['from'], 'to' => $p['to'], 'label' => ''],
            $prevSegments);
        if (($state['mode'] ?? 'normal') === 'promo' && !empty($state['promo_started'])) {
            $covered = false;
            foreach ($promoBands as &$b) {
                if ($b['from'] <= $state['promo_started']
                    && ($b['to'] === null || $b['to'] >= $state['promo_started'])) {
                    $b['to'] = null;
                    $covered = true;
                }
            }
            unset($b);
            if (!$covered) {
                $promoBands[] = ['from' => $state['promo_started'], 'to' => null, 'label' => ''];
            }
            $letzte = \count($promoBands) - 1;
            $promoBands[$letzte]['label'] = 'Aktion seit '
                . \date('d.m.', \strtotime((string) $state['promo_started']));
        }

        $windowDays = (int) ($opts['windowDays'] ?? 30);
        if (($state['mode'] ?? 'normal') === 'promo' && !empty($state['promo_started'])) {
            $wEnd = \date('Y-m-d', \strtotime((string) $state['promo_started'] . ' 12:00') - 86400);
            $label = $windowDays . '-Tage-Fenster vor Aktionsstart';
        } else {
            $wEnd = \date('Y-m-d', \strtotime($today . ' 12:00') - 86400);
            $label = 'rollierendes ' . $windowDays . '-Tage-Fenster';
        }
        $wStart = \date('Y-m-d', \strtotime($wEnd . ' 12:00') - ($windowDays - 1) * 86400);
        $windows = [['from' => $wStart, 'to' => $wEnd,
                     'label' => $label . ' (' . \date('d.m.', \strtotime($wStart)) . '–'
                              . \date('d.m.', \strtotime($wEnd)) . ')']];

        $prevClearDate = null;
        if (!empty($state['last_reduction_at']) && $prevSegments !== []) {
            $prevClearDate = \date('Y-m-d', \strtotime((string) $state['last_reduction_at'] . ' 12:00')
                                          + (int) ($opts['prevMaxDays'] ?? 42) * 86400);
        }

        // Ereignisse, die erst NACH dem Bezugstag beginnen, gehoeren nicht in den
        // Schrieb: Ein Nachweis zum 10. Juli darf keine Augustpreise zeigen.
        $events = \array_values(\array_filter($events,
            static fn($e) => ($e['valid_from'] ?? '') <= $today));

        return [
            'events'        => $events,
            'refWrites'     => $refWrites,
            'refLabel'      => $berechnet ? 'Referenz (berechnet)' : 'Referenz',
            'prevSegments'  => $prevSegments,
            'windows'       => $windows,
            'promoBands'    => $promoBands,
            'marker'        => $opts['marker'] ?? null,
            'prevClearDate' => $prevClearDate,
            'today'         => $today,
            'ariaLabel'     => $opts['ariaLabel'] ?? 'Preisverlauf mit ' . $windowDays
                             . '-Tage-Fenster, Referenz und Vorstufen-Anker',
        ];
    }
}
