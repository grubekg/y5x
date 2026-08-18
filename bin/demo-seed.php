#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Beispieldaten fuer STAGING — damit das Dashboard vor dem ersten Echtlauf pruefbar ist.
 *
 * Verweigert den Dienst in `prod`: In der Beweisgrundlage haben erfundene Preise nichts
 * zu suchen. Die SKUs tragen das Praefix DEMO-, damit sie auch in Staging sofort als
 * Testdaten erkennbar sind.
 *
 *     php bin/demo-seed.php [--loeschen]
 */
require __DIR__ . '/../autoload.php';

use Grube\Price30\Support\Db;

$db = Db::fromRuntime(__DIR__ . '/..');
if ($db->env === 'prod') {
    \fwrite(\STDERR, "Verweigert: Beispieldaten gehoeren nicht in die Beweisgrundlage.\n");
    exit(1);
}

$loeschen = \in_array('--loeschen', $argv, true);
foreach (['price_events', 'price_state', 'pss_write_log'] as $t) {
    $db->execute("DELETE FROM {p}$t WHERE sku LIKE 'DEMO-%'");
}
if ($loeschen) {
    $db->execute("DELETE FROM {p}run_log WHERE note LIKE 'Demo%'");
    echo "Beispieldaten entfernt.\n";
    exit(0);
}

$t = static fn(string $rel): string => (new DateTimeImmutable('today'))->modify($rel)->format('Y-m-d');

/** [sku, Beschreibung, Intervalle [von, bis, net, gross], Zustand] */
$faelle = [
    ['DEMO-TREPPE', 'Abverkauf mit zwei Stufen', [
        ['-90 days', '-40 days', '100.0000', '119.00'],
        ['-39 days', '-35 days', '91.5966',  '109.00'],   // kurzer Einbruch im Fenster
        ['-34 days', '-12 days', '100.0000', '119.00'],
        ['-11 days', '-4 days',  '83.1933',  '99.00'],    // Stufe 1
        ['-3 days',  null,       '74.7899',  '89.00'],    // Stufe 2
    ]],
    ['DEMO-STABIL', 'unveraendert seit Monaten', [
        ['-120 days', null, '100.0000', '119.00'],
    ]],
    ['DEMO-NEU', 'Neuartikel, Fenster noch nicht voll', [
        ['-9 days', '-2 days', '42.0168', '49.99'],
        ['-1 days', null,      '37.8151', '44.99'],
    ]],
];

foreach ($faelle as [$sku, $was, $intervalle]) {
    foreach ($intervalle as [$von, $bis, $net, $gross]) {
        $db->execute(
            'INSERT INTO {p}price_events (sku, market, currency, net, gross, valid_from, valid_to)
             VALUES (?,?,?,?,?,?,?)',
            [$sku, 'DE', 'EUR', $net, $gross, $t($von), $bis === null ? $t('-0 days') : $t($bis)]);
    }
    echo "  $sku — $was\n";
}

// Zustand und Referenz aus den Events NACHRECHNEN statt zu erfinden — dann zeigt das
// Dashboard genau das, was der Produktivlauf auch errechnen wuerde.
$heute = new DateTimeImmutable('today');
$app = \yaml_parse_file(__DIR__ . '/../config/app.yml') ?: [];
$fenster = new Grube\Price30\Calc\PriceWindow((int) ($app['window_days'] ?? 30));
$rechner = new Grube\Price30\Calc\ReferenceCalculator(
    $fenster,
    new Grube\Price30\Calc\PromoStateMachine($fenster, (int) ($app['permanent_after_days'] ?? 60)),
    (string) ($app['calculation_mode'] ?? 'frozen'),
    (bool) ($app['prev_price_enabled'] ?? false),
    (int) ($app['prev_price_max_days'] ?? 42));
$replay = new Grube\Price30\Calc\Replay($rechner);

foreach ($faelle as [$sku]) {
    $events = [];
    foreach ($db->query('SELECT * FROM {p}price_events WHERE sku=? AND market=? ORDER BY valid_from',
                        [$sku, 'DE']) as $z) {
        $events[] = new Grube\Price30\Calc\PriceEvent(
            new DateTimeImmutable($z['valid_from']),
            $z['valid_to'] !== null ? new DateTimeImmutable($z['valid_to']) : null,
            $z['net'], $z['gross'], $z['currency']);
    }
    $ref = $replay->until($events, $heute, 'EUR');
    if ($ref === null) { continue; }
    $s = $ref->state;
    $db->execute(
        'INSERT INTO {p}price_state (sku, market, mode, promo_started, last_reduction_at,
            pre_promo_gross, pre_promo_net, frozen_ref_net, frozen_ref_gross, window_complete,
            last_written_30_net, last_written_30_gross, last_written_prev_net,
            last_written_prev_gross, last_written_at, last_transition, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,NOW())',
        [$sku, 'DE', $s->mode, $s->promoStarted?->format('Y-m-d'),
         $s->lastReductionAt?->format('Y-m-d'), $s->prePromoGross, $s->prePromoNet,
         $s->frozenRefNet, $s->frozenRefGross, $ref->windowComplete ? 1 : 0,
         $ref->net, $ref->gross, $ref->prevNet, $ref->prevGross, $s->lastTransition]);

    foreach ([['30_NET', $ref->net], ['30_GROSS', $ref->gross],
              ['PREV_NET', $ref->prevNet], ['PREV_GROSS', $ref->prevGross]] as [$typ, $wert]) {
        if ($wert === null) { continue; }
        $db->execute(
            'INSERT INTO {p}pss_write_log (sku, market, price_type, old_value, new_value,
                currency, written_at, http_status, success, attempt, response_excerpt)
             VALUES (?,?,?,NULL,?,?,NOW(),200,1,1,?)',
            [$sku, 'DE', $typ, $wert, 'EUR', 'Beispieldaten — kein echter PSS-Call']);
    }
}

for ($i = 6; $i >= 0; $i--) {
    $db->execute(
        'INSERT INTO {p}run_log (run_date, market, started_at, finished_at, items_fetched,
            price_changes, pss_writes, anomalies, errors, status, note)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$t("-$i days"), 'DE', $t("-$i days") . ' 05:31:00', $t("-$i days") . ' 05:38:12',
         3, $i === 3 ? 1 : 0, $i === 3 ? 4 : 0, 0, 0, 'ok', 'Demo-Lauf (Beispieldaten)']);
}
echo "Beispieldaten angelegt ({$db->env}). Entfernen: php bin/demo-seed.php --loeschen\n";
