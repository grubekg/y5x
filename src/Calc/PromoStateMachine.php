<?php
declare(strict_types=1);

namespace Grube\Price30\Calc;

use Grube\Price30\Support\Money;

/**
 * Der Zustandsautomat hinter dem Betriebsmodus `frozen`.
 *
 * § 11 PAngV verlangt den niedrigsten Preis der 30 Tage **vor Beginn** der Ermäßigung.
 * Das ist mehr als ein rollierendes Minimum: Sobald eine Aktion läuft, muss die Referenz
 * stehenbleiben, sonst wandert der Aktionspreis selbst ins Fenster und die ausgewiesene
 * Ersparnis schrumpft von Tag zu Tag gegen null.
 *
 * Absatz 2 desselben Paragraphen regelt die progressive Rabattstaffelung: Wird während
 * einer laufenden Aktion weiter gesenkt, bleibt der ursprüngliche Referenzpreis
 * maßgeblich. Genau deshalb ändern weitere Senkungen im Zustand `promo` nichts.
 *
 * **Ein Promo-Kennzeichen des Shops schlägt jede Heuristik.** Liefert die iSHOP-Abfrage
 * ein Aktionsmerkmal, wird ausschließlich danach umgeschaltet — eine Preissenkung ist
 * nicht zwingend eine beworbene Ermäßigung, und eine Ermäßigung nicht zwingend eine
 * Senkung gegenüber gestern. Die Sprung-Heuristik ist der Rückfall, solange das
 * Kennzeichen nicht geklärt ist (TODO(setup) 1).
 */
final class PromoStateMachine
{
    public function __construct(
        private readonly PriceWindow $window,
        private readonly int $permanentAfterDays = 30,
    ) {
    }

    /**
     * Den Zustand um einen Tag fortschreiben.
     *
     * @param PriceEvent[]  $events      gesamte bekannte Historie (inkl. heute)
     * @param bool|null     $promoFlag   Aktionskennzeichen des Shops; null = unbekannt
     */
    public function advance(
        PromoState $alt,
        array $events,
        \DateTimeImmutable $heute,
        ?bool $promoFlag = null,
    ): PromoState {
        $heutigesEvent = $this->eventAm($events, $heute);
        if ($heutigesEvent === null) {
            // Kein Preis für heute — Artikel nicht geliefert oder verschwunden. Der
            // Zustand bleibt unangetastet; nichts zu entscheiden ist besser als zu raten.
            return $alt;
        }
        $gestern = $this->eventAm($events, $heute->modify('-1 day'));

        return $alt->isPromo()
            ? $this->ausPromo($alt, $heutigesEvent, $gestern, $heute, $promoFlag)
            : $this->ausNormal($alt, $heutigesEvent, $gestern, $events, $heute, $promoFlag);
    }

    private function ausNormal(
        PromoState $alt,
        PriceEvent $heutigesEvent,
        ?PriceEvent $gestern,
        array $events,
        \DateTimeImmutable $heute,
        ?bool $promoFlag,
    ): PromoState {
        if ($promoFlag === true) {
            $beginn = 'Aktionskennzeichen des Shops gesetzt';
        } elseif ($promoFlag === null
            && $gestern !== null
            && Money::isLess($heutigesEvent->gross, $gestern->gross)) {
            $beginn = 'Preissenkung gegenüber Vortag erkannt (Heuristik)';
        } else {
            return $alt->mode === PromoState::NORMAL && $alt->lastTransition === ''
                ? $alt
                : new PromoState(PromoState::NORMAL, null, null, null, null, null, null,
                    $alt->lastTransition);
        }

        // Die Referenz wird JETZT eingefroren, aus dem Fenster vor dem Aktionsbeginn.
        // Bezugspunkt ist `heute` — [heute−30, gestern] ist identisch mit
        // [promo_started−30, promo_started−1].
        $referenz = $this->window->lowestBefore($events, $heute);
        // Ohne Vortagspreis (Aktionskennzeichen am ersten bekannten Tag) gibt es kein
        // Vorniveau; dann dient der heutige Preis als Rückkehrschwelle, sonst bliebe
        // der Artikel für immer in `promo`.
        $vorNiveau = $gestern?->gross ?? $heutigesEvent->gross;

        return new PromoState(
            PromoState::PROMO,
            $heute,
            $vorNiveau,
            $gestern?->net ?? $heutigesEvent->net,   // Paar aus DEMSELBEN Event (§ 6.1)
            $heute,                                   // Timer fuer den PREV-Anker
            $referenz?->net,
            $referenz?->gross,
            $beginn,
        );
    }

    private function ausPromo(
        PromoState $alt,
        PriceEvent $heutigesEvent,
        ?PriceEvent $gestern,
        \DateTimeImmutable $heute,
        ?bool $promoFlag,
    ): PromoState {
        $beendet = static fn(string $grund): PromoState => new PromoState(
            PromoState::NORMAL, null, null, null, null, null, null, $grund);

        if ($promoFlag === false) {
            return $beendet('Aktionskennzeichen des Shops entfallen');
        }

        // Weitere Stufe waehrend der Aktion: Die Referenz bleibt eingefroren (§ 11 Abs. 2),
        // auch `pre_promo_*` bleibt stehen — der Vorstufen-Anker zeigt weiter auf den
        // Preis VOR der Aktion. Nur der Timer wird zurueckgesetzt.
        $weitereSenkung = $gestern !== null
            && Money::isLess($heutigesEvent->gross, $gestern->gross);

        if ($promoFlag === true) {
            return $weitereSenkung ? $this->mitTimer($alt, $heute) : $alt;
        }

        // Aktion beendet: Der Preis ist auf das Vorniveau (oder darüber) zurück.
        if ($alt->prePromoGross !== null
            && Money::isGreaterOrEqual($heutigesEvent->gross, $alt->prePromoGross)) {
            return $beendet('Preis zurück auf Vorniveau — Aktion beendet');
        }

        if ($weitereSenkung) {
            return $this->mitTimer($alt, $heute);
        }

        // Dauerhafte Senkung: Bleibt derselbe niedrigere Preis lange genug stehen, war es
        // keine Aktion, sondern ein neues Normalniveau. Ohne diesen Riegel bliebe ein
        // dauerhaft gesenkter Artikel für immer in `promo` und trüge ewig eine
        // eingefrorene, längst überholte Referenz.
        $tageStabil = (int) $heutigesEvent->validFrom->diff($heute)->days;
        if ($tageStabil >= $this->permanentAfterDays) {
            return $beendet(\sprintf(
                'Preis seit %d Tagen unverändert — als neues Normalniveau übernommen',
                $tageStabil));
        }

        return $alt;
    }

    /** Denselben Zustand mit zurueckgesetztem Senkungs-Timer. */
    private function mitTimer(PromoState $alt, \DateTimeImmutable $heute): PromoState
    {
        return new PromoState($alt->mode, $alt->promoStarted, $alt->prePromoGross,
            $alt->prePromoNet, $heute, $alt->frozenRefNet, $alt->frozenRefGross,
            'weitere Senkung während der Aktion — Referenz unverändert, Timer neu');
    }

    /** Das Event, das an diesem Tag galt. */
    private function eventAm(array $events, \DateTimeImmutable $tag): ?PriceEvent
    {
        foreach ($events as $e) {
            if ($e->validFrom <= $tag && ($e->validTo === null || $e->validTo >= $tag)) {
                return $e;
            }
        }
        return null;
    }
}
