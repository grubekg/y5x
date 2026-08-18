<?php
declare(strict_types=1);

/**
 * Einladung einlösen — die einzige Seite ohne Anmeldung.
 *
 * Der Eingeladene vergibt sein Passwort **selbst**. Niemand sonst hat es je gesehen,
 * auch keine Administration — nur so bleibt das `login_log` als Nachweis belastbar,
 * wer mit diesem Werkzeug gearbeitet hat.
 *
 * Die Gestaltung kommt aus derselben Hülle wie die Anmeldemaske
 * ({@see zugangsseite}): Zwei Kopien einer Gestaltung driften immer.
 */
require __DIR__ . '/lib.php';

use Grube\Price30\Support\Einladung;

$schluessel = \trim((string) ($_GET['k'] ?? $_POST['k'] ?? ''));
$einladung  = $schluessel !== '' ? Einladung::pruefen(db(), $schluessel) : null;
$fehler = '';
$fertig = false;

if ($einladung !== null && ($_POST['aktion'] ?? '') === 'setzen') {
    $pw  = (string) ($_POST['pw'] ?? '');
    $pw2 = (string) ($_POST['pw2'] ?? '');
    if (\mb_strlen($pw) < Einladung::PASSWORT_MINDESTLAENGE) {
        $fehler = 'Das Passwort braucht mindestens ' . Einladung::PASSWORT_MINDESTLAENGE . ' Zeichen.';
    } elseif ($pw !== $pw2) {
        $fehler = 'Die beiden Eingaben stimmen nicht überein.';
    } else {
        Einladung::einloesen(db(), $einladung, $pw);
        db()->execute('INSERT INTO {p}login_log (username, ip, erfolg, versucht_at) VALUES (?,?,1,NOW())',
            [$einladung['email'], (string) ($_SERVER['REMOTE_ADDR'] ?? '-')]);
        $fertig = true;
    }
}

\ob_start();

if ($fertig) { ?>
  <p>Ihr Passwort ist gesetzt. Es kennt niemand außer Ihnen — auch keine Administration.</p>
  <a class="knopf" style="display:block;text-align:center;text-decoration:none;box-sizing:border-box"
     href="index.php">Zur Anmeldung</a>
<?php } elseif ($einladung === null) { ?>
  <p>Der Link ist abgelaufen, wurde bereits verwendet oder zurückgezogen. Einladungen
     gelten <?= Einladung::GUELTIG_TAGE ?> Tage und lassen sich nur einmal einlösen.</p>
  <p>Aus Sicherheitsgründen sagt diese Seite nicht, welcher der drei Gründe zutrifft.</p>
<?php } else { ?>
  <p>Zugang für <b class="mono"><?= h($einladung['email']) ?></b>
     (<?= $einladung['role'] === 'admin' ? 'Administration' : 'Benutzer' ?>),
     eingerichtet von <?= h($einladung['created_by']) ?>.</p>
  <form method="post">
    <input type="hidden" name="aktion" value="setzen">
    <input type="hidden" name="k" value="<?= h($schluessel) ?>">
    <label for="pw">Passwort (mindestens <?= Einladung::PASSWORT_MINDESTLAENGE ?> Zeichen)</label>
    <input id="pw" name="pw" type="password" required autofocus autocomplete="new-password">
    <label for="pw2">Wiederholung</label>
    <input id="pw2" name="pw2" type="password" required autocomplete="new-password">
    <button class="knopf" type="submit">Passwort setzen</button>
  </form>
<?php }

$titel = match (true) {
    $fertig                => 'Zugang eingerichtet',
    $einladung === null    => 'Einladung nicht gültig',
    default                => 'Passwort vergeben',
};
$hilfe = $einladung !== null && !$fertig
    ? 'Ihr Passwort kennt danach niemand außer Ihnen. Es gibt bewusst keinen '
      . 'Selbstservice zum Zurücksetzen — bei einem Werkzeug mit Beweisfunktion soll '
      . 'nachvollziehbar bleiben, wer wem Zugang verschafft hat.'
    : ($einladung === null && !$fertig
        ? 'Bitte um eine neue Einladung bitten — '
          . '<a href="mailto:ecommerce@grube.de">kurze Nachricht genügt</a>.'
        : '');

zugangsseite($titel, (string) \ob_get_clean(), $fehler, $hilfe);
