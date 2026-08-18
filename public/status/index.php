<?php
declare(strict_types=1);

/**
 * Übersicht — Betriebszustand und Risikolage je Markt.
 *
 * Die wichtigste Zahl steht bewusst nicht in der Mitte, sondern zuerst: **Artikel, die
 * gerade eine Ermäßigung ausweisen, deren 30-Tage-Historie aber unvollständig ist.**
 * Genau dort beruht eine laufende Werbeaussage auf einer schwächeren Grundlage — das ist
 * die Menge, die man vor einer Abmahnung kennen will, nicht danach.
 */
require __DIR__ . '/lib.php';
require_login();

$heute = new DateTimeImmutable('today');
$maerkte = maerkte();
$dry = (bool) (cfg('app')['dry_run'] ?? true);

$je = [];
foreach (db()->query(
    'SELECT market,
            COUNT(*) AS artikel,
            SUM(mode = \'promo\') AS in_aktion,
            SUM(window_complete = 0) AS unvollstaendig,
            SUM(mode = \'promo\' AND window_complete = 0) AS risiko,
            SUM(last_written_30_gross IS NOT NULL) AS geschrieben
       FROM {p}price_state GROUP BY market') as $r) {
    $je[$r['market']] = $r;
}

$laeufe = [];
foreach (db()->query(
    'SELECT market, MAX(finished_at) AS zuletzt,
            SUM(run_date = CURDATE()) AS heute_gelaufen
       FROM {p}run_log WHERE status <> \'failed\' GROUP BY market') as $r) {
    $laeufe[$r['market']] = $r;
}

$fehler = db()->query(
    'SELECT market, SUM(errors) AS errors, SUM(anomalies) AS anomalies, SUM(pss_writes) AS writes
       FROM {p}run_log WHERE run_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY market');
$sieben = [];
foreach ($fehler as $r) { $sieben[$r['market']] = $r; }

$summe = static function (string $feld) use ($je): int {
    $n = 0;
    foreach ($je as $r) { $n += (int) ($r[$feld] ?? 0); }
    return $n;
};

seitenkopf('Übersicht');
?>
<?php if ($dry): ?>
<p class="beleg"><span class="warn">Trockenmodus aktiv</span> — es wird gerechnet und
protokolliert, aber <b>nicht</b> in den PSS geschrieben (<code>dry_run: true</code> in
<code>app.yml</code>).</p>
<?php endif; ?>

<div class="kpi">
  <div><b class="<?= $summe('risiko') > 0 ? 'bad' : 'ok' ?>"><?= $summe('risiko') ?></b>
       <span>Ermäßigung auf unvollständiger Historie</span></div>
  <div><b><?= $summe('in_aktion') ?></b><span>Artikel in Aktion (alle Märkte)</span></div>
  <div><b><?= $summe('artikel') ?></b><span>getrackte Artikel</span></div>
  <div><b><?= $summe('unvollstaendig') ?></b><span>Fenster noch nicht voll</span></div>
  <div><b><?= $summe('geschrieben') ?></b><span>mit Wert im PSS</span></div>
</div>

<h2>Je Markt</h2>
<table>
<tr><th>Markt</th><th>Shop</th><th class="num">Artikel</th><th class="num">in Aktion</th>
    <th class="num">Anteil</th><th class="num">Risiko</th><th class="num">Fenster unvollst.</th>
    <th>Schreiben</th><th>letzter Lauf</th><th class="num">Writes 7 T.</th>
    <th class="num">Fehler 7 T.</th><th class="num">Anomalien 7 T.</th></tr>
<?php foreach ($maerkte as $code => $m):
    if (!($m['active'] ?? false)) { continue; }
    $s = $je[$code] ?? [];
    $artikel = (int) ($s['artikel'] ?? 0);
    $aktion  = (int) ($s['in_aktion'] ?? 0);
    $risiko  = (int) ($s['risiko'] ?? 0);
    $l = $laeufe[$code] ?? [];
    $zuletzt = $l['zuletzt'] ?? null;
    // Mehr als 26 Stunden ohne Lauf heisst: Die Tagesabdeckung hat eine Luecke. Genau
    // die ist der zweite Teil der Beweiskette — ohne sie ist nicht belegt, dass taeglich
    // geprueft wurde.
    $veraltet = $zuletzt === null || (\time() - \strtotime($zuletzt)) > 26 * 3600;
    $w = $sieben[$code] ?? [];
?>
<tr>
  <td><b><?= h($code) ?></b></td>
  <td><?= h($m['shop'] ?? '—') ?></td>
  <td class="num"><?= $artikel ?></td>
  <td class="num"><?= $aktion ?></td>
  <td class="num"><?= $artikel ? \number_format(100 * $aktion / $artikel, 1, ',', '.') . ' %' : '—' ?></td>
  <td class="num <?= $risiko > 0 ? 'bad' : '' ?>">
    <?= $risiko > 0 ? '<a href="artikel.php?markt=' . h($code) . '&amp;filter=risiko">' . $risiko . '</a>' : '0' ?></td>
  <td class="num"><?= (int) ($s['unvollstaendig'] ?? 0) ?></td>
  <td><?= ($m['write_enabled'] ?? false)
        ? '<span class="tag">aktiv</span>'
        : '<span class="tag aus">aus</span>' ?></td>
  <td class="<?= $veraltet ? 'bad' : '' ?>">
    <?= $zuletzt ? h(\date('d.m.Y H:i', \strtotime($zuletzt))) : 'nie' ?>
    <?= $veraltet ? '<br><span class="bad">Lücke &gt; 26 h</span>' : '' ?></td>
  <td class="num"><?= (int) ($w['writes'] ?? 0) ?></td>
  <td class="num <?= (int) ($w['errors'] ?? 0) > 0 ? 'bad' : '' ?>"><?= (int) ($w['errors'] ?? 0) ?></td>
  <td class="num <?= (int) ($w['anomalies'] ?? 0) > 0 ? 'warn' : '' ?>"><?= (int) ($w['anomalies'] ?? 0) ?></td>
</tr>
<?php endforeach; ?>
</table>
<p class="hinweis">„Risiko" = Artikel, die gerade eine Ermäßigung ausweisen, deren
30-Tage-Historie aber noch Lücken hat. <b>CH</b> wird bewusst nur getrackt und nicht
geschrieben: Die Preisbekanntgabeverordnung folgt nicht der EU-30-Tage-Regel.</p>

<h2>Letzte Läufe</h2>
<table>
<tr><th>Datum</th><th>Markt</th><th>Status</th><th class="num">gelesen</th>
    <th class="num">Änderungen</th><th class="num">Writes</th><th class="num">Anomalien</th>
    <th class="num">Fehler</th><th>Dauer</th><th>Notiz</th></tr>
<?php foreach (db()->query(
    'SELECT * FROM {p}run_log ORDER BY id DESC LIMIT 20') as $r):
    $dauer = ($r['started_at'] && $r['finished_at'])
        ? \gmdate('H:i:s', \strtotime($r['finished_at']) - \strtotime($r['started_at'])) : '—';
?>
<tr>
  <td><?= datum($r['run_date']) ?></td><td><?= h($r['market']) ?></td>
  <td class="<?= $r['status'] === 'ok' ? 'ok' : ($r['status'] === 'failed' ? 'bad' : 'warn') ?>">
      <?= h($r['status']) ?></td>
  <td class="num"><?= (int) $r['items_fetched'] ?></td>
  <td class="num"><?= (int) $r['price_changes'] ?></td>
  <td class="num"><?= (int) $r['pss_writes'] ?></td>
  <td class="num"><?= (int) $r['anomalies'] ?></td>
  <td class="num"><?= (int) $r['errors'] ?></td>
  <td><?= h($dauer) ?></td><td><?= h(\mb_substr((string) $r['note'], 0, 90)) ?></td>
</tr>
<?php endforeach; ?>
</table>
</main>
