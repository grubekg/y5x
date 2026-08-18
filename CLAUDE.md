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

**Es gibt kein Aktionskennzeichen — dauerhaft** (Entscheidung GRUBE, 18.08.2026). Der
iSHOP liefert keines, und es ist auch keines gewünscht: Aktionen können aus
**verschiedenen Stellen** stammen, und ein Kennzeichen aus nur einer davon wäre
**schlimmer als gar keines**. Der Automat würde ihm ausschließlich glauben und jede
Aktion aus einer anderen Quelle stillschweigend übersehen — ein „nein" bei laufender
Aktion erzeugt genau die falsche Werbeaussage, die dieses Werkzeug verhindern soll.

Deshalb nimmt der Automat **gar keinen Flag-Parameter mehr entgegen** (ein Test hält das
per Reflection fest). Ein ungenutzter Parameter wäre eine Einladung, später eine einzelne
Aktionsquelle anzuklemmen. Das einzige verlässliche Signal ist der **tatsächlich
angewendete Preis aus dem Shop** — er ist die Vereinigung aller Aktionsquellen, gleich
woher sie kommen.

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

**Da es kein Aktionskennzeichen geben wird, ist diese Grenze dauerhaft tragend.** Ohne
externes Signal lässt sich eine lange Aktion nicht von einer dauerhaften Senkung
unterscheiden, und es gibt hier keine Einstellung ohne Nachteil:

| | Folge |
|---|---|
| zu **niedrig** | Referenz kippt mitten in einer langen Aktion auf den Aktionspreis |
| zu **hoch** | echte Dauersenkung schleppt monatelang eine überholte Referenz mit |

Die Grenze muss über der **längsten tatsächlich geplanten Aktionsdauer** liegen. Diese
Zahl ist damit eine Geschäftsentscheidung, keine technische — sie steht offen.

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

## Woher die Preise kommen — gefunden am 18.08.2026

**Die Routenliste macht das Raten überflüssig.** `GET /admin/` liefert alle 253
Admin-Endpunkte als JSON. Vorher hatte ich vergeblich nach GraphQL gesucht (404 auf
`/admin/graphql`, `/api/graphql`, `/admin/v3/api-docs` …).

```
GET /admin/pssoverview/prices/shop/get/{sku}/{customerGroup}/{customer}
-> {"prices": {"prices": {"0": "949,00 "}, "prices_net": {"0": "797,48 "}}}
```

`prices.prices` ist brutto, `prices.prices_net` netto, der Schlüssel `"0"` ist die
Menge — genau die Grundmenge (`amount 0`), die das Briefing verlangt. Rund **0,07–0,2 s**
je Artikel.

**Bewusst NICHT `/admin/pssoverview/prices/activeCache/get/...`:** Der liefert die rohen
PSS-Einträge mitsamt `provider: {"excludePromotions": true}` — den Preis **ohne**
Aktionen. `.../shop/get/...` ist die Sicht des Shops.

### Die Artikelliste: eine Anfrage statt tausend

Der naheliegende Weg — Produkte suchen, je Produkt `os/info` — kostet gemessen **0,27 s
und 389 KB pro Produkt**: für 1.000 Artikel rund fünf Minuten und 270 MB, nur um Nummern
zu erfahren. Artikel sind aber selbst suchbar:

```
/admin/os/overview?searchType=search_attr&searchEntries[0].negate=POS
  &searchEntries[0].name=com.novomind.ishop.core.Item.sku&searchEntries[0].comp=EXISTS
-> 35.641 Artikel in 1,6 s
```

Und weil die Ergebnistabelle den Wert des gesuchten Attributs als eigene Spalte führt,
steht die Artikelnummer direkt darin — kein zweiter Abruf. `negate=POS` ist Pflicht:
Ohne den Parameter sucht der Object Storage das **Gegenteil** und liefert eine sauber
formatierte, aber falsche Trefferliste.

> **PHP-Falle, die eine Stunde gekostet hätte:** `array_keys()` wandelt rein numerische
> Schlüssel in **Integer** um — aus der Artikelnummer wird eine Zahl, und führende Nullen
> gehen verloren. Deshalb `array_map(strval(...), array_keys(...))`.

### Erster Echtlauf (18.08.2026, 1.000 Artikel, DE)

