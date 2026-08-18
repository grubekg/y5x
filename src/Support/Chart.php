<?php
declare(strict_types=1);

namespace Grube\Price30\Support;

/**
 * Preisverlauf als SVG — serverseitig gezeichnet, ohne JavaScript.
 *
 * Drei Gründe für diese Bauart, und alle drei zählen bei einer Abmahnung:
 * die Seite lässt sich **drucken und als Anlage verschicken**, sie funktioniert ohne
 * externe Skripte (der Webspace liefert keine CDN-Inhalte aus), und das Bild entsteht
 * aus denselben Daten wie die Tabelle darunter — es kann gar nicht abweichen.
 *
 * **Gezeichnet wird als Treppe, nicht als Kurve.** Ein Preis gilt über ein Intervall
 * konstant und springt dann. Eine interpolierte Linie behauptete Zwischenpreise, die es
 * nie gab — bei einem Beweismittel ist das keine Kosmetik, sondern eine Falschaussage.
 */
final class Chart
{
    /**
     * Farben und Strichstärken stehen HIER, nicht im Stylesheet.
     *
     * Vorher trug das SVG nur Klassennamen und verliess sich auf das CSS der Seite. Das
     * hat sich als falscher Bau erwiesen: Im PDF mussten die Klassen per `strtr` ersetzt
     * werden, und ausserhalb der Seite — in jedem anderen Renderer, in einer Mail, in
     * einem Anhang — verschwand die Preistreppe schlicht. Ein Beweismittel muss überall
     * gleich aussehen, also trägt es seine Darstellung selbst.
     */
    private const FARBE = [
        'preis'    => '#1d3a2b',
        'referenz' => '#a8231b',
        'vorstufe' => '#6d4a9e',
        'stichtag' => '#2f6b3a',
        'fenster'  => '#2e5240',
        'aktion'   => '#c46a00',
        'raster'   => '#e4e4dc',
        'achse'    => '#6b6b64',
    ];

    public function __construct(
        private readonly int $width = 900,
        private readonly int $height = 260,
    ) {
    }

