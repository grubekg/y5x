<?php
declare(strict_types=1);

/**
 * Konto und Zugänge.
 *
 * **Was hier bewusst NICHT geht: Preisdaten ändern.** `price_events` ist die
 * Beweisgrundlage nach § 11 PAngV — eine Oberfläche, über die sich Preisintervalle
 * bearbeiten ließen, würde genau die Eigenschaft zerstören, für die es das Werkzeug
 * gibt. Wer nachträglich einen Preis ändern kann, kann jeden Nachweis erfinden. Auch
 * Korrekturen laufen deshalb über einen dokumentierten Lauf, nicht über ein Formular.
 *
 * Änderbar ist, was Bedienung betrifft: das eigene Passwort und die Zugänge.
 */
require __DIR__ . '/lib.php';
require_login();

$meldung = '';
$fehler  = '';

if (($_POST['aktion'] ?? '') === 'passwort') {
    $alt  = (string) ($_POST['alt'] ?? '');
    $neu  = (string) ($_POST['neu'] ?? '');
    $neu2 = (string) ($_POST['neu2'] ?? '');
    $row  = db()->one('SELECT * FROM {p}users WHERE username = ?', [current_user()]);
    if (!$row || !\password_verify($alt, $row['password_hash'])) {
        $fehler = 'Das bisherige Passwort stimmt nicht.';
    } elseif (\mb_strlen($neu) < 12) {
        $fehler = 'Das neue Passwort braucht mindestens 12 Zeichen.';
    } elseif ($neu !== $neu2) {
        $fehler = 'Die beiden neuen Passwörter stimmen nicht überein.';
    } else {
        db()->execute('UPDATE {p}users SET password_hash = ? WHERE id = ?',
            [\password_hash($neu, \PASSWORD_DEFAULT), $row['id']]);
        $meldung = 'Passwort geändert.';
    }
}

if (($_POST['aktion'] ?? '') === 'anlegen') {
    $mail = \strtolower(\trim((string) ($_POST['mail'] ?? '')));
    $pw   = (string) ($_POST['pw'] ?? '');
    if (!\filter_var($mail, \FILTER_VALIDATE_EMAIL)) {
        $fehler = 'Keine gültige E-Mail-Adresse.';
    } elseif (\mb_strlen($pw) < 12) {
        $fehler = 'Das Passwort braucht mindestens 12 Zeichen.';
    } else {
        db()->execute('INSERT INTO {p}users (username, password_hash, created_at)
                       VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)',
            [$mail, \password_hash($pw, \PASSWORD_DEFAULT)]);
        $meldung = "Zugang für $mail gespeichert.";
    }
}

if (($_POST['aktion'] ?? '') === 'entfernen') {
    $mail = \strtolower(\trim((string) ($_POST['mail'] ?? '')));
    if ($mail === current_user()) {
        $fehler = 'Den eigenen Zugang kann man nicht entfernen.';
    } else {
        $n = db()->execute('DELETE FROM {p}users WHERE username = ?', [$mail]);
        $meldung = $n > 0 ? "Zugang $mail entfernt." : 'Kein solcher Zugang.';
    }
}

seitenkopf('Konto', 'konto');
?>
<?php if ($meldung !== ''): ?>
  <p class="hinweiskasten ruhig"><b>Erledigt.</b> <?= h($meldung) ?></p>
<?php endif; ?>
<?php if ($fehler !== ''): ?>
  <p class="hinweiskasten"><b>Nicht möglich.</b> <?= h($fehler) ?></p>
<?php endif; ?>

<h2>Eigenes Passwort ändern</h2>
<div class="karte" style="max-width:26rem">
  <form method="post">
    <input type="hidden" name="aktion" value="passwort">
    <label for="p-alt">bisheriges Passwort</label>
    <input id="p-alt" name="alt" type="password" required autocomplete="current-password"
           style="width:100%;padding:.5rem;border:1px solid var(--linie-stark);border-radius:6px;margin-bottom:.6rem">
    <label for="p-neu">neues Passwort (mindestens 12 Zeichen)</label>
    <input id="p-neu" name="neu" type="password" required autocomplete="new-password"
           style="width:100%;padding:.5rem;border:1px solid var(--linie-stark);border-radius:6px;margin-bottom:.6rem">
    <label for="p-neu2">Wiederholung</label>
    <input id="p-neu2" name="neu2" type="password" required autocomplete="new-password"
           style="width:100%;padding:.5rem;border:1px solid var(--linie-stark);border-radius:6px;margin-bottom:.8rem">
    <button class="knopf" type="submit">Passwort ändern</button>
  </form>
