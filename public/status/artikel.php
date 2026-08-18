<?php
declare(strict_types=1);

/**
 * Artikelansicht — der Nachweis, den man im Abmahnungsfall in die Hand nimmt.
 *
 * Eine Abmahnung nennt Artikel, Markt und **Datum**. Diese Seite beantwortet für genau
 * diese Kombination drei Fragen, und zwar aus derselben Quelle wie der Produktivlauf:
 *
 * 1. Welcher Preis wurde an dem Tag verlangt?
 * 2. Welcher 30-Tage-Referenzpreis galt, aus welchem Tag stammt er, und welche 30 Tage
 *    wurden dafür betrachtet?
 * 3. Was wurde damals tatsächlich in den PSS geschrieben?
 *
 * Der Referenzwert wird **nachgerechnet**, nicht nachgeschlagen: `Calc\Replay` spielt
 * den Zustandsautomaten vom ersten bekannten Tag an mit dem Wissensstand des jeweiligen
 * Tages neu ab. Deshalb kann die Antwort nicht davon abhängen, was inzwischen gespeichert
 * wurde — und deshalb fällt es auf, wenn Nachrechnung und PSS-Protokoll auseinandergehen.
 *
 * Die Seite ist auf Druck ausgelegt (Anlage zum Schriftsatz).
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

$sku      = \trim((string) ($_GET['sku'] ?? ''));
$markt    = \trim((string) ($_GET['markt'] ?? 'DE'));
$stichtag = \trim((string) ($_GET['stichtag'] ?? \date('Y-m-d')));
$filter   = (string) ($_GET['filter'] ?? '');
$app      = cfg('app');

seitenkopf($sku !== '' ? "Artikel $sku ($markt)" : 'Artikel & Nachweis');
?>
<form class="suche" method="get">
  <input name="sku" value="<?= h($sku) ?>" placeholder="Artikelnummer (SKU)" size="18">
  <select name="markt"><?php foreach (maerkte() as $c => $m):
      if (!($m['active'] ?? false)) { continue; } ?>
    <option<?= $markt === $c ? ' selected' : '' ?>><?= h($c) ?></option>
  <?php endforeach; ?></select>
  <label>Stichtag <input type="date" name="stichtag" value="<?= h($stichtag) ?>"></label>
  <button>Nachweis erzeugen</button>
</form>

<?php if ($sku === ''):
    // --- Ohne SKU: Einstiegslisten, damit man nicht raten muss --------------
    $wo = $filter === 'risiko'
        ? "mode='promo' AND window_complete=0"
        : "mode='promo'";
    $liste = db()->query(
        "SELECT * FROM {p}price_state WHERE market = ? AND $wo
         ORDER BY window_complete ASC, promo_started DESC LIMIT 200", [$markt]);
?>
<h2><?= $filter === 'risiko'
      ? 'Ermäßigung auf unvollständiger Historie'
      : 'Artikel in Aktion' ?> — <?= h($markt) ?> (<?= count($liste) ?>)</h2>
<?php if ($liste === []): ?>
  <p class="hinweis">Keine Einträge. Sobald der tägliche Lauf Daten liefert, stehen sie hier.
  Eine Artikelnummer kann jederzeit direkt oben eingegeben werden.</p>
<?php else: ?>
<table>
<tr><th>SKU</th><th>Zustand</th><th>seit</th><th class="num">Vorniveau</th>
    <th class="num">eingefrorene Referenz</th><th>Fenster</th><th>zuletzt geschrieben</th><th></th></tr>
<?php foreach ($liste as $r): ?>
<tr>
  <td><?= h($r['sku']) ?></td>
  <td><span class="tag <?= $r['mode'] === 'promo' ? 'promo' : '' ?>"><?= h($r['mode']) ?></span></td>
  <td><?= datum($r['promo_started']) ?></td>
  <td class="num"><?= geld($r['pre_promo_gross']) ?></td>
  <td class="num"><?= geld($r['frozen_ref_gross']) ?></td>
  <td><?= $r['window_complete'] ? '<span class="ok">vollständig</span>'
                               : '<span class="bad">unvollständig</span>' ?></td>
  <td><?= $r['last_written_at'] ? h(\date('d.m.Y H:i', \strtotime($r['last_written_at']))) : '—' ?></td>
  <td><a href="?sku=<?= \urlencode($r['sku']) ?>&amp;markt=<?= h($markt) ?>">Nachweis</a></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php else:
    // --- Mit SKU: der eigentliche Nachweis ---------------------------------
    $zeilen = db()->query(
        'SELECT * FROM {p}price_events WHERE sku = ? AND market = ? ORDER BY valid_from',
        [$sku, $markt]);
    $events = [];
    foreach ($zeilen as $z) {
        $events[] = new PriceEvent(
            new DateTimeImmutable($z['valid_from']),
            $z['valid_to'] !== null ? new DateTimeImmutable($z['valid_to']) : null,
            $z['net'], $z['gross'], $z['currency']);
    }
    $state = db()->one('SELECT * FROM {p}price_state WHERE sku = ? AND market = ?', [$sku, $markt]);
    $waehrung = $zeilen ? (string) $zeilen[0]['currency'] : (maerkte()[$markt]['currency'] ?? 'EUR');

    $tage = (int) ($app['window_days'] ?? 30);
    $fenster = new PriceWindow($tage);
    $rechner = new ReferenceCalculator(
        $fenster,
        new PromoStateMachine($fenster, (int) ($app['permanent_after_days'] ?? 60)),
        (string) ($app['calculation_mode'] ?? 'frozen'),
        (bool) ($app['prev_price_enabled'] ?? false),
        (int) ($app['prev_price_max_days'] ?? 42));
    $replay = new Replay($rechner);
    $stich = new DateTimeImmutable($stichtag);

    $ref       = $events ? $replay->until($events, $stich, $waehrung) : null;
    $preisTag  = $events ? $replay->priceOn($events, $stich) : null;
    $fensterTage = $events ? $replay->windowDays($events, $stich, $tage) : [];
    $quelle    = $ref && $ref->hasValue()
        ? $fenster->lowestIn($events, ...$fenster->bounds($stich)) : null;

    if ($events === []): ?>
  <p class="beleg"><span class="warn">Keine Historie</span> für <b><?= h($sku) ?></b> im Markt
  <b><?= h($markt) ?></b>. Entweder wird der Artikel nicht getrackt, oder es gab noch keinen Lauf.</p>
<?php else: ?>

<div class="beleg">
  <b>Nachweis für Artikel <?= h($sku) ?>, Markt <?= h($markt) ?>, Stichtag <?= datum($stichtag) ?></b><br>
  Verlangter Preis an diesem Tag:
  <b><?= geld($preisTag?->gross, $waehrung) ?></b> brutto
  (netto <?= geld($preisTag?->net, $waehrung, 4) ?>)<br>
  Niedrigster Preis der <?= $tage ?> Tage davor
  (<?= datum($fensterTage[0]['date'] ?? null) ?>–<?= datum($fensterTage[count($fensterTage) - 1]['date'] ?? null) ?>):
  <b><?= geld($ref?->gross, $waehrung) ?></b> brutto
  (netto <?= geld($ref?->net, $waehrung, 4) ?>)<br>
  Grundlage: <?= h($ref?->origin ?? '—') ?><?php if ($quelle !== null): ?>,
    Beleg-Intervall <?= datum($quelle->validFrom->format('Y-m-d')) ?>–<?= datum($quelle->validTo?->format('Y-m-d')) ?><?php endif; ?><br>
  Zustand am Stichtag: <span class="tag <?= $ref?->state->isPromo() ? 'promo' : '' ?>">
    <?= h($ref?->state->mode ?? '—') ?></span>
  <?= $ref?->state->lastTransition ? '— ' . h($ref->state->lastTransition) : '' ?><br>
  <?php if ($app['prev_price_enabled'] ?? false): ?>
  Vorstufen-Anker <code>PREV_*</code>:
  <?= $ref?->hasPrev()
        ? '<b>' . geld($ref->prevGross, $waehrung) . '</b> brutto (netto '
          . geld($ref->prevNet, $waehrung, 4) . ')'
        : '<span class="warn">geleert</span>' ?>
  — <?= h($ref?->prevOrigin ?? '') ?><br>
  <?php endif; ?>
  Historie im Fenster:
  <?= $ref?->windowComplete
        ? '<span class="ok">lückenlos belegt</span>'
        : '<span class="bad">unvollständig — Aussage beruht auf kürzerer Historie</span>' ?><br>
  Berechnungsmodus: <code><?= h($app['calculation_mode'] ?? 'frozen') ?></code> ·
  erzeugt am <?= \date('d.m.Y H:i') ?> durch <?= h(current_user()) ?>
</div>

<h2>Preisverlauf</h2>
<?php
    // Fuer das Diagramm eine lueckenlose Tagesreihe: vom ersten Event bis 5 Tage nach
    // dem Stichtag (bzw. bis heute), damit Fenster und Stichtag im Bild liegen.
    $von = $events[0]->validFrom;
    $bisKandidat = $stich->modify('+5 days');
    $heute = new DateTimeImmutable('today');
    $bis = $bisKandidat > $heute ? $heute : $bisKandidat;
    if ($bis < $stich) { $bis = $stich; }
    $reihe = [];
    for ($t = $von; $t <= $bis; $t = $t->modify('+1 day')) {
        $e = $replay->priceOn($events, $t);
        $reihe[] = ['date' => $t->format('Y-m-d'), 'gross' => $e?->gross];
    }
    // Aktionszeitraeume aus der Nachrechnung: Tag fuer Tag pruefen, wann `promo` galt.
    $aktionen = [];
    $offen = null;
    foreach ($reihe as $z) {
        $tagRef = $replay->until($events, new DateTimeImmutable($z['date']), $waehrung);
        $imPromo = (bool) $tagRef?->state->isPromo();
        if ($imPromo && $offen === null) { $offen = $z['date']; }
        if (!$imPromo && $offen !== null) { $aktionen[] = ['from' => $offen, 'to' => $z['date']]; $offen = null; }
    }
    if ($offen !== null) { $aktionen[] = ['from' => $offen, 'to' => \end($reihe)['date']]; }

    echo (new Chart())->render(
        $reihe,
        $fensterTage ? ['from' => $fensterTage[0]['date'],
                        'to'   => $fensterTage[count($fensterTage) - 1]['date']] : null,
        $ref?->gross, $aktionen, $stichtag, $ref?->prevGross);
?>
<p class="hinweis">Als Treppe gezeichnet, nicht als Kurve: Ein Preis gilt über sein
Intervall konstant und springt dann. Eine interpolierte Linie behauptete Zwischenpreise,
die es nie gab. Blau hinterlegt das <?= $tage ?>-Tage-Fenster, orange die nachgerechneten
Aktionszeiträume, rot gestrichelt der 30-Tage-Referenzpreis, violett gepunktet der
Vorstufen-Anker <code>PREV_*</code>, grün der Stichtag. <b>Referenz und Vorstufe sind
verschiedene Dinge:</b> die Referenz ist das Minimum des Fensters, die Vorstufe der Preis
der unmittelbar vorangegangenen Stufe.</p>

<h2>Preisintervalle (Beweisgrundlage)</h2>
<table>
<tr><th>von</th><th>bis (letzter Beobachtungstag)</th><th class="num">brutto</th>
    <th class="num">netto</th><th>Währung</th><th class="num">Tage</th><th></th></tr>
<?php foreach ($events as $e):
    $imFenster = $fensterTage !== [] && $e->overlaps(
        new DateTimeImmutable($fensterTage[0]['date']),
        new DateTimeImmutable($fensterTage[count($fensterTage) - 1]['date']));
    $dauer = (int) $e->validFrom->diff($e->validTo ?? $heute)->days + 1;
?>
<tr<?= $quelle !== null && $e === $quelle ? ' style="background:#fff4f4"' : '' ?>>
  <td><?= datum($e->validFrom->format('Y-m-d')) ?></td>
  <td><?= datum($e->validTo?->format('Y-m-d')) ?></td>
  <td class="num"><?= geld($e->gross, $e->currency) ?></td>
  <td class="num"><?= geld($e->net, $e->currency, 4) ?></td>
  <td><?= h($e->currency) ?></td>
  <td class="num"><?= $dauer ?></td>
  <td><?= $quelle !== null && $e === $quelle ? '<b>Referenz</b>' : ($imFenster ? 'im Fenster' : '') ?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>Tagesabdeckung im Fenster</h2>
<?php $luecken = \array_filter($fensterTage, static fn($t) => $t['gross'] === null); ?>
<p><?= count($fensterTage) - count($luecken) ?> von <?= count($fensterTage) ?> Tagen belegt<?php
   if ($luecken !== []): ?> — <span class="bad">Lücken:
   <?= h(\implode(', ', \array_map(static fn($t) => \date('d.m.', \strtotime($t['date'])),
        \array_slice($luecken, 0, 12)))) ?><?= count($luecken) > 12 ? ' …' : '' ?></span><?php
   endif; ?></p>

<h2>Was in den PSS geschrieben wurde</h2>
<table>
<tr><th>Zeitpunkt</th><th>priceType</th><th class="num">alt</th><th class="num">neu</th>
    <th>Währung</th><th>HTTP</th><th>Erfolg</th><th class="num">Versuch</th><th>Antwort</th></tr>
<?php $writes = db()->query(
    'SELECT * FROM {p}pss_write_log WHERE sku = ? AND market = ? ORDER BY id DESC LIMIT 60',
    [$sku, $markt]);
foreach ($writes as $w): ?>
<tr>
  <td><?= h(\date('d.m.Y H:i', \strtotime($w['written_at']))) ?></td>
  <td><?= h($w['price_type']) ?></td>
  <td class="num"><?= geld($w['old_value'], $w['currency'], 4) ?></td>
  <td class="num"><?= geld($w['new_value'], $w['currency'], 4) ?></td>
  <td><?= h($w['currency']) ?></td>
  <td><?= h((string) $w['http_status']) ?></td>
  <td class="<?= $w['success'] ? 'ok' : 'bad' ?>"><?= $w['success'] ? 'ja' : 'nein' ?></td>
  <td class="num"><?= (int) $w['attempt'] ?></td>
  <td><?= h(\mb_substr((string) $w['response_excerpt'], 0, 70)) ?></td>
</tr>
<?php endforeach; ?>
<?php if ($writes === []): ?>
<tr><td colspan="9" class="hinweis">Noch kein Schreibvorgang protokolliert.</td></tr>
<?php endif; ?>
</table>

<?php
// Gegenprobe: Stimmt die Nachrechnung mit dem ueberein, was zuletzt geschrieben wurde?
// Weichen sie ab, will man das selbst als Erster wissen — nicht die Gegenseite.
if ($state !== null && $state['last_written_30_gross'] !== null && $ref?->gross !== null
    && $stichtag === \date('Y-m-d')):
    $gleich = Money::equals((string) $state['last_written_30_gross'], (string) $ref->gross);
?>
<p class="beleg">Gegenprobe für heute: nachgerechnet <b><?= geld($ref->gross, $waehrung) ?></b>,
zuletzt in den PSS geschrieben <b><?= geld($state['last_written_30_gross'], $waehrung) ?></b> —
<?= $gleich ? '<span class="ok">stimmt überein</span>'
            : '<span class="bad">weicht ab — bitte prüfen</span>' ?>.</p>
<?php endif; ?>

<?php endif; endif; ?>
</main>
