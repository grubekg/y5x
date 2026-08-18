<?php
declare(strict_types=1);

namespace Grube\Price30\Support;

use DateTimeImmutable;

/**
 * PriceChart — serverseitiger SVG-Renderer für den „Messschrieb" der Nachweisseite.
 *
 * **Herkunft: fertig geliefert von GRUBE am 18.08.2026**, samt PriceChartData und 21
 * Prüfungen. Übernommen mit vier Eingriffen, alle dokumentiert:
 *
 * 1. **Namensraum** `Grube\Price30\Support`, damit der PSR-4-Autoloader greift.
 * 2. **`static $prev` im Dedup-Closure entfernt.** Eine statische Variable in einem
 *    Closure überlebt den Aufruf: Beim zweiten `render()` im selben Request hätte sie
 *    den Wert des ersten Diagramms behalten und den ersten Referenzschritt
 *    verschluckt. Bei einem Diagramm je Seite fällt das nicht auf — auf einer Demoseite
 *    mit drei Fällen sofort, und in einem Beweisdokument wäre es ein stiller Fehler.
 * 3. **`ohneCssVariablen()`** löst `var(--x,#hex)` auf: mpdf kennt keine CSS-Variablen
 *    und zeichnete die Kurven sonst schwarz oder gar nicht.
 * 4. `usort` auf Kopien, damit die übergebenen Arrays unberührt bleiben — der Aufrufer
 *    nutzt dieselben Zeilen darunter noch für die Tabellen.
 */
final class PriceChart
{
    private const W = 860;
    private const H = 300;
    private const PAD_L = 60;
    private const PAD_R = 20;
    private const PLOT_TOP = 20;
    private const PLOT_BOTTOM = 250;
    private const REF_OFFSET = 3;
    private const PREV_OFFSET = 6;

