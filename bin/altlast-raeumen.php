#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Die Einträge unter dem ALTEN Schlüssel entfernen — der ohne `provider`.
 *
 *   php bin/altlast-raeumen.php [--market AT,PL,SK] [--wirklich]
 *
 * Bis zum 19.08.2026 schrieb der Preisschreiber unter
 * `[brand=… country=… currency=…]`. Seitdem schreibt er unter demselben Schlüssel
 * **mit** `provider=preisschreiber`, damit der große ERP-Import die Werte nicht wieder
 * wegräumt. Was unter dem alten Schlüssel steht, wird von diesem Werkzeug nie wieder
 * angefasst: Es veraltet still weiter und steht dabei neben dem gültigen Wert.
 *
 * Zwei konkurrierende `30_GROSS` für denselben Artikel und Markt sind keine
 * Ordnungsfrage — es ist ein Referenzpreis, der eine Werbeaussage trägt. Welcher von
 * beiden gewinnt, entscheidet dann die Anzeige, nicht wir.
 *
 * **Ohne `--wirklich` wird nur gezählt und aufgelistet.** Gelöscht wird ausschließlich,
 * was dieses Werkzeug selbst geschrieben hat: die vier eigenen `priceType` unter dem
 * alten Schlüssel. Fremde Einträge werden nicht angerührt — die Schlüssel gehen
 * vollständig und einzeln mit, nichts wird über einen Bereich gelöscht.
 */
require __DIR__ . '/../autoload.php';

use Grube\Price30\Adapters\PssPriceAdapter;
use Grube\Price30\Cli\Run;
use Grube\Price30\Support\Db;
use Grube\Price30\Support\Env;
use Grube\Price30\Support\Http;

$db  = Db::fromRuntime(__DIR__ . '/..');
$env = new Env(__DIR__ . '/../.env');
$markets = (\yaml_parse_file(__DIR__ . '/../config/markets.yml') ?: [])['markets'] ?? [];
$tun = \in_array('--wirklich', $argv, true);

$nur = null;
foreach ($argv as $i => $a) {
    if ($a === '--market') { $nur = \array_filter(\explode(',', (string) ($argv[$i + 1] ?? ''))); }
}

$pss = new PssPriceAdapter(new Http($env->get('PSS_BASE_URL'), $env->get('PSS_USER'),
    $env->get('PSS_PASS')), 3);
$typen = ['30_GROSS', '30_NET', 'PREV_GROSS', 'PREV_NET'];

\printf("%s · %s\n\n", $db->env, $tun ? 'ES WIRD GELÖSCHT' : 'nur zählen (--wirklich fehlt)');

foreach ($markets as $code => $m) {
    if (!($m['active'] ?? false)) { continue; }
    if ($nur !== null && !\in_array((string) $code, $nur, true)) { continue; }

    $waehrung = (string) ($m['currency'] ?? 'EUR');
    $alt = \sprintf('[brand=%s country=%s currency=%s]',
        (string) ($m['shop_brand'] ?? 'grube'), \strtolower((string) $code), $waehrung);

    // Nur Artikel, für die dieses Werkzeug im Markt überhaupt einmal geschrieben hat.
    $skus = \array_column($db->query(
        'SELECT DISTINCT sku FROM {p}pss_write_log WHERE market = ? AND success = 1',
        [(string) $code]), 'sku');
    if ($skus === []) {
        \printf("%-3s  nichts geschrieben, nichts zu räumen\n", $code);
        continue;
    }
    \printf("%-3s  %s Artikel × %d priceType = %s Schlüssel unter %s\n",
        $code, \number_format(\count($skus), 0, ',', '.'), \count($typen),
        \number_format(\count($skus) * \count($typen), 0, ',', '.'), $alt);
    if (!$tun) { continue; }

    $weg = 0; $fehl = 0;
    foreach (\array_chunk($skus, 125) as $block) {          // 125 × 4 = 500 Schlüssel
        $keys = [];
        foreach ($block as $sku) {
            foreach ($typen as $typ) { $keys[] = PssPriceAdapter::schluessel($sku, $typ, $alt); }
        }
        $r = $pss->loeschen($keys);
        if ($r['ok']) { $weg += \count($keys); } else { $fehl += \count($keys); }
    }
    \printf("     %s Schlüssel geschickt, %s fehlgeschlagen\n",
        \number_format($weg, 0, ',', '.'), \number_format($fehl, 0, ',', '.'));
}

\printf("\nNeuer Schlüssel bleibt unberührt: provider=%s\n", Run::PROVIDER);
