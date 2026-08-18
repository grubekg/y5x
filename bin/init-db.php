#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Schema anlegen. `php bin/init-db.php [--env staging|prod]` */
require __DIR__ . '/../autoload.php';

use Grube\Price30\Support\Db;

$laufzeit = __DIR__ . '/..';
$env = null;
foreach ($argv as $i => $a) {
    if ($a === '--env') { $env = $argv[$i + 1] ?? null; }
}
$db = $env !== null
    ? new Db(require $laufzeit . '/db.php', $env)
    : Db::fromRuntime($laufzeit);

$sql = \file_get_contents(__DIR__ . '/../sql/schema.sql');
// Kommentarzeilen VOR dem Zerlegen entfernen: Ein Semikolon in einem Kommentar wuerde
// die Anweisung sonst mitten im Satz zerschneiden.
$sql = \preg_replace('/^\s*--.*$/m', '', (string) $sql);
$sql = \str_replace('{{P}}', $db->prefix(), $sql);

$angelegt = 0;
foreach (\array_filter(\array_map('trim', \explode(';', $sql))) as $stmt) {
    $db->pdo()->exec($stmt);
    $angelegt++;
}
echo "Schema angelegt in {$db->env}: {$angelegt} Anweisungen, Praefix {$db->prefix()}\n";
