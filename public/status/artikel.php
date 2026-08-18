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
use Grube\Price30\Support\Chart;
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
<form class="suche" method="get">
  <span><label for="f-q">Artikel suchen</label>
    <input id="f-q" name="q" value="<?= h($suche) ?>" placeholder="Artikelnummer"
           class="mono" size="16" inputmode="numeric"></span>
  <span><label for="f-markt">Markt</label>
    <select id="f-markt" name="markt"><?php foreach (maerkte() as $c => $m):
        if (!($m['active'] ?? false)) { continue; } ?>
      <option<?= $markt === $c ? ' selected' : '' ?>><?= h($c) ?></option>
    <?php endforeach; ?></select></span>
  <span><label for="f-filter">Auswahl</label>
    <select id="f-filter" name="filter">
      <?php foreach (['alle' => 'alle Artikel', 'aktion' => 'in Aktion',
                      'normal' => 'ohne Aktion', 'risiko' => 'Risiko',
                      'unvollstaendig' => 'Fenster unvollständig'] as $k => $v): ?>
      <option value="<?= $k ?>"<?= $filter === $k ? ' selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select></span>
  <?php if ($sku !== ''): ?>
  <span><label for="f-tag">Stichtag</label>
    <input id="f-tag" type="date" name="stichtag" value="<?= h($stichtag) ?>"></span>
  <input type="hidden" name="sku" value="<?= h($sku) ?>">
  <?php endif; ?>
  <button class="knopf" type="submit"><?= $sku !== '' ? 'Prüfen' : 'Filtern' ?></button>
  <?php if ($sku !== ''): ?>
  <button class="knopf sekundaer" type="button" onclick="window.print()">Nachweis drucken</button>
  <a class="knopf sekundaer" style="text-decoration:none;line-height:1.6"
     href="?markt=<?= h($markt) ?>&amp;filter=<?= h($filter) ?>">zur Liste</a>
  <?php endif; ?>
