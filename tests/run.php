<?php
declare(strict_types=1);

/**
 * Prüfszenarien für die Berechnung — ohne Netz, ohne Datenbank, ohne Composer.
 *
 * Was hier geprüft wird, ist die Rechtsaussage des Werkzeugs. Jedes Szenario trägt
 * deshalb den Absatz des Briefings bzw. des § 11 PAngV, den es absichert.
 */
require __DIR__ . '/../autoload.php';

use Grube\Price30\Calc\EventJournal;
use Grube\Price30\Calc\PriceEvent;
use Grube\Price30\Calc\PriceWindow;
use Grube\Price30\Calc\PromoState;
use Grube\Price30\Calc\PromoStateMachine;
use Grube\Price30\Calc\ReferenceCalculator;
use Grube\Price30\Support\Money;

$FAILS = [];

function pruefe(string $name, bool $ok, string $detail = ''): void
{
    global $FAILS;
    echo '  ' . ($ok ? 'ok  ' : 'FEHL') . '  ' . $name . ($detail !== '' ? "   [$detail]" : '') . "\n";
    if (!$ok) {
        $FAILS[] = $name;
    }
}

function tag(string $s): \DateTimeImmutable
{
    return new \DateTimeImmutable($s . ' 00:00:00');
}

/** Ein Preisintervall bequem erzeugen. */
function ev(string $von, ?string $bis, string $net, string $gross, string $cur = 'EUR'): PriceEvent
{
    return new PriceEvent(tag($von), $bis === null ? null : tag($bis), $net, $gross, $cur);
}

function rechner(string $modus = ReferenceCalculator::MODE_FROZEN, int $permanent = 30): ReferenceCalculator
{
    $w = new PriceWindow(30);
    return new ReferenceCalculator($w, new PromoStateMachine($w, $permanent), $modus);
}

// ---------------------------------------------------------------- Geldbeträge
echo "[Geldbeträge — keine Fließkommafehler]\n";
pruefe('0,1 + 0,2 vergleicht exakt gegen 0,3',
    Money::equals(\bcadd('0.1', '0.2', 4), '0.3'));
pruefe('gleiche Beträge in verschiedener Schreibweise sind gleich',
    Money::equals('129', '129.0000'));
pruefe('Nullpreis wird nicht als positiv anerkannt', !Money::isPositive('0.00'));
pruefe('Negativpreis wird nicht als positiv anerkannt', !Money::isPositive('-1.00'));
$fehler = false;
try { Money::normalize('kein Preis'); } catch (\InvalidArgumentException) { $fehler = true; }
pruefe('unlesbarer Betrag wirft, statt still 0 zu werden', $fehler);

// ---------------------------------------------------------------- Fenster
echo "\n[Fenster — heute gehört nie dazu (§ 6.2)]\n";
$heute = tag('2026-08-31');
[$von, $bis] = (new PriceWindow(30))->bounds($heute);
pruefe('Fenster endet gestern', $bis->format('Y-m-d') === '2026-08-30', $bis->format('Y-m-d'));
pruefe('Fenster beginnt 30 Tage vor heute', $von->format('Y-m-d') === '2026-08-01', $von->format('Y-m-d'));

$events = [
    ev('2026-08-01', '2026-08-29', '100.0000', '119.00'),
    ev('2026-08-30', null, '84.0300', '99.99'),        // seit gestern gesenkt
];
$tiefstes = (new PriceWindow(30))->lowestBefore($events, $heute);
pruefe('gestern gesenkter Preis zählt zum Fenster',
    $tiefstes !== null && Money::equals($tiefstes->gross, '99.99'));

$heuteGesenkt = [
    ev('2026-08-01', '2026-08-30', '100.0000', '119.00'),
    ev('2026-08-31', null, '50.0000', '59.50'),        // ERST HEUTE gesenkt
];
$tiefstes = (new PriceWindow(30))->lowestBefore($heuteGesenkt, $heute);
pruefe('heute gesenkter Preis zählt NICHT zum Fenster',
    $tiefstes !== null && Money::equals($tiefstes->gross, '119.00'),
    $tiefstes?->gross ?? 'null');

