# y5x — 30-Tage-Bestpreis-Tracker

**Kürzel:** `y5x` · **Klarname:** 30-Tage-Bestpreis-Tracker · **Typ:** php (CLI-Cron + Statusseite)
**Branch-Regeln:** `main` → prod, `develop` → staging · **Repo:** github.com/grubekg/y5x
**Tabellen:** `y5x_prod_*` / `y5x_stg_*` · **URL:** grube.tools/y5x/status/ (staging: /staging/y5x/status/)

## Zweck und warum hier keine KI arbeitet

§ 11 PAngV verlangt bei Werbung mit Preisermäßigungen die Angabe des niedrigsten
Gesamtpreises der letzten 30 Tage **vor Anwendung der Ermäßigung**. Der EuGH
(C-330/23, Aldi Süd, 2024) hat klargestellt, dass sich auch beworbene
Prozent-Ersparnisse auf diesen Referenzpreis beziehen müssen.

Dieses Werkzeug liefert den Wert produktgenau je Markt an den PSS. Es ist damit Teil
einer **rechtlichen Beweiskette** — und deshalb vollständig deterministisch. Kein
Modell, keine Schätzung, keine Heuristik ohne dokumentierten Grund. Jede Zahl muss sich
aus `price_events` von Hand nachrechnen lassen.

Die **Darstellungslogik** (ob und wann der Wert im Frontend erscheint) liegt bei
iSHOP/Templates. Der Tracker schreibt den Wert für alle getrackten Artikel, immer.

## Der Rechenkern — was er tut und warum

Vier Klassen in `src/Calc/`, alle ohne Netz und ohne Datenbank prüfbar
(`php tests/run.php`, 33 Szenarien).

### `PriceWindow` — heute gehört nie ins Fenster

Das Fenster ist `[heute−30, gestern]`. Der heutige Preis ist der **Aktionspreis**, gegen
den verglichen wird; nähme man ihn in die Referenz auf, vergliche man ihn mit sich selbst.

### `EventJournal` — `valid_to` ist der letzte BEOBACHTUNGSTAG

Hier steckte ein Widerspruch im Briefing: Das Schema kommentiert `valid_to NULL` als
„aktuell", die Fortschreibungsregel setzt bei unverändertem Preis aber `valid_to = heute`.
Nach dem ersten Lauf wäre `valid_to` damit nie mehr NULL.

**Aufgelöst zugunsten der Fortschreibungsregel**, weil sie die beweiskräftigere ist:
`valid_to` sagt „bis hierhin haben wir diesen Preis gemessen". Ein NULL würde Geltung in
die Zukunft behaupten. Fällt ein Lauf aus, endet das Intervall ehrlich am letzten
Beobachtungstag, und die Lücke ist über `run_log` sichtbar statt stillschweigend
überbrückt. Das aktuelle Intervall ist folglich **das mit dem jüngsten `valid_from`**,
nicht „das mit NULL".

**Reihenfolge im Lauf:** erst Journal fortschreiben, dann rechnen.

### `PromoStateMachine` — warum eingefroren wird

Ein rollierendes Minimum genügt dem Gesetzeswortlaut nicht: Ab dem zweiten Aktionstag
steht der Aktionspreis selbst im Fenster, die ausgewiesene Referenz konvergiert gegen
ihn, und die beworbene Ersparnis läuft gegen null. Deshalb friert der Zustand `promo`
die Referenz auf das Fenster **vor** Aktionsbeginn ein.

§ 11 Abs. 2 (progressive Rabattstaffelung) ist der Grund, warum weitere Senkungen
während der Aktion nichts ändern. Getestet.

Zwei Riegel gegen Dauerzustände:
* Rückkehr auf das Vorniveau beendet die Aktion.
* Bleibt ein gesenkter Preis `permanent_after_days` (30) unverändert stehen, war es
  keine Aktion, sondern ein neues Normalniveau. Ohne diesen Riegel trüge ein dauerhaft
  gesenkter Artikel ewig eine überholte Referenz.

**Ein Aktionskennzeichen des Shops schlägt jede Heuristik.** Eine Preissenkung ist nicht
zwingend eine beworbene Ermäßigung, und eine Ermäßigung nicht zwingend eine Senkung
gegenüber gestern. Solange TODO(setup) 1 offen ist, gilt die Sprung-Heuristik.

### `Replay` — Nachrechnung zum Stichtag (der Abmahnungsfall)

Eine Abmahnung nennt ein **Datum**: „Am 14. Juli haben Sie mit −30 % geworben."
Zu beantworten ist dann nicht, was heute gilt, sondern was an jenem Tag galt.

