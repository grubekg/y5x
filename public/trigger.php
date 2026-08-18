<?php
declare(strict_types=1);
/**
 * Auslöser für den Tageslauf — gedacht für den Cron des ISPConfig-Panels.
 *
 * Der Lauf dauert rund elf Minuten und überlebt den Request deshalb nicht: PHP-FPM
 * bricht ihn ab, sobald der Aufrufer weg ist. Er wird darum per `setsid` abgelöst
 * gestartet und läuft eigenständig weiter; diese Seite antwortet sofort.
 *
 * `flock -n` verhindert Doppelläufe. Zwei gleichzeitige Läufe würden dieselben
 * Preisereignisse schreiben und sich gegenseitig die Zustände überschreiben — ein
 * Cron, der einmal zu oft feuert, darf keinen Schaden anrichten.
 *
 * `--write` steht hier und nicht in der `app.yml`: Der Auslieferungszustand der
 * Konfiguration bleibt der Trockenmodus, damit ein von Hand gestarteter Lauf nichts
 * überträgt. Scharf ist genau dieser eine, sichtbare Weg.
 */
// Diese Datei liegt im Web-Ordner, die Laufzeit liegt AUSSERHALB davon — Token,
// Sperre und Protokoll gehoeren nicht in den Docroot. Die Umgebung steht am Pfad:
// Staging wird unter /web/staging/ ausgeliefert (Konvention grube.tools).
$umgebung = \str_contains(__DIR__, '/web/staging/') ? 'staging' : 'prod';
$basis = '/var/www/clients/client1/web81/private/apps/y5x/' . $umgebung;
$erwartet = \trim(@\file_get_contents($basis . '/trigger.token') ?: '');
$gegeben  = (string) ($_GET['token'] ?? '');

// hash_equals statt ===: Ein Zeichenvergleich, der beim ersten Unterschied abbricht,
// verrät über die Antwortzeit, wie viele Zeichen stimmten.
if ($erwartet === '' || !\hash_equals($erwartet, $gegeben)) {
    \http_response_code(403);
    exit("verboten\n");
}

$sperre = $basis . '/logs/run.lock';
$log    = $basis . '/logs/cron-' . \date('Y-m-d') . '.log';
@\mkdir($basis . '/logs', 0775, true);

$cmd = \sprintf(
    'setsid flock -n %s /opt/php-8.5/bin/php -d memory_limit=1536M %s --alle --write --limit 100000 >> %s 2>&1 &',
    \escapeshellarg($sperre), \escapeshellarg($basis . '/bin/run.php'), \escapeshellarg($log));
\exec($cmd);

\header('Content-Type: text/plain; charset=utf-8');
echo "Lauf angestossen " . \date('c') . "\n";
echo "Protokoll: logs/cron-" . \date('Y-m-d') . ".log\n";