$gleichstand = [
    ev('2026-08-02', '2026-08-10', '100.0000', '119.00'),
    ev('2026-08-11', null, '100.0000', '119.00'),
];
$tiefstes = (new PriceWindow(30))->lowestBefore($gleichstand, $heute);
pruefe('bei Gleichstand gewinnt das jüngste Event',
    $tiefstes !== null && $tiefstes->validFrom->format('Y-m-d') === '2026-08-11');

// ------------------------------------------------- Event-Fortschreibung (§ 5)
echo "\n[Event-Fortschreibung — die vier Faelle]\n";
$j = new EventJournal();

$p = $j->plan([], '100.0000', '119.00', 'EUR', tag('2026-08-01'));
pruefe('neuer Artikel oeffnet ein Intervall', $p['action'] === 'neu', $p['action']);

// `valid_to` ist der letzte BEOBACHTUNGSTAG, nicht NULL — siehe EventJournal.
$bestand = [ev('2026-08-01', '2026-08-04', '100.0000', '119.00')];
$p = $j->plan($bestand, '100.0000', '119.00', 'EUR', tag('2026-08-05'));
pruefe('unveraenderter Preis verlaengert bis heute',
    $p['action'] === 'unveraendert' && $p['close_at'] === '2026-08-05', $p['close_at'] ?? '-');

$p = $j->plan($bestand, '84.0300', '99.99', 'EUR', tag('2026-08-05'));
pruefe('geaenderter Preis schliesst gestern und oeffnet heute',
    $p['action'] === 'geaendert' && $p['close_at'] === '2026-08-04'
    && $p['open']?->validFrom->format('Y-m-d') === '2026-08-05');

$p = $j->plan($bestand, null, null, 'EUR', tag('2026-08-05'));
pruefe('verschwundener Artikel: bereits beendetes Intervall bleibt, wie es ist',
    $p['action'] === 'nichts', $p['action']);
$nochOffen = [ev('2026-08-01', null, '100.0000', '119.00')];
$p2 = $j->plan($nochOffen, null, null, 'EUR', tag('2026-08-05'));
pruefe('verschwundener Artikel mit offenem Intervall wird am Vortag beendet',
    $p2['action'] === 'verschwunden' && $p2['close_at'] === '2026-08-04', $p2['close_at'] ?? '-');
$nachher = $j->apply($nochOffen, $p2, tag('2026-08-05'));
pruefe('Historie bleibt nach dem Verschwinden erhalten', count($nachher) === count($nochOffen));

$p = $j->plan([], null, null, 'EUR', tag('2026-08-05'));
pruefe('unbekannter Artikel ohne Preis erzeugt nichts', $p['action'] === 'nichts');

// Waehrungswechsel gilt als Aenderung — sonst stuenden zwei Waehrungen in einem Intervall.
$p = $j->plan($bestand, '100.0000', '119.00', 'PLN', tag('2026-08-05'));
pruefe('Waehrungswechsel oeffnet ein neues Intervall', $p['action'] === 'geaendert');

// ------------------------------------------------- Aktionslogik (§ 6.2, frozen)
echo "\n[frozen — Aktionsstart, Staffelung, Ende (§ 11 PAngV)]\n";
$r = rechner();

// Vorgeschichte: 119,00 ueber lange Zeit, dazwischen eine Senkung auf 109,00.
$historie = [
    ev('2026-07-01', '2026-08-09', '100.0000', '119.00'),
    ev('2026-08-10', '2026-08-14', '91.5966', '109.00'),   // kurzzeitig guenstiger
    ev('2026-08-15', '2026-08-30', '100.0000', '119.00'),
];

