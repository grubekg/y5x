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

## Befehle

```bash
bash deploy.sh staging                       # Code -> Laufzeit + Statusseite
php bin/init-db.php --env staging            # Schema anlegen
php tests/run.php                            # Rechenkerne, ohne Netz/DB/Composer
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
6. Verzeichnisschutz für `status/` im ISPConfig-Panel; GitHub-Repo `grubekg/y5x` anlegen.