</form>

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
    if ($suche !== '') { $wo[] = 's.sku LIKE ?'; $args[] = $suche . '%'; }

    $von = 'FROM {p}price_state s
        LEFT JOIN {p}price_events e ON e.sku = s.sku AND e.market = s.market
             AND e.valid_from = (SELECT MAX(valid_from) FROM {p}price_events
                                 WHERE sku = s.sku AND market = s.market)
        WHERE ' . \implode(' AND ', $wo);

    $gesamt  = (int) (db()->one('SELECT COUNT(*) AS n FROM {p}price_state s WHERE '
                . \implode(' AND ', \array_map(
                    static fn($x) => \str_replace('s.sku LIKE ?', 's.sku LIKE ?', $x), $wo)), $args)['n'] ?? 0);
    $proSeite = 100;
    $seiten = \max(1, (int) \ceil($gesamt / $proSeite));
    $seite  = \min($seiten, \max(1, (int) ($_GET['seite'] ?? 1)));

    $zeilen = db()->query(
        'SELECT s.*, e.gross AS vk_gross, e.net AS vk_net, e.currency, e.valid_from AS vk_seit '
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
  <th>Fenster</th><th>zuletzt geschrieben</th><th></th></tr></thead>
<tbody>
<?php foreach ($zeilen as $r):
    $cur = (string) ($r['currency'] ?? 'EUR');
    $inAktion = $r['mode'] === 'promo';
?>
<tr<?= $inAktion ? ' class="offen"' : '' ?>>
  <td class="mono"><b><?= h($r['sku']) ?></b>
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
  <td><a href="?sku=<?= \urlencode($r['sku']) ?>&amp;markt=<?= h($markt) ?>">Nachweis</a></td>
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
    $fensterTage = $replay->windowDays($events, $stich, $tage);
    $quelle = $ref !== null && $ref->hasValue()
        ? $fenster->lowestIn($events, ...$fenster->bounds($stich)) : null;
    $zustand = db()->one('SELECT * FROM {p}price_state WHERE sku = ? AND market = ?', [$sku, $markt]);

    // Aktionszeiträume für das Diagramm — nachgerechnet, nicht gespeichert.
    $ersterTag = $events[0]->validFrom;
    $letzterTag = $heute > $stich ? $heute : $stich;
    $reihe = [];
    $aktionen = []; $offen = null;
    for ($t = $ersterTag; $t <= $letzterTag; $t = $t->modify('+1 day')) {
        $e = $replay->priceOn($events, $t);
        $reihe[] = ['date' => $t->format('Y-m-d'), 'gross' => $e?->gross];
        $imPromo = (bool) $replay->until($events, $t, $waehrung)?->state->isPromo();
        if ($imPromo && $offen === null) { $offen = $t->format('Y-m-d'); }
        if (!$imPromo && $offen !== null) { $aktionen[] = ['from' => $offen, 'to' => $t->format('Y-m-d')]; $offen = null; }
    }
    if ($offen !== null) { $aktionen[] = ['from' => $offen, 'to' => \end($reihe)['date']]; }

    // Der Fall, der die Rechtslogik erklärt: Referenz unter der Vorstufe.
    $delle = $ref !== null && $ref->hasValue() && $ref->hasPrev()
        && Money::compare((string) $ref->gross, (string) $ref->prevGross) < 0;
?>
<div class="druckkopf">
  <h1>Preisnachweis <?= h($sku) ?> · Markt <?= h($markt) ?></h1>
  <p>Zeitraum <?= datum($ersterTag->format('Y-m-d')) ?>–<?= datum($letzterTag->format('Y-m-d')) ?> ·
     Stichtag <?= datum($stichtag) ?> · erstellt <?= \date('d.m.Y H:i') ?> von <?= h(current_user()) ?></p>
  <p>Quelle: <span class="mono">price_events</span> (lückenlose Preisintervalle),
     <span class="mono">pss_write_log</span> (Schreibprotokoll). Der Referenzwert ist aus den
     Intervallen <b>nachgerechnet</b>, nicht nachgeschlagen.</p>
</div>

<div class="artikelkopf">
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
  <span class="meta">Markt <?= h($markt) ?> · <?= h($waehrung) ?> ·
    Verkaufspreis heute <b class="mono"><?= geld($replay->priceOn($events, $heute)?->gross, $waehrung) ?></b></span>
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
<div class="schrieb">
<?= (new Chart())->render($reihe,
      $fensterTage !== [] ? ['from' => $fensterTage[0]['date'],
                             'to' => $fensterTage[\count($fensterTage) - 1]['date']] : null,
      $ref?->gross, $aktionen, $stichtag, $ref?->prevGross) ?>
<div class="legende">
  <span class="l-preis"><i></i>Verkaufspreis brutto (amount 0)</span>
  <span class="l-ref"><i></i>Referenz 30_GROSS</span>
  <?php if ($ref?->hasPrev()): ?><span class="l-prev"><i></i>Vorstufen-Anker PREV_GROSS</span><?php endif; ?>
  <span class="l-fenster"><i></i><?= $tage ?>-Tage-Fenster</span>
  <?php if ($aktionen !== []): ?><span class="l-aktion"><i></i>Aktionsphase</span><?php endif; ?>
</div>
</div>
<p class="fussnote">Als Treppe gezeichnet, nicht als Kurve: Ein Preis gilt über sein
  Intervall konstant und springt dann — eine interpolierte Linie behauptete Zwischenpreise,
  die es nie gab. Lücken unterbrechen die Linie, statt sie zu überbrücken.</p>

<h2>Aktuelle Werte &amp; Zustand</h2>
<div class="raster">
  <div class="karte">
    <h3>Referenzwerte<?= $trocken ? ' (simuliert)' : ' im PSS' ?></h3>
    <div class="etikett"><span class="typ">30_GROSS</span>
      <span><span class="wert"><?= geld($ref?->gross, $waehrung) ?></span>
        <span class="sub"><?= h($ref?->origin ?? '—') ?></span></span></div>
    <div class="etikett"><span class="typ">30_NET</span>
      <span><span class="wert"><?= geld($ref?->net, $waehrung, 4) ?></span>
        <span class="sub">gleiches Ereignis wie 30_GROSS (Konsistenzregel)</span></span></div>
    <?php if ($app['prev_price_enabled'] ?? false): ?>
    <div class="etikett"><span class="typ">PREV_GROSS</span>
      <span><span class="wert"><?= $ref?->hasPrev() ? geld($ref->prevGross, $waehrung) : '—' ?></span>
        <span class="sub"><?= h($ref?->prevOrigin ?? '') ?></span></span></div>
    <div class="etikett"><span class="typ">PREV_NET</span>
      <span><span class="wert"><?= $ref?->hasPrev() ? geld($ref->prevNet, $waehrung, 4) : '—' ?></span>
        <span class="sub"><?= $ref?->hasPrev() ? 'gleiches Ereignis wie PREV_GROSS' : 'Anker geleert' ?></span></span></div>
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
