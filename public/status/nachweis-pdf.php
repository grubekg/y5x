<?php
declare(strict_types=1);

/**
 * Nachweis als PDF — das Dokument, das im Abmahnfall an den Schriftsatz geheftet wird.
 *
 * **Warum ein eigenes PDF und kein „Drucken"-Knopf:** Ein Browserausdruck hängt von
 * Seitenrändern, Zoomstufe und Druckdialog des jeweiligen Rechners ab. Ein Beweismittel
 * soll bei jedem gleich aussehen und als Datei weitergegeben werden können.
 *
 * Erzeugt mit mpdf (reines PHP, kein Shell-Aufruf, kein Browser im Hintergrund) — auf
 * diesem Webspace gibt es weder wkhtmltopdf noch Chromium, und ein FPM-Prozess, der
 * einen Browser startet, wäre für ein Compliance-Werkzeug die falsche Art von Abhängigkeit.
 *
 * Die Zahlen entstehen aus **denselben** Klassen wie die Bildschirmansicht — der Wert im
 * PDF kann nicht von dem abweichen, was die Seite zeigt.
 */
require __DIR__ . '/lib.php';
require_login();

use Grube\Price30\Calc\PriceEvent;
use Grube\Price30\Calc\PriceWindow;
use Grube\Price30\Calc\PromoStateMachine;
use Grube\Price30\Calc\ReferenceCalculator;
use Grube\Price30\Calc\Replay;
use Grube\Price30\Support\PriceChart;
use Grube\Price30\Support\PriceChartData;

$sku      = \trim((string) ($_GET['sku'] ?? ''));
$markt    = \trim((string) ($_GET['markt'] ?? 'DE'));
$stichtag = \trim((string) ($_GET['stichtag'] ?? \date('Y-m-d')));
$app      = cfg('app');
$tage     = (int) ($app['window_days'] ?? 30);

$zeilen = db()->query(
    'SELECT * FROM {p}price_events WHERE sku = ? AND market = ? ORDER BY valid_from',
    [$sku, $markt]);
if ($zeilen === []) {
    \http_response_code(404);
    exit('Kein Preisintervall für diesen Artikel in diesem Markt.');
}

$events = [];
foreach ($zeilen as $z) {
    $events[] = new PriceEvent(new DateTimeImmutable($z['valid_from']),
        $z['valid_to'] !== null ? new DateTimeImmutable($z['valid_to']) : null,
        $z['net'], $z['gross'], $z['currency']);
}
$waehrung = (string) $zeilen[0]['currency'];

$fenster = new PriceWindow($tage);
$rechner = new ReferenceCalculator($fenster,
    new PromoStateMachine($fenster, (int) ($app['permanent_after_days'] ?? 60)),
    (string) ($app['calculation_mode'] ?? 'frozen'),
    (bool) ($app['prev_price_enabled'] ?? false),
    (int) ($app['prev_price_max_days'] ?? 42));
$replay = new Replay($rechner);

$stich  = new DateTimeImmutable($stichtag);
$heute  = new DateTimeImmutable('today');
$ref    = $replay->until($events, $stich, $waehrung);
$preis  = $replay->priceOn($events, $stich);
[$fVon, $fBis, $quelle, $fWort] = beleg_fenster($fenster, $events, $ref, $stich);
$fensterTage = $replay->windowDays($events, $fBis->modify('+1 day'), $tage);
$name = artikelname($sku, $markt);
$shop = produkt_url($sku, $markt);
$link = $shop['url'];

// Diagramm — **derselbe Renderer wie am Bildschirm**. Zwei Zeichenwege fuer dasselbe
// Bild waeren die sicherste Art, dass Ausdruck und Anzeige irgendwann auseinanderlaufen;
// bei einem Beweisdokument ist genau das die Eigenschaft, die man nicht haben will.
$refReihe = []; $prevReihe = []; $prevOffen = null; $letzteRef = null;
$ersterTag = $events[0]->validFrom;
$letzterTag = $stich < $ersterTag ? $ersterTag : $stich;
for ($t = $ersterTag; $t <= $letzterTag; $t = $t->modify('+1 day')) {
    $r = $replay->until($events, $t, $waehrung);
    if ($r === null) { continue; }
    $d = $t->format('Y-m-d');
    if ($r->gross !== null && ($letzteRef === null || (float) $letzteRef !== (float) $r->gross)) {
        $refReihe[] = ['date' => $d, 'value' => (float) $r->gross];
        $letzteRef = $r->gross;
    }
    if ($r->hasPrev()) {
        if ($prevOffen === null) {
            $prevOffen = ['from' => $d, 'to' => null, 'value' => (float) $r->prevGross];
        }
    } elseif ($prevOffen !== null) {
        $prevOffen['to'] = $t->modify('-1 day')->format('Y-m-d');
        $prevReihe[] = $prevOffen;
        $prevOffen = null;
    }
}
if ($prevOffen !== null) { $prevReihe[] = $prevOffen; }

