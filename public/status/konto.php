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

use Grube\Price30\Support\Einladung;

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

// --- Ab hier nur Administration -------------------------------------------
// Serverseitig geprueft, nicht bloss in der Anzeige versteckt: Ein Formular, das man
// nicht sieht, kann man trotzdem abschicken.
$verwaltung = ist_admin();
$neuerLink = null;

if (($_POST['aktion'] ?? '') === 'einladen' && $verwaltung) {
    $mail  = \strtolower(\trim((string) ($_POST['mail'] ?? '')));
    $rolle = ($_POST['rolle'] ?? 'user') === 'admin' ? 'admin' : 'user';
    if (!\filter_var($mail, \FILTER_VALIDATE_EMAIL)) {
        $fehler = 'Keine gültige E-Mail-Adresse.';
    } elseif (db()->one('SELECT id FROM {p}users WHERE username = ?', [$mail]) !== null) {
        $fehler = 'Für diese Adresse gibt es bereits einen Zugang.';
    } else {
        $e = Einladung::anlegen(db(), $mail, $rolle, current_user());
        $basis = (\str_contains((string) ($_SERVER['HTTP_HOST'] ?? ''), 'grube.tools') ? 'https://' : 'http://')
               . ($_SERVER['HTTP_HOST'] ?? 'grube.tools')
               . \rtrim(\dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $neuerLink = Einladung::link($basis, $e['id'], $e['token']);
        $versandt = Einladung::versenden($mail, $neuerLink, current_user(), y5x_env());
        db()->execute('UPDATE {p}invitations SET mail_sent = ? WHERE id = ?', [$versandt ? 1 : 0, $e['id']]);
        $meldung = $versandt
            ? "Einladung an $mail verschickt."
            : "Einladung für $mail angelegt — der Mailversand hat nicht geklappt, "
              . 'bitte den Link unten weitergeben.';
    }
}

if (($_POST['aktion'] ?? '') === 'zurueckziehen' && $verwaltung) {
    $n = db()->execute('UPDATE {p}invitations SET revoked_at = NOW()
                        WHERE id = ? AND used_at IS NULL AND revoked_at IS NULL',
        [(int) ($_POST['id'] ?? 0)]);
    $meldung = $n > 0 ? 'Einladung zurückgezogen.' : 'Diese Einladung war schon erledigt.';
}

if (($_POST['aktion'] ?? '') === 'rolle' && $verwaltung) {
    $mail  = \strtolower(\trim((string) ($_POST['mail'] ?? '')));
    $rolle = ($_POST['rolle'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $admins = (int) db()->one("SELECT COUNT(*) AS n FROM {p}users WHERE role = 'admin'")['n'];
    if ($rolle === 'user' && $admins <= 1
        && db()->one('SELECT role FROM {p}users WHERE username = ?', [$mail])['role'] === 'admin') {
        // Sonst sperrt sich die Installation aus und niemand kann mehr Zugänge vergeben.
        $fehler = 'Das ist die letzte Administration — die Rolle lässt sich nicht entziehen.';
    } else {
        db()->execute('UPDATE {p}users SET role = ? WHERE username = ?', [$rolle, $mail]);
        $meldung = "Rolle von $mail auf " . ($rolle === 'admin' ? 'Administration' : 'Benutzer') . ' gesetzt.';
    }
}

if (($_POST['aktion'] ?? '') === 'entfernen' && $verwaltung) {
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

<?php if ($neuerLink !== null): ?>
<div class="hinweiskasten ruhig">
  <b>Einladungslink</b> — gilt <?= Einladung::GUELTIG_TAGE ?> Tage und lässt sich nur einmal
  einlösen. Er wird <b>nur jetzt</b> angezeigt; danach steht er nirgends mehr im Klartext,
  auch nicht in der Datenbank.<br>
  <code style="word-break:break-all"><?= h($neuerLink) ?></code>
</div>
<?php endif; ?>

<?php if ($verwaltung): ?>
<h2>Kollegen einladen</h2>
<div class="karte" style="max-width:30rem">
  <form method="post">
    <input type="hidden" name="aktion" value="einladen">
    <label for="e-mail">E-Mail-Adresse</label>
    <input id="e-mail" name="mail" type="email" required class="mono"
           style="width:100%;padding:.5rem;border:1px solid var(--linie-stark);border-radius:6px;margin-bottom:.6rem">
    <label for="e-rolle">Rolle</label>
    <select id="e-rolle" name="rolle"
            style="width:100%;padding:.5rem;border:1px solid var(--linie-stark);border-radius:6px;margin-bottom:.8rem">
      <option value="user">Benutzer — sieht alles, verwaltet nur den eigenen Zugang</option>
      <option value="admin">Administration — darf zusätzlich Zugänge vergeben</option>
    </select>
    <button class="knopf" type="submit">Einladung verschicken</button>
  </form>
  <p class="fussnote">Der Eingeladene vergibt sein Passwort <b>selbst</b>. Es gibt bewusst
    keine Startpasswörter: Wer eines vergibt, kennt es — und dann belegt das
    Anmeldeprotokoll nicht mehr zuverlässig, wer gearbeitet hat.</p>
</div>

<?php $offen = db()->query(
  'SELECT * FROM {p}invitations WHERE used_at IS NULL AND revoked_at IS NULL
     AND expires_at > NOW() ORDER BY id DESC'); ?>
<?php if ($offen !== []): ?>
<h2>Offene Einladungen</h2>
<div class="tabelle">
<table>
<thead><tr><th>E-Mail-Adresse</th><th>Rolle</th><th>eingeladen von</th><th>gültig bis</th>
  <th>Mail</th><th></th></tr></thead>
<tbody>
<?php foreach ($offen as $e): ?>
<tr>
  <td class="mono"><?= h($e['email']) ?></td>
  <td><?= $e['role'] === 'admin' ? 'Administration' : 'Benutzer' ?></td>
  <td class="mono"><?= h($e['created_by']) ?></td>
  <td class="mono"><?= h(\date('d.m.Y H:i', \strtotime($e['expires_at']))) ?></td>
  <td><?= $e['mail_sent']
        ? '<span class="status ok">verschickt</span>'
        : '<span class="status warn">nicht zugestellt</span>' ?></td>
  <td><form method="post" style="display:inline">
      <input type="hidden" name="aktion" value="zurueckziehen">
      <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
      <button class="knopf sekundaer" type="submit">zurückziehen</button></form></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<h2>Zugänge</h2>
<div class="tabelle">
<table>
<thead><tr><th>E-Mail-Adresse</th><th>Rolle</th><th>angelegt</th><th>zuletzt angemeldet</th>
  <th class="zahl">Fehlversuche 24 h</th><th></th></tr></thead>
<tbody>
<?php foreach (db()->query('SELECT u.*,
    (SELECT COUNT(*) FROM {p}login_log l WHERE l.username = u.username AND l.erfolg = 0
       AND l.versucht_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS fehl
    FROM {p}users u ORDER BY u.role, u.username') as $u): ?>
<tr>
  <td class="mono"><?= h($u['username']) ?><?= $u['username'] === current_user()
      ? ' <span class="status ok">Sie</span>' : '' ?></td>
  <td>
    <form method="post" style="display:flex;gap:.3rem;align-items:center">
      <input type="hidden" name="aktion" value="rolle">
      <input type="hidden" name="mail" value="<?= h($u['username']) ?>">
      <select name="rolle" onchange="this.form.submit()"
              style="padding:.2rem .35rem;border:1px solid var(--linie-stark);border-radius:5px">
        <option value="user"<?= $u['role'] === 'user' ? ' selected' : '' ?>>Benutzer</option>
        <option value="admin"<?= $u['role'] === 'admin' ? ' selected' : '' ?>>Administration</option>
      </select>
      <noscript><button class="knopf sekundaer" type="submit">setzen</button></noscript>
    </form>
  </td>
  <td class="mono"><?= $u['created_at'] ? h(\date('d.m.Y', \strtotime($u['created_at']))) : '—' ?></td>
  <td class="mono"><?= $u['last_login'] ? h(\date('d.m.Y H:i', \strtotime($u['last_login']))) : 'nie' ?></td>
  <td class="zahl" <?= (int) $u['fehl'] >= 5 ? 'style="color:var(--vorfall);font-weight:700"' : '' ?>><?= (int) $u['fehl'] ?></td>
  <td><?php if ($u['username'] !== current_user()): ?>
      <form method="post" style="display:inline"
            onsubmit="return confirm('Zugang <?= h($u['username']) ?> wirklich entfernen?')">
        <input type="hidden" name="aktion" value="entfernen">
        <input type="hidden" name="mail" value="<?= h($u['username']) ?>">
        <button class="knopf sekundaer" type="submit">entfernen</button>
      </form><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p class="fussnote">Die letzte Administration lässt sich nicht herabstufen — sonst könnte
  niemand mehr Zugänge vergeben.</p>
<?php else: ?>
<h2>Zugänge</h2>
<p class="hinweiskasten ruhig"><b>Zugangsverwaltung liegt bei der Administration.</b>
  Sie können hier Ihr eigenes Passwort ändern. Für einen neuen Kollegen wenden Sie sich
  an eine Person mit Administrationsrolle — sie verschickt eine Einladung.</p>
<?php endif; ?>

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

<?php if ($verwaltung): ?>
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
<?php endif; ?>
</main></body></html>
