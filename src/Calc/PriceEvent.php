<?php
declare(strict_types=1);

namespace Grube\Price30\Calc;

use Grube\Price30\Support\Money;

/**
 * Ein Preisintervall: „vom Tag X bis zum Tag Y galt dieser Preis".
 *
 * Gespeichert werden bewusst Intervalle statt täglicher Momentaufnahmen. Die Beweiskraft
 * ist dieselbe — für jeden Kalendertag lässt sich der geltende Preis lückenlos ableiten —,
 * aber bei rund 13.000 Artikeln × 8 Märkten bleibt die Tabelle klein genug, um sie
 * unbegrenzt aufzubewahren.
 *
 * `validTo === null` heißt „gilt noch". Das ist kein „unbekannt": Ein offenes Intervall
 * ist der heute gültige Preis.
 */
final class PriceEvent
{
    public readonly string $net;
    public readonly string $gross;

    public function __construct(
        public readonly \DateTimeImmutable $validFrom,
        public readonly ?\DateTimeImmutable $validTo,
        string $net,
        string $gross,
        public readonly string $currency = 'EUR',
    ) {
        $this->net = Money::normalize($net);
        $this->gross = Money::normalize($gross);
    }

    /**
     * Überschneidet sich dieses Intervall mit [$von, $bis]?
     *
     * Beide Grenzen sind einschließlich. Ein offenes Intervall reicht bis heute und
     * damit über jedes Fensterende hinaus.
     */
    public function overlaps(\DateTimeImmutable $von, \DateTimeImmutable $bis): bool
    {
        if ($this->validFrom > $bis) {
            return false;                     // beginnt erst nach dem Fenster
        }
        return $this->validTo === null || $this->validTo >= $von;
    }

    public function isOpen(): bool
    {
        return $this->validTo === null;
    }
}
