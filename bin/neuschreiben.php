#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Einmalig nach einem Schlüsselwechsel: den Schreibstand zurücksetzen.
 *
 *   php bin/neuschreiben.php [--wirklich]
 *
 * **Warum es das braucht.** Der Delta-Write vergleicht den zu schreibenden Wert mit
 * `last_written_*` und lässt aus, was sich nicht geändert hat. Beim Wechsel auf den
 * Schlüssel mit `provider=preisschreiber` (19.08.2026) hat sich aber nicht der Wert
 * geändert, sondern der **Ort**. Ohne diesen Schritt bliebe unter dem neuen Schlüssel
 * dauerhaft nichts stehen — und nichts sähe nach Fehler aus. Genau der Zustand, den
 * dieses Werkzeug nicht haben darf.
 *
 * Zurückgesetzt wird ausschließlich der Schreibstand. Die Beweisgrundlage bleibt
 * unberührt: `price_events`, der Zustandsautomat und `pss_write_log` sagen weiterhin,
 * was gemessen und was wann übertragen wurde.
 *
 * Bewusst kein Migrationsskript: Migrationen laufen bei jedem Aufruf erneut, und ein
 * versehentlich wiederholter Vollschreiblauf über acht Märkte ist nichts, was
 * nebenbei passieren sollte.
 */
require __DIR__ . '/../autoload.php';

use Grube\Price30\Support\Db;

$db  = Db::fromRuntime(__DIR__ . '/..');
$tun = \in_array('--wirklich', $argv, true);

$offen = $db->one(
    "SELECT COUNT(*) AS n FROM {p}price_state WHERE last_written_at IS NOT NULL");
\printf("%s: %s Artikel×Markt tragen einen Schreibstand.\n",
    $db->env, \number_format((int) ($offen['n'] ?? 0), 0, ',', '.'));

if (!$tun) {
    echo "Nichts getan (--wirklich fehlt). Danach schreibt der nächste Lauf alles neu.\n";
    exit(0);
}

$n = $db->execute(
    "UPDATE {p}price_state
        SET last_written_30_net = NULL, last_written_30_gross = NULL,
            last_written_prev_net = NULL, last_written_prev_gross = NULL,
            last_written_at = NULL
      WHERE last_written_at IS NOT NULL");
\printf("%s Zeilen zurückgesetzt — der nächste Lauf schreibt den ganzen Bestand neu.\n",
    \number_format($n, 0, ',', '.'));
