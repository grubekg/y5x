<?php
declare(strict_types=1);

namespace Grube\Price30\Support;

/**
 * Zugangsdaten aus `.env` der Laufzeit.
 *
 * Bewusst ein eigener, winziger Parser statt `parse_ini_file`: Letzteres stolpert über
 * `#` und `"` in Werten und liefert dann still einen abgeschnittenen Schlüssel — ein
 * Fehler, der auf diesem Webspace schon einmal Zeit gekostet hat.
 */
final class Env
{
    private array $werte = [];

    public function __construct(string $pfad)
    {
        if (!\is_readable($pfad)) {
            return;
        }
        foreach (\file($pfad, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $zeile) {
            $zeile = \trim($zeile);
            if ($zeile === '' || $zeile[0] === '#' || !\str_contains($zeile, '=')) {
                continue;
            }
            [$k, $v] = \explode('=', $zeile, 2);
            $this->werte[\trim($k)] = \trim($v);
        }
    }

    public function get(string $name, string $vorgabe = ''): string
    {
        return $this->werte[$name] ?? $vorgabe;
    }
}
