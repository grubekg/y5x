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
require __DIR__ . '/stil.php';

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

/** Deutsches Zahlenformat mit schmalem Leerzeichen — Beträge sollen in Spalten stehen. */
function geld(?string $v, string $cur = 'EUR', int $stellen = 2): string
{
    if ($v === null) {
        return '—';
    }
    $zeichen = ['EUR' => '€', 'CHF' => 'CHF', 'SEK' => 'kr', 'DKK' => 'kr', 'PLN' => 'zł'];
    return \number_format((float) $v, $stellen, ',', '.') . "\u{202f}" . ($zeichen[$cur] ?? $cur);
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

/**
 * Rolle des angemeldeten Benutzers — bei jedem Aufruf frisch aus der Datenbank.
 *
 * Bewusst **nicht** in der Sitzung gehalten: Wird jemandem die Adminrolle entzogen,
 * soll das sofort greifen und nicht erst beim nächsten Anmelden. Bei einem Werkzeug,
 * dessen Protokoll etwas belegen soll, ist eine veraltete Berechtigung im Sitzungsspeicher
 * die falsche Art von Bequemlichkeit.
 */
function current_role(): string
{
    static $rolle = null;
    if ($rolle === null) {
        $z = db()->one('SELECT role FROM {p}users WHERE username = ?', [current_user()]);
        $rolle = (string) ($z['role'] ?? 'user');
    }
    return $rolle;
}

function ist_admin(): bool
{
    return current_role() === 'admin';
}

/**
 * Anmeldung.
 *
 * Drei Dinge, die bei einem Werkzeug mit Beweisfunktion nicht verhandelbar sind:
 *
 * * **Die Meldung bleibt generisch.** „E-Mail oder Passwort stimmen nicht" verrät nicht,
 *   ob das Konto existiert — sonst wird die Anmeldemaske zum Kontoverzeichnis.
 * * **Versuchssperre.** Nach fünf Fehlversuchen je Konto+IP ist 15 Minuten Ruhe, und
 *   auch das wird generisch gemeldet.
 * * **Jeder Versuch wird protokolliert**, Erfolg wie Fehlschlag, mit Zeit, Konto und IP.
 *   Ein Werkzeug, das Beweise führt, muss auch belegen können, wer es bedient hat.
 */
function require_login(): void
{
    start_session();

    if (isset($_GET['abmelden'])) {
        // Vollstaendig abraeumen, nicht nur den Benutzer aus der Sitzung nehmen:
        // Sitzungsdaten, Cookie, Sitzungs-ID.
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'],
                      $p['secure'], $p['httponly']);
        }
        session_destroy();
        header('Location: ' . strtok((string) ($_SERVER['PHP_SELF'] ?? 'index.php'), '?'));
        exit;
    }

    if (!empty($_SESSION['user'])) {
        return;
    }
    $fehler = '';
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');

    if (($_POST['action'] ?? '') === 'login') {
        $u = strtolower(trim((string) ($_POST['username'] ?? '')));
        $gesperrt = (int) (db()->one(
            'SELECT COUNT(*) AS n FROM {p}login_log
              WHERE username = ? AND ip = ? AND erfolg = 0
                AND versucht_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)',
            [$u, $ip])['n'] ?? 0);

        if ($gesperrt >= 5) {
            $fehler = 'Zu viele Versuche — bitte in 15 Minuten erneut probieren.';
        } else {
            $row = db()->one('SELECT * FROM {p}users WHERE username = ?', [$u]);
            $ok = $row && password_verify((string) ($_POST['password'] ?? ''), $row['password_hash']);
            db()->execute(
                'INSERT INTO {p}login_log (username, ip, erfolg, versucht_at) VALUES (?,?,?,NOW())',
                [$u, $ip, $ok ? 1 : 0]);
            if ($ok) {
                session_regenerate_id(true);   // Sitzungsfixierung ausschliessen
                $_SESSION['user'] = $u;
                db()->execute('UPDATE {p}users SET last_login = NOW() WHERE id = ?', [$row['id']]);
                header('Location: ?');
                exit;
            }
            $fehler = 'E-Mail-Adresse oder Passwort stimmen nicht. '
                    . 'Nach 5 Fehlversuchen wird der Zugang 15 Minuten gesperrt.';
        }
    }
    anmeldeseite($fehler);
    exit;
}

