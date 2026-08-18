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
        /**
         * Vorstufen-Anker (§ 6.4). `null` heißt ausdrücklich **leeren**, nicht
         * „unverändert lassen": Ein abgelaufener Streichpreis muss aus dem Frontend
         * verschwinden, sonst wirbt der Shop mit einer Ersparnis gegen einen Preis, den
         * es seit Wochen nicht mehr gibt.
         */
        public readonly ?string $prevNet = null,
        public readonly ?string $prevGross = null,
        public readonly string $prevOrigin = '',
    ) {
    }

    public function hasValue(): bool
    {
        return $this->net !== null && $this->gross !== null;
    }

    public function hasPrev(): bool
    {
        return $this->prevNet !== null && $this->prevGross !== null;
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
        private readonly bool $prevEnabled = false,
        private readonly int $prevMaxDays = 42,
    ) {
    }

    /**
     * Der Vorstufen-Anker `PREV_*` — der Preis der unmittelbaren Vorstufe (§ 6.4).
     *
     * Ausdrücklich **nicht** der Ur-Normalpreis und **nicht** die UVP: Bei einer
     * Abverkaufs-Preistreppe soll der Streichpreis die letzte Stufe zeigen, gegen die
     * tatsächlich reduziert wurde.
     *
     * Zwei Leerungsgründe, und der zweite ist der, den man leicht vergisst:
     * Rückkehr nach `normal` — und **Zeitablauf**. Ein eigener Preis von vor Monaten
     * taugt nicht mehr als Streichpreis-Anker; `prev_price_max_days` begrenzt das. Jede
     * weitere Senkung setzt den Timer zurück, denn dann ist der Anker wieder frisch.
     *
     * @return array{0: ?string, 1: ?string, 2: string} [net, gross, Begründung]
     */
    private function prev(PromoState $state, \DateTimeImmutable $heute): array
    {
        if (!$this->prevEnabled) {
            return [null, null, 'PREV abgeschaltet (prev_price_enabled: false)'];
        }
        if (!$state->isPromo() || $state->prePromoGross === null || $state->prePromoNet === null) {
            return [null, null, 'keine laufende Preisstufe — Anker geleert'];
        }
        if ($state->lastReductionAt !== null) {
            $tage = (int) $state->lastReductionAt->diff($heute)->days;
            if ($tage > $this->prevMaxDays) {
                return [null, null, \sprintf(
                    'letzte Senkung vor %d Tagen (Grenze %d) — Anker geleert',
                    $tage, $this->prevMaxDays)];
            }
        }
        return [$state->prePromoNet, $state->prePromoGross,
                'Preis der Vorstufe vom ' . ($state->promoStarted?->modify('-1 day')
                    ->format('d.m.Y') ?? '?')];
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
            // Ohne Zustandslogik gibt es auch keine Preisstufe — der PREV-Anker haengt
            // definitionsgemaess am Uebergang normal -> promo.
            $tiefstes = $this->window->lowestBefore($events, $heute);
            return new Reference($tiefstes?->net, $tiefstes?->gross, $currency,
                new PromoState(), $vollstaendig,
                'rollierendes Fenster [heute−30, gestern]',
                null, null, 'PREV im Modus rolling nicht definiert');
        }

        $neu = $this->machine->advance($state, $events, $heute, $promoFlag);
        [$prevNet, $prevGross, $prevGrund] = $this->prev($neu, $heute);

        if ($neu->isPromo() && $neu->frozenRefGross !== null) {
            return new Reference($neu->frozenRefNet, $neu->frozenRefGross, $currency,
                $neu, $vollstaendig,
                'eingefroren zum Aktionsbeginn ' . $neu->promoStarted?->format('Y-m-d'),
                $prevNet, $prevGross, $prevGrund);
        }

        // Auch im Zustand `promo` kann die eingefrorene Referenz fehlen — etwa wenn die
        // Aktion am allerersten bekannten Tag begann. Dann ist das rollierende Fenster
        // die einzige belegbare Aussage; sie ist schwächer, aber nicht falsch.
        $tiefstes = $this->window->lowestBefore($events, $heute);
        return new Reference($tiefstes?->net, $tiefstes?->gross, $currency, $neu, $vollstaendig,
            $neu->isPromo()
                ? 'Aktion ohne Vorgeschichte — rollierendes Fenster als Rückfall'
                : 'rollierendes Fenster [heute−30, gestern]',
            $prevNet, $prevGross, $prevGrund);
    }
}
