<?php
declare(strict_types=1);

namespace Grube\Price30\Support;

/**
 * Einladungen für neue Zugänge.
 *
 * **Warum keine Startpasswörter:** Ein Admin, der eines vergibt, kennt das Passwort des
 * Kollegen. Damit ist das `login_log` als Nachweis, wer gearbeitet hat, geschwächt —
 * und genau darauf soll man sich bei einem Werkzeug mit Beweisfunktion verlassen können.
 * Mit einer Einladung vergibt der Eingeladene sein Passwort selbst; niemand sonst hat es
 * je gesehen.
 *
 * Der Einladungsschlüssel ist ein **Passwort-Äquivalent** und wird deshalb nur als Hash
 * gespeichert. Wer die Tabelle lesen könnte, dürfte sonst jede offene Einladung
 * übernehmen. Der Klartext existiert genau einmal: im Link, der herausgeht.
 */
final class Einladung
{
    public const GUELTIG_TAGE = 7;
    public const PASSWORT_MINDESTLAENGE = 12;

    /**
     * Neue Einladung anlegen. Gibt den **Klartext-Schlüssel** zurück — das einzige Mal.
     *
     * @return array{id:int, token:string}
     */
    public static function anlegen(Db $db, string $email, string $rolle, string $vonWem): array
    {
        // 32 Byte aus dem kryptografischen Zufallsgenerator: raten ist damit ausgeschlossen.
        $token = \bin2hex(\random_bytes(32));
        $db->execute(
            'INSERT INTO {p}invitations (email, role, token_hash, created_by, created_at, expires_at)
             VALUES (?,?,?,?,NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))',
            [$email, $rolle, \password_hash($token, \PASSWORD_DEFAULT), $vonWem, self::GUELTIG_TAGE]);
        return ['id' => (int) $db->pdo()->lastInsertId(), 'token' => $token];
    }

    /**
     * Einladung zu einem Schlüssel finden — oder null.
     *
     * Der Schlüssel trägt die ID im Klartext voran (`<id>-<token>`), damit nicht jede
     * offene Einladung durchprobiert werden muss. Geprüft wird trotzdem der Hash.
     */
    public static function pruefen(Db $db, string $schluessel): ?array
    {
        [$id, $token] = \array_pad(\explode('-', $schluessel, 2), 2, '');
        if (!\ctype_digit($id) || $token === '') {
            return null;
        }
        $e = $db->one('SELECT * FROM {p}invitations WHERE id = ?', [(int) $id]);
        if ($e === null || !\password_verify($token, $e['token_hash'])) {
            return null;
        }
        if ($e['used_at'] !== null || $e['revoked_at'] !== null
            || \strtotime((string) $e['expires_at']) < \time()) {
            return null;
        }
        return $e;
    }

    /** Einladung einlösen: Zugang anlegen und Einladung verbrauchen. */
    public static function einloesen(Db $db, array $einladung, string $passwort): void
    {
        $db->execute(
            'INSERT INTO {p}users (username, password_hash, role, created_at)
             VALUES (?,?,?,NOW())
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role)',
            [$einladung['email'], \password_hash($passwort, \PASSWORD_DEFAULT), $einladung['role']]);
        $db->execute('UPDATE {p}invitations SET used_at = NOW() WHERE id = ?', [$einladung['id']]);
    }

    public static function link(string $basis, int $id, string $token): string
    {
        return \rtrim($basis, '/') . '/einladung.php?k=' . \rawurlencode($id . '-' . $token);
    }

    /**
     * Einladungsmail. Schlägt der Versand fehl, ist das **kein** Fehlschlag der
     * Einladung — der Link steht dem Admin ohnehin auf der Seite. Ein Werkzeug, das
     * einen Zugang von einer Zustellung abhängig macht, blockiert sich bei der ersten
     * Spamfilter-Laune selbst.
     */
    public static function versenden(string $email, string $link, string $vonWem, string $env): bool
    {
        $betreff = 'Zugang zum Bestpreis-Tracker (Preisnachweis § 11 PAngV)';
        $text = "Hallo,\n\n"
              . "$vonWem hat Ihnen einen Zugang zum Bestpreis-Tracker eingerichtet.\n\n"
              . "Passwort selbst vergeben:\n$link\n\n"
              . "Der Link gilt " . self::GUELTIG_TAGE . " Tage und lässt sich nur einmal verwenden.\n"
              . "Ihr Passwort kennt danach niemand außer Ihnen — auch keine Administration.\n\n"
              . "Umgebung: $env\n"
              . "Falls Sie damit nichts anfangen können, ignorieren Sie diese Nachricht bitte.\n";
        $kopf = "From: Bestpreis-Tracker <webmaster@grube.tools>\r\n"
              . "Reply-To: $vonWem\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "X-Mailer: y5x";
        return @\mail($email, '=?UTF-8?B?' . \base64_encode($betreff) . '?=', $text, $kopf);
    }
}
