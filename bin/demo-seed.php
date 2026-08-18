#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Beispieldaten für STAGING — damit die Ansichten ohne Wartezeit beurteilbar sind.
 *
 * Verweigert den Dienst in `prod`: In der Beweisgrundlage haben erfundene Preise nichts
 * zu suchen. Alle Artikelnummern tragen das Präfix `DEMO-`, damit sie auch in Staging
 * auf den ersten Blick als Testdaten erkennbar sind.
 *
 * **`DEMO-JAHR` ist bewusst kein hübscher Verlauf, sondern ein Lehrstück.** Er ist so
 * getaktet, dass der Fall eintritt, der die Rechtslogik erklärt: Ein kurzer Einbruch
 * liegt im 30-Tage-Fenster VOR dem Beginn der Abverkaufstreppe. Die Referenz fällt
 * dadurch unter die unmittelbare Vorstufe — 239,00 € statt 269,00 € —, und genau darauf
 * muss sich eine Ersparnisangabe beziehen. Wer nur eine glatte Treppe zeichnet, sieht
 * diesen Fall nie.
 *
 *     php bin/demo-seed.php [--loeschen]
 */
require __DIR__ . '/../autoload.php';

use Grube\Price30\Calc\PriceEvent;
use Grube\Price30\Calc\PriceWindow;
use Grube\Price30\Calc\PromoStateMachine;
use Grube\Price30\Calc\ReferenceCalculator;
use Grube\Price30\Calc\Replay;
use Grube\Price30\Support\Db;

$db = Db::fromRuntime(__DIR__ . '/..');
if ($db->env === 'prod') {
    \fwrite(\STDERR, "Verweigert: Beispieldaten gehören nicht in die Beweisgrundlage.\n");
    exit(1);
}

foreach (['price_events', 'price_state', 'pss_write_log', 'article_meta'] as $t) {
    $db->execute("DELETE FROM {p}$t WHERE sku LIKE 'DEMO-%'");
}
$db->execute("DELETE FROM {p}run_log WHERE note LIKE 'Beispiel%'");
if (\in_array('--loeschen', $argv, true)) {
    echo "Beispieldaten entfernt.\n";
    exit(0);
}

$tag = static fn(int $v): string
    => (new DateTimeImmutable('today'))->modify("$v days")->format('Y-m-d');

/**
 * Ein Jahr Preisgeschichte. Jede Zeile: [ab Tag, netto, brutto, was dort passiert].
 * Das Intervall endet jeweils am Vortag der nächsten Zeile.
 */
$jahr = [
    [-365, '209.2437', '249.00', 'Normalpreis'],
    [-299, '167.2269', '199.00', 'Frühjahrsaktion'],
    [-284, '209.2437', '249.00', 'zurück auf Normalpreis'],
    [-139, '226.0504', '269.00', 'Preiserhöhung'],
    [ -75, '200.8403', '239.00', 'kurzer Einbruch — fällt später ins Fenster'],
    [ -67, '226.0504', '269.00', 'zurück'],
    [ -45, '192.4370', '229.00', 'Abverkauf Stufe 1'],
    [ -24, '167.2269', '199.00', 'Abverkauf Stufe 2'],
    [  -9, '150.4202', '179.00', 'Abverkauf Stufe 3'],
];

$faelle = [
    ['DEMO-JAHR', 'Beispielartikel: ein Jahr Preisverlauf mit Abverkaufstreppe', $jahr],
    ['DEMO-STABIL', 'Beispielartikel: seit Monaten unverändert', [[-120, '100.0000', '119.00', 'Normalpreis']]],
    ['DEMO-NEU', 'Beispielartikel: Neuartikel, Fenster noch nicht voll', [
        [-9, '42.0168', '49.99', 'Einführungspreis'],
        [-1, '37.8151', '44.99', 'Senkung'],
    ]],
];

foreach ($faelle as [$sku, $name, $zeilen]) {
    foreach ($zeilen as $i => [$ab, $net, $gross, $was]) {
        $bis = isset($zeilen[$i + 1]) ? $tag($zeilen[$i + 1][0] - 1) : $tag(0);
        $db->execute(
            'INSERT INTO {p}price_events (sku, market, currency, net, gross, valid_from, valid_to)
             VALUES (?,?,?,?,?,?,?)',
            [$sku, 'DE', 'EUR', $net, $gross, $tag($ab), $bis]);
    }
    $db->execute('INSERT INTO {p}article_meta (sku, market, name, fetched_at)
                  VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE name = VALUES(name)',
        [$sku, 'DE', $name]);
    echo \sprintf("  %-12s %d Intervalle — %s\n", $sku, \count($zeilen), $name);
}

// Zustand NACHRECHNEN statt erfinden: Das Dashboard soll genau das zeigen, was ein
// Produktivlauf errechnen würde.
$app = \yaml_parse_file(__DIR__ . '/../config/app.yml') ?: [];
$fenster = new PriceWindow((int) ($app['window_days'] ?? 30));
$rechner = new ReferenceCalculator($fenster,
    new PromoStateMachine($fenster, (int) ($app['permanent_after_days'] ?? 60)),
    (string) ($app['calculation_mode'] ?? 'frozen'),
    (bool) ($app['prev_price_enabled'] ?? false),
    (int) ($app['prev_price_max_days'] ?? 42));
$replay = new Replay($rechner);
$heute = new DateTimeImmutable('today');

foreach ($faelle as [$sku]) {
    $events = [];
    foreach ($db->query('SELECT * FROM {p}price_events WHERE sku=? AND market=? ORDER BY valid_from',
                        [$sku, 'DE']) as $z) {
        $events[] = new PriceEvent(new DateTimeImmutable($z['valid_from']),
            $z['valid_to'] !== null ? new DateTimeImmutable($z['valid_to']) : null,
            $z['net'], $z['gross'], $z['currency']);
    }
    $ref = $replay->until($events, $heute, 'EUR');
    if ($ref === null) { continue; }
    $s = $ref->state;
    $db->execute(
        'INSERT INTO {p}price_state (sku, market, mode, promo_started, last_reduction_at,
            pre_promo_gross, pre_promo_net, frozen_ref_net, frozen_ref_gross, window_complete,
            last_transition, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())',
        [$sku, 'DE', $s->mode, $s->promoStarted?->format('Y-m-d'),
         $s->lastReductionAt?->format('Y-m-d'), $s->prePromoGross, $s->prePromoNet,
         $s->frozenRefNet, $s->frozenRefGross, $ref->windowComplete ? 1 : 0,
         \mb_substr($s->lastTransition, 0, 160)]);

    \printf("     -> Referenz %s · Vorstufe %s · Zustand %s%s\n",
        $ref->gross ?? '—', $ref->prevGross ?? '—', $s->mode,
        $ref->hasPrev() && $ref->gross !== null && $ref->gross < $ref->prevGross
            ? '  [Referenz UNTER Vorstufe — der Lehrfall]' : '');
}

for ($i = 6; $i >= 0; $i--) {
    $db->execute(
        'INSERT INTO {p}run_log (run_date, market, started_at, finished_at, items_fetched,
            price_changes, pss_writes, anomalies, errors, status, note)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$tag(-$i), 'DE', $tag(-$i) . ' 05:31:00', $tag(-$i) . ' 05:38:12',
         3, $i === 0 ? 1 : 0, 0, 0, 0, 'ok', 'Beispiellauf (erfundene Daten)']);
}
echo "\nBeispieldaten angelegt ({$db->env}). Entfernen: php bin/demo-seed.php --loeschen\n";