// Tag 1 der Aktion: heute 99,00.
$mitAktion = array_merge($historie, [ev('2026-08-31', null, '83.1933', '99.00')]);
$erg = $r->calculate($mitAktion, new PromoState(), tag('2026-08-31'));
pruefe('Aktionsstart schaltet auf promo', $erg->state->isPromo(), $erg->state->lastTransition);
pruefe('Referenz ist das Minimum VOR der Aktion (109,00)',
    Money::equals((string) $erg->gross, '109.00'), (string) $erg->gross);
pruefe('Vorniveau wird festgehalten',
    Money::equals((string) $erg->state->prePromoGross, '119.00'));

// Tag 2: weitere Senkung auf 89,00 — progressive Staffelung, § 11 Abs. 2.
$staffel = array_merge($historie, [
    ev('2026-08-31', '2026-08-31', '83.1933', '99.00'),
    ev('2026-09-01', null, '74.7899', '89.00'),
]);
$erg2 = $r->calculate($staffel, $erg->state, tag('2026-09-01'));
pruefe('weitere Senkung waehrend der Aktion aendert die Referenz NICHT',
    Money::equals((string) $erg2->gross, '109.00'), (string) $erg2->gross);
pruefe('Zustand bleibt promo', $erg2->state->isPromo());

// Tag 3: zurueck auf 119,00 — Aktion beendet.
$ende = array_merge($historie, [
    ev('2026-08-31', '2026-08-31', '83.1933', '99.00'),
    ev('2026-09-01', '2026-09-01', '74.7899', '89.00'),
    ev('2026-09-02', null, '100.0000', '119.00'),
]);
$erg3 = $r->calculate($ende, $erg2->state, tag('2026-09-02'));
pruefe('Rueckkehr auf Vorniveau beendet die Aktion',
    !$erg3->state->isPromo(), $erg3->state->lastTransition);
pruefe('danach gilt wieder das rollierende Fenster (enthaelt jetzt 89,00)',
    Money::equals((string) $erg3->gross, '89.00'), (string) $erg3->gross);

echo "\n[frozen — dauerhafte Senkung und Oszillation]\n";
// Dauerhafte Senkung: 99,00 bleibt stehen. Nach permanent_after_days -> normal.
$rKurz = rechner(ReferenceCalculator::MODE_FROZEN, 10);
$dauer = [
    ev('2026-07-01', '2026-08-30', '100.0000', '119.00'),
    ev('2026-08-31', null, '83.1933', '99.00'),
];
$st = $rKurz->calculate($dauer, new PromoState(), tag('2026-08-31'))->state;
$nach5 = $rKurz->calculate($dauer, $st, tag('2026-09-05'));
pruefe('nach 5 Tagen noch promo (Schwelle 10)', $nach5->state->isPromo());
$nach10 = $rKurz->calculate($dauer, $st, tag('2026-09-10'));
pruefe('nach 10 unveraenderten Tagen gilt es als neues Normalniveau',
    !$nach10->state->isPromo(), $nach10->state->lastTransition);

// Oszillation: rauf/runter darf nicht in einem Dauer-promo enden.
$osz = [
    ev('2026-08-20', '2026-08-27', '100.0000', '119.00'),
    ev('2026-08-28', '2026-08-28', '91.5966', '109.00'),
    ev('2026-08-29', '2026-08-30', '100.0000', '119.00'),
    ev('2026-08-31', null, '91.5966', '109.00'),
];
$s1 = $r->calculate($osz, new PromoState(), tag('2026-08-31'))->state;
pruefe('erneute Senkung startet wieder eine Aktion', $s1->isPromo());
$zurueck = array_merge(array_slice($osz, 0, 3), [
    ev('2026-08-31', '2026-08-31', '91.5966', '109.00'),
    ev('2026-09-01', null, '100.0000', '119.00'),
]);
$s2 = $r->calculate($zurueck, $s1, tag('2026-09-01'))->state;
pruefe('Rueckkehr beendet sie wieder', !$s2->isPromo());

