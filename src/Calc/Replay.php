<?php
declare(strict_types=1);

namespace Grube\Price30\Calc;

/**
 * Nachrechnung zu einem beliebigen Stichtag — das Herzstück der Verteidigung.
 *
 * Eine Abmahnung nennt ein Datum: „Am 14. Juli haben Sie mit −30 % geworben." Zu
 * beantworten ist dann nicht, was heute gilt, sondern was **an jenem Tag** galt und aus
 * welchem Beleg es sich ergab.
 *
 * Genau das kann dieses Werkzeug, weil die Berechnung rein deterministisch über
 * `price_events` läuft: Der Zustandsautomat wird vom ersten bekannten Tag an Tag für Tag
 * neu abgespielt, mit dem Wissensstand des jeweiligen Tages. Es wird also nichts
 * gespeichert und später geglaubt — es wird **nachgerechnet**.
 *
 * Der Vergleich mit `pss_write_log` schließt die Kette: Stimmt der nachgerechnete Wert
 * mit dem überein, der damals tatsächlich in den PSS geschrieben wurde, ist die
 * Werbeaussage belegt. Weichen sie ab, will man das selbst als Erster wissen.
 */
final class Replay
{
    public function __construct(private readonly ReferenceCalculator $calc)
    {
    }

    /**
     * Zustand und Referenz, wie sie am Stichtag galten.
     *
     * @param PriceEvent[] $events    gesamte bekannte Historie
     * @param array<string,bool> $promoFlags  optional: Aktionskennzeichen je Tag (Y-m-d)
     */
    public function until(
        array $events,
        \DateTimeImmutable $stichtag,
        string $currency = 'EUR',
        array $promoFlags = [],
    ): ?Reference {
        $start = null;
        foreach ($events as $e) {
            if ($start === null || $e->validFrom < $start) {
                $start = $e->validFrom;
            }
        }
        if ($start === null || $start > $stichtag) {
            return null;                       // an diesem Tag gab es den Artikel noch nicht
        }

        $state = new PromoState();
        $ref = null;
        for ($tag = $start; $tag <= $stichtag; $tag = $tag->modify('+1 day')) {
            // Nur, was an diesem Tag schon bekannt war. Ein Event, das später beginnt,
            // darf die damalige Entscheidung nicht beeinflussen — sonst rechnete man mit
            // Wissen aus der Zukunft und der Nachweis wäre wertlos.
            $bekannt = \array_values(\array_filter($events,
                static fn(PriceEvent $e): bool => $e->validFrom <= $tag));
            $ref = $this->calc->calculate($bekannt, $state, $tag, $currency,
                $promoFlags[$tag->format('Y-m-d')] ?? null);
            $state = $ref->state;
        }
        return $ref;
    }

    /**
     * Der Preis, der an einem bestimmten Tag galt — die zweite Hälfte des Nachweises.
     *
     * @param PriceEvent[] $events
     */
    public function priceOn(array $events, \DateTimeImmutable $tag): ?PriceEvent
    {
        foreach ($events as $e) {
            if ($e->validFrom <= $tag && ($e->validTo === null || $e->validTo >= $tag)) {
                return $e;
            }
        }
        return null;
    }

    /**
     * Die Tage des Fensters, das am Stichtag galt, mit dem jeweils geltenden Preis.
     *
     * Für die Darstellung: Ein Prüfer will sehen, welcher Preis an welchem der 30 Tage
     * verlangt wurde, und wo die Lücken sind.
     *
     * @param PriceEvent[] $events
     * @return array<int, array{date: string, gross: ?string, net: ?string}>
     */
    public function windowDays(array $events, \DateTimeImmutable $stichtag, int $windowDays = 30): array
    {
        $von = $stichtag->modify('-' . $windowDays . ' days');
        $bis = $stichtag->modify('-1 day');
        $out = [];
        for ($tag = $von; $tag <= $bis; $tag = $tag->modify('+1 day')) {
            $e = $this->priceOn($events, $tag);
            $out[] = ['date' => $tag->format('Y-m-d'), 'gross' => $e?->gross, 'net' => $e?->net];
        }
        return $out;
    }
}
