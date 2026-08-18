#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Benutzer der Statusseite verwalten.
 *
 *     php bin/user.php add  <mail> <passwort> [admin|user]
 *     php bin/user.php pass <mail> <passwort>
 *     php bin/user.php list
 *     php bin/user.php del  <mail>
 *
 * Das Passwort wird nur als Hash gespeichert (`password_hash`, Standardverfahren) und
 * taucht weder in der Datenbank noch in einem Log im Klartext auf.
 */
require __DIR__ . '/../autoload.php';

use Grube\Price30\Support\Db;

$db = Db::fromRuntime(__DIR__ . '/..');
$befehl = $argv[1] ?? 'list';
$mail = \strtolower(\trim((string) ($argv[2] ?? '')));

switch ($befehl) {
    case 'add':
    case 'pass':
        $pw = (string) ($argv[3] ?? '');
        $rolle = \in_array($argv[4] ?? '', ['admin', 'user'], true) ? $argv[4] : 'user';
        if ($mail === '' || $pw === '') {
            \fwrite(\STDERR, "Aufruf: php bin/user.php $befehl <mail> <passwort>\n");
            exit(1);
        }
        $hash = \password_hash($pw, \PASSWORD_DEFAULT);
        $db->execute(
            'INSERT INTO {p}users (username, password_hash, role, created_at) VALUES (?,?,?,NOW())
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)', [$mail, $hash, $rolle]);
        echo "Benutzer $mail gespeichert (Rolle $rolle, {$db->env}, Tabelle {$db->table('users')}).\n";
        break;

    case 'del':
        $n = $db->execute('DELETE FROM {p}users WHERE username = ?', [$mail]);
        echo "$n Benutzer entfernt.\n";
        break;

    default:
        foreach ($db->query('SELECT username, role, created_at, last_login FROM {p}users ORDER BY username') as $u) {
            \printf("  %-36s %-6s angelegt %s  zuletzt %s\n", $u['username'], $u['role'],
                $u['created_at'] ?? '—', $u['last_login'] ?? 'nie');
        }
}
