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
 * **Es gibt kein Aktionskennzeichen, und es soll auch keines geben** (Entscheidung
 * GRUBE, 18.08.2026). Aktionen können aus verschiedenen Stellen stammen; ein Kennzeichen
 * aus nur einer davon wäre **schlimmer als gar keines**: Der Automat würde ihm
 * ausschließlich glauben und jede Aktion aus einer anderen Quelle stillschweigend
 * übersehen. Ein Kennzeichen, das „nein" sagt, obwohl eine Aktion läuft, erzeugt genau
 * die falsche Werbeaussage, die dieses Werkzeug verhindern soll.
 *
 * Deshalb nimmt dieser Automat **bewusst keinen Flag-Parameter entgegen**. Das einzige
 * verlässliche Signal ist der tatsächlich angewendete Preis aus dem Shop — er ist die
 * Vereinigung aller Aktionsquellen, gleich woher sie kommen. Erkannt wird eine Aktion
 * daran, dass er gegenüber gestern fällt.
 *
 * **Der Preis dieser Entscheidung steht in `permanent_after_days`.** Ohne externes
 * Signal lässt sich eine lange Aktion nicht von einer dauerhaften Senkung unterscheiden;
 * die Grenze muss deshalb über der längsten tatsächlich geplanten Aktionsdauer liegen.
 * Zu niedrig, und die Referenz kippt mitten in einer Aktion auf den Aktionspreis; zu
 * hoch, und eine echte Dauersenkung schleppt monatelang eine überholte Referenz mit.
 * Es gibt hier keine Einstellung ohne Nachteil — nur eine bewusst gewählte.
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
     * @param PriceEvent[] $events gesamte bekannte Historie (inkl. heute)
     */
    public function advance(
        PromoState $alt,
        array $events,
        \DateTimeImmutable $heute,
    ): PromoState {
        $heutigesEvent = $this->eventAm($events, $heute);
        if ($heutigesEvent === null) {
            // Kein Preis für heute — Artikel nicht geliefert oder verschwunden. Der
            // Zustand bleibt unangetastet; nichts zu entscheiden ist besser als zu raten.
            return $alt;
        }
        $gestern = $this->eventAm($events, $heute->modify('-1 day'));

        return $alt->isPromo()
            ? $this->ausPromo($alt, $heutigesEvent, $gestern, $heute)
            : $this->ausNormal($alt, $heutigesEvent, $gestern, $events, $heute);
    }

    private function ausNormal(
        PromoState $alt,
        PriceEvent $heutigesEvent,
        ?PriceEvent $gestern,
        array $events,
        \DateTimeImmutable $heute,
    ): PromoState {
        if ($gestern !== null && Money::isLess($heutigesEvent->gross, $gestern->gross)) {
            $beginn = \sprintf('Preissenkung %s → %s gegenüber dem Vortag',
                Money::toScale($gestern->gross, 2), Money::toScale($heutigesEvent->gross, 2));
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
        // Ohne Preissenkung gäbe es keinen Übergang — an dieser Stelle ist ein
        // Vortagspreis also immer vorhanden.
        $vorNiveau = $gestern->gross;

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
    ): PromoState {
        $beendet = static fn(string $grund): PromoState => new PromoState(
            PromoState::NORMAL, null, null, null, null, null, null, $grund);

        // Weitere Stufe waehrend der Aktion: Die Referenz bleibt eingefroren (§ 11 Abs. 2),
        // auch `pre_promo_*` bleibt stehen — der Vorstufen-Anker zeigt weiter auf den
        // Preis VOR der Aktion. Nur der Timer wird zurueckgesetzt.
        $weitereSenkung = $gestern !== null
            && Money::isLess($heutigesEvent->gross, $gestern->gross);

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
