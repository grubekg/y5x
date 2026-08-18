<?php
declare(strict_types=1);

/**
 * Artikelliste und Nachweis.
 *
 * **Ohne SKU: alle Artikel, nicht nur die in Aktion** (Vorgabe GRUBE, 18.08.2026).
 * Der vorherige Stand listete ausschließlich laufende Aktionen — man kam an einen
 * ruhenden Artikel nur heran, wenn man seine Nummer auswendig wusste. Ein
 * Nachweiswerkzeug, das den gesuchten Artikel nicht finden lässt, ist im Ernstfall
 * wertlos. Die Filter sind Einschränkungen einer vollständigen Liste, keine Vorauswahl.
 *
 * **Mit SKU: der Nachweis.** Artikel + Markt + Stichtag ergeben ein druckbares Dokument.
 * Der Referenzwert wird über `Calc\Replay` NACHGERECHNET, jeweils nur mit dem
 * Wissensstand des betreffenden Tages.
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
use Grube\Price30\Support\Money;

$app      = cfg('app');
$sku      = \trim((string) ($_GET['sku'] ?? ''));
$markt    = \trim((string) ($_GET['markt'] ?? 'DE'));
$filter   = (string) ($_GET['filter'] ?? 'alle');
$suche    = \trim((string) ($_GET['q'] ?? ''));
$stichtag = \trim((string) ($_GET['stichtag'] ?? \date('Y-m-d')));
$trocken  = (bool) ($app['dry_run'] ?? true);
$tage     = (int) ($app['window_days'] ?? 30);

$fenster = new PriceWindow($tage);
$rechner = new ReferenceCalculator(
    $fenster,
    new PromoStateMachine($fenster, (int) ($app['permanent_after_days'] ?? 60)),
    (string) ($app['calculation_mode'] ?? 'frozen'),
    (bool) ($app['prev_price_enabled'] ?? false),
    (int) ($app['prev_price_max_days'] ?? 42));
$replay = new Replay($rechner);

seitenkopf($sku !== '' ? "Nachweis $sku" : 'Artikel & Nachweis', 'artikel');
?>
<?php if ($sku === ''): ?>
<!-- Auswahlfelder feuern direkt ab (ein `onchange`, kein Framework). Ohne JavaScript
     bleibt der Knopf sichtbar — die Seite funktioniert in jedem Fall. -->
<form class="suche" method="get">
  <span><label for="f-q">Artikel suchen</label>
    <input id="f-q" name="q" value="<?= h($suche) ?>"
           class="mono" size="18" placeholder="Nummer oder Bezeichnung"></span>
  <span><label for="f-markt">Markt</label>
    <select id="f-markt" name="markt" onchange="this.form.submit()"><?php foreach (maerkte() as $c => $m):
        if (!($m['active'] ?? false)) { continue; } ?>
      <option<?= $markt === $c ? ' selected' : '' ?>><?= h($c) ?></option>
    <?php endforeach; ?></select></span>
  <span><label for="f-filter">Auswahl</label>
    <select id="f-filter" name="filter" onchange="this.form.submit()">
      <?php foreach (['alle' => 'alle Artikel', 'aktion' => 'in Aktion',
                      'normal' => 'ohne Aktion', 'risiko' => 'Risiko',
                      'unvollstaendig' => 'Fenster unvollständig'] as $k => $v): ?>
      <option value="<?= $k ?>"<?= $filter === $k ? ' selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select></span>
  <noscript><button class="knopf" type="submit">Filtern</button></noscript>
  <button class="knopf" type="submit" style="display:none" id="k-filtern">Filtern</button>
  <script>document.getElementById('k-filtern').remove()</script>
</form>
<?php else: ?>
<form class="suche" method="get">
  <input type="hidden" name="sku" value="<?= h($sku) ?>">
  <input type="hidden" name="markt" value="<?= h($markt) ?>">
  <span><label for="f-tag">Stichtag</label>
    <input id="f-tag" type="date" name="stichtag" value="<?= h($stichtag) ?>"
           onchange="this.form.submit()"></span>
  <noscript><button class="knopf" type="submit">Prüfen</button></noscript>
  <a class="knopf" style="text-decoration:none;line-height:1.6"
     href="nachweis-pdf.php?sku=<?= \urlencode($sku) ?>&amp;markt=<?= h($markt) ?>&amp;stichtag=<?= h($stichtag) ?>">Nachweis herunterladen (PDF)</a>
  <a class="knopf sekundaer" style="text-decoration:none;line-height:1.6"
     href="?markt=<?= h($markt) ?>">zur Liste</a>
</form>
<?php endif; ?>

<?php
// ============================================================ Liste (ohne SKU)
if ($sku === ''):
    $wo = ['s.market = ?'];
    $args = [$markt];
    switch ($filter) {
        case 'aktion':          $wo[] = "s.mode = 'promo'"; break;
        case 'normal':          $wo[] = "s.mode = 'normal'"; break;
        case 'risiko':          $wo[] = "s.mode = 'promo' AND s.window_complete = 0"; break;
        case 'unvollstaendig':  $wo[] = 's.window_complete = 0'; break;
    }
    // Gesucht wird nach Artikelnummer ODER Bezeichnung — wer den Artikel im Kopf hat,
    // hat selten die Nummer parat.
    if ($suche !== '') {
        $wo[] = '(s.sku LIKE ? OR a.name LIKE ?)';
        $args[] = $suche . '%';
        $args[] = '%' . $suche . '%';
    }

    $von = 'FROM {p}price_state s
        LEFT JOIN {p}article_meta a ON a.sku = s.sku AND a.market = s.market
        LEFT JOIN {p}price_events e ON e.sku = s.sku AND e.market = s.market
             AND e.valid_from = (SELECT MAX(valid_from) FROM {p}price_events
                                 WHERE sku = s.sku AND market = s.market)
        WHERE ' . \implode(' AND ', $wo);

    $gesamt = (int) (db()->one('SELECT COUNT(*) AS n FROM {p}price_state s
        LEFT JOIN {p}article_meta a ON a.sku = s.sku AND a.market = s.market
        WHERE ' . \implode(' AND ', $wo), $args)['n'] ?? 0);
    $proSeite = 100;
    $seiten = \max(1, (int) \ceil($gesamt / $proSeite));
    $seite  = \min($seiten, \max(1, (int) ($_GET['seite'] ?? 1)));

    $zeilen = db()->query(
        'SELECT s.*, a.name AS bezeichnung, e.gross AS vk_gross, e.net AS vk_net,
                e.currency, e.valid_from AS vk_seit '
        . $von . ' ORDER BY s.mode DESC, s.window_complete ASC, s.sku
          LIMIT ' . $proSeite . ' OFFSET ' . (($seite - 1) * $proSeite), $args);
?>
<h2>Artikel — <?= h($markt) ?><?= $filter !== 'alle' ? ' · ' . h($filter) : '' ?></h2>
<?php if ($zeilen === []): ?>
  <p class="hinweiskasten ruhig"><b>Keine Treffer.</b>
     <?= $suche !== '' ? 'Zur Suche „' . h($suche) . '" ' : 'Zu dieser Auswahl ' ?>
     liegt in Markt <?= h($markt) ?> nichts vor. Sobald der tägliche Lauf Daten liefert,
     stehen sie hier.</p>
<?php else: ?>
<div class="tabelle">
<table>
<thead><tr><th>Artikel</th><th>Zustand</th><th class="zahl">Preis heute</th>
  <th class="zahl">Referenz 30 T.</th><th class="zahl">Vorstufe</th>
  <th>Fenster</th><th>zuletzt geschrieben</th></tr></thead>
<tbody>
<?php foreach ($zeilen as $r):
    $cur = (string) ($r['currency'] ?? 'EUR');
    $inAktion = $r['mode'] === 'promo';
?>
<tr class="klickbar<?= $inAktion ? ' offen' : '' ?>">
  <td class="mono">
      <a href="?sku=<?= \urlencode($r['sku']) ?>&amp;markt=<?= h($markt) ?>"
         class="zeilenlink"><b><?= h($r['sku']) ?></b></a>
      <?php if (($r['bezeichnung'] ?? null) !== null): ?>
      <span class="sub bezeichnung"><?= h($r['bezeichnung']) ?></span>
      <?php endif; ?>
      <span class="sub">seit <?= datum($r['vk_seit']) ?> unverändert</span></td>
  <td><?php if ($inAktion): ?>
        <span class="status aktion">Aktion</span>
        <span class="sub">seit <?= datum($r['promo_started']) ?></span>
      <?php else: ?><span class="status ok">normal</span><?php endif; ?></td>
  <td class="zahl"><?= geld($r['vk_gross'], $cur) ?></td>
  <td class="zahl"><?= geld($r['last_written_30_gross'] ?? $r['frozen_ref_gross'], $cur) ?></td>
  <td class="zahl"><?= geld($r['pre_promo_gross'], $cur) ?></td>
  <td><?= $r['window_complete']
        ? '<span class="status ok">vollständig</span>'
        : '<span class="status warn">unvollständig</span>' ?></td>
  <td class="mono"><?= $r['last_written_at']
        ? h(\date('d.m.Y H:i', \strtotime((string) $r['last_written_at'])))
        : '<span class="sub" style="font-family:system-ui">' . ($trocken ? 'Trockenmodus' : 'noch nie') . '</span>' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p class="blaettern">
  <?php $qs = static function (int $n): string {
      $q = $_GET; $q['seite'] = $n; return '?' . \http_build_query($q); }; ?>
  <?php if ($seite > 1): ?><a href="<?= h($qs($seite - 1)) ?>">←</a><?php endif; ?>
  <?php for ($n = \max(1, $seite - 4); $n <= \min($seiten, $seite + 4); $n++): ?>
    <?= $n === $seite ? '<b>' . $n . '</b>' : '<a href="' . h($qs($n)) . '">' . $n . '</a>' ?>
  <?php endfor; ?>
  <?php if ($seite < $seiten): ?><a href="<?= h($qs($seite + 1)) ?>">→</a><?php endif; ?>
  <span class="zaehler"><?= zahl(($seite - 1) * $proSeite + 1) ?>–<?= zahl(($seite - 1) * $proSeite + \count($zeilen)) ?>
    von <b><?= zahl($gesamt) ?></b> Artikeln</span>
</p>
<?php endif; ?>

<?php
// ============================================================ Nachweis (mit SKU)
else:
    $zeilen = db()->query(
        'SELECT * FROM {p}price_events WHERE sku = ? AND market = ? ORDER BY valid_from',
        [$sku, $markt]);
    $events = [];
    foreach ($zeilen as $z) {
        $events[] = new PriceEvent(new DateTimeImmutable($z['valid_from']),
            $z['valid_to'] !== null ? new DateTimeImmutable($z['valid_to']) : null,
            $z['net'], $z['gross'], $z['currency']);
    }
    if ($events === []): ?>
  <p class="hinweiskasten"><b>Keine Historie.</b> Für <span class="mono"><?= h($sku) ?></span>
     liegt im Markt <?= h($markt) ?> kein Preisintervall vor — entweder wird der Artikel
     nicht getrackt, oder es gab noch keinen Lauf.</p>
<?php else:
    $waehrung = (string) $zeilen[0]['currency'];
    $stich  = new DateTimeImmutable($stichtag);
    $heute  = new DateTimeImmutable('today');
    $ref      = $replay->until($events, $stich, $waehrung);
    $jetzt    = $replay->until($events, $heute, $waehrung);
    $preisTag = $replay->priceOn($events, $stich);
    // Das Fenster, aus dem die Referenz wirklich stammt — bei laufender Aktion ist das
    // das Fenster VOR dem Aktionsbeginn, nicht das vor dem Stichtag.
    [$fVon, $fBis, $quelle, $fWort] = beleg_fenster($fenster, $events, $ref, $stich);
    $fensterTage = $replay->windowDays($events, $fBis->modify('+1 day'), $tage);
    $zustand = db()->one('SELECT * FROM {p}price_state WHERE sku = ? AND market = ?', [$sku, $markt]);
    $writeLogRoh = db()->query(
        'SELECT * FROM {p}pss_write_log WHERE sku = ? AND market = ? ORDER BY id', [$sku, $markt]);

    // Nachgerechnete Referenz-Treppe: Solange nichts geschrieben wird (Trockenmodus),
    // ist `pss_write_log` leer und das Diagramm hätte keine Referenzlinie. Also wird sie
    // Tag für Tag nachgerechnet — und im Schrieb ausdrücklich als „berechnet" ausgewiesen.
    $refReihe = []; $prevReihe = []; $prevOffen = null; $letzteRef = null;
    $ersterTag = $events[0]->validFrom;
    $letzterTag = $heute > $stich ? $heute : $stich;
    for ($t = $ersterTag; $t <= $letzterTag; $t = $t->modify('+1 day')) {
        $r = $replay->until($events, $t, $waehrung);
        if ($r === null) { continue; }
        $d = $t->format('Y-m-d');
        if ($r->gross !== null && ($letzteRef === null || !Money::equals($letzteRef, $r->gross))) {
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

    // Der Fall, der die Rechtslogik erklärt: Referenz unter der Vorstufe.
    $delle = $ref !== null && $ref->hasValue() && $ref->hasPrev()
        && Money::compare((string) $ref->gross, (string) $ref->prevGross) < 0;
?>
<div class="druckkopf">
  <h1>Preisnachweis <?= h($sku) ?> · Markt <?= h($markt) ?></h1>
  <?php if (($nameOben = artikelname($sku, $markt)) !== null): ?>
  <p><b><?= h($nameOben) ?></b></p><?php endif; ?>
  <p>Zeitraum <?= datum($ersterTag->format('Y-m-d')) ?>–<?= datum($letzterTag->format('Y-m-d')) ?> ·
     Stichtag <?= datum($stichtag) ?> · erstellt <?= \date('d.m.Y H:i') ?> von <?= h(current_user()) ?></p>
  <p>Quelle: <span class="mono">price_events</span> (lückenlose Preisintervalle),
     <span class="mono">pss_write_log</span> (Schreibprotokoll). Der Referenzwert ist aus den
     Intervallen <b>nachgerechnet</b>, nicht nachgeschlagen.</p>
</div>

<?php
    $name = artikelname($sku, $markt);
    $shop = produkt_url($sku, $markt);
    $link = $shop['url'];
?>
<div class="artikelkopf">
  <div class="kopfzeile1">
    <span class="sku"><?= h($sku) ?></span>
    <?php if ($jetzt?->state->isPromo()): ?>
      <span class="status aktion">Aktion seit <?= datum($jetzt->state->promoStarted?->format('Y-m-d')) ?>
        — Tag <?= (int) $jetzt->state->promoStarted?->diff($heute)->days + 1 ?></span>
    <?php else: ?><span class="status ok">ohne Aktion</span><?php endif; ?>
    <?= $jetzt?->windowComplete
        ? '<span class="status ok">Fenster vollständig</span>'
        : '<span class="status warn">Fenster unvollständig</span>' ?>
    <?= (maerkte()[$markt]['write_enabled'] ?? false)
        ? ($trocken ? '<span class="status aus">Trockenmodus</span>' : '<span class="status ok">Schreiben aktiv</span>')
        : '<span class="status aus">nur Beobachtung</span>' ?>
    <span class="shoplink">
      <?php if ($link !== null): ?>
        <a href="<?= h($link) ?>" target="_blank" rel="noopener"
           title="<?= h($link) ?>">im Shop ansehen ↗</a>
      <?php else: ?>
        <span style="color:var(--neutral)"><?= h($shop['hinweis']) ?></span>
      <?php endif; ?>
      <?php if (($pssLink = pss_link($sku)) !== null): ?>
        <span class="trenner">·</span>
        <a href="<?= h($pssLink) ?>" target="_blank" rel="noopener"
           title="Preiseinträge dieses Artikels im PSS (<?= h(\parse_url($pssLink, \PHP_URL_HOST) ?? '') ?>) — verlangt dieselbe Anmeldung wie die Schnittstelle">im PSS ansehen ↗</a>
      <?php endif; ?>
    </span>
  </div>
  <!-- Zweite Zeile, linksbündig: Bezeichnung samt Variante (Farbe, Größe) steht vorn,
       dahinter Markt, Währung und der heute verlangte Preis. Die Variante ist Teil des
       Artikelnamens im Shop (`import:E0074`, z. B. „T-Shirt Hunting, oliv, Gr. XXL") —
       ohne sie wäre auf einem Nachweis nicht bestimmbar, welcher Artikel gemeint ist. -->
  <div class="kopfzeile2">
    <?php if ($name !== null): ?><b class="bezeichnung"><?= h($name) ?></b><?php
      else: ?><span class="ohnename">Bezeichnung nicht abrufbar</span><?php endif; ?>
    <span class="trenner">·</span>Markt <?= h($markt) ?>
    <span class="trenner">·</span><?= h($waehrung) ?>
    <span class="trenner">·</span>Verkaufspreis heute
    <b class="mono"><?= geld($replay->priceOn($events, $heute)?->gross, $waehrung) ?></b>
  </div>
  <?php if ($link !== null): ?>
  <div class="kopfzeile2"><span class="mono" style="word-break:break-all"><?= h($link) ?></span>
    <span class="trenner">·</span>Adresse geprüft am
    <?= h(\date('d.m.Y', \strtotime((string) $shop['geprueft']))) ?></div>
  <?php endif; ?>
</div>

<?php if ($delle): ?>
<div class="hinweiskasten">
  <b>Referenz liegt unter der Vorstufe:</b> Eine frühere Senkung reicht in das
  <?= $tage ?>-Tage-Fenster des Aktionsstarts hinein. Der niedrigste Preis der letzten
  <?= $tage ?> Tage ist deshalb <span class="mono"><?= geld($ref->gross, $waehrung) ?></span>,
  nicht der Vorstufenpreis <span class="mono"><?= geld($ref->prevGross, $waehrung) ?></span>.
  <b>Eine Ersparnisangabe muss sich auf <?= geld($ref->gross, $waehrung) ?> beziehen.</b>
</div>
<?php endif; ?>

<h2>Preisverlauf &amp; Referenz — Messschrieb</h2>
<p class="fussnote" style="margin:0 0 .4rem">Schattiert ist das <?= $tage ?>-Tage-Fenster
vor dem <b><?= h($fWort) ?></b> (<?= datum($fVon->format('Y-m-d')) ?>–<?= datum($fBis->format('Y-m-d')) ?>) —
also genau der Zeitraum, aus dem die ausgewiesene Referenz stammt.</p>
<div class="schrieb">
<?php
    $eingabe = PriceChartData::build($zeilen, $writeLogRoh, $zustand ?? [],
        $heute->format('Y-m-d'), [
            'marker'       => ['date' => $stichtag, 'label' => 'Stichtag'],
            'windowDays'   => $tage,
            'prevMaxDays'  => (int) ($app['prev_price_max_days'] ?? 42),
            'refWrites'    => $refReihe,
            'prevSegments' => $prevReihe,
        ]);
    echo PriceChart::render($eingabe);
?>
<div class="legende">
  <span class="l-preis"><i></i>Verkaufspreis brutto (amount 0)</span>
  <span class="l-ref"><i></i>Referenz 30_GROSS<?= ($eingabe['refLabel'] ?? '') === 'Referenz (berechnet)' ? ' (berechnet, noch nicht geschrieben)' : '' ?></span>
  <?php if (($eingabe['prevSegments'] ?? []) !== []): ?><span class="l-prev"><i></i>Vorstufen-Anker PREV_GROSS</span><?php endif; ?>
  <span class="l-fenster"><i></i><?= $tage ?>-Tage-Fenster</span>
  <?php if (($eingabe['promoBands'] ?? []) !== []): ?><span class="l-aktion"><i></i>Aktionsphase</span><?php endif; ?>
</div>
</div>
<p class="fussnote">Als Treppe gezeichnet, nicht als Kurve: Ein Preis gilt über sein
  Intervall konstant und springt dann — eine interpolierte Linie behauptete Zwischenpreise,
  die es nie gab. Lücken unterbrechen die Linie, statt sie zu überbrücken.</p>

<h2>Aktuelle Werte &amp; Zustand</h2>
<div class="raster">
  <div class="karte">
    <h3>Referenzwerte<?= $trocken ? ' (simuliert)' : ' im PSS' ?></h3>
    <div class="etikett">
      <span class="typ">30_GROSS</span>
      <span class="wert"><?= geld($ref?->gross, $waehrung) ?></span>
      <span class="sub"><?= h($ref?->origin ?? '—') ?></span>
    </div>
    <div class="etikett">
      <span class="typ">30_NET</span>
      <span class="wert"><?= geld($ref?->net, $waehrung, 4) ?></span>
      <span class="sub">gleiches Ereignis wie 30_GROSS (Konsistenzregel)</span>
    </div>
    <?php if ($app['prev_price_enabled'] ?? false): ?>
    <div class="etikett">
      <span class="typ">PREV_GROSS</span>
      <span class="wert"><?= $ref?->hasPrev() ? geld($ref->prevGross, $waehrung) : '—' ?></span>
      <span class="sub"><?= h($ref?->prevOrigin ?? '') ?></span>
    </div>
    <div class="etikett">
      <span class="typ">PREV_NET</span>
      <span class="wert"><?= $ref?->hasPrev() ? geld($ref->prevNet, $waehrung, 4) : '—' ?></span>
      <span class="sub"><?= $ref?->hasPrev() ? 'gleiches Ereignis wie PREV_GROSS' : 'Anker geleert' ?></span>
    </div>
    <?php endif; ?>
  </div>

  <div class="karte">
    <h3>Zustandsautomat</h3>
    <dl class="zustand">
      <dt>Modus am Stichtag</dt>
      <dd><?= h($ref?->state->mode ?? '—') ?><?= $ref?->state->promoStarted
            ? ' · seit ' . datum($ref->state->promoStarted->format('Y-m-d')) : '' ?></dd>
      <dt>Preis vor der Aktion</dt>
      <dd><?= geld($ref?->state->prePromoGross, $waehrung) ?> brutto ·
          <?= geld($ref?->state->prePromoNet, $waehrung, 4) ?> netto</dd>
      <dt>Eingefrorene Referenz</dt>
      <dd><?= geld($ref?->state->frozenRefGross, $waehrung) ?> brutto ·
          <?= geld($ref?->state->frozenRefNet, $waehrung, 4) ?> netto</dd>
      <dt>Fenster</dt>
      <dd><?php $belegt = \count(\array_filter($fensterTage, static fn($t) => $t['gross'] !== null));
          echo $belegt, '/', \count($fensterTage), ' Tage belegt'; ?></dd>
      <dt>Letzter Übergang</dt>
      <dd style="font-family:system-ui"><?= h($ref?->state->lastTransition ?: '—') ?></dd>
    </dl>
  </div>

  <div class="karte">
    <h3>Stichtagsprüfung</h3>
    <div class="stempel">
      <h3>Geprüft: <?= datum($stichtag) ?></h3>
      <p>Verkaufspreis <?= geld($preisTag?->gross, $waehrung) ?> br. · <?= geld($preisTag?->net, $waehrung, 4) ?> nt.</p>
      <p>Referenz 30 T. <?= geld($ref?->gross, $waehrung) ?> br. · <?= geld($ref?->net, $waehrung, 4) ?> nt.</p>
      <?php if ($app['prev_price_enabled'] ?? false): ?>
      <p>Vorstufe <?= $ref?->hasPrev() ? geld($ref->prevGross, $waehrung) . ' br. · gesetzt' : '— · geleert' ?></p>
      <?php endif; ?>
      <p>Modus <?= h($ref?->state->mode ?? '—') ?></p>
      <p class="quelle"><?= $quelle !== null
        ? 'Belegt durch das Intervall ' . datum($quelle->validFrom->format('Y-m-d')) . '–'
          . datum($quelle->validTo?->format('Y-m-d')) . '.'
        : 'Kein Intervall im Fenster — die Aufzeichnung beginnt am '
          . datum($ersterTag->format('Y-m-d')) . '.' ?>
        Für Abmahnfälle „Nachweis drucken" verwenden.</p>
    </div>
  </div>
</div>

<h2>Ereignisse — lückenlose Preisintervalle</h2>
<div class="tabelle">
<table>
<thead><tr><th>#</th><th>gültig von</th><th>gültig bis</th><th class="zahl">brutto</th>
  <th class="zahl">netto</th><th class="zahl">Dauer</th><th>im Fenster</th></tr></thead>
<tbody>
<?php foreach ($events as $i => $e):
    $imF = $fensterTage !== [] && $e->overlaps(
        new DateTimeImmutable($fensterTage[0]['date']),
        new DateTimeImmutable($fensterTage[\count($fensterTage) - 1]['date']));
    $dauer = (int) $e->validFrom->diff($e->validTo ?? $heute)->days + 1;
    $istQuelle = $quelle !== null && $e === $quelle;
?>
<tr<?= $istQuelle ? ' class="offen"' : '' ?>>
  <td class="mono"><?= $i + 1 ?></td>
  <td class="mono"><?= datum($e->validFrom->format('Y-m-d')) ?></td>
  <td class="mono"><?= $e->validTo?->format('Y-m-d') === $heute->format('Y-m-d')
        ? 'heute <span class="sub">läuft</span>' : datum($e->validTo?->format('Y-m-d')) ?></td>
  <td class="zahl"><?= geld($e->gross, $e->currency) ?></td>
  <td class="zahl"><?= geld($e->net, $e->currency, 4) ?></td>
  <td class="zahl"><?= $dauer ?> T.</td>
  <td><?= $istQuelle ? '<b>Referenz</b>' : ($imF ? 'ja' : '') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<h2>Schreibprotokoll dieses Artikels</h2>
<div class="tabelle">
<table>
<thead><tr><th>Zeitpunkt</th><th>Eintrag</th><th class="zahl">alt</th><th class="zahl">neu</th>
  <th>Ergebnis</th></tr></thead>
<tbody>
<?php $writes = db()->query(
    'SELECT * FROM {p}pss_write_log WHERE sku = ? AND market = ? ORDER BY id DESC LIMIT 60',
    [$sku, $markt]);
foreach ($writes as $w): ?>
<tr>
  <td class="mono"><?= h(\date('d.m.Y H:i', \strtotime((string) $w['written_at']))) ?></td>
  <td class="mono"><?= h($w['price_type']) ?></td>
  <td class="zahl"><?= geld($w['old_value'], $w['currency'], 4) ?></td>
  <td class="zahl"><?= geld($w['new_value'], $w['currency'], 4) ?></td>
  <td><span class="status <?= $w['success'] ? 'ok' : 'vorfall' ?>">
      <?= $w['success'] ? 'übertragen' : 'fehlgeschlagen' ?></span></td>
</tr>
<?php endforeach; ?>
<?php if ($writes === []): ?>
<tr><td colspan="5" style="color:var(--neutral)">
  <?= $trocken
    ? 'Trockenmodus — es wurde noch nichts an den PSS übertragen.'
    : 'Noch kein Schreibvorgang protokolliert.' ?></td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<?php if ($zustand !== null && $zustand['last_written_30_gross'] !== null
          && $ref?->gross !== null && $stichtag === \date('Y-m-d')):
    $gleich = Money::equals((string) $zustand['last_written_30_gross'], (string) $ref->gross); ?>
<p class="hinweiskasten <?= $gleich ? 'ruhig' : '' ?>">
  <b>Gegenprobe:</b> nachgerechnet <?= geld($ref->gross, $waehrung) ?>, zuletzt geschrieben
  <?= geld($zustand['last_written_30_gross'], $waehrung) ?> —
  <?= $gleich ? 'stimmt überein.' : '<b>weicht ab, bitte prüfen.</b>' ?></p>
<?php endif; ?>

<?php endif; endif; ?>
</main></body></html>
