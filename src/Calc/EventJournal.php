<?php
declare(strict_types=1);

namespace Grube\Price30\Calc;

use Grube\Price30\Support\Money;

/**
 * Fortschreibung der Preisintervalle (§ 5 des Briefings).
 *
 * **Auflösung eines Widerspruchs im Briefing.** Das Schema kommentiert `valid_to NULL`
 * als „aktuell", die Fortschreibungsregel setzt bei unverändertem Preis aber
 * `valid_to = heute`. Nach dem ersten Lauf wäre `valid_to` damit nie mehr NULL, und
 * „NULL = aktuell" liefe leer.
 *
 * Maßgeblich ist die Fortschreibungsregel, denn sie ist die beweiskräftigere:
 * **`valid_to` ist der letzte Tag, an dem dieser Preis TATSÄCHLICH BEOBACHTET wurde.**
 * Ein NULL würde Geltung in die Zukunft behaupten — belegen lässt sich aber nur, was
 * gemessen wurde. Fällt ein Lauf aus, endet das Intervall ehrlich am letzten
 * Beobachtungstag, und die Lücke ist über `run_log` sichtbar, statt stillschweigend
 * überbrückt zu werden.
 *
 * Das aktuelle Intervall ist deshalb nicht „das mit NULL", sondern **das mit dem
 * jüngsten `valid_from`**. NULL bleibt als Zustand zulässig (frisch angelegt, noch nie
 * fortgeschrieben) und wird überall mitbehandelt.
 *
 * **Reihenfolge im Lauf:** erst das Journal fortschreiben, dann rechnen. Sonst kennt die
 * Berechnung den heutigen Preis noch nicht und der Aktionsvergleich liefe ins Leere.
 */
final class EventJournal
{
    /** Das aktuell geltende Intervall — das mit dem jüngsten Beginn. */
    public function current(array $events): ?PriceEvent
    {
        $treffer = null;
        foreach ($events as $e) {
            if ($treffer === null || $e->validFrom > $treffer->validFrom) {
                $treffer = $e;
            }
        }
        return $treffer;
    }

    /**
     * Was ist heute mit diesem Artikel zu tun?
     *
     * @param PriceEvent[] $events
     * @return array{action: string, close_at: ?string, open: ?PriceEvent}
     */
    public function plan(
        array $events,
        ?string $netHeute,
        ?string $grossHeute,
        string $currency,
        \DateTimeImmutable $heute,
    ): array {
        $aktuell = $this->current($events);
        $gestern = $heute->modify('-1 day');

        // --- Artikel kam heute nicht aus dem Shop -----------------------------
        if ($netHeute === null || $grossHeute === null) {
            if ($aktuell === null) {
                return ['action' => 'nichts', 'close_at' => null, 'open' => null];
            }
            if ($aktuell->validTo !== null) {
                // Bereits am letzten Beobachtungstag beendet — nichts zu tun. Gelöscht
                // wird ohnehin nie: Die Historie ist der Beleg dafür, was verlangt wurde.
                return ['action' => 'nichts', 'close_at' => null, 'open' => null];
            }
            $ende = $aktuell->validFrom > $gestern ? $aktuell->validFrom : $gestern;
            return ['action' => 'verschwunden', 'close_at' => $ende->format('Y-m-d'), 'open' => null];
        }

        // --- Erster Preis ueberhaupt ------------------------------------------
        if ($aktuell === null) {
            return ['action' => 'neu', 'close_at' => null,
                    'open' => new PriceEvent($heute, $heute, $netHeute, $grossHeute, $currency)];
        }

        // --- Unveraendert: Beobachtung bis heute verlaengern -------------------
        $gleich = Money::equals($aktuell->gross, $grossHeute)
               && Money::equals($aktuell->net, $netHeute)
               && $aktuell->currency === $currency;

        if ($gleich) {
            return ['action' => 'unveraendert', 'close_at' => $heute->format('Y-m-d'), 'open' => null];
        }

        // --- Geaendert: gestern schliessen, heute neu oeffnen ------------------
        // Der Schlusstag ist gestern und nicht der letzte Beobachtungstag: Der alte Preis
        // galt bis einschliesslich gestern, sonst entstuende eine Luecke im Kalender.
        return ['action' => 'geaendert',
                'close_at' => $gestern->format('Y-m-d'),
                'open' => new PriceEvent($heute, $heute, $netHeute, $grossHeute, $currency)];
    }

    /**
     * Denselben Plan auf eine Liste im Speicher anwenden — für Tests und `backfill`.
     *
     * @param PriceEvent[] $events
     * @return PriceEvent[]
     */
    public function apply(array $events, array $plan, \DateTimeImmutable $heute): array
    {
        if ($plan['action'] === 'nichts') {
            return $events;
        }
        $aktuell = $this->current($events);
        $neu = [];
        foreach ($events as $e) {
            if ($aktuell !== null && $e === $aktuell && $plan['close_at'] !== null) {
                $neu[] = new PriceEvent($e->validFrom,
                    new \DateTimeImmutable($plan['close_at'] . ' 00:00:00'),
                    $e->net, $e->gross, $e->currency);
                continue;
            }
            $neu[] = $e;
        }
        if ($plan['open'] !== null) {
            $neu[] = $plan['open'];
        }
        return $neu;
    }
}
