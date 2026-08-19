#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Einmalig: Anomalien aus der Notizspalte in `run_issue` nachtragen.
 *
 *   php bin/befunde-nachtragen.php [--wirklich]
 *
 * Für Läufe vor dem 19.08.2026 gab es die Tabelle noch nicht; was von ihren verworfenen
 * Datensätzen bekannt ist, steht in der Notiz — und dort **auf zehn Einträge gekappt**.
 * Genau das wird hier gerettet, mehr ist nicht zu holen. Jede nachgetragene Zeile trägt
 * den Vermerk, dass sie aus der Notiz stammt und die Liste unvollständig ist; eine
 * Beweisgrundlage darf nicht so tun, als sei sie lückenlos, wenn sie es nicht ist.
 *
 * Ohne `--wirklich` wird nur gezeigt, was geschähe.
 */
require __DIR__ . '/../autoload.php';

use Grube\Price30\Support\Db;

$db  = Db::fromRuntime(__DIR__ . '/..');
$tun = \in_array('--wirklich', $argv, true);

$laeufe = $db->query(
    "SELECT r.id, r.run_date, r.market, r.anomalies, r.note
       FROM {p}run_log r
      WHERE r.note LIKE '%verworfen:%'
        AND NOT EXISTS (SELECT 1 FROM {p}run_issue i WHERE i.run_id = r.id)");

$gesamt = 0;
foreach ($laeufe as $l) {
    if (!\preg_match('/verworfen: (.*?)(?: \| |$)/su', (string) $l['note'], $m)) { continue; }
    $liste = \preg_replace('/ … \(\+\d+ weitere\)$/u', '', $m[1]);
    $zeilen = [];
    foreach (\explode('; ', (string) $liste) as $eintrag) {
        if (!\preg_match('/^(\S+) \((.+?): netto (\S+) \/ brutto (\S+)\)$/u', \trim($eintrag), $t)) {
            continue;
        }
        $zeilen[] = [$t[1], $t[2] . ' (aus der Notiz nachgetragen — die Liste war auf zehn '
            . 'Einträge gekappt, es fehlen weitere)', $t[3], $t[4]];
    }
    if ($zeilen === []) { continue; }
    \printf("Lauf %d (%s, %s): %d von %d Anomalien nachtragbar\n",
        $l['id'], $l['run_date'], $l['market'], \count($zeilen), (int) $l['anomalies']);
    $gesamt += \count($zeilen);
    if (!$tun) { continue; }
    foreach ($zeilen as [$sku, $grund, $net, $gross]) {
        $db->execute(
            'INSERT INTO {p}run_issue (run_id, run_date, market, kind, sku, detail, net, gross)
             VALUES (?,?,?,?,?,?,?,?)',
            [$l['id'], $l['run_date'], $l['market'], 'anomalie', $sku,
             \mb_substr($grund, 0, 512), $net, $gross]);
    }
}
\printf("\n%d Zeilen %s.\n", $gesamt, $tun ? 'nachgetragen' : 'wären nachzutragen (--wirklich fehlt)');
