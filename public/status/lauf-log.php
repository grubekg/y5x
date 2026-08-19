<?php
declare(strict_types=1);

/**
 * Laufprotokoll als CSV — alle verworfenen Datensätze und Fehler eines Laufs.
 *
 * Warum eine Datei und keine breitere Spalte: Die Notiz im `run_log` fasst zusammen, und
 * jede Zusammenfassung schneidet ab. Gebraucht wird im Zweifel aber genau die Zeile, die
 * abgeschnitten war — „welcher Artikel wurde warum verworfen". Das ist eine Liste, keine
 * Meldung, und Listen gehören in eine Datei, die man filtern und weiterreichen kann.
 *
 * Semikolon und BOM, weil die Datei in aller Regel in Excel geöffnet wird: Ohne BOM
 * zerlegt Excel die Umlaute, ohne Semikolon steckt die ganze Zeile in Spalte A.
 *
 *   lauf-log.php?lauf=<id>          ein Lauf (ein Markt)
 *   lauf-log.php?tag=YYYY-MM-DD     alle Märkte eines Tages
 */
require __DIR__ . '/lib.php';
require_login();

$lauf = (int) ($_GET['lauf'] ?? 0);
$tag  = \trim((string) ($_GET['tag'] ?? ''));

if ($lauf > 0) {
    $wo = 'i.run_id = ?';
    $args = [$lauf];
    $name = 'lauf-' . $lauf;
} elseif (\preg_match('/^\d{4}-\d{2}-\d{2}$/', $tag)) {
    $wo = 'i.run_date = ?';
    $args = [$tag];
    $name = 'tag-' . $tag;
} else {
    \http_response_code(400);
    \header('Content-Type: text/plain; charset=utf-8');
    exit("Angabe fehlt: ?lauf=<id> oder ?tag=YYYY-MM-DD\n");
}

$zeilen = db()->query(
    "SELECT i.run_date, i.market, i.kind, i.sku, i.detail, i.net, i.gross,
            r.started_at, r.status
       FROM {p}run_issue i
       LEFT JOIN {p}run_log r ON r.id = i.run_id
      WHERE $wo
      ORDER BY i.market, i.kind, i.id", $args);

$datei = \sprintf('y5x-laufprotokoll-%s-%s.csv', y5x_env(), $name);
\header('Content-Type: text/csv; charset=utf-8');
\header('Content-Disposition: attachment; filename="' . $datei . '"');

$aus = \fopen('php://output', 'w');
\fwrite($aus, "\xEF\xBB\xBF");                      // BOM für Excel
\fputcsv($aus, ['Lauf begonnen', 'Laufdatum', 'Markt', 'Art', 'Artikelnummer',
                'Befund', 'Netto', 'Brutto'], ';', '"', '');
foreach ($zeilen as $z) {
    \fputcsv($aus, [
        $z['started_at'] ?? '',
        $z['run_date'],
        $z['market'],
        $z['kind'] === 'anomalie' ? 'verworfen' : 'Fehler',
        $z['sku'] ?? '',
        $z['detail'],
        $z['net'] ?? '',
        $z['gross'] ?? '',
    ], ';', '"', '');
}
\fclose($aus);
