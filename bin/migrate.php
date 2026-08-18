#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Migrationen anwenden. `php bin/migrate.php [--env staging|prod] [--file <name>]`
 *
 * `init-db.php` legt nur an (CREATE TABLE IF NOT EXISTS) — bestehende Tabellen bekommen
 * neue Spalten nur hierueber. Bereits vorhandene Spalten werden uebersprungen, damit ein
 * zweiter Lauf nicht scheitert.
 */
require __DIR__ . '/../autoload.php';

use Grube\Price30\Support\Db;

$laufzeit = __DIR__ . '/..';
$env = null; $nur = null;
foreach ($argv as $i => $a) {
    if ($a === '--env')  { $env = $argv[$i + 1] ?? null; }
    if ($a === '--file') { $nur = $argv[$i + 1] ?? null; }
}
$db = $env !== null ? new Db(require $laufzeit . '/db.php', $env) : Db::fromRuntime($laufzeit);

$dateien = \glob(__DIR__ . '/../sql/migrations/*.sql') ?: [];
\sort($dateien);
foreach ($dateien as $datei) {
    if ($nur !== null && !\str_contains($datei, $nur)) { continue; }
    $sql = \preg_replace('/^\s*--.*$/m', '', (string) \file_get_contents($datei));
    $sql = \str_replace('{{P}}', $db->prefix(), (string) $sql);
    foreach (\array_filter(\array_map('trim', \explode(';', $sql))) as $stmt) {
        try {
            $db->pdo()->exec($stmt);
            echo "  ok       " . \basename($datei) . "\n";
        } catch (\PDOException $e) {
            // 1060 = Duplicate column, 1091 = can't drop; beides heisst "schon erledigt".
            if (\in_array($e->errorInfo[1] ?? 0, [1060, 1091], true)) {
                echo "  schon da " . \basename($datei) . "\n";
                continue;
            }
            throw $e;
        }
    }
}
echo "Migrationen fertig ({$db->env}, Praefix {$db->prefix()}).\n";
