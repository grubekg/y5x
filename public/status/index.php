<?php
declare(strict_types=1);

/**
 * Übersicht — beantwortet zuerst die einzige Frage, die morgens zählt:
 * **„Muss ich hinfassen?"**
 *
 * Deshalb steht oben ein Satz und darunter eine Liste von Handlungen mit Kontext und
 * nächstem Schritt — nicht eine Wand aus Zählern. Ein Dashboard, das nur zählt, erzwingt
 * Detektivarbeit; eines, das verlinkt, erledigt sie.
 */
require __DIR__ . '/lib.php';
require_login();

$app     = cfg('app');
$maerkte = maerkte();
// Der Schreibmodus kommt aus dem letzten Lauf, nicht aus `dry_run` in der app.yml —
// siehe schreibmodi() in lib.php. Je Markt, weil CH dauerhaft nur beobachtet wird.
$modi    = schreibmodi();
$trocken = !schreibt_scharf();
$fensterTage = (int) ($app['window_days'] ?? 30);

// --- Kennzahlen je Markt ----------------------------------------------------
$kennzahlen = [];
foreach (db()->query(
    "SELECT market,
            COUNT(*) AS artikel,
            SUM(mode = 'promo') AS in_aktion,
            SUM(window_complete = 0) AS unvollstaendig,
            SUM(mode = 'promo' AND window_complete = 0) AS risiko,
            SUM(last_written_30_gross IS NOT NULL) AS geschrieben
       FROM {p}price_state GROUP BY market") as $r) {
    $kennzahlen[$r['market']] = $r;
}

// --- Laufzustand je Markt ---------------------------------------------------
$laeufe = [];
foreach (db()->query(
    "SELECT market,
            MAX(CASE WHEN status = 'laeuft' THEN started_at END)                AS offen,
            MAX(CASE WHEN status IN ('ok','partial') THEN finished_at END)      AS zuletzt_ok,
            MIN(CASE WHEN status IN ('ok','partial') THEN run_date END)         AS erster_lauf,
            SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY id DESC), ',', 1)      AS letzter_status,
            SUM(status = 'failed' AND run_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS fehl7
       FROM {p}run_log GROUP BY market") as $r) {
    $laeufe[$r['market']] = $r;
}
$sieben = [];
foreach (db()->query(
    "SELECT market, SUM(pss_writes) AS writes, SUM(errors) AS errors,
            SUM(write_errors) AS schreibfehler, SUM(anomalies) AS anomalien
       FROM {p}run_log WHERE run_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY market") as $r) {
    $sieben[$r['market']] = $r;
}

// --- Zustand je Markt bestimmen, Handlungen sammeln -------------------------
$zustaende = [];
$handlungen = [];
$einrichtung = [];
$gesund = 0;
$aktiveMaerkte = \array_filter($maerkte, static fn($m) => (bool) ($m['active'] ?? false));

foreach ($aktiveMaerkte as $code => $m) {
    $lauf = $laeufe[$code] ?? [];
    $k    = $kennzahlen[$code] ?? [];
    // Anlauf: Tage seit dem ersten erfolgreichen Lauf. Das ist die Uhr des MARKTES;
    // ein neu aufgenommener Artikel hat immer seine eigene, deshalb steht die
    // Artikelzahl mit unvollständigem Fenster daneben als eigene Spalte.
    $anlauf = null;
    if (!empty($lauf['erster_lauf'])) {
        $tage = (int) ((new DateTimeImmutable($lauf['erster_lauf']))
            ->diff(new DateTimeImmutable('today'))->days) + 1;
        if ($tage <= $fensterTage) {
            $anlauf = ['tag' => $tage, 'ziel' => $fensterTage];
        }
    }
    $z = markt_zustand($lauf, $k, $m, $anlauf);
    $z['anlauf'] = $anlauf;
    $zustaende[$code] = $z;

    if ($z['code'] === 'gesund') { $gesund++; }

    if ($z['code'] === 'einrichtung') {
        // Bewusst NICHT je Markt eine eigene Zeile: Sieben gleichlautende Punkte wären
        // wieder Tapete, nur in anderer Farbe. Gesammelt wird unten zu EINEM Eintrag.
        $einrichtung[] = $code;
    } elseif ($z['code'] === 'vorfall') {
        $handlungen[] = ['stufe' => 'vorfall', 'wort' => 'Vorfall',
            'was' => "<b>Markt {$code}: {$z['wort']}</b> — {$z['detail']}. Die Preisstände "
                   . 'des letzten erfolgreichen Laufs gelten fort; bereits berechnete '
                   . 'Referenzwerte bleiben gültig.',
            'tu' => '<span class="mono">bin/run.php --market ' . h($code) . '</span>'];
    }
    $risiko = (int) ($k['risiko'] ?? 0);
    if ($risiko > 0) {
        $handlungen[] = ['stufe' => 'warn', 'wort' => 'Prüfen',
            'was' => "<b>{$risiko} Artikel weisen in {$code} eine Ermäßigung auf unvollständiger "
                   . 'Historie aus</b> — die Referenz beruht auf „seit Angebotsbeginn", nicht auf '
                   . 'vollen 30 Tagen.',
            'tu' => '<a href="artikel.php?markt=' . h($code) . '&amp;filter=risiko">Artikel ansehen</a>'];
    }
}

// Die Einrichtung kommt als EIN Punkt, mit Namen statt Zahl — und mit dem konkreten
// nächsten Schritt: Ohne Shop-Kennung nützt der beste Cron nichts.
if ($einrichtung !== []) {
    $ohneKennung = \array_values(\array_filter($einrichtung,
        static fn(string $c) => (($maerkte[$c]['shop'] ?? 'TODO') === 'TODO')));
    $handlungen[] = ['stufe' => 'einrichtung', 'wort' => 'Einrichtung',
        'was' => \sprintf('<b>%d von %d Märkten sind noch nicht in Betrieb</b> (%s) — '
               . 'es liegt kein erfolgreicher Lauf vor.%s',
            \count($einrichtung), \count($aktiveMaerkte), \implode(', ', $einrichtung),
            $ohneKennung !== []
                ? ' Zuerst fehlt die Shop-Kennung in <code>markets.yml</code> für '
                  . \implode(', ', $ohneKennung) . '.'
                : ' Cron einrichten, dann Erstlauf.'),
        'tu' => '<span class="mono">bin/run.php --market ' . h($einrichtung[0]) . '</span>'];
}

$summe = static function (string $feld) use ($kennzahlen): int {
    $n = 0;
    foreach ($kennzahlen as $r) { $n += (int) ($r[$feld] ?? 0); }
    return $n;
};
// Einrichtung färbt die Plakette nicht ein: Ein System, das noch aufgebaut wird,
// ist nicht gestört. Nur Vorfälle und Prüfpunkte heben die Stufe.
$stufe = 'okstufe';
foreach ($handlungen as $hd) {
    if ($hd['stufe'] === 'vorfall') { $stufe = 'vorfallstufe'; break; }
    if ($hd['stufe'] === 'warn')    { $stufe = 'warnstufe'; }
}
$echte = \count(\array_filter($handlungen, static fn($x) => $x['stufe'] !== 'einrichtung'));
$satz = match (true) {
    $echte === 0 && $einrichtung !== [] => 'Aufbau läuft — noch nichts zu beanstanden.',
    $echte === 0                        => 'Betrieb läuft — nichts zu tun.',
    default => \sprintf('%s — %d %s %s heute einen Blick.',
        $stufe === 'vorfallstufe' ? 'Betrieb gestört' : 'Betrieb läuft',
        $echte, $echte === 1 ? 'Punkt' : 'Punkte', $echte === 1 ? 'braucht' : 'brauchen'),
};

seitenkopf('Übersicht', 'index');
?>
<section aria-label="Systemzustand">
  <div class="plakette <?= h($stufe) ?>">
    <span class="punkt" aria-hidden="true"></span>
    <div>
      <h1><?= h($satz) ?></h1>
      <p><?= \count($aktiveMaerkte) - \count($einrichtung) ?> von
         <?= \count($aktiveMaerkte) ?> Märkten in Betrieb ·
         <?= zahl($summe('artikel')) ?> Artikel getrackt ·
         <?= $trocken
            ? 'der letzte Lauf hat nicht geschrieben, der PSS bleibt unverändert'
            : '<b>scharf</b> — der letzte Lauf hat Schreibsätze an den PSS übertragen' ?>.</p>
    </div>
  </div>

  <?php if ($handlungen !== []): ?>
  <ul class="handeln">
    <?php foreach ($handlungen as $hd): ?>
    <li>
      <span class="stufe <?= h($hd['stufe']) ?>"><?= h($hd['wort']) ?></span>
      <span class="was"><?= $hd['was'] ?></span>
      <span class="tu"><?= $hd['tu'] ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<h2>Kennzahlen</h2>
<div class="kpi">
  <div class="risiko<?= $summe('risiko') === 0 ? ' leer' : '' ?>">
    <span class="label">Risiko</span>
    <b><?= zahl($summe('risiko')) ?></b>
    <span class="def">Ermäßigung ausgewiesen, <?= $fensterTage ?>-Tage-Historie noch lückenhaft</span>
  </div>
  <div>
    <span class="label">In Aktion</span>
    <b><?= zahl($summe('in_aktion')) ?></b>
    <span class="def">Artikel im Zustand „Aktion" — Referenz eingefroren</span>
  </div>
  <div>
    <span class="label">Fenster unvollständig</span>
    <b><?= zahl($summe('unvollstaendig')) ?></b>
    <span class="def">Neuartikel und Anlaufphase — die Referenz reift noch</span>
  </div>
  <div>
    <span class="label">Getrackt</span>
    <b><?= zahl($summe('artikel')) ?></b>
    <span class="def">Artikel × Märkte mit täglicher Preiserfassung</span>
  </div>
  <div>
    <span class="label">Mit Referenz im PSS</span>
    <b><?= zahl($summe('geschrieben')) ?></b>
    <span class="def">Schreibziel 30_NET / 30_GROSS<?= $trocken
      ? ' — der letzte Lauf hat nichts übertragen' : '' ?></span>
  </div>
</div>

<h2>Märkte</h2>
<div class="tabelle">
<table>
<thead>
<tr><th>Markt</th><th>Status</th><th>Schreiben</th><th class="zahl">Artikel</th>
    <th class="zahl">in Aktion</th><th class="zahl">Risiko</th>
    <th class="zahl">Fenster<br>unvollst.</th><th>letzter Lauf</th>
    <th class="zahl">Schreibsätze<br>7 Tage</th><th class="zahl">Fehler<br>7 Tage</th></tr>
</thead>
<tbody>
<?php foreach ($aktiveMaerkte as $code => $m):
    $k = $kennzahlen[$code] ?? [];
    $z = $zustaende[$code];
    $lauf = $laeufe[$code] ?? [];
    $w = $sieben[$code] ?? [];
    $artikel = (int) ($k['artikel'] ?? 0);
    $aktion  = (int) ($k['in_aktion'] ?? 0);
    $risiko  = (int) ($k['risiko'] ?? 0);
?>
<!-- Ganze Zeile klickbar (siehe .zeilenlink im Stil): ein echter Link in der ersten
     Zelle, per ::after ueber die Zeile gespannt. Bleibt fokussierbar und in neuem Tab zu
     oeffnen — ein onclick auf <tr> koennte beides nicht. Die beiden Zahlen mit EIGENEM
     Ziel (in Aktion, Risiko) liegen darueber und behalten ihren Link. -->
<tr class="klickbar">
  <td><a href="artikel.php?markt=<?= h($code) ?>" class="zeilenlink"
         title="Alle Artikel in <?= h($code) ?> ansehen"><b><?= h($code) ?></b></a>
      <span class="sub"><?= h((string) ($m['shop'] ?? '—')) ?> ·
      <?= h((string) ($m['currency'] ?? '')) ?></span></td>
  <td>
    <span class="status <?= h($z['klasse']) ?>"><?= h($z['wort']) ?></span>
    <?php if (($z['anlauf'] ?? null) !== null): ?>
    <span class="anlauf"><small><?= h($z['detail']) ?></small>
      <span class="balken" role="progressbar" aria-valuenow="<?= (int) $z['anlauf']['tag'] ?>"
            aria-valuemin="0" aria-valuemax="<?= (int) $z['anlauf']['ziel'] ?>"
            aria-label="Anlaufphase"><i style="width:<?= (int) \round(
              100 * $z['anlauf']['tag'] / \max(1, $z['anlauf']['ziel'])) ?>%"></i></span></span>
    <?php endif; ?>
  </td>
  <td><?php if ($m['write_enabled'] ?? false): ?>
        <span class="status ok">aktiv</span>
      <?php else: ?>
        <span class="status aus">nur Beobachtung</span>
      <?php endif; ?></td>
  <td class="zahl"><?= zahl($artikel) ?></td>
  <td class="zahl"><a href="artikel.php?markt=<?= h($code) ?>&amp;filter=aktion"><?= zahl($aktion) ?></a>
      <span class="sub"><?= $artikel ? \number_format(100 * $aktion / $artikel, 1, ',', '.') . ' %' : '—' ?></span></td>
  <td class="zahl"><?= $risiko > 0
        ? '<a href="artikel.php?markt=' . h($code) . '&amp;filter=risiko" style="color:var(--vorfall);font-weight:700">' . zahl($risiko) . '</a>'
        : '0' ?></td>
  <td class="zahl"><?= zahl((int) ($k['unvollstaendig'] ?? 0)) ?></td>
  <td class="mono"><?= !empty($lauf['zuletzt_ok'])
        ? h(\date('d.m.Y H:i', \strtotime((string) $lauf['zuletzt_ok'])))
        : '—' ?><span class="sub"><?= h($z['detail']) ?></span></td>
  <td class="zahl"><?= zahl((int) ($w['writes'] ?? 0)) ?><?php
      $mm = $modi[$code] ?? 'unbekannt';
      if ($mm === 'gesperrt') { echo ' <span class="sub">nur Beobachtung</span>'; }
      elseif ($mm === 'trocken') { echo ' <span class="sub">trocken</span>'; }
      elseif ($mm === 'unbekannt') { echo ' <span class="sub">Modus unbekannt</span>'; }
      if ((int) ($w['schreibfehler'] ?? 0) > 0) {
          echo ' <span class="sub" style="color:var(--vorfall)">'
             . zahl((int) $w['schreibfehler']) . ' Fehler</span>';
      } ?></td>
  <td class="zahl<?= (int) ($w['errors'] ?? 0) > 0 ? ' ' : '' ?>"
      <?= (int) ($w['errors'] ?? 0) > 0 ? 'style="color:var(--vorfall);font-weight:700"' : '' ?>>
      <?= zahl((int) ($w['errors'] ?? 0)) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<dl class="fussnote">
  <dt>Risiko</dt><dd>Artikel, die gerade eine Ermäßigung ausweisen, deren
    <?= $fensterTage ?>-Tage-Historie aber noch Lücken hat (Neuartikel, Anlauf).</dd>
  <dt>Anlauf</dt><dd>Tage seit dem ersten erfolgreichen Lauf dieses Marktes. Ein später
    aufgenommener Artikel hat seine eigene Uhr — die Spalte „Fenster unvollständig" zählt
    diese Artikel unabhängig davon.</dd>
  <dt>Nur Beobachtung (CH)</dt><dd>Preise werden erfasst und belegt, aber nicht
    geschrieben — die Schweizer Preisbekanntgabeverordnung folgt eigenen Regeln; die
    Freigabe durch Legal steht aus.</dd>
  <dt>Schreibmodus</dt><dd>Steht je Lauf in der Zeile, nicht in der Konfiguration:
    <code>scharf</code> = übertragen, <code>trocken</code> = nur gerechnet,
    <code>gesperrt</code> = <code>write_enabled</code> aus (CH),
    <code>unbekannt</code> = Läufe vor dem 19.08.2026, für die der Modus nicht
    festgehalten wurde.</dd>
</dl>

<h2>Letzte Läufe</h2>
<div class="tabelle">
<table>
<thead><tr><th>Zeitpunkt</th><th>Markt</th><th>Status</th><th class="zahl">gelesen</th>
  <th class="zahl">Änderungen</th><th class="zahl">Schreibsätze</th>
  <th class="zahl">Anomalien</th><th>Dauer</th><th>Notiz</th></tr></thead>
<tbody>
<?php foreach (db()->query('SELECT * FROM {p}run_log ORDER BY id DESC LIMIT 15') as $r):
    $klasse = match ($r['status']) {
        'ok' => 'ok', 'partial' => 'warn', 'laeuft' => 'laeuft', default => 'vorfall' };
    $wort = match ($r['status']) {
        'ok' => 'ok', 'partial' => 'mit Fehlern', 'laeuft' => 'läuft', default => 'fehlgeschlagen' };
    $dauer = ($r['started_at'] && $r['finished_at'])
        ? \sprintf('%d:%02d min', (int) ((\strtotime($r['finished_at']) - \strtotime($r['started_at'])) / 60),
                   (\strtotime($r['finished_at']) - \strtotime($r['started_at'])) % 60)
        : '—';
?>
<tr>
  <td class="mono"><?= h(\date('d.m. H:i', \strtotime((string) $r['started_at']))) ?></td>
  <td><?= h($r['market']) ?></td>
  <td><span class="status <?= $klasse ?>"><?= h($wort) ?></span></td>
  <td class="zahl"><?= zahl((int) $r['items_fetched']) ?></td>
  <td class="zahl"><?= zahl((int) $r['price_changes']) ?></td>
  <td class="zahl"><?= zahl((int) $r['pss_writes']) ?><?php
      if (($r['write_mode'] ?? 'unbekannt') !== 'scharf') {
          echo ' <span class="sub">' . h(match ($r['write_mode'] ?? 'unbekannt') {
              'gesperrt' => 'gesperrt', 'trocken' => 'trocken', default => 'Modus unbekannt',
          }) . '</span>';
      } elseif ((int) ($r['write_errors'] ?? 0) > 0) {
          echo ' <span class="sub" style="color:var(--vorfall)">'
             . zahl((int) $r['write_errors']) . ' Fehler</span>';
      } ?></td>
  <td class="zahl"><?= zahl((int) $r['anomalies']) ?></td>
  <td class="mono"><?= h($dauer) ?></td>
  <td><?= h(\mb_substr((string) $r['note'], 0, 120)) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</main></body></html>
