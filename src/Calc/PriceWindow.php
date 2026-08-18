<?php
declare(strict_types=1);

namespace Grube\Price30\Calc;

use Grube\Price30\Support\Money;

/**
 * Das 30-Tage-Fenster und die Auswahl des Referenz-Events darin.
 *
 * **Heute gehört nie zum Fenster.** Der heutige Preis ist der Aktionspreis, gegen den
 * verglichen wird — nähme man ihn in die Referenz auf, vergliche man ihn mit sich selbst.
 * Das Fenster endet deshalb gestern und umfasst `window_days` Tage: `[heute−30, gestern]`.
 *
 * **Der Referenztag wird über den BRUTTOpreis bestimmt**, und net stammt danach aus
 * demselben Event (§ 6.1 des Briefings). Zwei unabhängige Minima über net und gross
 * wären der subtilere Fehler: Bei einer zwischenzeitlichen Mehrwertsteueränderung könnten
 * sie aus verschiedenen Tagen stammen und zueinander nicht mehr passen — 30_NET und
 * 30_GROSS stünden dann in einem Verhältnis, das es nie gegeben hat.
 */
final class PriceWindow
{
    public function __construct(private readonly int $windowDays = 30)
    {
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [von, bis] */
    public function bounds(\DateTimeImmutable $heute): array
    {
        $bis = $heute->modify('-1 day');
        $von = $heute->modify('-' . $this->windowDays . ' days');
        return [$von, $bis];
    }

    /**
     * Das Event mit dem niedrigsten Bruttopreis im Fenster.
     *
     * Bei Gleichstand gewinnt das **jüngste** Event: Steht derselbe Betrag mehrfach im
     * Fenster, ist der zuletzt tatsächlich verlangte Preis der aussagekräftigere Beleg.
     *
     * @param PriceEvent[] $events
     */
    public function lowestIn(array $events, \DateTimeImmutable $von, \DateTimeImmutable $bis): ?PriceEvent
    {
        $treffer = null;
        foreach ($events as $e) {
            if (!$e->overlaps($von, $bis)) {
                continue;
            }
            if ($treffer === null) {
                $treffer = $e;
                continue;
            }
            $vergleich = Money::compare($e->gross, $treffer->gross);
            if ($vergleich < 0 || ($vergleich === 0 && $e->validFrom > $treffer->validFrom)) {
                $treffer = $e;
            }
        }
        return $treffer;
    }

    /** Bequemlichkeit: das Fenster relativ zu einem Stichtag auswerten. */
    public function lowestBefore(array $events, \DateTimeImmutable $stichtag): ?PriceEvent
    {
        [$von, $bis] = $this->bounds($stichtag);
        return $this->lowestIn($events, $von, $bis);
    }

    /**
     * Liegt lückenlose Historie über das volle Fenster vor?
     *
     * Erst dann ist der Referenzwert im Sinne des § 11 voll belastbar. Vorher wird er
     * trotzdem geschrieben — bei einem Neuartikel ist „niedrigster Preis seit
     * Angebotsbeginn" genau richtig —, aber `window_complete = 0` hält fest, dass die
     * Aussage auf kürzerer Historie beruht.
     *
     * @param PriceEvent[] $events
     */
    public function isComplete(array $events, \DateTimeImmutable $heute): bool
    {
        [$von, $bis] = $this->bounds($heute);
        $tag = $von;
        while ($tag <= $bis) {
            $gedeckt = false;
            foreach ($events as $e) {
                if ($e->validFrom <= $tag && ($e->validTo === null || $e->validTo >= $tag)) {
                    $gedeckt = true;
                    break;
                }
            }
            if (!$gedeckt) {
                return false;
            }
            $tag = $tag->modify('+1 day');
        }
        return true;
    }
}