    /**
     * @param array<int, array{date: string, gross: ?string}> $tage  lückenlose Tagesreihe
     * @param array{from: string, to: string}|null $fenster  30-Tage-Fenster zum Hervorheben
     * @param string|null $referenz  Referenzpreis als waagerechte Linie
     * @param array<int, array{from: string, to: string}> $aktionen  Aktionszeiträume
     */
    public function render(
        array $tage,
        ?array $fenster = null,
        ?string $referenz = null,
        array $aktionen = [],
        ?string $stichtag = null,
        ?string $prev = null,
    ): string {
        $tage = \array_values($tage);
        $n = \count($tage);
        if ($n < 2) {
            return '<p class="hinweis">Zu wenig Historie für ein Diagramm.</p>';
        }

        [$l, $r, $o, $u] = [58, 14, 14, 34];              // Ränder
        $iw = $this->width - $l - $r;
        $ih = $this->height - $o - $u;

        $werte = [];
        foreach ($tage as $t) {
            if ($t['gross'] !== null) {
                $werte[] = (float) $t['gross'];
            }
        }
        foreach ([$referenz, $prev] as $zusatz) {
            if ($zusatz !== null) {
                $werte[] = (float) $zusatz;
            }
        }
        if ($werte === []) {
            return '<p class="hinweis">Keine Preise im Zeitraum.</p>';
        }
        $min = \min($werte);
        $max = \max($werte);
        // Etwas Luft, und niemals eine Nulllinien-Skala: Bei Preisen um 119 EUR
        // verschluckte eine Achse ab 0 jede Aktion optisch.
        $spanne = \max($max - $min, 0.01);
        $min -= $spanne * 0.15;
        $max += $spanne * 0.15;
        // Auf runde Beträge greifen. Eine Achse mit „165,50 / 224,00 / 282,50" ist zwar
        // richtig, aber unlesbar — auf einem Beweisdokument soll man Werte ablesen können,
        // ohne zu rechnen.
        // Ziel sind vier bis fünf Rasterlinien. Mit /2 geriet der Schritt zu grob: Aus
        // einer Spanne von 117 EUR wurde ein 100er-Schritt, und die Kurve klebte in der
        // oberen Hälfte.
        $schritt = $this->rasterSchritt(($max - $min) / 4);
        $min = \floor($min / $schritt) * $schritt;
        $max = \ceil($max / $schritt) * $schritt;

        $x = static fn(int $i): float => $l + ($n === 1 ? 0 : $i * $iw / ($n - 1));
        $y = static fn(float $v): float => $o + $ih - (($v - $min) / ($max - $min)) * $ih;
        $idx = [];
        foreach ($tage as $i => $t) {
            $idx[$t['date']] = $i;
        }

        $svg = \sprintf(
            '<svg viewBox="0 0 %d %d" width="100%%" role="img" aria-label="Preisverlauf" '
            . 'preserveAspectRatio="xMidYMid meet" class="verlauf" '
            . 'font-family="system-ui,sans-serif">'
            . '<rect width="%d" height="%d" fill="#ffffff"/>',
            $this->width, $this->height, $this->width, $this->height);

        // --- Fenster hinterlegen -------------------------------------------
        if ($fenster !== null && isset($idx[$fenster['from']], $idx[$fenster['to']])) {
            $svg .= \sprintf(
                '<rect x="%.1f" y="%d" width="%.1f" height="%d" fill="' . self::FARBE['fenster'] . '" opacity="0.10"/>'
                . '<text x="%.1f" y="%d" font-size="10" fill="' . self::FARBE['achse'] . '">30-Tage-Fenster</text>',
                $x($idx[$fenster['from']]), $o,
                \max($x($idx[$fenster['to']]) - $x($idx[$fenster['from']]), 1), $ih,
                $x($idx[$fenster['from']]) + 4, $o + 12);
        }

        // --- Aktionszeiträume ------------------------------------------------
        foreach ($aktionen as $a) {
            if (!isset($idx[$a['from']])) {
                continue;
            }
            $bis = $idx[$a['to']] ?? ($n - 1);
            $svg .= \sprintf('<rect x="%.1f" y="%d" width="%.1f" height="%d" fill="'
                . self::FARBE['aktion'] . '" opacity="0.16"/>',
                $x($idx[$a['from']]), $o, \max($x($bis) - $x($idx[$a['from']]), 1), $ih);
        }

        // --- Achse ------------------------------------------------------------
        $linien = \max(2, (int) \round(($max - $min) / $schritt));
        for ($k = 0; $k <= $linien; $k++) {
            $anteil = $k / $linien;
            $wert = $max - ($max - $min) * $anteil;
            $yy = $o + $ih * $anteil;
            $svg .= \sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="'
                . self::FARBE['raster'] . '" stroke-width="1"/>'
                . '<text x="%d" y="%.1f" font-size="10" fill="' . self::FARBE['achse']
                . '" text-anchor="end">%s</text>',
                $l, $yy, $this->width - $r, $yy, $l - 6, $yy + 4,
                \number_format($wert, 2, ',', '.'));
        }

        // --- Treppenlinie -----------------------------------------------------
        $d = '';
        $letztesY = null;
        foreach ($tage as $i => $t) {
            if ($t['gross'] === null) {
                $letztesY = null;                 // Lücke: Linie unterbrechen, nicht raten
                continue;
            }
            $yy = $y((float) $t['gross']);
            if ($letztesY === null) {
                $d .= \sprintf('M%.1f %.1f ', $x($i), $yy);
            } else {
                // erst waagerecht bis zum neuen Tag, dann senkrecht auf den neuen Preis
                $d .= \sprintf('L%.1f %.1f L%.1f %.1f ', $x($i), $letztesY, $x($i), $yy);
            }
            $letztesY = $yy;
        }
        $svg .= '<path d="' . \trim($d) . '" fill="none" stroke="' . self::FARBE['preis']
              . '" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="square"/>';