`Replay` spielt den Zustandsautomaten vom ersten bekannten Tag an neu ab — jeweils
**nur mit den Events, die an diesem Tag schon bekannt waren**. Ohne diese Beschränkung
rechnete man mit Wissen aus der Zukunft, und der Nachweis wäre wertlos. Getestet.

Damit wird der Referenzwert **nachgerechnet, nicht nachgeschlagen**. Genau deshalb kann
das Dashboard auch die Gegenprobe ziehen: Stimmt die Nachrechnung mit dem überein, was
damals laut `pss_write_log` geschrieben wurde? Weichen sie ab, will man das selbst als
Erster wissen — nicht die Gegenseite.

### `PREV_*` — Vorstufen-Anker (§ 6.4, Nachtrag 18.08.2026)

Ein **eigener** Streichpreis für Abverkaufs-Preistreppen: immer der Preis der
unmittelbaren Vorstufe, nie der Ur-Normalpreis und nie die UVP (die kommt aus `zw7`,
eigener Kanal).

**Nicht mit der 30-Tage-Referenz verwechseln — sie beantworten verschiedene Fragen:**

| | beantwortet | Beispiel `DEMO-TREPPE` |
|---|---|---|
| `30_GROSS` | niedrigster Preis im Fenster **vor** der Ermäßigung — die rechtliche Basis | **109,00 €** (kurzer Einbruch im Fenster) |
| `PREV_GROSS` | Preis der unmittelbar vorangegangenen Stufe — freiwilliges Frontend-Futter | **119,00 €** |

Sie fallen nur auseinander, wenn das Fenster einen Einbruch enthält. Genau dieser Fall
steht als Fixture im Test, weil er sonst nie auffiele.

Zwei Leerungsgründe, und der zweite wird leicht vergessen: Rückkehr nach `normal` — und
**Zeitablauf** (`prev_price_max_days`, Vorgabe 42). Ein eigener Preis von vor Monaten
taugt nicht mehr als Streichpreis-Anker (UWG-Verschleiß); jede weitere Senkung setzt den
Timer zurück. `null` heißt im Ergebnis ausdrücklich **leeren**, nicht „unverändert
lassen" — ein abgelaufener Streichpreis muss aus dem Frontend verschwinden.

Die Pflicht-Referenzen `30_*` laufen davon völlig unabhängig weiter.

### `permanent_after_days` muss größer sein als die längste Aktion

Die Vorgabe wurde mit dem Nachtrag von 30 auf **60** angehoben, und der Test zeigt,
warum: Bei 30 Tagen kippt eine 35-Tage-Aktion fälschlich auf den Aktionspreis — die
Heuristik hält sie für ein neues Normalniveau, die ausgewiesene Referenz fällt von
119,00 € auf 99,00 €, und die beworbene Ersparnis verschwindet mitten in der Aktion.
Ohne externes Signal kann die Heuristik eine lange Aktion nicht von einer Dauersenkung
unterscheiden. **Mit einem iSHOP-Aktionskennzeichen entfällt sie vollständig** — ein
weiterer Grund, TODO(setup) 1 zu klären.

### `ReferenceCalculator` — Konsistenzregel

Der Referenz**tag** wird über den **Brutto**preis bestimmt; `30_NET` stammt dann aus
**demselben** Event. Zwei unabhängige Minima wären der subtilere Fehler: Bei einer
Mehrwertsteueränderung könnten sie aus verschiedenen Tagen stammen — 30_NET und
30_GROSS stünden in einem Verhältnis, das es nie gegeben hat. Fixture im Test:
119,00/100,00 (19 %) gegen 118,00/107,27 (10 %); das unabhängige Netto-Minimum wäre
100,00 und damit falsch.

### Geldbeträge nie als `float`

`Support\Money` rechnet mit `bcmath` auf Zeichenketten, genau wie die DECIMAL-Spalten.
Ein `float` kann 0,1 nicht exakt darstellen; ein Referenzpreis, der um einen Cent
danebenliegt, ist eine falsche Werbeaussage. Verglichen wird nur über `Money::compare`,
nie mit `<`, `==` oder `min()`. Ein unlesbarer Betrag **wirft**, statt still 0 zu werden —
ein Nullpreis als Minimum im PSS wäre der teuerste denkbare Fehler.

## PSS — was am 18.08.2026 gemessen wurde (löst TODO(setup) 2 teilweise)

