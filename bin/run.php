#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tageslauf.
 *
 *   php bin/run.php [--market DE] [--limit N] [--quiet] [--ohne-abruf] [--write]
 *
 * `--write` schaltet fuer diesen Lauf scharf; ohne den Schalter gilt `dry_run` aus
 * `app.yml`. `--ohne-abruf` rechnet und schreibt auf dem vorhandenen Bestand, ohne den
 * Shop zu belasten.
 *
 */
require __DIR__ . '/../autoload.php';

use Grube\Price30\Adapters\IshopPriceAdapter;
use Grube\Price30\Adapters\PssPriceAdapter;
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
$abruf = !\in_array('--ohne-abruf', $argv, true);
// Scharfschalten ist eine bewusste Handlung: `--write` ueberstimmt `dry_run` fuer DIESEN
// Lauf. Ohne den Schalter wird gerechnet und protokolliert, aber nichts uebertragen.
if (\in_array('--write', $argv, true)) { $app['dry_run'] = false; }

$http = new Http($env->get('ISHOP_BASE_URL'), $env->get('ISHOP_USER'), $env->get('ISHOP_PASS'));
$pss = new PssPriceAdapter(new Http($env->get('PSS_BASE_URL'), $env->get('PSS_USER'),
    $env->get('PSS_PASS')), (int) ($app['max_write_retries'] ?? 3));
$run = new Run($db, new IshopPriceAdapter($http), $app, $markets, $pss);

$start = \microtime(true);
\printf("Lauf %s · Markt %s · Grenze %d · %s · PSS %s\n", $db->env, $markt, $limit,
    ($app['dry_run'] ?? true) ? 'Trockenmodus' : 'SCHREIBEND',
    \parse_url($env->get('PSS_BASE_URL'), \PHP_URL_HOST) ?: '—');
$z = $run->fuerMarkt($markt, $limit, $laut, $abruf);
\printf("fertig in %.1f s\n", \microtime(true) - $start);
foreach ($z as $k => $v) { \printf("  %-14s %d\n", $k, $v); }
