<?php
declare(strict_types=1);

namespace Grube\Price30\Support;

/**
 * Geldbeträge als Dezimalzeichenkette — niemals als float.
 *
 * Der Grund ist nicht Pedanterie. Ein `float` kann 0,1 nicht exakt darstellen; zwei
 * Preise, die in der Datenbank gleich sind, können nach dem Umweg über `float`
 * ungleich vergleichen. Dieses Werkzeug ist Teil einer Beweiskette nach § 11 PAngV —
 * ein Referenzpreis, der um einen Cent danebenliegt, ist eine falsche Werbeaussage.
 *
 * Gerechnet wird deshalb mit `bcmath` auf Zeichenketten, genau so, wie die Werte in
 * DECIMAL-Spalten stehen. Verglichen wird über {@see compare}, nie mit `<`, `==` oder
 * `min()`.
 */
final class Money
{
    /** Nachkommastellen, mit denen intern verglichen wird (net hat 4, gross 2). */
    public const SCALE = 4;

    /**
     * Auf eine vergleichbare Form bringen: „129", „129.0", „129.00" sind derselbe Betrag.
     *
     * Wirft bei allem, was kein Betrag ist. Ein stiller Rückfall auf 0 wäre hier der
     * gefährlichste Fehler überhaupt — ein Nullpreis als Minimum im PSS.
     */
    public static function normalize(string|int|float $value): string
    {
        $s = \is_float($value)
            ? \number_format($value, self::SCALE, '.', '')
            : \trim((string) $value);
        if ($s === '' || !\preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            throw new \InvalidArgumentException("kein gültiger Geldbetrag: " . \var_export($value, true));
        }
        return \bcadd($s, '0', self::SCALE);
    }

    /** -1, 0 oder 1 — exakt, ohne Fließkomma. */
    public static function compare(string $a, string $b): int
    {
        return \bccomp(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function isLess(string $a, string $b): bool
    {
        return self::compare($a, $b) < 0;
    }

    public static function isGreaterOrEqual(string $a, string $b): bool
    {
        return self::compare($a, $b) >= 0;
    }

    public static function equals(string $a, string $b): bool
    {
        return self::compare($a, $b) === 0;
    }

    /** Positiv und größer null — der Eingangsfilter gegen Null- und Negativpreise. */
    public static function isPositive(string $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    /** Auf die Stellenzahl der Zielspalte runden (kaufmännisch), fürs Schreiben. */
    public static function toScale(string $value, int $scale): string
    {
        $n = self::normalize($value);
        $gerundet = \bcadd($n, ($n[0] === '-' ? '-' : '') . '0.' . \str_repeat('0', $scale) . '5', $scale + 1);
        return \bcadd($gerundet, '0', $scale);
    }
}