$zeilenRoh = \array_map(static fn($z) => [
    'gross' => $z['gross'], 'net' => $z['net'],
    'valid_from' => $z['valid_from'], 'valid_to' => $z['valid_to']], $zeilen);
$writeLogRoh = db()->query(
    'SELECT * FROM {p}pss_write_log WHERE sku = ? AND market = ? ORDER BY id', [$sku, $markt]);
$zustandAmStichtag = [
    'mode'              => $ref?->state->mode ?? 'normal',
    'promo_started'     => $ref?->state->promoStarted?->format('Y-m-d'),
    'last_reduction_at' => $ref?->state->lastReductionAt?->format('Y-m-d'),
];
$svg = PriceChart::ohneCssVariablen(PriceChart::render(
    PriceChartData::build($zeilenRoh, $writeLogRoh, $zustandAmStichtag, $stichtag, [
        'marker'       => ['date' => $stichtag, 'label' => 'Stichtag'],
        'windowDays'   => $tage,
        'prevMaxDays'  => (int) ($app['prev_price_max_days'] ?? 42),
        'refWrites'    => $refReihe,
        'prevSegments' => $prevReihe,
    ])));

$belegt = \count(\array_filter($fensterTage, static fn($t) => $t['gross'] !== null));

\ob_start();
?>
<style>
 body{font-family:sans-serif;font-size:9.5pt;color:#1d221e}
 h1{font-size:14pt;margin:0 0 2mm}
 h2{font-size:8pt;letter-spacing:1.2pt;text-transform:uppercase;color:#6b6b64;
    margin:6mm 0 1.5mm;border-bottom:.4pt solid #b9b9ae;padding-bottom:.8mm}
 .kopf{border-bottom:1.2pt solid #14231b;padding-bottom:2mm;margin-bottom:3mm}
 .kopf p{margin:.6mm 0;font-size:8.5pt}
 table{width:100%;border-collapse:collapse;font-size:8.5pt}
 th,td{border:.4pt solid #dcdcd4;padding:1.1mm 1.6mm;text-align:left}
 th{background:#f0f0ec;font-size:7.5pt;text-transform:uppercase;letter-spacing:.6pt}
 td.z,th.z{text-align:right}
 .stempel{border:1pt dashed #2e5240;padding:2.5mm 3mm;margin-top:2mm}
 .stempel p{margin:.7mm 0;font-size:10pt}
 .hinweis{border-left:2.5pt solid #8a5200;background:#f8ecd7;padding:2mm 3mm;margin:2.5mm 0;font-size:8.5pt}
 .fuss{color:#6b6b64;font-size:7.5pt;margin-top:2mm}
</style>
<div class="kopf">
  <h1>Preisnachweis <?= h($sku) ?> · Markt <?= h($markt) ?></h1>
  <?php if ($name !== null): ?><p><b><?= h($name) ?></b></p><?php endif; ?>
  <p>Stichtag <b><?= datum($stichtag) ?></b> · Erfassungszeitraum
     <?= datum($ersterTag->format('Y-m-d')) ?>–<?= datum($letzterTag->format('Y-m-d')) ?></p>
  <p>Erstellt am <?= \date('d.m.Y H:i') ?> von <?= h(current_user()) ?> ·
     Berechnungsmodus <?= h((string) ($app['calculation_mode'] ?? 'frozen')) ?> ·
     Fenster <?= $tage ?> Tage</p>
  <p>Quelle: Preisintervalle (<i>price_events</i>) und Schreibprotokoll (<i>pss_write_log</i>).
     Der Referenzwert ist aus den Intervallen <b>nachgerechnet</b>, nicht nachgeschlagen.
     <?php if ($link !== null): ?><br>Produktseite im Shop: <?= h($link) ?>
     (Adresse geprüft am <?= h(\date('d.m.Y', \strtotime((string) $shop['geprueft']))) ?>)<?php
     else: ?><br>Produktseite: <?= h($shop['hinweis']) ?><?php endif; ?></p>
</div>

<div class="stempel">
  <p><b>Am <?= datum($stichtag) ?> galt:</b></p>
  <p>Verkaufspreis&nbsp;&nbsp;&nbsp;<b><?= geld($preis?->gross, $waehrung) ?></b> brutto ·
     <?= geld($preis?->net, $waehrung, 4) ?> netto</p>
  <p>Referenz <?= $tage ?> Tage&nbsp;&nbsp;<b><?= geld($ref?->gross, $waehrung) ?></b> brutto ·
     <?= geld($ref?->net, $waehrung, 4) ?> netto</p>
  <?php if ($app['prev_price_enabled'] ?? false): ?>
  <p>Vorstufe&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<?= $ref?->hasPrev()
        ? geld($ref->prevGross, $waehrung) . ' brutto' : '— (geleert)' ?></p>
  <?php endif; ?>
  <p>Zustand&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<?= h($ref?->state->mode ?? '—') ?>
     · Fenster <?= $belegt ?>/<?= \count($fensterTage) ?> Tage belegt</p>
</div>

<p class="fuss">Grundlage des Referenzwerts: <?= h($ref?->origin ?? '—') ?><?php
  if ($quelle !== null): ?>, belegt durch das Intervall
  <?= datum($quelle->validFrom->format('Y-m-d')) ?>–<?= datum($quelle->validTo?->format('Y-m-d')) ?><?php
  endif; ?>.</p>

<h2>Preisverlauf</h2>
<?= $svg ?>
<p class="fuss">Als Treppe gezeichnet: Ein Preis gilt über sein Intervall konstant und
  springt dann. Eine interpolierte Linie behauptete Zwischenpreise, die es nie gab.
  Schattiert das <?= $tage ?>-Tage-Fenster vor dem <?= h($fWort) ?>
  (<?= datum($fVon->format('Y-m-d')) ?>–<?= datum($fBis->format('Y-m-d')) ?>) — der Zeitraum,
  aus dem die ausgewiesene Referenz stammt. Gestrichelt die Referenz.</p>

<h2>Preisintervalle</h2>
<table>
<tr><th>#</th><th>gültig von</th><th>gültig bis</th><th class="z">brutto</th>
    <th class="z">netto</th><th class="z">Tage</th><th>im Fenster</th></tr>
<?php foreach ($events as $i => $e):
    $imF = $fensterTage !== [] && $e->overlaps(
        new DateTimeImmutable($fensterTage[0]['date']),
        new DateTimeImmutable($fensterTage[\count($fensterTage) - 1]['date']));
?>
<tr>
  <td><?= $i + 1 ?></td>
  <td><?= datum($e->validFrom->format('Y-m-d')) ?></td>
  <td><?= datum($e->validTo?->format('Y-m-d')) ?></td>
  <td class="z"><?= geld($e->gross, $e->currency) ?></td>
  <td class="z"><?= geld($e->net, $e->currency, 4) ?></td>
  <td class="z"><?= (int) $e->validFrom->diff($e->validTo ?? $heute)->days + 1 ?></td>
  <td><?= $quelle !== null && $e === $quelle ? 'Referenz' : ($imF ? 'ja' : '') ?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>Schreibprotokoll</h2>
<?php $writes = db()->query(
  'SELECT * FROM {p}pss_write_log WHERE sku = ? AND market = ? ORDER BY id DESC LIMIT 40',
  [$sku, $markt]); ?>
<?php if ($writes === []): ?>
<p class="fuss">Für diesen Artikel wurde noch kein Wert an den PSS übertragen<?=
  h(match (schreibmodi()[$markt] ?? 'unbekannt') {
      'gesperrt' => ' (dieser Markt wird bewusst nur beobachtet)',
      'trocken'  => ' (der letzte Lauf hat nicht geschrieben)',
      'unbekannt'=> ' (Schreibmodus der bisherigen Läufe nicht festgehalten)',
      default    => '',
  }) ?>.</p>
<?php else: ?>
<table>
<tr><th>Zeitpunkt</th><th>Eintrag</th><th class="z">alt</th><th class="z">neu</th><th>Ergebnis</th></tr>
<?php foreach ($writes as $w): ?>
<tr><td><?= h(\date('d.m.Y H:i', \strtotime($w['written_at']))) ?></td>
    <td><?= h($w['price_type']) ?></td>
    <td class="z"><?= geld($w['old_value'], $w['currency'], 4) ?></td>
    <td class="z"><?= geld($w['new_value'], $w['currency'], 4) ?></td>
    <td><?= $w['success'] ? 'übertragen' : 'fehlgeschlagen' ?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<?php
$html = (string) \ob_get_clean();

$tmp = \sys_get_temp_dir() . '/y5x-mpdf';
if (!\is_dir($tmp)) { @\mkdir($tmp, 0770, true); }
$pdf = new \Mpdf\Mpdf(['tempDir' => $tmp, 'format' => 'A4',
    'margin_top' => 14, 'margin_bottom' => 16, 'margin_left' => 14, 'margin_right' => 14]);
$pdf->SetTitle("Preisnachweis $sku $markt " . datum($stichtag));
$pdf->SetAuthor('Preisschreiber · GRUBE KG');
// Fusszeile als Tabelle, nicht mit `float`: mpdf setzt Floats in Kopf-/Fusszeilen nicht
// zuverlaessig um — im ersten Versuch klebte „11:13Seite 1 von 1" zusammen.
$pdf->SetHTMLFooter(
    '<table width="100%" style="border-top:.4pt solid #b9b9ae;font-size:7pt;color:#6b6b64">'
    . '<tr><td style="padding-top:1mm">Preisnachweis ' . h($sku) . ' · ' . h($markt)
    . ' · Stichtag ' . datum($stichtag) . ' · erstellt ' . \date('d.m.Y H:i') . '</td>'
    . '<td align="right" style="padding-top:1mm">Seite {PAGENO} von {nbpg}</td></tr></table>');
$pdf->WriteHTML($html);
$pdf->Output(\sprintf('Preisnachweis_%s_%s_%s.pdf', $sku, $markt, \str_replace('-', '', $stichtag)), 'D');