        // --- Referenzlinie ----------------------------------------------------
        if ($referenz !== null) {
            $yy = $y((float) $referenz);
            // Beschriftung UNTER die Linie: Über ihr liegt fast immer die Preistreppe,
            // und zwei sich kreuzende Beschriftungen sind auf einem Beweisdokument
            // schlimmer als eine ungewohnte Position.
            $svg .= \sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="'
                . self::FARBE['referenz'] . '" stroke-width="1.6" stroke-dasharray="6 4"/>'
                . '<text x="%d" y="%.1f" font-size="10" font-weight="600" fill="'
                . self::FARBE['referenz'] . '">30-Tage-Referenz %s</text>',
                $l, $yy, $this->width - $r, $yy, $l + 4, $yy + 12,
                \number_format((float) $referenz, 2, ',', '.'));
        }

        // --- Vorstufen-Anker ---------------------------------------------------
        if ($prev !== null) {
            $yy = $y((float) $prev);
            $svg .= \sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="'
                . self::FARBE['vorstufe'] . '" stroke-width="1.8" stroke-dasharray="2 5"/>'
                . '<text x="%d" y="%.1f" font-size="10" font-weight="600" fill="'
                . self::FARBE['vorstufe'] . '" text-anchor="end">Vorstufe %s</text>',
                $l, $yy, $this->width - $r, $yy, $this->width - $r - 4, $yy - 5,
                \number_format((float) $prev, 2, ',', '.'));
        }

        // --- Stichtag ----------------------------------------------------------
        if ($stichtag !== null && isset($idx[$stichtag])) {
            // Beschriftung nach innen ziehen, wenn der Stichtag am Rand liegt — sonst
            // wird sie beschnitten (im PDF fiel „Stichtag" halb aus dem Bild).
            $sx = $x($idx[$stichtag]);
            $anker = $sx > $this->width - $r - 30 ? 'end' : ($sx < $l + 30 ? 'start' : 'middle');
            $svg .= \sprintf('<line x1="%.1f" y1="%d" x2="%.1f" y2="%d" stroke="'
                . self::FARBE['stichtag'] . '" stroke-width="1.4" stroke-dasharray="3 3"/>'
                . '<text x="%.1f" y="%d" font-size="10" fill="' . self::FARBE['stichtag']
                . '" text-anchor="%s">Stichtag</text>',
                $sx, $o, $sx, $o + $ih, $sx, $o + $ih + 26, $anker);
        }

        // --- Datumsbeschriftung -------------------------------------------------
        // So viele Marken, wie ohne Überlappung passen: Bei einem Jahresverlauf sind drei
        // Daten zu wenig, um einen Zeitpunkt zu verorten.
        $marken = \min(8, \max(2, (int) \floor($iw / 78)));
        $jahresspanne = \strtotime($tage[$n - 1]['date']) - \strtotime($tage[0]['date']) > 200 * 86400;
        for ($k = 0; $k <= $marken; $k++) {
            $i = (int) \round($k * ($n - 1) / $marken);
            $anker = $k === 0 ? 'start' : ($k === $marken ? 'end' : 'middle');
            $svg .= \sprintf('<text x="%.1f" y="%d" font-size="10" fill="'
                . self::FARBE['achse'] . '" text-anchor="%s">%s</text>',
                $x($i), $o + $ih + 14, $anker,
                \date($jahresspanne ? 'm/y' : 'd.m.', \strtotime($tage[$i]['date'])));
        }

        return $svg . '</svg>';
    }

    /** Nächstgrößerer „runder" Schritt (1/2/5 × Zehnerpotenz) für die Preisachse. */
    private function rasterSchritt(float $roh): float
    {
        if ($roh <= 0) {
            return 1.0;
        }
        $potenz = 10 ** \floor(\log10($roh));
        foreach ([1, 2, 5, 10] as $f) {
            if ($roh <= $f * $potenz) {
                return (float) ($f * $potenz);
            }
        }
        return (float) (10 * $potenz);
    }
}
