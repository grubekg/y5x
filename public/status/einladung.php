<?php
declare(strict_types=1);

/**
 * Einladung einlösen — die einzige Seite ohne Anmeldung.
 *
 * Der Eingeladene vergibt sein Passwort **selbst**. Niemand sonst hat es je gesehen,
 * auch keine Administration — nur so bleibt das `login_log` als Nachweis belastbar,
 * wer mit diesem Werkzeug gearbeitet hat.
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

?><!doctype html><html lang="de"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bestpreis-Tracker — Einladung</title>
<?php y5x_stil(); ?>
<style>
 html,body{height:100%}
 body{display:grid;place-items:center;padding:1.2rem;font-size:15px}
 .buehne{width:min(26rem,100%)}
 .marke-gross b{font-size:1.15rem}
 .marke-gross small{display:block;color:var(--neutral);font-size:.72rem;
   letter-spacing:.16em;text-transform:uppercase;margin-top:.15rem}
 .anmeldung{background:var(--karte);border:1px solid var(--linie);border-radius:10px;
   padding:1.3rem 1.4rem 1.5rem;margin-top:.9rem}
 .anmeldung label{display:block;font-size:.78rem;color:var(--neutral);font-weight:600;
   letter-spacing:.04em;margin:0 0 .25rem}
 .anmeldung input{width:100%;padding:.6rem .65rem;border:1px solid var(--linie-stark);
   border-radius:7px;font:inherit;margin-bottom:.9rem}
 .anmeldung .knopf{width:100%;padding:.65rem;font-size:.95rem}
</style>
</head><body>
<div class="buehne">
  <p class="marke-gross"><b>Bestpreis-Tracker</b>
    <small>Preisnachweis · § 11 PAngV · GRUBE KG</small></p>

  <?php if ($fertig): ?>
  <div class="anmeldung">
    <h1 style="font-size:1.02rem;margin:0 0 .5rem">Zugang eingerichtet</h1>
    <p>Ihr Passwort ist gesetzt. Es kennt niemand außer Ihnen — auch keine Administration.</p>
    <p><a class="knopf" style="display:block;text-align:center;text-decoration:none"
          href="index.php">Zur Anmeldung</a></p>
  </div>

  <?php elseif ($einladung === null): ?>
  <div class="anmeldung">
    <h1 style="font-size:1.02rem;margin:0 0 .5rem">Einladung nicht gültig</h1>
    <p>Der Link ist abgelaufen, wurde bereits verwendet oder zurückgezogen.
       Einladungen gelten <?= Einladung::GUELTIG_TAGE ?> Tage und lassen sich nur einmal
       einlösen.</p>
    <p class="fussnote">Bitte um eine neue Einladung bitten — aus Sicherheitsgründen sagt
       diese Seite nicht, welcher der Gründe zutrifft.</p>
  </div>

  <?php else: ?>
  <div class="anmeldung">
    <h1 style="font-size:1.02rem;margin:0 0 .3rem">Passwort vergeben</h1>
    <p style="color:var(--neutral);font-size:.9rem;margin:0 0 1rem">
      Zugang für <b class="mono"><?= h($einladung['email']) ?></b>
      (<?= $einladung['role'] === 'admin' ? 'Administration' : 'Benutzer' ?>),
      eingerichtet von <?= h($einladung['created_by']) ?>.</p>
    <?php if ($fehler !== ''): ?>
      <div class="hinweiskasten" role="alert"><b>Nicht möglich.</b> <?= h($fehler) ?></div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="aktion" value="setzen">
      <input type="hidden" name="k" value="<?= h($schluessel) ?>">
      <label for="pw">Passwort (mindestens <?= Einladung::PASSWORT_MINDESTLAENGE ?> Zeichen)</label>
      <input id="pw" name="pw" type="password" required autofocus autocomplete="new-password">
      <label for="pw2">Wiederholung</label>
      <input id="pw2" name="pw2" type="password" required autocomplete="new-password">
      <button class="knopf" type="submit">Passwort setzen</button>
    </form>
  </div>
  <?php endif; ?>

  <p class="fussnote" style="margin-top:1rem">Umgebung: <?= h(y5x_env()) ?> ·
     Anmeldungen und Einladungen werden protokolliert.</p>
</div>
</body></html>
