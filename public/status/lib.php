<?php
declare(strict_types=1);

/**
 * Gemeinsame Grundlage der Statusseiten.
 *
 * Der DB-Zugang kommt aus der LAUFZEIT, nicht aus dem Home-Verzeichnis: Der PHP-FPM-Pool
 * dieses Webspace darf nur `web/`, `private/` und `tmp/` lesen — ein `require` auf
 * `$HOME/secrets/db.php` endet mit „Permission denied" und einem 500er. `deploy.sh`
 * spiegelt die Zugangsdaten deshalb nach `private/apps/y5x/<env>/db.php` (600) und legt
 * daneben eine `RUNTIME`-Datei mit dem Pfad.
 */

use Grube\Price30\Support\Db;

function y5x_env(): string
{
    return \str_contains(__DIR__, '/web/staging/') ? 'staging' : 'prod';
}

function y5x_runtime(): string
{
    return '/var/www/clients/client1/web81/private/apps/y5x/' . y5x_env();
}

require y5x_runtime() . '/autoload.php';

function db(): Db
{
    static $db = null;
    return $db ??= Db::fromRuntime(y5x_runtime());
}

function cfg(string $datei): array
{
    static $cache = [];
    return $cache[$datei] ??= (\yaml_parse_file(y5x_runtime() . "/config/$datei.yml") ?: []);
}

function maerkte(): array
{
    return cfg('markets')['markets'] ?? [];
}

function h($s): string
{
    return \htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function geld(?string $v, string $cur = 'EUR', int $stellen = 2): string
{
    return $v === null ? '—' : \number_format((float) $v, $stellen, ',', '.') . ' ' . $cur;
}

function datum(?string $d): string
{
    return $d ? \date('d.m.Y', \strtotime($d)) : '—';
}

// --- Anmeldung ---------------------------------------------------------------
function start_session(): void
{
    if (\session_status() !== \PHP_SESSION_ACTIVE) {
        \session_name('y5x_' . y5x_env());
        \session_start();
    }
}

function current_user(): string
{
    start_session();
    return (string) ($_SESSION['user'] ?? 'anonym');
}

function require_login(): void
{
    start_session();
    if (!empty($_SESSION['user'])) {
        return;
    }
    $fehler = '';
    if (($_POST['action'] ?? '') === 'login') {
        $u = \strtolower(\trim((string) ($_POST['username'] ?? '')));
        $row = db()->one('SELECT * FROM {p}users WHERE username = ?', [$u]);
        if ($row && \password_verify((string) ($_POST['password'] ?? ''), $row['password_hash'])) {
            $_SESSION['user'] = $u;
            db()->execute('UPDATE {p}users SET last_login = NOW() WHERE id = ?', [$row['id']]);
            \header('Location: ?');
            exit;
        }
        $fehler = 'Anmeldung fehlgeschlagen.';
    }
    ?><!doctype html><meta charset="utf-8"><title>y5x — Anmeldung</title>
    <style>body{font:15px/1.5 system-ui;margin:8rem auto;max-width:22rem;color:#222}
    input{width:100%;padding:.6rem;margin:.3rem 0;border:1px solid #bbb;border-radius:4px}
    button{padding:.6rem 1rem;margin-top:.6rem}.f{color:#a00}</style>
    <h1>30-Tage-Bestpreis-Tracker</h1>
    <?php if ($fehler !== '') { echo '<p class="f">' . h($fehler) . '</p>'; } ?>
    <form method="post"><input type="hidden" name="action" value="login">
      <input name="username" placeholder="E-Mail-Adresse" autofocus>
      <input name="password" type="password" placeholder="Passwort"><button>Anmelden</button>
    </form>
    <p style="color:#888;font-size:.85em">Umgebung: <?= h(y5x_env()) ?></p>
    <?php
    exit;
}

/** Kopf und Stil — an einer Stelle, damit die Anlage einheitlich aussieht. */
function seitenkopf(string $titel): void
{
    ?><!doctype html><html lang="de"><meta charset="utf-8">
<title>y5x — <?= h($titel) ?></title>
<style>
 body{font:14px/1.55 system-ui,sans-serif;margin:0;color:#1c1c1c;background:#f6f6f4}
 header{background:#1f3a5f;color:#fff;padding:.75rem 1.2rem;display:flex;
        justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}
 header a{color:#cfe0f5}
 main{padding:1.2rem;max-width:1180px;margin:0 auto}
 h2{font-size:1.05rem;margin:1.6rem 0 .6rem}
 .kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.7rem}
 .kpi div{background:#fff;border:1px solid #e2e2de;border-radius:6px;padding:.6rem .8rem}
 .kpi b{display:block;font-size:1.5rem;line-height:1.2}
 .kpi span{color:#666;font-size:.82em}
 table{border-collapse:collapse;width:100%;background:#fff;font-size:.92em}
 th,td{border:1px solid #e2e2de;padding:.32rem .5rem;text-align:left;vertical-align:top}
 th{background:#f0f0ec;font-weight:600}
 td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
 .warn{color:#8a4b00;font-weight:600}
 .bad{color:#a01818;font-weight:600}
 .ok{color:#1d6b2a}
 .tag{display:inline-block;padding:.05rem .4rem;border-radius:3px;font-size:.8em;
      background:#eceae4;border:1px solid #dcdad2}
 .tag.promo{background:#fde9d0;border-color:#f0c68c}
 .tag.aus{background:#eee;color:#666}
 .hinweis{color:#666}
 .verlauf{background:#fff;border:1px solid #e2e2de;border-radius:6px}
 .verlauf .linie{fill:none;stroke:#1f3a5f;stroke-width:2}
 .verlauf .referenz{stroke:#a01818;stroke-width:1.5;stroke-dasharray:5 3}
 .verlauf .prev{stroke:#7a4fa3;stroke-width:1.5;stroke-dasharray:2 4}
 .verlauf .mini.pv{fill:#7a4fa3}
 .verlauf .stichtag{stroke:#1d6b2a;stroke-width:1.5;stroke-dasharray:2 2}
 .verlauf .fenster{fill:#1f3a5f;opacity:.06}
 .verlauf .aktion{fill:#e8890c;opacity:.13}
 .verlauf .raster{stroke:#e8e8e4;stroke-width:1}
 .verlauf .achse{font-size:10px;fill:#777}
 .verlauf .mini{font-size:10px;fill:#666}
 .verlauf .mini.ref{fill:#a01818}
 form.suche{margin:.6rem 0 1rem;display:flex;gap:.4rem;flex-wrap:wrap}
 form.suche input,form.suche select{padding:.35rem .5rem;border:1px solid #ccc;border-radius:4px}
 .beleg{background:#fff;border:1px solid #e2e2de;border-left:4px solid #1f3a5f;
        border-radius:6px;padding:.7rem .9rem;margin:.6rem 0}
 @media print{header{background:#fff;color:#000}body{background:#fff}
   .kpi div,table,.verlauf,.beleg{break-inside:avoid}form.suche{display:none}}
</style>
<header>
  <b>30-Tage-Bestpreis-Tracker</b>
  <nav><a href="index.php">Übersicht</a> · <a href="artikel.php">Artikel &amp; Nachweis</a></nav>
  <span><?= h(y5x_env()) ?> · <?= h(current_user()) ?></span>
</header>
<main>
    <?php
}