| | |
|---|---|
| Dauer | 119 s (Folgelauf 83 s) |
| gelesen / Fehler | 1.000 / **0** |
| angelegte Intervalle | 999 |
| **verworfen** | **1** — Artikel 1147934587 mit 0,00 € netto und brutto |
| Netto/Brutto | durchweg Faktor ≈ 1,19; Spanne 0,35 € bis 6.199,00 € |
| PSS-Writes | **0** (Adapter existiert nicht) |

Der Anomalie-Filter aus § 3 hat also im allerersten Lauf gegriffen — ohne ihn wäre ein
Nullpreis als 30-Tage-Minimum durchgelaufen und hätte eine Ersparnis von 100 %
ausgewiesen. **Verworfene Datensätze werden mit Artikelnummer und Begründung
protokolliert**, nicht bloß gezählt: „1 Anomalie" lässt sich später niemandem erklären.

Der zweite Lauf meldete 999 × `unveraendert` und 0 × `neu` — die Fortschreibung ist
idempotent.

## Datenbank — die eine Regel, die hier alles trägt

Alle Projekte und **beide Umgebungen** teilen sich eine MySQL-Datenbank; getrennt wird
nur über den Tabellennamen. `Support\Db` kennt deshalb keinen Weg zu einer Tabelle ohne
Präfix — `query()` weist einen nackten Namen zurück. Ein Staging-Lauf, der in die
Produktionstabellen schriebe, verfälschte die Beweisgrundlage.

## Das Dashboard — Gestaltungssystem „Prüfprotokoll"

Kein SaaS-Look, sondern dokumentarische Ruhe: Papierton, Haarlinien, gesperrte
Versal-Sektionslabels wie auf Prüfformularen, Monospace mit Tabellenziffern für alles
Faktische, damit Beträge in Spalten stehen wie gedruckt. Markenfläche in Tannengrün —
GRUBE ist Forst. Ohne Framework, Webfonts, CDN oder Build-Schritt; das SVG entsteht
serverseitig aus `price_events`. Vorlagen und Begründung: `docs/design/`.

Zwei Regeln gelten überall:

* **Farbe trägt nie allein.** Jeder Status hat Zeichen UND Wort (✓ gesund, ! Anlauf,
  × fehlgeschlagen, ◌ läuft, ◧ Einrichtung) — Barrierefreiheit und zugleich die
  Voraussetzung, dass ein Schwarzweißdruck lesbar bleibt.
* **Rot ist echten Vorfällen vorbehalten.**

### „Nie gelaufen" ist kein Vorfall

Der vorherige Stand zeigte für ein System, das noch nie gelaufen war, achtmal
„Lücke > 26 h" in Rot plus einen Lauf mit Status `failed` und Notiz „laeuft". **Wenn ab
Tag 1 alles rot ist, ist Rot ab Tag 30 bedeutungslos** — Alarmmüdigkeit ist bei einem
Compliance-Werkzeug gefährlich.

`markt_zustand()` unterscheidet deshalb: `einrichtung` (kein erfolgreicher Lauf) ·
`laeuft` · `anlauf` (mit Fortschrittsbalken) · `vorfall` · `gesund`. Und die
Einrichtungs-Märkte werden zu **einem** Punkt zusammengefasst — sieben gleichlautende
Zeilen wären wieder Tapete, nur in anderer Farbe. Die Plakette sagt dann
„Aufbau läuft — noch nichts zu beanstanden", nicht „7 Probleme".

`run_log.status` kennt jetzt `laeuft`; ein Lauf ohne Abschluss wird vom **nächsten** Lauf
desselben Marktes ehrlich als `failed` geschlossen (»abgebrochen — kein Abschluss
protokolliert«), statt aus dem Alter geraten zu werden.

> **Der gemeldete „Umlaut-Fehler" war keiner.** Geprüft: `utf8mb4` trägt
> „Zeitüberschreitung · Prüfläufe · 79,95 €" fehlerfrei durch Verbindung, Spalte und
> Rücklesen. Die Ursache war banal — **ich** hatte die Notizen ohne Umlaute geschrieben
> („laeuft", „geaendert"), eine Angewohnheit aus Shell-Heredocs. Behoben wurde der Text,
> nicht der Zeichensatz.

### Ein Satz oben, dann Handlungen statt Zähler