/**
 * Gemeinsame Hülle aller Seiten VOR der Anmeldung — Anmeldung wie Einladung.
 *
 * Bewusst eine Funktion und keine zwei Vorlagen: Beide Seiten hatten zuerst je eine
 * eigene Kopie desselben Aufbaus, und prompt liefen sie auseinander (Hintergrundmotiv,
 * Umgebungs-Chip und Fußzeile fehlten der Einladungsseite). Zwei Kopien einer Gestaltung
 * driften immer; die Frage ist nur, wann es jemandem auffällt.
 *
 * @param string $h1      Überschrift in der Karte
 * @param string $inhalt  fertiges HTML des Karteninhalts
 * @param string $fehler  optionaler Meldungstext (rot, role=alert)
 * @param string $hilfe   optionale Fußnote unter der Karte
 */
function zugangsseite(string $h1, string $inhalt, string $fehler = '', string $hilfe = ''): void
{
    $env = y5x_env();
    ?><!doctype html><html lang="de"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Preisschreiber — <?= h($h1) ?></title>
<?php y5x_kopfzeilen(); y5x_stil(); ?>
<style>
 html,body{height:100%}
 body{display:grid;place-items:center;padding:1.2rem;font-size:15px}
 .motiv{position:fixed;inset:0;pointer-events:none;z-index:0}
 .motiv svg{width:100%;height:100%}
 .buehne{position:relative;z-index:1;width:min(26rem,100%)}
 .marke-gross{margin:0 0 .9rem}
 .marke-gross b{font-size:1.15rem;letter-spacing:.02em}
 .marke-gross small{display:block;color:var(--neutral);font-size:.72rem;
   letter-spacing:.16em;text-transform:uppercase;margin-top:.15rem}
 .zugang{background:var(--karte);border:1px solid var(--linie);border-radius:10px;
   padding:1.3rem 1.4rem 1.5rem;box-shadow:0 1px 2px rgba(20,35,27,.06)}
 .zugang .kopfzeile{display:flex;justify-content:space-between;align-items:center;
   gap:.8rem;margin-bottom:1rem}
 .zugang h1{font-size:1.02rem;margin:0}
 .umgebung{border-radius:99px;padding:.14rem .6rem;font-size:.76rem;font-weight:700;
   white-space:nowrap}
 .umgebung.staging{background:#f2c14e;color:#241d05}
 .umgebung.prod{background:var(--tanne-hell);color:#fff}
 .fehlerkasten{background:var(--vorfall-flaeche);border:1px solid #e5b8b4;
   border-left:4px solid var(--vorfall);border-radius:8px;padding:.6rem .8rem;
   margin-bottom:1rem;font-size:.9rem}
 .fehlerkasten b{color:var(--vorfall)}
 .zugang label{display:block;font-size:.78rem;color:var(--neutral);font-weight:600;
   letter-spacing:.04em;margin:0 0 .25rem}
 .zugang input{width:100%;padding:.6rem .65rem;border:1px solid var(--linie-stark);
   border-radius:7px;background:#fff;font:inherit;margin-bottom:.9rem}
 .zugang input.mono{font-family:var(--mono)}
 .zugang .knopf{width:100%;padding:.65rem .9rem;font-size:.95rem;margin-top:.2rem}
 .zugang p{margin:.2rem 0 .9rem;color:var(--neutral);font-size:.9rem}
 .hilfe{margin-top:.9rem;font-size:.82rem;color:var(--neutral)}
 .fuss{margin-top:1rem;color:var(--neutral);font-size:.76rem;display:flex;
   justify-content:space-between;gap:1rem;flex-wrap:wrap}
 @media (prefers-reduced-motion:no-preference){
   .zugang{animation:auftauchen .28s ease-out}
   @keyframes auftauchen{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
 }
</style>
</head><body>
<!-- Das Messschrieb-Motiv der Nachweisseite als Wasserzeichen: Preistreppe, Fenster
     und Referenzlinie. Rein dekorativ, deshalb aria-hidden. -->
<div class="motiv" aria-hidden="true">
  <svg viewBox="0 0 1200 620" preserveAspectRatio="xMidYMid slice">
    <!-- Mittig im viewBox (0…1200), damit der Streifen unter der Karte steht: Bei
         preserveAspectRatio="xMidYMid slice" faellt die viewBox-Mitte (600) auf die
         Mitte des Fensters, und dort sitzt auch die Karte. Vorher stand der Streifen
         bei 470…770, seine Mitte also 20 Einheiten daneben. -->
    <rect x="450" y="0" width="300" height="620" fill="#2e5240" opacity=".05"/>
    <path d="M-20,180 H360 V330 H620 V180 H900 V430 H1220" fill="none"
          stroke="#14231b" stroke-width="3" opacity=".07" stroke-linejoin="round"/>
    <path d="M-20,196 H900 V346 H1220" fill="none" stroke="#a8231b"
          stroke-width="2" stroke-dasharray="9 7" opacity=".08"/>
  </svg>
</div>
<div class="buehne">
  <p class="marke-gross"><b>Preisschreiber</b>
    <small>Preisnachweis · § 11 PAngV · GRUBE KG</small></p>
  <div class="zugang">
    <div class="kopfzeile">
      <h1><?= h($h1) ?></h1>
      <span class="umgebung <?= $env === 'prod' ? 'prod' : 'staging' ?>"><?= h($env) ?></span>
    </div>
    <?php if ($fehler !== ''): ?>
    <div class="fehlerkasten" role="alert"><b>Nicht möglich.</b> <?= h($fehler) ?></div>
    <?php endif; ?>
    <?= $inhalt ?>
  </div>
  <?php if ($hilfe !== ''): ?><p class="hilfe"><?= $hilfe ?></p><?php endif; ?>
  <p class="fuss"><span>Internes Werkzeug · Anmeldungen werden protokolliert</span>
    <span class="mono">Läufe täglich 05:30</span></p>
</div>
</body></html>
    <?php
}

/** Die Anmeldemaske — nutzt dieselbe Hülle wie die Einladung. */
function anmeldeseite(string $fehler = ''): void
{
    \ob_start();
    ?>
    <form method="post">
      <input type="hidden" name="action" value="login">
      <label for="a-mail">E-Mail-Adresse</label>
      <input id="a-mail" name="username" type="email" class="mono" required autofocus
             autocomplete="username" spellcheck="false">
      <label for="a-pass">Passwort</label>
      <input id="a-pass" name="password" type="password" required autocomplete="current-password">
      <button class="knopf" type="submit">Anmelden</button>
    </form>
    <?php
    zugangsseite('Anmeldung', (string) \ob_get_clean(), $fehler,
        'Zugang verloren oder neues Konto nötig? '
        . '<a href="mailto:ecommerce@grube.de">Kurze Nachricht genügt</a> — es gibt bewusst '
        . 'keinen Selbstservice zum Zurücksetzen.');
}

/**
 * Artikelbezeichnung — aus dem Zwischenspeicher, sonst einmalig aus dem iSHOP.
 *
 * Der Name ist Anzeigehilfe, keine Beweisgrundlage: `price_events` bleibt unberührt.
 * Deshalb eine eigene Tabelle mit Abrufzeitpunkt — ändert der Shop die Bezeichnung,
 * bleibt nachvollziehbar, welchen Stand ein gedruckter Nachweis zeigte.
 */
function artikelname(string $sku, string $markt): ?string
{
    $z = db()->one('SELECT name FROM {p}article_meta WHERE sku = ? AND market = ?', [$sku, $markt]);
    if ($z !== null) {
        return $z['name'];
    }
    $name = null;
    try {
        $env = new Grube\Price30\Support\Env(y5x_runtime() . '/.env');
        $adapter = new Grube\Price30\Adapters\IshopPriceAdapter(
            new Grube\Price30\Support\Http($env->get('ISHOP_BASE_URL'),
                $env->get('ISHOP_USER'), $env->get('ISHOP_PASS'), 20));
        $name = $adapter->name($sku);
    } catch (\Throwable) {
        // Der Shop ist nicht erreichbar — das darf den Nachweis nicht aufhalten.
        return null;
    }
    db()->execute('INSERT INTO {p}article_meta (sku, market, name, fetched_at)
                   VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE name = VALUES(name),
                   fetched_at = NOW()', [$sku, $markt, $name]);
    return $name;
}

/**
 * Die Shop-Adresse des Marktes — je nach Umgebung.
 *
 * In `staging` gilt `url_staging` (die Integrationsumgebung), in `prod` gilt `url`.
 * Der Unterschied ist keine Formalie: Ein Nachweis, der auf einer Staging-Seite eine
 * Produktiv-URL nennt, behauptet, unter dieser Adresse sei geworben worden — und im
 * Abmahnfall ist genau die URL der Streitgegenstand.
 */
function markt_shopadresse(string $markt): string
{
    $m = maerkte()[$markt] ?? [];
    $feld = y5x_env() === 'prod' ? 'url' : 'url_staging';
    $wert = (string) ($m[$feld] ?? '');
    // Fehlt die Staging-Adresse, wird NICHT auf die Produktivadresse ausgewichen —
    // lieber kein Link als ein falscher.
    return $wert;
}

/**
 * Link auf die Preisanzeige des PSS für diesen Artikel.
 *
 * Bewusst **aus `PSS_BASE_URL` abgeleitet** und nicht als eigener Eintrag gepflegt: Das
 * ist genau die Adresse, auf die dieser Lauf auch schreibt. Zwei getrennt gepflegte
 * Angaben liefen früher oder später auseinander, und dann zeigte der Nachweis auf eine
 * andere Umgebung, als er beschreibt.
 *
 *   staging -> https://integ.grube.de/admin/pss/api/v2_beta/prices?skus=…
 *   prod    -> https://admin.grube.app/admin/pss/api/v2_beta/prices?skus=…
 *
 * Die Anzeige verlangt dieselbe Anmeldung wie die Schnittstelle — der Browser fragt beim
 * Öffnen danach.
 */
function pss_link(string $sku): ?string
{
    static $basis = null;
    if ($basis === null) {
        $basis = (new Grube\Price30\Support\Env(y5x_runtime() . '/.env'))->get('PSS_BASE_URL');
    }
    if ($basis === '') {
        return null;
    }
    return \rtrim($basis, '/') . '?skus=' . \rawurlencode($sku);
}

/**
 * Die **kanonische** Produkt-URL des Artikels — aufgelöst, nicht konstruiert.
 *
 * **Warum nicht der Suchlink:** Eine Abmahnung nennt eine Adresse. `…/search/?q=<sku>`
 * ist funktional und landet richtig, ist aber nicht die URL, unter der geworben wurde —
 * auf einem Nachweisdokument gehört die echte hin (Hinweis GRUBE, 18.08.2026).
 *
 * **Warum nicht selbst gebaut:** Dafür bräuchte es Slug und Produkt-ID. Die Produkt-ID
 * ließe sich zwar aus den ersten sechs Stellen der Artikelnummer ableiten (geprüft an
 * drei Artikeln), der Slug steht aber in keinem lesbaren Attribut des Produktobjekts.
 * Eine aus einem Zahlenmuster geratene Adresse hat auf einem Beweisdokument ohnehin
 * nichts verloren.
 *
 * **Also: der Shop sagt es selbst.** Ein Aufruf der Shop-Suche folgt der Weiterleitung;
 * die Zieladresse IST die kanonische URL. Sie wird mit Zeitstempel festgehalten — ändert
 * der Shop später den Slug, bleibt belegbar, welche Adresse zum Zeitpunkt des Nachweises
 * galt. Bleibt die Weiterleitung aus (der Artikel hat keine Produktseite, gemessen an
 * 1000344342), wird das ehrlich vermerkt statt eine Adresse zu erfinden.
 *
 * @return array{url: ?string, geprueft: ?string, hinweis: string}
 */
function produkt_url(string $sku, string $markt): array
{
    $z = db()->one('SELECT url, url_checked_at FROM {p}article_meta WHERE sku = ? AND market = ?',
        [$sku, $markt]);
    if ($z !== null && $z['url_checked_at'] !== null) {
        return ['url' => $z['url'], 'geprueft' => $z['url_checked_at'],
                'hinweis' => $z['url'] === null ? 'keine Produktseite im Shop' : ''];
    }

    $basis = markt_shopadresse($markt);
    if ($basis === '' || $basis === 'TODO') {
        return ['url' => null, 'geprueft' => null,
                'hinweis' => 'Shop-Adresse für ' . $markt . ' ist in markets.yml nicht gesetzt'];
    }

    $ziel = null;
    $ch = \curl_init(\rtrim($basis, '/') . '/search/?q=' . \rawurlencode($sku));
    \curl_setopt_array($ch, [
        \CURLOPT_RETURNTRANSFER => true, \CURLOPT_FOLLOWLOCATION => true,
        \CURLOPT_TIMEOUT => 20, \CURLOPT_NOBODY => false,
        \CURLOPT_USERAGENT => 'y5x-Preisnachweis (interne Belegpruefung)',
    ]);
    \curl_exec($ch);
    $effektiv = (string) \curl_getinfo($ch, \CURLINFO_EFFECTIVE_URL);
    $status = (int) \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
    \curl_close($ch);

    // Nur eine echte Produktseite zaehlt. Bleibt die Suche stehen, gibt es keine.
    if ($status === 200 && \str_contains($effektiv, '/p/')) {
        $ziel = \strtok($effektiv, '#') ?: $effektiv;
    }

    db()->execute(
        'INSERT INTO {p}article_meta (sku, market, url, url_checked_at, fetched_at)
         VALUES (?,?,?,NOW(),NOW())
         ON DUPLICATE KEY UPDATE url = VALUES(url), url_checked_at = NOW()',
        [$sku, $markt, $ziel]);

    return ['url' => $ziel, 'geprueft' => \date('Y-m-d H:i:s'),
            'hinweis' => $ziel === null ? 'keine Produktseite im Shop' : ''];
}

/**
 * Das Fenster, aus dem der Referenzwert TATSÄCHLICH stammt — und das Intervall darin.
 *
 * **Der Unterschied ist keine Feinheit, sondern eine Falschangabe.** Läuft eine Aktion,
 * ist die Referenz zum Aktionsbeginn eingefroren; sie stammt also aus dem Fenster VOR
 * `promo_started`, nicht aus dem Fenster vor dem Stichtag. Wer den Beleg über das
 * heutige Fenster berechnet, nennt im Nachweisdokument das falsche Intervall — bemerkt
 * am 18.08.2026 an einem Prüfartikel: als Beleg für 79,95 € (Juni-Einbruch) erschien
 * das laufende Aktionsintervall 21.07.–18.08.
 *
 * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: object|null, 3: string}
 *         [von, bis, Beleg-Intervall, Bezugstag als Text]
 */
function beleg_fenster(
    Grube\Price30\Calc\PriceWindow $fenster,
    array $events,
    ?Grube\Price30\Calc\Reference $ref,
    \DateTimeImmutable $stichtag,
): array {
    $bezug = $stichtag;
    $wort  = 'Stichtag';
    if ($ref !== null && $ref->state->isPromo() && $ref->state->promoStarted !== null) {
        $bezug = $ref->state->promoStarted;
        $wort  = 'Aktionsbeginn';
    }
    [$von, $bis] = $fenster->bounds($bezug);
    return [$von, $bis, $fenster->lowestIn($events, $von, $bis), $wort];
}

/**
 * Zustand eines Marktes — die Logik hinter den Statuszeichen.
 *
 * **„Nie gelaufen" ist kein Vorfall, sondern ein Einrichtungszustand.** Der vorherige
 * Stand zeigte für ein System, das noch nie gelaufen war, achtmal „Lücke > 26 h" in Rot.
 * Wenn ab Tag 1 alles rot ist, ist Rot ab Tag 30 bedeutungslos — Alarmmüdigkeit ist bei
 * einem Compliance-Werkzeug gefährlich. Rot bleibt deshalb echten Vorfällen vorbehalten.
 *
 * @return array{code:string, wort:string, klasse:string, detail:string}
 */
function markt_zustand(array $lauf, array $kennzahl, array $konfig, ?array $anlauf): array
{
    if (($lauf['offen'] ?? null) !== null) {
        return ['code' => 'laeuft', 'wort' => 'läuft', 'klasse' => 'laeuft',
                'detail' => 'seit ' . date('H:i', strtotime($lauf['offen']))];
    }
    if (($lauf['zuletzt_ok'] ?? null) === null) {
        return ['code' => 'einrichtung', 'wort' => 'Einrichtung', 'klasse' => 'einrichtung',
                'detail' => 'noch kein erfolgreicher Lauf'];
    }
    $alter = time() - strtotime((string) $lauf['zuletzt_ok']);
    if (($lauf['letzter_status'] ?? '') === 'failed') {
        return ['code' => 'vorfall', 'wort' => 'Lauf fehlgeschlagen', 'klasse' => 'vorfall',
                'detail' => 'letzter erfolgreicher Lauf ' . zeitspanne($alter) . ' her'];
    }
    if ($alter > 26 * 3600) {
        return ['code' => 'vorfall', 'wort' => 'Lauf ausgeblieben', 'klasse' => 'vorfall',
                'detail' => 'letzter Lauf ' . zeitspanne($alter) . ' her'];
    }
    if ($anlauf !== null && $anlauf['tag'] < $anlauf['ziel']) {
        return ['code' => 'anlauf', 'wort' => 'Anlauf', 'klasse' => 'warn',
                'detail' => sprintf('Tag %d von %d — Referenz reift', $anlauf['tag'], $anlauf['ziel'])];
    }
    return ['code' => 'gesund', 'wort' => 'gesund', 'klasse' => 'ok',
            'detail' => 'letzter Lauf ' . zeitspanne($alter) . ' her'];
}

function zeitspanne(int $sekunden): string
{
    if ($sekunden < 90)   { return 'gerade eben'; }
    if ($sekunden < 5400) { return round($sekunden / 60) . ' min'; }
    if ($sekunden < 172800) { return round($sekunden / 3600) . ' h'; }
    return round($sekunden / 86400) . ' Tage';
}

function zahl(int|float|string|null $n): string
{
    return $n === null ? '—' : number_format((float) $n, 0, ',', '.');
}

/** Kopf und Stil — an einer Stelle, damit beide Seiten dieselbe Anlage ergeben. */
function seitenkopf(string $titel, string $aktiv = ''): void
{
    $app = cfg('app');
    $trocken = (bool) ($app['dry_run'] ?? true);
    ?><!doctype html><html lang="de"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Preisschreiber — <?= h($titel) ?></title>
<?php y5x_kopfzeilen(); y5x_stil(); ?>
</head><body>
<header>
  <div class="kopfinhalt">
  <div class="marke">Preisschreiber<small>Preisnachweis · § 11 PAngV</small></div>
  <nav aria-label="Bereiche">
    <a href="index.php"<?= $aktiv === 'index' ? ' aria-current="page"' : '' ?>>Übersicht</a>
    <a href="artikel.php"<?= $aktiv === 'artikel' ? ' aria-current="page"' : '' ?>>Artikel &amp; Nachweis</a>
  </nav>
  <div class="chipzeile">
    <?php if ($trocken): ?>
    <span class="chip trocken" title="dry_run: true — es wird gerechnet und protokolliert, aber nicht in den PSS geschrieben">Trockenmodus</span>
    <?php else: ?>
    <span class="chip scharf" title="dry_run: false — Schreibsätze gehen an den PSS">scharf geschaltet</span>
    <?php endif; ?>
    <span class="chip"><?= h(y5x_env()) ?></span>
    <span class="chip mono">Stand <?= date('d.m.Y · H:i') ?></span>
    <a class="chip" href="konto.php" style="text-decoration:none"
       title="Konto: Passwort ändern, Zugänge verwalten"
       <?= $aktiv === 'konto' ? 'aria-current="page" style="text-decoration:none;background:rgba(255,255,255,.16)"' : '' ?>><?= h(current_user()) ?></a>
    <a class="chip" href="?abmelden=1" style="text-decoration:none">abmelden</a>
  </div>
  </div>
</header>
<main>
    <?php
}