Endpunkt `GET {PSS_BASE_URL}?skus=<liste>` mit Basic-Auth (Zugang wie `zw7`, Benutzer
`seo-index-agent`), `Accept: application/json`. Echter Eintrag, Artikel 5001961923:

```json
{ "sku": "5001961923", "priceType": "PRICE_GROSS", "price": 949.0,
  "customer": "0", "customerGroup": "DEFAULT", "amount": 0,
  "mcs": "[brand=grube country=de currency=EUR]",
  "validFrom": "2026-07-16T00:00:00", "validTo": "9999-12-31T23:59:59",
  "vatRate": 0.19, "priceUnit": "STCK",
  "provider": "{\"excludePromotions\": \"true\"}" }
```

Abweichungen vom Briefing, die der Adapter berücksichtigen muss:

| Briefing | tatsächlich |
|---|---|
| Wertfeld `value` | Wertfeld heißt **`price`** |
| „Site-/Markt-Zuordnungsfeld" gesucht | es ist **`mcs`**: `[brand=grube country=de currency=EUR]` |
| Feldliste unbekannt | zusätzlich `provider`, `priceUnit`, `vatRate`, `validFrom`, `validTo` |

Vorhandene priceTypes je SKU: `PRICE_NET/GROSS`, `RRP_NET/GROSS`, `UNREBATED_NET/GROSS`.

> ⚠️ **Der PSS ist für dieses Werkzeug ausschließlich ZIEL, niemals Quelle.**
> Gelesen wird im Betrieb **nur aus dem iSHOP**; in den PSS wird am Ende geschrieben.
> Der Lesezugang existiert (aus `zw7`) und wäre bequemer — genau deshalb steht die
> Warnung hier.
>
> Zwei unabhängige Gründe, und der zweite ist der schwerere:
>
> 1. `PRICE_GROSS` trägt `provider: {"excludePromotions": "true"}` — es ist der Preis
>    **ohne** Aktionen.
> 2. **Es gibt Aktionen, die im PSS überhaupt nicht abgebildet sind** (Auskunft GRUBE,
>    18.08.2026). Der PSS kann den tatsächlich angewendeten Endpreis also nicht einmal
>    im Grundsatz liefern — kein Feld, kein Flag, keine Umgehung.
>
> Maßgeblich ist der Preis, den ein anonymer Standardkunde im Shop tatsächlich zahlt.
> Den kennt nur der Shop. Ein aus dem PSS gespeister Tracker würde Aktionszeiträume
> stillschweigend übersehen und einen zu hohen Referenzpreis ausweisen — also genau die
> Werbeaussage verfälschen, die er absichern soll.
>
> Aus demselben Grund kann ein **PSS-Aktionskennzeichen die Frage aus TODO(setup) 1
> nicht beantworten.** Gebraucht wird das Kennzeichen des **Shops**.

Offene Datenauffälligkeit: Zwei Zeilen tragen ein doppelt maskiertes
`provider`-Feld (`{\"excludePromotions\": \"false\"}`) — beim Auswerten robust behandeln.

## Datenbank — die eine Regel, die hier alles trägt

Alle Projekte und **beide Umgebungen** teilen sich eine MySQL-Datenbank; getrennt wird
nur über den Tabellennamen. `Support\Db` kennt deshalb keinen Weg zu einer Tabelle ohne
Präfix — `query()` weist einen nackten Namen zurück. Ein Staging-Lauf, der in die
Produktionstabellen schriebe, verfälschte die Beweisgrundlage.

## Das Dashboard (grube.tools/staging/y5x/status/)

Kein Betriebsfenster, sondern ein **Verteidigungswerkzeug**. Zwei Seiten:

**`index.php` — Lage je Markt.** Die erste Kachel ist bewusst nicht die größte Zahl,
sondern die gefährlichste: *Artikel, die gerade eine Ermäßigung ausweisen, deren
30-Tage-Historie aber unvollständig ist.* Dort beruht eine laufende Werbeaussage auf
schwacher Grundlage — das will man vor einer Abmahnung wissen, nicht danach. Dazu je
Markt: getrackte Artikel, Anteil in Aktion, Schreibfreigabe (CH steht auf „aus"),
letzter Lauf mit **Lücken-Warnung ab 26 h**, Writes/Fehler/Anomalien der letzten 7 Tage.

**`artikel.php` — der Nachweis.** Artikel + Markt + **Stichtag** ergeben ein druckbares
Dokument (Anlage zum Schriftsatz): verlangter Preis an dem Tag, geltende 30-Tage-Referenz
mit dem **Beleg-Intervall, aus dem sie stammt**, der Zustand samt Begründung, die
Tagesabdeckung des Fensters mit benannten Lücken, sämtliche Preisintervalle und alles,
was laut `pss_write_log` je geschrieben wurde.

Das Diagramm ist serverseitiges SVG — kein JavaScript, keine externen Skripte, druckbar.
**Gezeichnet als Treppe, nicht als Kurve:** Ein Preis gilt über sein Intervall konstant
und springt dann; eine interpolierte Linie behauptete Zwischenpreise, die es nie gab.
Bei einem Beweismittel ist das keine Kosmetik. Lücken unterbrechen die Linie, statt sie
zu überbrücken.

`bin/demo-seed.php` legt drei Beispielartikel an, damit die Seiten vor dem ersten
Echtlauf prüfbar sind. Es **verweigert den Dienst in `prod`** — erfundene Preise haben in
der Beweisgrundlage nichts zu suchen.

## Befehle

```bash
bash deploy.sh staging                       # Code -> Laufzeit + Statusseite
php bin/init-db.php --env staging            # Schema anlegen
php bin/migrate.php --env staging            # Spalten in bestehenden Tabellen nachziehen
php tests/run.php                            # 47 Szenarien, ohne Netz/DB/Composer
php bin/demo-seed.php [--loeschen]           # Beispieldaten (nur staging)
```

## Betrieb

* **Trockenmodus ist der Auslieferungszustand** (`dry_run: true`). Scharfschalten ist
  eine bewusste Handlung.
* **Reihenfolge zwingend:** DiVA-Preisimport → iSHOP aktuell → Tracker-Lauf.
* `write_enabled: false` für **CH**: Die Schweizer Preisbekanntgabeverordnung folgt nicht
  der EU-30-Tage-Regel. Getrackt wird trotzdem, damit Historie vorliegt, wenn Legal
  entscheidet.
* **Delta-Writes:** `last_written_*` wird nur bei Erfolg gesetzt — dadurch holt der
  nächste Lauf einen fehlgeschlagenen Write von selbst nach.
* Statusseite liest den DB-Zugang aus der Laufzeit, nicht aus `$HOME/secrets/`: Der
  FPM-Pool darf nur `web/`, `private/` und `tmp/` lesen (Muster wie zw7/mbc/7he).

## Offen (TODO(setup))

1. **iSHOP:** Endpunkt/Query für die Preisabfrage `amount 0` (GraphQL-Introspection),
   Beispiel-Response — und die entscheidende Frage: **liefert sie ein Aktionskennzeichen?**
   Falls ja, ersetzt es die Sprung-Heuristik vollständig (`PromoStateMachine` ist darauf
   vorbereitet, `$promoFlag` durchreichen genügt).
2. **PSS-Write:** Upsert-Semantik (Update per Schlüssel vs. delete+create), Schreib-Endpunkt
   und Auth. Der Lese-Payload ist geklärt (siehe oben); zu klären bleibt, ob `30_NET` /
   `30_GROSS` als priceType überhaupt angelegt werden müssen.
3. Zeitpunkt des täglichen DiVA-Preisimports je Shop (Cron danach).
4. Entscheidung Anlauf: **Vorlauf** (30 Tage vor werblicher Nutzung produktiv) vs.
   **Backfill** (existiert eine Preishistorie der letzten 30+ Tage?).
5. `alert_email` und die Shop-Kennungen je Markt für `markets.yml`.
6. **`PREV_NET` / `PREV_GROSS` im PSS anlegen bzw. bestätigen** — und die
   Leerungs-Semantik klären: Eintrag löschbar, oder `value: 0` mit der
   Template-Konvention „0 = nicht anzeigen"? Der Rechenkern liefert `null` für „leeren";
   welcher Weg daraus wird, entscheidet der Adapter.
7. **`prev_price_max_days` von Legal kalibrieren lassen** (Vorgabe 42 Tage). Ebenso:
   Ist `permanent_after_days: 60` größer als die längste tatsächlich geplante Aktion?
8. Verzeichnisschutz für `status/` im ISPConfig-Panel; GitHub-Repo `grubekg/y5x` anlegen.

## Abweichung vom Briefing, bewusst

`app.yml` steht auf `dry_run: true`, das Briefing nennt `false`. Solange die Adapter
fehlen, wäre `false` ohnehin wirkungslos; darüber hinaus soll das erste Schreiben in ein
Produktivsystem eine bewusste Handlung sein, kein Nebeneffekt eines Deploys. Beim
Scharfschalten umstellen.
