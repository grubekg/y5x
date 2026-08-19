#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Rücklese-Prüfung: Steht im PSS noch, was wir geschrieben haben?
 *
 *   php bin/nachlese.php [--stichprobe 50] [--market DE] [--csv datei.csv]
 *
 * **Warum es das gibt.** Am 19.08.2026 quittierte der PSS 391.968 Schreibsätze mit
 * HTTP 204, und für DE, FR, DK und SE war anschließend **kein einziger** davon
 * auffindbar; für AT, PL und SK standen sie alle. Ein sofort wiederholter Schreibsatz
 * landete und war lesbar — der Schreibweg ist also in Ordnung, die Werte verschwinden
 * später wieder. Eine Erfolgsquittung beweist demnach nur, dass der Aufruf angenommen
 * wurde, nicht dass der Wert bleibt.
 *
 * Für ein Werkzeug, das eine Beweiskette trägt, ist das der gefährlichste Zustand
 * überhaupt: Alles meldet Erfolg, und im Shop steht nichts. Diese Prüfung fragt
 * deshalb nach, statt zu glauben — und zwar mit Abstand zum Schreiben, denn genau
 * dazwischen geht der Wert verloren.
 *
 * **Ursache geklärt am 19.08.2026** (Entwickler iSHOP): Der große ERP-Import ersetzt den
 * Preisbestand eines Landes und räumt dabei alles weg, was nicht aus ihm stammt. Behoben
 * durch `provider=preisschreiber` im Schreibschlüssel. Die Prüfung bleibt trotzdem — sie
 * ist der Riegel, der den Fehler überhaupt sichtbar gemacht hat, und der nächste Import,
 * der etwas anders macht, fällt wieder nur hier auf.
 */
require __DIR__ . '/../autoload.php';

use Grube\Price30\Support\Db;
use Grube\Price30\Support\Env;
use Grube\Price30\Support\Http;
use Grube\Price30\Support\Money;

$laufzeit = __DIR__ . '/..';
$opt = static function (string $name, ?string $vorgabe = null) use ($argv): ?string {
    foreach ($argv as $i => $a) {
        if ($a === "--$name") { return $argv[$i + 1] ?? ''; }
    }
    return $vorgabe;
};

$db      = Db::fromRuntime($laufzeit);
$env     = new Env($laufzeit . '/.env');
$markets = (\yaml_parse_file($laufzeit . '/config/markets.yml') ?: [])['markets'] ?? [];
$stich   = \max(1, (int) $opt('stichprobe', '50'));
$nur     = $opt('market', null);
$csv     = $opt('csv', null);

$http = new Http($env->get('PSS_BASE_URL'), $env->get('PSS_USER'), $env->get('PSS_PASS'));

\printf("Rücklese-Prüfung (%s) · Stichprobe %d je Markt\n\n", $db->env, $stich);
\printf("%-4s %8s %10s %8s %11s  %s\n", 'Markt', 'geprüft', 'vorhanden', 'fehlt', 'abweichend', 'Befund');

$befunde = [];
$gesamt = ['gepruef' => 0, 'da' => 0, 'weg' => 0, 'anders' => 0];

foreach ($markets as $code => $m) {
    if (!($m['active'] ?? false) || ($nur !== null && $nur !== '' && $code !== $nur)) { continue; }
    if (!($m['write_enabled'] ?? false)) {
        \printf("%-4s %8s %10s %8s %11s  %s\n", $code, '—', '—', '—', '—',
            'nur Beobachtung (write_enabled aus)');
        continue;
    }
    $waehrung = (string) ($m['currency'] ?? 'EUR');
    // Derselbe Schluessel, unter dem geschrieben wird — mit `provider`. Ohne ihn suchte
    // die Pruefung an der Stelle, an der seit dem 19.08.2026 nichts mehr steht, und
    // meldete dauerhaft "alles weg".
    $mcs = \sprintf('[brand=%s country=%s currency=%s provider=%s]',
        (string) ($m['shop_brand'] ?? 'grube'), \strtolower((string) $code), $waehrung,
        \Grube\Price30\Cli\Run::PROVIDER);

    // Zufällige Stichprobe statt der ersten N: Die ersten N sind immer dieselben
    // Artikel, und ein Verlust, der nur einen Teil des Sortiments trifft, bliebe
    // damit dauerhaft unsichtbar.
    $zeilen = $db->query(
        "SELECT sku, last_written_30_gross AS soll, last_written_at
           FROM {p}price_state
          WHERE market = ? AND last_written_30_gross IS NOT NULL
          ORDER BY RAND() LIMIT $stich", [$code]);
    if ($zeilen === []) {
        \printf("%-4s %8s %10s %8s %11s  %s\n", $code, '0', '—', '—', '—',
            'nichts geschrieben, nichts zu prüfen');
        continue;
    }

    $da = $weg = $anders = 0;
    // Der Abzug liefert ALLE Preiseinträge der angefragten Artikel — bei rund tausend
    // Einträgen je Artikel wird das schnell groß. Deshalb in kleinen Bündeln.
    foreach (\array_chunk($zeilen, 5) as $block) {
        $skus = \array_column($block, 'sku');
        try {
            $antwort = $http->json('', ['skus' => \implode(',', $skus)]);
        } catch (\Throwable $e) {
            \printf("  %s: Abruf fehlgeschlagen — %s\n", $code, $e->getMessage());
            continue;
        }
        $ist = [];
        foreach (\is_array($antwort) ? $antwort : [] as $e) {
            if (($e['priceType'] ?? '') === '30_GROSS' && ($e['mcs'] ?? '') === $mcs) {
                $ist[(string) $e['sku']] = (string) $e['price'];
            }
        }
        foreach ($block as $z) {
            $soll = (string) $z['soll'];
            $habe = $ist[$z['sku']] ?? null;
            if ($habe === null) {
                $weg++;
                $befunde[] = [$code, $z['sku'], $soll, '', 'fehlt', $z['last_written_at']];
            } elseif (!Money::equals($soll, $habe)) {
                $anders++;
                $befunde[] = [$code, $z['sku'], $soll, $habe, 'abweichend', $z['last_written_at']];
            } else {
                $da++;
            }
        }
    }
    $n = $da + $weg + $anders;
    $gesamt['gepruef'] += $n; $gesamt['da'] += $da;
    $gesamt['weg'] += $weg; $gesamt['anders'] += $anders;
    \printf("%-4s %8d %10d %8d %11d  %s\n", $code, $n, $da, $weg, $anders,
        $weg === 0 && $anders === 0 ? 'alles da'
            : \sprintf('%.1f %% der Stichprobe fehlt oder weicht ab', 100 * ($weg + $anders) / \max(1, $n)));
}

\printf("\nGESAMT  geprüft %d · vorhanden %d · fehlt %d · abweichend %d\n",
    $gesamt['gepruef'], $gesamt['da'], $gesamt['weg'], $gesamt['anders']);

if ($csv !== null && $csv !== '') {
    $f = \fopen($csv, 'w');
    \fwrite($f, "\xEF\xBB\xBF");
    \fputcsv($f, ['Markt', 'Artikelnummer', 'geschrieben', 'gefunden', 'Befund', 'geschrieben am'], ';', '"', '');
    foreach ($befunde as $b) { \fputcsv($f, $b, ';', '"', ''); }
    \fclose($f);
    \printf("Befunde: %s (%d Zeilen)\n", $csv, \count($befunde));
}

// Rückgabewert, damit ein Cron das auswerten kann: 1 = es fehlt etwas.
exit(($gesamt['weg'] + $gesamt['anders']) > 0 ? 1 : 0);