Die Übersicht beantwortet zuerst: *„Muss ich hinfassen?"* Darunter die konkreten Punkte
mit Kontext und nächstem Schritt. Ein Dashboard, das nur zählt, erzwingt Detektivarbeit;
eines, das verlinkt, erledigt sie — **jede Zahl in der Markttabelle ist ein Link** auf die
entsprechend gefilterte Artikelliste.

Der Trockenmodus steht als Chip permanent im Kopf, und alle Schreibzahlen tragen `sim.`
Die Kachel „Mit Referenz im PSS" zeigt im Trockenmodus **0**, nicht einen alten Stand:
Eine veraltete Zahl, die aussieht wie eine aktuelle, ist schlimmer als keine.

### Die Artikelliste umfasst ALLE Artikel

**Vorgabe GRUBE, 18.08.2026.** Der vorherige Stand listete nur laufende Aktionen — an
einen ruhenden Artikel kam man nur heran, wenn man seine Nummer auswendig wusste. Ein
Nachweiswerkzeug, das den gesuchten Artikel nicht finden lässt, ist im Ernstfall wertlos.
Die Filter (`alle` · `in Aktion` · `ohne Aktion` · `Risiko` · `Fenster unvollständig`)
sind Einschränkungen einer vollständigen Liste, keine Vorauswahl; dazu Suche nach
Artikelnummer und Blätterung zu 100 Zeilen.

### Die Nachweisseite ist das Herzstück

Signatur ist der **Messschrieb**: Treppenkurve, Fenster als schattiertes Band, Referenz
als rote Treppenlinie, PREV violett nur in Aktionsphasen, Stichtags-Läufer. Dazu die
Stichtagsprüfung als Stempelblock und ein **Druckmodus**, der die Seite in ein
eigenständiges Beweisdokument verwandelt: Kopfbogen mit Artikel, Zeitraum, Ersteller und
Quellenangabe erscheint nur im Druck, Navigation und Suche verschwinden.

Fällt die Referenz unter die Vorstufe, erklärt ein Hinweiskasten den Grund — so vermittelt
die Seite die Rechtslogik nebenbei jedem, der sie öffnet.

### Anmeldung

Generische Fehlermeldung (die Maske darf kein Kontoverzeichnis werden), Versuchssperre
nach 5 Fehlversuchen je Konto+IP für 15 Minuten, `session_regenerate_id(true)` bei Erfolg
und **jeder** Versuch im `login_log` mit Zeit, Konto und IP. Gehasht wird mit
`PASSWORD_DEFAULT` (derzeit bcrypt); das Template nannte argon2id — der Unterschied ist
hier ohne Belang, wichtig ist das Verfahren, nicht die Marke.

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

1. ~~iSHOP-Endpunkt~~ — **erledigt am 18.08.2026**, siehe oben. Offen bleibt allein die
   **Marktdimension**: Der Endpunkt kennt keinen Markt-Parameter und liefert die Preise
   des Standard-Shops. Wie AT/FR/PL/SK/SE/DK/CH abgefragt werden (eigener Host? Header?
   MCS-Parameter?), ist noch zu klären — ebenso, dass die Antwort **keine Währung**
   mitliefert; die Währungsprüfung aus § 3 stützt sich bis dahin allein auf `markets.yml`.
1b. **Längste geplante Aktionsdauer**, damit `permanent_after_days` darübergesetzt werden
   kann. Ohne Aktionskennzeichen trägt diese Zahl allein — sie ist jetzt die wichtigste
   offene Angabe.
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

## Zugang zur Statusseite

Benutzer werden mit `php bin/user.php add <mail> <passwort>` angelegt (nur der Hash wird
gespeichert). Angelegt in **staging**: `alexander.zindler@grube.de`.

Das ersetzt **keinen** Verzeichnisschutz: `.htaccess` wirkt auf nginx nicht, und die
Anmeldung schützt nur die Seiten selbst. Der Verzeichnisschutz für `status/` ist im
ISPConfig-Panel zu setzen.

## Abweichung vom Briefing, bewusst

`app.yml` steht auf `dry_run: true`, das Briefing nennt `false`. Solange die Adapter
fehlen, wäre `false` ohnehin wirkungslos; darüber hinaus soll das erste Schreiben in ein
Produktivsystem eine bewusste Handlung sein, kein Nebeneffekt eines Deploys. Beim
Scharfschalten umstellen.
