#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tageslauf.
 *
 *   php bin/run.php [--market DE|--alle] [--limit N] [--sku NR] [--quiet]
 *                   [--ohne-abruf] [--write]
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
$nurSku = $opt('sku', null);
$limit = (int) ($opt('limit', '1000'));
$laut  = !\in_array('--quiet', $argv, true);
$abruf = !\in_array('--ohne-abruf', $argv, true);
// Scharfschalten ist eine bewusste Handlung: `--write` ueberstimmt `dry_run` fuer DIESEN
// Lauf. Ohne den Schalter wird gerechnet und protokolliert, aber nichts uebertragen.
if (\in_array('--write', $argv, true)) { $app['dry_run'] = false; }

$takt = (int) ($app['requests_per_minute'] ?? 330);
foreach ($argv as $i => $a) { if ($a === '--takt') { $takt = (int) ($argv[$i + 1] ?? $takt); } }
$http = new Http($env->get('ISHOP_BASE_URL'), $env->get('ISHOP_USER'), $env->get('ISHOP_PASS'),
    120, $takt);
$pss = new PssPriceAdapter(new Http($env->get('PSS_BASE_URL'), $env->get('PSS_USER'),
    $env->get('PSS_PASS')), (int) ($app['max_write_retries'] ?? 3));
$run = new Run($db, new IshopPriceAdapter($http), $app, $markets, $pss);
if ($nurSku !== null && $nurSku !== '') { $run->nurSku((string) $nurSku); }

$start = \microtime(true);
// --alle: sämtliche aktiven Märkte, mit EINEM gemeinsamen Sammelabzug.
if (\in_array('--alle', $argv, true)) {
    $aktiv = [];
    foreach ($markets as $code => $m) {
        if ($m['active'] ?? false) { $aktiv[$code] = (string) ($m['currency'] ?? 'EUR'); }
    }
    \printf("Alle Märkte: %s\n\n", \implode(', ', \array_keys($aktiv)));
    $sammel = $run->sammelPreise($aktiv, $laut);
    $gesamt = [];
    foreach ($aktiv as $code => $waehrung) {
        $t0 = \microtime(true);
        \printf("\n--- %s ---\n", $code);
        $z = $run->fuerMarkt($code, $limit, $laut, true, $sammel);
        \printf("  %s in %.0f s\n", $code, \microtime(true) - $t0);
        foreach ($z as $k => $v) { $gesamt[$k] = ($gesamt[$k] ?? 0) + $v; }
    }
    \printf("\nGESAMT in %.0f s (%.1f min)\n", \microtime(true) - $start, (\microtime(true) - $start) / 60);
    foreach ($gesamt as $k => $v) { \printf("  %-14s %s\n", $k, \number_format($v, 0, ',', '.')); }
    exit(0);
}
\printf("Lauf %s · Markt %s · Grenze %d · %s · PSS %s\n", $db->env, $markt, $limit,
    ($app['dry_run'] ?? true) ? 'Trockenmodus' : 'SCHREIBEND',
    \parse_url($env->get('PSS_BASE_URL'), \PHP_URL_HOST) ?: '—');
\printf("Takt: %s\n", $takt > 0 ? "$takt Anfragen/min" : 'ungedrosselt');
$z = $run->fuerMarkt($markt, $limit, $laut, $abruf);
\printf("fertig in %.1f s\n", \microtime(true) - $start);
foreach ($z as $k => $v) { \printf("  %-14s %d\n", $k, $v); }
