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

        $x = static fn(int $i): float => $l + ($n === 1 ? 0 : $i * $iw / ($n - 1));
        $y = static fn(float $v): float => $o + $ih - (($v - $min) / ($max - $min)) * $ih;
        $idx = [];
        foreach ($tage as $i => $t) {
            $idx[$t['date']] = $i;
        }

        $svg = \sprintf(
            '<svg viewBox="0 0 %d %d" width="100%%" role="img" aria-label="Preisverlauf" '
            . 'preserveAspectRatio="xMidYMid meet" class="verlauf">',
            $this->width, $this->height);

        // --- Fenster hinterlegen -------------------------------------------
        if ($fenster !== null && isset($idx[$fenster['from']], $idx[$fenster['to']])) {
            $svg .= \sprintf(
                '<rect x="%.1f" y="%d" width="%.1f" height="%d" class="fenster"/>'
                . '<text x="%.1f" y="%d" class="mini">30-Tage-Fenster</text>',
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
            $svg .= \sprintf('<rect x="%.1f" y="%d" width="%.1f" height="%d" class="aktion"/>',
                $x($idx[$a['from']]), $o, \max($x($bis) - $x($idx[$a['from']]), 1), $ih);
        }

        // --- Achse ------------------------------------------------------------
        foreach ([0.0, 0.5, 1.0] as $anteil) {
            $wert = $min + ($max - $min) * (1 - $anteil);
            $yy = $o + $ih * $anteil;
            $svg .= \sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" class="raster"/>'
                . '<text x="%d" y="%.1f" class="achse" text-anchor="end">%s</text>',
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
        $svg .= '<path d="' . \trim($d) . '" class="linie"/>';

        // --- Referenzlinie ----------------------------------------------------
        if ($referenz !== null) {
            $yy = $y((float) $referenz);
            $svg .= \sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" class="referenz"/>'
                . '<text x="%d" y="%.1f" class="mini ref">30-Tage-Referenz %s</text>',
                $l, $yy, $this->width - $r, $yy, $l + 4, $yy - 5,
                \number_format((float) $referenz, 2, ',', '.'));
        }

        // --- Vorstufen-Anker ---------------------------------------------------
        if ($prev !== null) {
            $yy = $y((float) $prev);
            $svg .= \sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" class="prev"/>'
                . '<text x="%d" y="%.1f" class="mini pv" text-anchor="end">Vorstufe %s</text>',
                $l, $yy, $this->width - $r, $yy, $this->width - $r - 4, $yy - 5,
                \number_format((float) $prev, 2, ',', '.'));
        }

        // --- Stichtag ----------------------------------------------------------
        if ($stichtag !== null && isset($idx[$stichtag])) {
            // Beschriftung nach innen ziehen, wenn der Stichtag am Rand liegt — sonst
            // wird sie beschnitten (im PDF fiel „Stichtag" halb aus dem Bild).
            $sx = $x($idx[$stichtag]);
            $anker = $sx > $this->width - $r - 30 ? 'end' : ($sx < $l + 30 ? 'start' : 'middle');
            $svg .= \sprintf('<line x1="%.1f" y1="%d" x2="%.1f" y2="%d" class="stichtag"/>'
                . '<text x="%.1f" y="%d" class="mini" text-anchor="%s">Stichtag</text>',
                $sx, $o, $sx, $o + $ih, $sx, $o + $ih + 26, $anker);
        }

        // --- Datumsbeschriftung -------------------------------------------------
        foreach ([0, (int) ($n / 2), $n - 1] as $i) {
            $svg .= \sprintf('<text x="%.1f" y="%d" class="achse" text-anchor="middle">%s</text>',
                $x($i), $o + $ih + 14,
                \date('d.m.', \strtotime($tage[$i]['date'])));
        }

        return $svg . '</svg>';
    }
}
