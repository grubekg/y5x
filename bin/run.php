#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tageslauf. `php bin/run.php [--market DE] [--limit N] [--quiet]`
 *
 * Geschrieben wird ausschliesslich in die eigenen Tabellen. Ein PSS-Write findet nicht
 * statt — der Adapter dafuer existiert noch nicht (TODO(setup) 2).
 */
require __DIR__ . '/../autoload.php';

use Grube\Price30\Adapters\IshopPriceAdapter;
use Grube\Price30\Cli\Run;
use Grube\Price30\Support\Db;
use Grube\Price30\Support\Env;
use Grube\Price30\Support\Http;

$laufzeit = __DIR__ . '/..';
$opt = static function (string $name, ?string $vorgabe = null) use ($argv): ?string {
    foreach ($argv as $i => $a) {
        if ($a === "--$name") { return $argv[$i + 1] ?? ''; }
    }
    return $vorgabe;
};

$db  = Db::fromRuntime($laufzeit);
$env = new Env($laufzeit . '/.env');
$app = \yaml_parse_file($laufzeit . '/config/app.yml') ?: [];
$markets = (\yaml_parse_file($laufzeit . '/config/markets.yml') ?: [])['markets'] ?? [];

$markt = (string) $opt('market', 'DE');
$limit = (int) ($opt('limit', '1000'));
$laut  = !\in_array('--quiet', $argv, true);

$http = new Http($env->get('ISHOP_BASE_URL'), $env->get('ISHOP_USER'), $env->get('ISHOP_PASS'));
$run = new Run($db, new IshopPriceAdapter($http), $app, $markets);

$start = \microtime(true);
echo "Lauf {$db->env} · Markt $markt · Grenze $limit Artikel · KEIN PSS-Write\n";
$z = $run->fuerMarkt($markt, $limit, $laut);
\printf("fertig in %.1f s\n", \microtime(true) - $start);
foreach ($z as $k => $v) { \printf("  %-14s %d\n", $k, $v); }
