<?php
declare(strict_types=1);

/**
 * PSR-4-Autoloader ohne Composer.
 *
 * Composer wird für Guzzle und Monolog gebraucht (Abschnitt 8 des Briefings), aber die
 * Rechenkerne und ihre Tests dürfen nicht davon abhängen: Die Berechnung ist der Teil,
 * der vor Gericht tragen muss, und er soll sich auf jedem PHP 8 ohne Installationsschritt
 * nachrechnen lassen. Liegt `vendor/autoload.php` vor, wird es zusätzlich geladen.
 */
\spl_autoload_register(static function (string $class): void {
    $prefix = 'Grube\\Price30\\';
    if (!\str_starts_with($class, $prefix)) {
        return;
    }
    $pfad = __DIR__ . '/src/' . \str_replace('\\', '/', \substr($class, \strlen($prefix))) . '.php';
    if (\is_file($pfad)) {
        require $pfad;
    }
});

if (\is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}