</div>

<h2>Zugänge</h2>
<div class="tabelle">
<table>
<thead><tr><th>E-Mail-Adresse</th><th>angelegt</th><th>zuletzt angemeldet</th>
  <th class="zahl">Fehlversuche 24 h</th><th></th></tr></thead>
<tbody>
<?php foreach (db()->query('SELECT u.*,
    (SELECT COUNT(*) FROM {p}login_log l WHERE l.username = u.username AND l.erfolg = 0
       AND l.versucht_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS fehl
    FROM {p}users u ORDER BY u.username') as $u): ?>
<tr>
  <td class="mono"><?= h($u['username']) ?><?= $u['username'] === current_user()
      ? ' <span class="status ok">Sie</span>' : '' ?></td>
  <td class="mono"><?= $u['created_at'] ? h(\date('d.m.Y', \strtotime($u['created_at']))) : '—' ?></td>
  <td class="mono"><?= $u['last_login'] ? h(\date('d.m.Y H:i', \strtotime($u['last_login']))) : 'nie' ?></td>
  <td class="zahl<?= (int) $u['fehl'] >= 5 ? '' : '' ?>"
      <?= (int) $u['fehl'] >= 5 ? 'style="color:var(--vorfall);font-weight:700"' : '' ?>><?= (int) $u['fehl'] ?></td>
  <td><?php if ($u['username'] !== current_user()): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Zugang <?= h($u['username']) ?> wirklich entfernen?')">
        <input type="hidden" name="aktion" value="entfernen">
        <input type="hidden" name="mail" value="<?= h($u['username']) ?>">
        <button class="knopf sekundaer" type="submit">entfernen</button>
      </form><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<h2>Zugang anlegen</h2>
<div class="karte" style="max-width:26rem">
  <form method="post">
    <input type="hidden" name="aktion" value="anlegen">
    <label for="n-mail">E-Mail-Adresse</label>
    <input id="n-mail" name="mail" type="email" required class="mono"
           style="width:100%;padding:.5rem;border:1px solid var(--linie-stark);border-radius:6px;margin-bottom:.6rem">
    <label for="n-pw">Startpasswort (mindestens 12 Zeichen)</label>
    <input id="n-pw" name="pw" type="text" required class="mono"
           style="width:100%;padding:.5rem;border:1px solid var(--linie-stark);border-radius:6px;margin-bottom:.8rem">
    <button class="knopf" type="submit">Zugang anlegen</button>
  </form>
  <p class="fussnote">Es gibt bewusst keinen Selbstservice zum Zurücksetzen — bei einem
    Werkzeug mit Beweisfunktion soll nachvollziehbar bleiben, wer wem Zugang verschafft hat.</p>
</div>

<h2>Was sich hier nicht ändern lässt</h2>
<p class="hinweiskasten">
  <b>Preisdaten sind nicht bearbeitbar</b>, und das ist Absicht.
  <span class="mono">price_events</span> ist die Beweisgrundlage nach § 11 PAngV. Eine
  Oberfläche, über die sich Preisintervalle ändern ließen, würde genau die Eigenschaft
  zerstören, für die es dieses Werkzeug gibt: Wer nachträglich einen Preis ändern kann,
  kann jeden Nachweis erfinden. Korrekturen laufen über einen dokumentierten Lauf
  (<span class="mono">bin/run.php</span>) oder über <span class="mono">bin/backfill.php</span>
  — beides hinterlässt eine Spur im <span class="mono">run_log</span>.
</p>

<h2>Letzte Anmeldungen</h2>
<div class="tabelle">
<table>
<thead><tr><th>Zeitpunkt</th><th>Konto</th><th>Herkunft</th><th>Ergebnis</th></tr></thead>
<tbody>
<?php foreach (db()->query('SELECT * FROM {p}login_log ORDER BY id DESC LIMIT 20') as $l): ?>
<tr>
  <td class="mono"><?= h(\date('d.m.Y H:i', \strtotime($l['versucht_at']))) ?></td>
  <td class="mono"><?= h($l['username']) ?></td>
  <td class="mono"><?= h($l['ip']) ?></td>
  <td><span class="status <?= $l['erfolg'] ? 'ok' : 'vorfall' ?>">
      <?= $l['erfolg'] ? 'angemeldet' : 'abgelehnt' ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</main></body></html>