// ------------------------------------------------- Konsistenzregel (§ 6.1)
echo "\n[Konsistenzregel — net und gross aus DEMSELBEN Event]\n";
// Mehrwertsteuerwechsel: das guenstigere BRUTTO hat das hoehere NETTO.
$mwst = [
    ev('2026-08-02', '2026-08-15', '100.0000', '119.00'),   // 19 %
    ev('2026-08-16', null,         '107.2727', '118.00'),   // 10 %, brutto guenstiger
];
$ergM = $r->calculate($mwst, new PromoState(), tag('2026-08-31'));
pruefe('Referenztag wird ueber den Bruttopreis bestimmt',
    Money::equals((string) $ergM->gross, '118.00'), (string) $ergM->gross);
pruefe('netto stammt aus demselben Event, nicht aus dem netto-Minimum',
    Money::equals((string) $ergM->net, '107.2727'), (string) $ergM->net);
pruefe('das unabhaengige netto-Minimum waere 100,0000 gewesen — Paar haette nie existiert',
    !Money::equals((string) $ergM->net, '100.0000'));

// ------------------------------------------------- Anlaufphase (§ 6.3)
echo "\n[Anlaufphase — window_complete]\n";
$kurz = [ev('2026-08-25', null, '100.0000', '119.00')];
$ergK = $r->calculate($kurz, new PromoState(), tag('2026-08-31'));
pruefe('kurze Historie liefert trotzdem einen Wert',
    $ergK->hasValue() && Money::equals((string) $ergK->gross, '119.00'));
pruefe('window_complete ist dabei 0', !$ergK->windowComplete);
$voll = [ev('2026-07-01', null, '100.0000', '119.00')];
pruefe('lueckenlose Historie setzt window_complete',
    $r->calculate($voll, new PromoState(), tag('2026-08-31'))->windowComplete);
$luecke = [
    ev('2026-08-01', '2026-08-10', '100.0000', '119.00'),
    ev('2026-08-20', null, '100.0000', '119.00'),
];
pruefe('Luecke in der Historie verhindert window_complete',
    !$r->calculate($luecke, new PromoState(), tag('2026-08-31'))->windowComplete);

// ------------------------------------------------- Promo-Kennzeichen schlaegt Heuristik
echo "\n[Aktionskennzeichen des Shops schlaegt die Heuristik (§ 6.2)]\n";
$ohneSprung = [
    ev('2026-07-01', '2026-08-30', '100.0000', '119.00'),
    ev('2026-08-31', null, '100.0000', '119.00'),
];
$mitFlag = $r->calculate($ohneSprung, new PromoState(), tag('2026-08-31'), 'EUR', true);
pruefe('Aktion ohne Preissprung wird per Kennzeichen erkannt', $mitFlag->state->isPromo());
$sprungOhneFlag = [
    ev('2026-07-01', '2026-08-30', '100.0000', '119.00'),
    ev('2026-08-31', null, '83.1933', '99.00'),
];
$keinFlag = $r->calculate($sprungOhneFlag, new PromoState(), tag('2026-08-31'), 'EUR', false);
pruefe('Preissprung OHNE Kennzeichen loest keine Aktion aus, wenn das Kennzeichen fuehrt',
    !$keinFlag->state->isPromo());

// ------------------------------------------------- rolling als Rueckfallebene
echo "\n[rolling — die dokumentierte Schwaeche]\n";
$rRoll = rechner(ReferenceCalculator::MODE_ROLLING);
$ergR = $rRoll->calculate($staffel, new PromoState(), tag('2026-09-01'));
pruefe('rolling nimmt den Aktionspreis von gestern in die Referenz auf',
    Money::equals((string) $ergR->gross, '99.00'), (string) $ergR->gross);
pruefe('frozen liefert am selben Tag die gesetzlich richtige Referenz',
    Money::equals((string) $erg2->gross, '109.00'));

echo "\n" . (empty($FAILS)
    ? "ALLE SZENARIEN BESTANDEN"
    : count($FAILS) . ' FEHLGESCHLAGEN: ' . implode(', ', $FAILS)) . "\n";
exit(empty($FAILS) ? 0 : 1);
