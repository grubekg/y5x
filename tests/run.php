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
use Grube\Price30\Calc\Replay;
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

function rechner(string $modus = ReferenceCalculator::MODE_FROZEN, int $permanent = 60,
                 bool $prev = false, int $prevTage = 42): ReferenceCalculator
{
    $w = new PriceWindow(30);
    return new ReferenceCalculator($w, new PromoStateMachine($w, $permanent), $modus,
        $prev, $prevTage);
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

// ------------------------------------------------- Kein Aktionskennzeichen (Entscheidung)
echo "\n[Der angewendete Preis ist das einzige Signal (Entscheidung 18.08.2026)]\n";
// Der iSHOP liefert kein Aktionskennzeichen, und es soll auch keines geben: Aktionen
// koennen aus verschiedenen Stellen stammen, ein Kennzeichen aus nur einer davon waere
// schlimmer als keines. Der Automat nimmt deshalb gar keinen Flag-Parameter mehr an.
pruefe('der Automat nimmt kein Aktionskennzeichen entgegen',
    (new ReflectionMethod(PromoStateMachine::class, 'advance'))->getNumberOfParameters() === 3);
pruefe('auch der Rechner nicht',
    (new ReflectionMethod(ReferenceCalculator::class, 'calculate'))->getNumberOfParameters() === 4);

$ohneSprung = [
    ev('2026-07-01', '2026-08-30', '100.0000', '119.00'),
    ev('2026-08-31', null, '100.0000', '119.00'),
];
pruefe('ohne Preissenkung keine Aktion — egal aus welcher Quelle sie stammen moechte',
    !$r->calculate($ohneSprung, new PromoState(), tag('2026-08-31'))->state->isPromo());
$sprung = [
    ev('2026-07-01', '2026-08-30', '100.0000', '119.00'),
    ev('2026-08-31', null, '83.1933', '99.00'),
];
$erkannt = $r->calculate($sprung, new PromoState(), tag('2026-08-31'));
pruefe('jede Senkung des angewendeten Preises wird erkannt', $erkannt->state->isPromo());
pruefe('die Begruendung nennt beide Preise',
    str_contains($erkannt->state->lastTransition, '119.00')
    && str_contains($erkannt->state->lastTransition, '99.00'),
    $erkannt->state->lastTransition);

// ------------------------------------------------- rolling als Rueckfallebene
echo "\n[rolling — die dokumentierte Schwaeche]\n";
$rRoll = rechner(ReferenceCalculator::MODE_ROLLING);
$ergR = $rRoll->calculate($staffel, new PromoState(), tag('2026-09-01'));
pruefe('rolling nimmt den Aktionspreis von gestern in die Referenz auf',
    Money::equals((string) $ergR->gross, '99.00'), (string) $ergR->gross);
pruefe('frozen liefert am selben Tag die gesetzlich richtige Referenz',
    Money::equals((string) $erg2->gross, '109.00'));

// ------------------------------------------------- Vorstufen-Anker PREV_* (§ 6.4)
echo "\n[PREV — Vorstufen-Anker fuer Abverkaufs-Preistreppen]\n";
$rp = rechner(ReferenceCalculator::MODE_FROZEN, 60, true, 42);

// Preistreppe: 119,00 -> 99,00 (Stufe 1) -> 89,00 (Stufe 2)
$treppe1 = [
    ev('2026-07-01', '2026-08-30', '100.0000', '119.00'),
    ev('2026-08-31', null,         '83.1933', '99.00'),
];
$s1 = $rp->calculate($treppe1, new PromoState(), tag('2026-08-31'));
pruefe('PREV wird bei Stufenstart auf die Vorstufe gesetzt',
    Money::equals((string) $s1->prevGross, '119.00'), (string) $s1->prevGross);
pruefe('PREV netto stammt aus demselben Event',
    Money::equals((string) $s1->prevNet, '100.0000'), (string) $s1->prevNet);
// Die beiden Werte fallen nur auseinander, wenn das Fenster einen Einbruch enthaelt:
// Die 30er-Referenz ist das MINIMUM des Fensters, PREV die UNMITTELBARE Vorstufe.
$mitDelle = [
    ev('2026-07-01', '2026-08-09', '100.0000', '119.00'),
    ev('2026-08-10', '2026-08-14', '91.5966', '109.00'),   // kurzer Einbruch im Fenster
    ev('2026-08-15', '2026-08-30', '100.0000', '119.00'),
    ev('2026-08-31', null,         '83.1933', '99.00'),
];
$sd = $rp->calculate($mitDelle, new PromoState(), tag('2026-08-31'));
pruefe('30er-Referenz ist das Fenster-MINIMUM (109,00)',
    Money::equals((string) $sd->gross, '109.00'), (string) $sd->gross);
pruefe('PREV ist die UNMITTELBARE Vorstufe (119,00) — nicht dasselbe',
    Money::equals((string) $sd->prevGross, '119.00')
    && !Money::equals((string) $sd->gross, (string) $sd->prevGross), (string) $sd->prevGross);

$treppe2 = [
    ev('2026-07-01', '2026-08-30', '100.0000', '119.00'),
    ev('2026-08-31', '2026-09-09', '83.1933', '99.00'),
    ev('2026-09-10', null,         '74.7899', '89.00'),
];
$s2 = $rp->calculate($treppe2, $s1->state, tag('2026-09-10'));
pruefe('weitere Stufe laesst PREV auf dem Preis VOR der Aktion stehen',
    Money::equals((string) $s2->prevGross, '119.00'), (string) $s2->prevGross);
pruefe('weitere Stufe setzt den Timer zurueck',
    $s2->state->lastReductionAt?->format('Y-m-d') === '2026-09-10',
    $s2->state->lastReductionAt?->format('Y-m-d') ?? 'null');

// Zeitablauf: 42 Tage nach der letzten Senkung ist der Anker verbraucht.
$lang = [
    ev('2026-07-01', '2026-08-30', '100.0000', '119.00'),
    ev('2026-08-31', null,         '83.1933', '99.00'),
];
$nach40 = $rp->calculate($lang, $s1->state, tag('2026-10-10'));   // 40 Tage
pruefe('PREV haelt innerhalb der Frist', $nach40->hasPrev(), $nach40->prevOrigin);
$nach45 = $rp->calculate($lang, $s1->state, tag('2026-10-15'));   // 45 Tage
pruefe('PREV wird nach Fristablauf geleert', !$nach45->hasPrev(), $nach45->prevOrigin);
pruefe('die 30er-Referenz bleibt davon unberuehrt', $nach45->hasValue());

// Rueckkehr zu normal leert den Anker.
$zurueckNormal = [
    ev('2026-07-01', '2026-08-30', '100.0000', '119.00'),
    ev('2026-08-31', '2026-09-04', '83.1933', '99.00'),
    ev('2026-09-05', null,         '100.0000', '119.00'),
];
$s3 = $rp->calculate($zurueckNormal, $s1->state, tag('2026-09-05'));
pruefe('Rueckkehr zu normal leert PREV', !$s3->hasPrev() && !$s3->state->isPromo(),
    $s3->prevOrigin);

// Feature-Flag aus.
$ohne = rechner(ReferenceCalculator::MODE_FROZEN, 60, false)
    ->calculate($treppe1, new PromoState(), tag('2026-08-31'));
pruefe('prev_price_enabled: false unterdrueckt jeden PREV-Wert', !$ohne->hasPrev(),
    $ohne->prevOrigin);
pruefe('die Pflicht-Referenz laeuft trotzdem', $ohne->hasValue());

// permanent_after_days MUSS groesser sein als die laengste Aktion — Gegenprobe.
$langeAktion = [
    ev('2026-06-01', '2026-07-31', '100.0000', '119.00'),
    ev('2026-08-01', null,         '83.1933', '99.00'),
];
$mit30 = rechner(ReferenceCalculator::MODE_FROZEN, 30)
    ->calculate($langeAktion, rechner(ReferenceCalculator::MODE_FROZEN, 30)
        ->calculate($langeAktion, new PromoState(), tag('2026-08-01'))->state,
        tag('2026-09-05'));
$mit60 = rechner(ReferenceCalculator::MODE_FROZEN, 60)
    ->calculate($langeAktion, rechner(ReferenceCalculator::MODE_FROZEN, 60)
        ->calculate($langeAktion, new PromoState(), tag('2026-08-01'))->state,
        tag('2026-09-05'));
pruefe('mit permanent_after_days=30 kippt eine 35-Tage-Aktion faelschlich auf den Aktionspreis',
    !$mit30->state->isPromo() && Money::equals((string) $mit30->gross, '99.00'),
    (string) $mit30->gross);
pruefe('mit dem neuen Standard 60 bleibt die Referenz korrekt eingefroren',
    $mit60->state->isPromo() && Money::equals((string) $mit60->gross, '119.00'),
    (string) $mit60->gross);

// ------------------------------------------------- Nachrechnung zum Stichtag
echo "\n[Nachrechnung zum Stichtag — der Abmahnungsfall]\n";
$replay = new Replay($r);

// Historie mit einer Aktion, die am 31.08. begann und am 02.09. endete.
$fall = [
    ev('2026-07-01', '2026-08-09', '100.0000', '119.00'),
    ev('2026-08-10', '2026-08-14', '91.5966', '109.00'),
    ev('2026-08-15', '2026-08-30', '100.0000', '119.00'),
    ev('2026-08-31', '2026-09-01', '83.1933', '99.00'),   // Aktion
    ev('2026-09-02', '2026-09-20', '100.0000', '119.00'),
];

$amAktionstag = $replay->until($fall, tag('2026-08-31'));
pruefe('Stichtag 31.08. (Aktionstag 1): Referenz 109,00',
    $amAktionstag !== null && Money::equals((string) $amAktionstag->gross, '109.00'),
    (string) $amAktionstag?->gross);
pruefe('Stichtag 31.08.: Zustand war promo', (bool) $amAktionstag?->state->isPromo());

$amTag2 = $replay->until($fall, tag('2026-09-01'));
pruefe('Stichtag 01.09. (Aktionstag 2): Referenz UNVERAENDERT 109,00',
    Money::equals((string) $amTag2?->gross, '109.00'), (string) $amTag2?->gross);

$davor = $replay->until($fall, tag('2026-08-20'));
pruefe('Stichtag 20.08. (vor der Aktion): normal, Referenz 109,00',
    !$davor?->state->isPromo() && Money::equals((string) $davor?->gross, '109.00'));

$danach = $replay->until($fall, tag('2026-09-05'));
pruefe('Stichtag 05.09. (nach der Aktion): normal, Fenster enthaelt jetzt 99,00',
    !$danach?->state->isPromo() && Money::equals((string) $danach?->gross, '99.00'),
    (string) $danach?->gross);

pruefe('vor dem ersten bekannten Tag gibt es keinen Nachweis',
    $replay->until($fall, tag('2026-06-30')) === null);

// Kein Wissen aus der Zukunft: dieselbe Historie, aber Stichtag VOR der Aktion —
// der spaetere Aktionspreis darf die damalige Referenz nicht beeinflussen.
$mitZukunft = $replay->until($fall, tag('2026-08-20'));
$ohneZukunft = $replay->until(array_slice($fall, 0, 3), tag('2026-08-20'));
pruefe('Nachrechnung nutzt kein Wissen aus der Zukunft',
    Money::equals((string) $mitZukunft?->gross, (string) $ohneZukunft?->gross));

$preis = $replay->priceOn($fall, tag('2026-08-31'));
pruefe('geltender Preis am Stichtag ist belegbar',
    $preis !== null && Money::equals($preis->gross, '99.00'));

$tage = $replay->windowDays($fall, tag('2026-08-31'));
pruefe('Fenster wird tagesweise belegt (30 Tage)', count($tage) === 30, (string) count($tage));
pruefe('erster Fenstertag ist 01.08.', $tage[0]['date'] === '2026-08-01', $tage[0]['date']);
pruefe('letzter Fenstertag ist 30.08.', $tage[29]['date'] === '2026-08-30', $tage[29]['date']);
$mitPreis = array_filter($tage, static fn($t) => $t['gross'] !== null);
pruefe('alle 30 Fenstertage sind mit einem Preis belegt', count($mitPreis) === 30);

// --------------------------------------- das Protokoll darf nicht falsch berichten
echo "\n[Schreibprotokoll — der Lauf berichtet ueber sich selbst]\n";
// Bis zum 19.08.2026 schrieb laufBeenden() fest `pss_writes = 0` und die Notiz
// "Schreib-Adapter noch nicht gebaut", waehrend an diesem Tag 391.968 Saetze
// fehlerfrei an den PSS gingen. Ein Werkzeug, das eine Beweiskette traegt, darf ueber
// die eigene Arbeit nicht falsch berichten — deshalb steht das hier als Riegel.
$quelle = (string) file_get_contents(__DIR__ . '/../src/Cli/Run.php');
pruefe('kein fest verdrahtetes pss_writes = 0 mehr',
    !str_contains($quelle, 'pss_writes = 0,'));
pruefe('die Notiz behauptet keinen fehlenden Schreibadapter',
    !str_contains($quelle, 'Schreib-Adapter noch nicht gebaut'));
pruefe('laufBeenden nimmt den Schreibmodus des Laufs entgegen',
    (new ReflectionMethod(\Grube\Price30\Cli\Run::class, 'laufBeenden'))
        ->getNumberOfParameters() === 5);
pruefe('der Schreibmodus wird in die Zeile geschrieben',
    str_contains($quelle, 'write_mode = ?') && str_contains($quelle, 'write_errors = ?'));

// Die Notiz fasst zusammen, die Liste gehoert in eine Datei: Zehn Anomalien im Klartext
// plus "… (+31 weitere)" ergaben auf der Statusseite 120 sichtbare Zeichen — die eine
// Zeile, die man im Zweifel braucht, war zuverlaessig abgeschnitten.
pruefe('die Notiz traegt die Anomalienliste nicht mehr im Klartext',
    !str_contains($quelle, "' | verworfen: '"));
pruefe('jeder Befund wird einzeln abgelegt',
    str_contains($quelle, '{p}run_issue'));
pruefe('auch Fehler bekommen ihre Artikelnummer',
    str_contains($quelle, "\$fehler[] = ['sku' => \$sku"));
pruefe('der Deckel fuer Einzelbefunde steht als Konstante',
    (new ReflectionClassConstant(\Grube\Price30\Cli\Run::class, 'BEFUNDE_MAX'))
        ->getValue() > 0);

// ----------------------------------------- der Schreibschluessel traegt den Provider
echo "\n[Schreibschluessel — provider=preisschreiber]\n";
// Der grosse ERP-Import ersetzt den Preisbestand eines Landes und raeumt alles weg, was
// nicht aus ihm stammt (Auskunft Entwickler iSHOP, 19.08.2026). Ohne den Provider im
// Schluessel verschwinden die Werte nach jedem Import wieder — und nichts sieht nach
// Fehler aus, weil der PSS jeden Satz mit 204 quittiert.
$schreibMcs = (new ReflectionMethod(\Grube\Price30\Cli\Run::class, 'mcsSchreiben'));
$schreibMcs->setAccessible(true);
$leer = (new ReflectionClass(\Grube\Price30\Cli\Run::class))->newInstanceWithoutConstructor();
$maerkteFeld = (new ReflectionProperty(\Grube\Price30\Cli\Run::class, 'markets'));
$maerkteFeld->setAccessible(true);
$maerkteFeld->setValue($leer, ['DE' => ['currency' => 'EUR']]);
$schluessel = $schreibMcs->invoke($leer, 'DE', 'EUR');
pruefe('der Schreibschluessel entspricht der Vorlage des Entwicklers',
    $schluessel === '[brand=grube country=de currency=EUR provider=preisschreiber]',
    $schluessel);

$leseMcs = (new ReflectionMethod(\Grube\Price30\Cli\Run::class, 'mcs'));
$leseMcs->setAccessible(true);
pruefe('der Leseschluessel bleibt OHNE Provider — der Shop kennt ihn nicht',
    $leseMcs->invoke($leer, 'DE', 'EUR') === '[brand=grube country=de currency=EUR]');

pruefe('die Kennung steht als Konstante, nicht in der Konfiguration',
    \Grube\Price30\Cli\Run::PROVIDER === 'preisschreiber');

echo "\n" . (empty($FAILS)
    ? "ALLE SZENARIEN BESTANDEN"
    : count($FAILS) . ' FEHLGESCHLAGEN: ' . implode(', ', $FAILS)) . "\n";
exit(empty($FAILS) ? 0 : 1);