    public static function render(array $cfg): string
    {
        $events = $cfg['events'] ?? [];
        if ($events === []) {
            return '<p class="hinweiskasten">Keine Preisereignisse vorhanden — '
                 . 'der Messschrieb erscheint nach dem ersten Lauf.</p>';
        }
        \usort($events, static fn($a, $b) => \strcmp($a['valid_from'], $b['valid_from']));
        $today = $cfg['today'] ?? \date('Y-m-d');

        // --- Zeitskala ----------------------------------------------------
        $t0 = $events[0]['valid_from'];
        $t1 = $today;
        foreach ($events as $e) { $t1 = \max($t1, $e['valid_to'] ?? $today); }
        // Nie ueber den Bezugstag hinaus: Ein Intervall kann laenger gelten, als der
        // Nachweis reicht — die Zeitachse eines Nachweises zum 1. Mai darf trotzdem
        // nicht bis in den Juni laufen.
        if ($t1 > $today) { $t1 = $today; }
        foreach (($cfg['refWrites'] ?? []) as $w) { $t0 = \min($t0, $w['date']); $t1 = \max($t1, $w['date']); }
        if (!empty($cfg['marker']['date'])) {
            $t0 = \min($t0, $cfg['marker']['date']); $t1 = \max($t1, $cfg['marker']['date']);
        }
        $span = \max(1, self::days($t0, $t1));
        $innerW = self::W - self::PAD_L - self::PAD_R;
        $x = static fn(string $d): float => \round(self::PAD_L + self::days($t0, $d) * $innerW / $span, 1);
        $xEnd = $x($t1);

        // --- Preisskala ---------------------------------------------------
        $vals = [];
        foreach ($events as $e) { $vals[] = (float) $e['gross']; }
        foreach (($cfg['refWrites'] ?? []) as $w) { $vals[] = (float) $w['value']; }
        foreach (($cfg['prevSegments'] ?? []) as $p) { $vals[] = (float) $p['value']; }
        [$lo, $hi, $step] = self::niceScale(\min($vals), \max($vals));
        $innerH = self::PLOT_BOTTOM - self::PLOT_TOP;
        $y = static fn(float $p): float => \round(self::PLOT_BOTTOM - ($p - $lo) * $innerH / ($hi - $lo), 1);

        $s = [];
        $aria = \htmlspecialchars($cfg['ariaLabel'] ?? 'Preisverlauf mit 30-Tage-Fenster und Referenzwerten', \ENT_QUOTES);
        $s[] = '<svg viewBox="0 0 ' . self::W . ' ' . self::H . '" role="img" aria-label="' . $aria . '">';
        $s[] = '<title>' . $aria . '</title>';

        // --- Phasenflächen ------------------------------------------------
        foreach (($cfg['promoBands'] ?? []) as $b) {
            $bx = $x($b['from']);
            $bw = \max(2, ($b['to'] ? $x(self::nextDay($b['to'])) : $xEnd) - $bx);
            $s[] = '<rect x="' . $bx . '" y="' . self::PLOT_TOP . '" width="' . \round($bw, 1) . '" height="' . $innerH
                 . '" fill="var(--aktion,#c46a00)" opacity=".13"/>';
            if (!empty($b['label'])) {
                $s[] = '<text x="' . \round($bx + $bw / 2, 1) . '" y="34" text-anchor="middle" font-size="10" '
                     . 'fill="#8a6a3c" font-family="system-ui">' . \htmlspecialchars($b['label']) . '</text>';
            }
        }
        foreach (($cfg['windows'] ?? []) as $wnd) {
            $wx = $x($wnd['from']);
            $ww = \max(2, $x(self::nextDay($wnd['to'])) - $wx);
            $s[] = '<rect x="' . $wx . '" y="' . self::PLOT_TOP . '" width="' . \round($ww, 1) . '" height="' . $innerH
                 . '" fill="var(--fenster,#2e5240)" opacity=".08"/>';
            if (!empty($wnd['label'])) {
                // Mittig ueber dem Band, aber innerhalb der Zeichenflaeche gehalten.
                $lx = \min(\max(\round($wx + $ww / 2, 1), self::PAD_L + 90), self::W - self::PAD_R - 90);
                $s[] = '<text x="' . $lx . '" y="245" text-anchor="middle" font-size="10" '
                     . 'fill="var(--fenster,#2e5240)" font-family="system-ui">'
                     . \htmlspecialchars($wnd['label']) . '</text>';
            }
        }

        // --- Raster + Preisachse -----------------------------------------
        $s[] = '<g stroke="#e8e8e2">';
        for ($p = $lo; $p <= $hi + 1e-9; $p += $step) {
            $s[] = '<line x1="' . self::PAD_L . '" y1="' . $y($p) . '" x2="' . $xEnd . '" y2="' . $y($p) . '"/>';
        }
        $s[] = '</g>';
        $s[] = '<g font-family="ui-monospace,Consolas,monospace" font-size="10" fill="#777">';
        for ($p = $lo; $p <= $hi + 1e-9; $p += $step) {
            $s[] = '<text x="' . (self::PAD_L - 6) . '" y="' . ($y($p) + 3) . '" text-anchor="end">'
                 . self::fmt($p, $step < 1 ? 2 : 0) . ' €</text>';
        }
        $s[] = '</g>';

        // --- Zeitachse ----------------------------------------------------
        $s[] = '<line x1="' . self::PAD_L . '" y1="' . self::PLOT_BOTTOM . '" x2="' . $xEnd . '" y2="'
             . self::PLOT_BOTTOM . '" stroke="#b9b9ae"/>';
        $s[] = '<g font-family="ui-monospace,Consolas,monospace" font-size="10" fill="#777" text-anchor="middle">';
        $lastLabelX = -1e9;
        foreach (self::timeTicks($t0, $t1) as $tick) {
            $tx = $x($tick);
            if ($tx - $lastLabelX < 46) { continue; }
            $s[] = '<text x="' . $tx . '" y="266">' . self::deDate($tick) . '</text>';
            $lastLabelX = $tx;
        }
        $s[] = '</g>';

        // --- Stichtags-Läufer --------------------------------------------
        if (!empty($cfg['marker']['date'])) {
            $mx = $x($cfg['marker']['date']);
            // Anker am Rand umschlagen lassen, sonst wird die Beschriftung beschnitten —
            // der Stichtag liegt fast immer ganz rechts.
            $anker = $mx > self::W - self::PAD_R - 60 ? 'end'
                   : ($mx < self::PAD_L + 60 ? 'start' : 'middle');
            $s[] = '<line x1="' . $mx . '" y1="' . self::PLOT_TOP . '" x2="' . $mx . '" y2="' . self::PLOT_BOTTOM
                 . '" stroke="var(--ok,#2f6b3a)" stroke-width="1.5" stroke-dasharray="3 3"/>';
            $s[] = '<text x="' . $mx . '" y="14" text-anchor="' . $anker . '" font-size="10" '
                 . 'fill="var(--ok,#2f6b3a)" font-family="ui-monospace,Consolas,monospace">'
                 . \htmlspecialchars($cfg['marker']['label'] ?? 'Stichtag') . ' '
                 . self::deDate($cfg['marker']['date']) . '</text>';
        }

        // --- Referenz-Treppe ----------------------------------------------
        $writes = $cfg['refWrites'] ?? [];
        \usort($writes, static fn($a, $b) => \strcmp($a['date'], $b['date']));
        // Aufeinanderfolgende gleiche Werte zusammenfassen. Bewusst OHNE `static` im
        // Closure — eine statische Variable überlebte den Aufruf und verschluckte beim
        // zweiten Diagramm im selben Request den ersten Schritt.
        $entdoppelt = [];
        $vorher = null;
        foreach ($writes as $w) {
            if ($vorher === null || \abs($vorher - (float) $w['value']) > 0.004) {
                $entdoppelt[] = $w;
            }
            $vorher = (float) $w['value'];
        }
        $writes = $entdoppelt;
        if ($writes !== []) {
            $d = 'M' . $x($writes[0]['date']) . ',' . ($y((float) $writes[0]['value']) + self::REF_OFFSET);
            for ($i = 1, $n = \count($writes); $i < $n; $i++) {
                $d .= ' H' . $x($writes[$i]['date'])
                    . ' V' . ($y((float) $writes[$i]['value']) + self::REF_OFFSET);
            }
            $d .= ' H' . $xEnd;
            $s[] = '<path d="' . $d . '" fill="none" stroke="var(--referenz,#a8231b)" '
                 . 'stroke-width="1.8" stroke-dasharray="6 4"/>';
            $lastRef = (float) \end($writes)['value'];
            // UNTER die Linie: Ueber ihr stehen die Stufenbeschriftungen des
            // Verkaufspreises, und zwei sich ueberdruckende Zahlen sind auf einem
            // Beweisdokument schlimmer als eine ungewohnte Position.
            $refLabelY = $y($lastRef) + 14;
        }

        // --- Vorstufen-Anker ----------------------------------------------
        foreach (($cfg['prevSegments'] ?? []) as $seg) {
            $sx = $x($seg['from']);
            $ex = $seg['to'] ? $x(self::nextDay($seg['to'])) : $xEnd;
            $sy = $y((float) $seg['value']) + self::PREV_OFFSET;
            $s[] = '<path d="M' . $sx . ',' . $sy . ' H' . \round($ex, 1) . '" fill="none" '
                 . 'stroke="var(--vorstufe,#6d4a9e)" stroke-width="2.4" stroke-dasharray="2 5"/>';
        }
        $openPrev = \array_values(\array_filter($cfg['prevSegments'] ?? [], static fn($p) => empty($p['to'])));
        $prevLabelY = null;
        if ($openPrev !== []) {
            $pv = (float) $openPrev[0]['value'];
            $prevLabelY = $y($pv) + 18;
        }

        // Beide Beschriftungen haengen rechtsbuendig am selben Rand. Liegen Referenz und
        // Vorstufe preislich nah beieinander, ueberdruckten sie sich — dann wandert die
        // Referenz nach unten weg. Auf einem Beweisdokument ist eine unleserliche Zahl
        // schlimmer als eine ungewohnte Position.
        if (isset($refLabelY, $prevLabelY) && \abs($refLabelY - $prevLabelY) < 14) {
            $refLabelY = $prevLabelY + 16;
        }
        if (isset($refLabelY, $lastRef)) {
            $s[] = '<text x="' . ($xEnd - 4) . '" y="' . \max(self::PLOT_TOP + 10, $refLabelY)
                 . '" text-anchor="end" font-size="10" fill="var(--referenz,#a8231b)" '
                 . 'font-family="system-ui">'
                 . \htmlspecialchars($cfg['refLabel'] ?? 'Referenz') . ': ' . self::fmt($lastRef) . ' €</text>';
        }
        if ($prevLabelY !== null) {
            $extra = empty($cfg['prevClearDate']) ? ''
                   : ' · wird ' . self::deDate($cfg['prevClearDate']) . ' geleert';
            $s[] = '<text x="' . ($xEnd - 4) . '" y="' . $prevLabelY . '" text-anchor="end" font-size="10" '
                 . 'fill="var(--vorstufe,#6d4a9e)" font-family="system-ui">Vorstufe '
                 . self::fmt((float) $openPrev[0]['value']) . ' €' . \htmlspecialchars($extra) . '</text>';
        }

        // --- Verkaufspreis -------------------------------------------------
        $d = 'M' . $x($events[0]['valid_from']) . ',' . $y((float) $events[0]['gross']);
        for ($i = 1, $n = \count($events); $i < $n; $i++) {
            $d .= ' H' . $x($events[$i]['valid_from']) . ' V' . $y((float) $events[$i]['gross']);
        }
        $d .= ' H' . $xEnd;
        $s[] = '<path d="' . $d . '" fill="none" stroke="var(--preis,#1d3a2b)" '
             . 'stroke-width="2.6" stroke-linejoin="round"/>';

        if (\count($events) <= 8) {
            $s[] = '<g font-size="10" font-family="ui-monospace,Consolas,monospace" fill="var(--preis,#1d3a2b)">';
            $prevVal = null;
            foreach ($events as $e) {
                $g = (float) $e['gross'];
                if ($prevVal !== null && \abs($g - $prevVal) < 0.004) { $prevVal = $g; continue; }
                $prevVal = $g;
                $ly = $y($g) - 5;
                if ($ly < self::PLOT_TOP + 10) { $ly = $y($g) + 14; }
                $s[] = '<text x="' . ($x($e['valid_from']) + 4) . '" y="' . $ly . '">' . self::fmt($g) . '</text>';
            }
            $s[] = '</g>';
        }

        $s[] = '</svg>';
        return \implode("\n", $s);
    }

