<?php
declare(strict_types=1);

namespace Grube\Price30\Calc;

/**
 * Das Ergebnis eines Lauftages für einen Artikel in einem Markt.
 *
 * `net` und `gross` stammen garantiert aus DEMSELBEN Event — das ist die Konsistenzregel
 * aus § 6.1 und der Grund, warum hier ein Paar zurückkommt und nicht zwei einzelne Werte.
 */
final class Reference
{
    public function __construct(
        public readonly ?string $net,
        public readonly ?string $gross,
        public readonly string $currency,
        public readonly PromoState $state,
        public readonly bool $windowComplete,
        /** Woher der Wert stammt — für Statusseite und Audit. */
        public readonly string $origin,
    ) {
    }

    public function hasValue(): bool
    {
        return $this->net !== null && $this->gross !== null;
    }
}

/**
 * Der 30-Tage-Referenzpreis je (sku, market) — die eine Zahl, um die es geht.
 *
 * Zwei Betriebsarten, beide hier, damit der Unterschied an einer Stelle sichtbar ist:
 *
 * * **`frozen`** (Vorgabe) bildet den Gesetzeswortlaut ab: niedrigster Preis der 30 Tage
 *   VOR Beginn der Ermäßigung, während der Aktion eingefroren.
 * * **`rolling`** ist das stumpfe Minimum über `[heute−30, gestern]`. Bewusst als
 *   einfache Rückfallebene vorhanden, aber mit einer dokumentierten Schwäche: Ab dem
 *   zweiten Aktionstag steht der Aktionspreis selbst im Fenster, die ausgewiesene
 *   Referenz konvergiert gegen ihn, und die beworbene Ersparnis läuft ins Leere. Das
 *   entspricht nicht dem Wortlaut „vor der Anwendung der Preisermäßigung".
 */
final class ReferenceCalculator
{
    public const MODE_FROZEN  = 'frozen';
    public const MODE_ROLLING = 'rolling';

    public function __construct(
        private readonly PriceWindow $window,
        private readonly PromoStateMachine $machine,
        private readonly string $mode = self::MODE_FROZEN,
    ) {
    }

    /**
     * @param PriceEvent[] $events  gesamte bekannte Historie einschließlich heute
     * @param bool|null    $promoFlag  Aktionskennzeichen des Shops, falls vorhanden
     */
    public function calculate(
        array $events,
        PromoState $state,
        \DateTimeImmutable $heute,
        string $currency = 'EUR',
        ?bool $promoFlag = null,
    ): Reference {
        $vollstaendig = $this->window->isComplete($events, $heute);

        if ($this->mode === self::MODE_ROLLING) {
            $tiefstes = $this->window->lowestBefore($events, $heute);
            return new Reference($tiefstes?->net, $tiefstes?->gross, $currency,
                new PromoState(), $vollstaendig,
                'rollierendes Fenster [heute−30, gestern]');
        }

        $neu = $this->machine->advance($state, $events, $heute, $promoFlag);

        if ($neu->isPromo() && $neu->frozenRefGross !== null) {
            return new Reference($neu->frozenRefNet, $neu->frozenRefGross, $currency,
                $neu, $vollstaendig,
                'eingefroren zum Aktionsbeginn ' . $neu->promoStarted?->format('Y-m-d'));
        }

        // Auch im Zustand `promo` kann die eingefrorene Referenz fehlen — etwa wenn die
        // Aktion am allerersten bekannten Tag begann. Dann ist das rollierende Fenster
        // die einzige belegbare Aussage; sie ist schwächer, aber nicht falsch.
        $tiefstes = $this->window->lowestBefore($events, $heute);
        return new Reference($tiefstes?->net, $tiefstes?->gross, $currency, $neu, $vollstaendig,
            $neu->isPromo()
                ? 'Aktion ohne Vorgeschichte — rollierendes Fenster als Rückfall'
                : 'rollierendes Fenster [heute−30, gestern]');
    }
}
