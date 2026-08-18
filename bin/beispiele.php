#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 20 Beispielartikel mit echter Preisgeschichte — nur STAGING.
 *
 * Sie dienen dazu, den **Schreibweg** vollständig durchzuspielen: Ein echter Artikel
 * kann das heute nicht, weil seine Historie erst mit dem ersten Lauf beginnt und das
 * Fenster `[heute−30, gestern]` damit leer ist. Es gibt schlicht keinen Tag davor.
 *
 * Die Fälle sind bewusst verschieden, damit nicht nur der einfache Weg getestet wird:
 * laufende Aktion, beendete Aktion, Preistreppe, Preiserhöhung, abgelaufener
 * Vorstufen-Anker, unveränderter Preis, kurze Historie.
 *
 *     php bin/beispiele.php [--loeschen]
 */
require __DIR__ . '/../autoload.php';

use Grube\Price30\Support\Db;

$db = Db::fromRuntime(__DIR__ . '/..');
if ($db->env === 'prod') {
    \fwrite(\STDERR, "Verweigert: Beispieldaten gehören nicht in die Beweisgrundlage.\n");
    exit(1);
}

foreach (['price_events', 'price_state', 'pss_write_log', 'article_meta'] as $t) {
    $db->execute("DELETE FROM {p}$t WHERE sku LIKE 'Y5X-BSP-%'");
}
if (\in_array('--loeschen', $argv, true)) {
    echo "Beispiele entfernt.\n";
    exit(0);
}

$tag = static fn(int $v): string
    => (new DateTimeImmutable('today'))->modify("$v days")->format('Y-m-d');
$netto = static fn(float $brutto): string => \number_format($brutto / 1.19, 4, '.', '');

/** [Kurzname, [[ab Tag, brutto], …]] — das Intervall endet am Vortag des nächsten. */
$muster = [
    ['laufende Aktion, Fenster voll',        [[-120, 249.00], [-14, 199.00]]],
    ['Aktion mit Delle im Fenster',          [[-120, 249.00], [-40, 229.00], [-33, 249.00], [-9, 199.00]]],
    ['Preistreppe, drei Stufen',             [[-150, 299.00], [-30, 259.00], [-18, 229.00], [-5, 199.00]]],
    ['Aktion beendet, wieder normal',        [[-120, 149.00], [-40, 119.00], [-20, 149.00]]],
    ['Preiserhöhung, keine Aktion',          [[-120, 89.00], [-25, 99.00]]],
    ['unverändert seit Monaten',             [[-200, 59.90]]],
    ['Vorstufe abgelaufen (>42 Tage)',       [[-200, 399.00], [-60, 329.00]]],
    ['kurze Historie (12 Tage)',             [[-12, 79.00], [-3, 69.00]]],
    ['zwei Aktionen hintereinander',         [[-150, 199.00], [-70, 169.00], [-55, 199.00], [-10, 159.00]]],
    ['Aktion seit gestern',                  [[-90, 449.00], [-1, 399.00]]],
    ['tiefe Senkung, 40 %',                  [[-120, 500.00], [-7, 299.00]]],
    ['kleine Senkung, 3 %',                  [[-120, 103.00], [-7, 99.90]]],
    ['Preis schwankt mehrfach',              [[-120, 79.00], [-50, 69.00], [-44, 79.00], [-20, 74.00], [-6, 69.00]]],
    ['Aktion, dann tiefer, dann Ende',       [[-150, 219.00], [-40, 189.00], [-25, 169.00], [-4, 219.00]]],
    ['Neuartikel mit Aktion',                [[-20, 129.00], [-2, 109.00]]],
    ['hoher Preis, kleine Aktion',           [[-120, 2499.00], [-8, 2399.00]]],
    ['Cent-Betrag',                          [[-120, 1.29], [-6, 0.99]]],
    ['Erhöhung nach Aktion',                 [[-120, 39.00], [-60, 34.00], [-40, 42.00]]],
    ['lange Aktion (50 Tage)',               [[-150, 179.00], [-50, 139.00]]],
    ['Aktion heute begonnen',                [[-90, 89.00], [0, 69.00]]],
];

foreach ($muster as $i => [$was, $verlauf]) {
    $sku = \sprintf('Y5X-BSP-%02d', $i + 1);
    foreach ($verlauf as $n => [$ab, $brutto]) {
        $bis = isset($verlauf[$n + 1]) ? $tag($verlauf[$n + 1][0] - 1) : $tag(0);
        $db->execute(
            'INSERT INTO {p}price_events (sku, market, currency, net, gross, valid_from, valid_to)
             VALUES (?,?,?,?,?,?,?)',
            [$sku, 'DE', 'EUR', $netto($brutto), \number_format($brutto, 2, '.', ''),
             $tag($ab), $bis]);
    }
    $db->execute('INSERT INTO {p}price_state (sku, market, mode, updated_at)
                  VALUES (?,?,\'normal\',NOW())', [$sku, 'DE']);
    $db->execute('INSERT INTO {p}article_meta (sku, market, name, fetched_at) VALUES (?,?,?,NOW())
                  ON DUPLICATE KEY UPDATE name = VALUES(name)',
        [$sku, 'DE', 'Beispiel ' . ($i + 1) . ': ' . $was]);
    \printf("  %s  %-34s %d Intervalle\n", $sku, $was, \count($verlauf));
}
echo "\n20 Beispiele angelegt. Rechnen und schreiben:\n";
echo "  php bin/run.php --market DE --ohne-abruf --write\n";