    /**
     * `var(--referenz,#a8231b)` -> `#a8231b`.
     *
     * mpdf kennt keine CSS-Variablen. Ohne diesen Schritt zeichnete das PDF die Kurven
     * schwarz oder gar nicht — und ein Beweisdokument, dessen Linien fehlen, ist wertlos.
     * Im Browser bleibt das Original mit Variablen, damit die Seitenfarben durchschlagen.
     */
    public static function ohneCssVariablen(string $svg): string
    {
        return \preg_replace('/var\(\s*--[a-z-]+\s*,\s*(#[0-9a-fA-F]{3,8})\s*\)/', '$1', $svg) ?? $svg;
    }

    // ---------------------------------------------------------------- Helfer

    private static function days(string $a, string $b): int
    {
        return (int) \round((\strtotime($b . ' 12:00') - \strtotime($a . ' 12:00')) / 86400);
    }

    private static function nextDay(string $d): string
    {
        return \date('Y-m-d', \strtotime($d . ' 12:00') + 86400);
    }

    /** 1-2-5-Skala mit ~4–6 Rasterlinien und leichtem Rand. */
    private static function niceScale(float $min, float $max): array
    {
        if ($max - $min < 1e-9) { $min -= 1; $max += 1; }
        $pad = ($max - $min) * 0.10;
        $min -= $pad; $max += $pad;
        $raw = ($max - $min) / 4;
        $mag = 10 ** \floor(\log10($raw));
        $step = $mag;
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            $step = $m * $mag;
            if ($step >= $raw) { break; }
        }
        return [\floor($min / $step) * $step, \ceil($max / $step) * $step, $step];
    }

    private static function timeTicks(string $t0, string $t1): array
    {
        $ticks = [$t0];
        $everyOther = self::days($t0, $t1) > 200;
        $c = new DateTimeImmutable(\substr($t0, 0, 7) . '-01');
        $end = new DateTimeImmutable($t1);
        $i = 0;
        while (($c = $c->modify('first day of next month')) <= $end) {
            if (!$everyOther || $i++ % 2 === 0) { $ticks[] = $c->format('Y-m-d'); }
        }
        $ticks[] = $t1;
        return \array_values(\array_unique($ticks));
    }

    private static function deDate(string $d): string
    {
        return \date('d.m.', \strtotime($d));
    }

    private static function fmt(float $v, int $dec = 2): string
    {
        return \number_format($v, $dec, ',', '.');
    }
}
