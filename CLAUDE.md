# y5x — Preisschreiber

**Kürzel:** `y5x` · **Klarname:** Preisschreiber · **Typ:** php (CLI-Cron + Statusseite)
Im Briefing hieß er *30-Tage-Bestpreis-Tracker* (`price30-tracker`); umbenannt am
18.08.2026 auf **Preisschreiber**. Die Herkunftsangabe bleibt hier stehen, damit der
Zusammenhang zwischen Briefing und Werkzeug auffindbar ist.
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

## Der Volllauf: 10,7 Minuten für acht Märkte (gemessen 18.08.2026, Integ)

Vollständiger Lauf über alle aktiven Märkte und den gesamten Bestand, mit der
korrigierten Preisquelle (vier Sammelabzüge statt zwei):

| | |
|---|---|
| **Gesamtdauer** | **643 s = 10,7 min** |
| davon Sammelabzug (einmalig für ALLE Märkte) | 490 s — laden und zerlegen von ~1 GB |
| davon die acht Märkte | 153 s (DE 61 · AT 20 · FR 10 · PL 3 · SK 4 · SE 7 · DK 28 · CH 27) |
| gelesen | 278.928 Artikel × Markt |
| Preisereignisse angelegt | 180.239 |
| im Markt nicht geführt | 98.753 |
| Anomalien verworfen | 58 |
| Fehler | **0** |

**Der Sammelabzug ist der Fixkostenanteil** und wächst nicht mit der Artikelzahl: Dieselben
vier Dateien enthalten alle acht Märkte, deshalb werden sie einmal geladen und achtmal
ausgewertet. Ein Lauf je Markt würde 8 GB statt 1 GB bedeuten.

Die Zahlen für PL (3.639), SK (89) und SE (5.361) sind **kein** Datenverlust: Auf der
Integration sind diese Märkte nur dünn bepreist (Auskunft GRUBE). Umgekehrt gibt es
Artikel, die es nur in einem Markt gibt — die Marktmengen sind nicht ineinander enthalten.

Für den Nachtlauf heißt das: Ein Zeitfenster von **20 Minuten** ist mit Reserve bemessen.

### „davon aus promotionPrices" ist NICHT „in Aktion"

Die Laufausgabe nennt je Markt, wie viele Werte aus `promotionPrices` stammen — und das
sind praktisch 100 %. Das heißt **nicht**, dass alles reduziert ist: Der Shop führt den
angewendeten Preis für fast jeden Artikel dort, auch wenn er dem Listenpreis entspricht.
Die Zahl sagt, WOHER der Wert kam. Wie viele Artikel tatsächlich ermäßigt sind, sagt
allein der Vergleich mit der Referenz — also das Dashboard, nicht der Lauf.

### Anomalien im echten Bestand

Die 90 verworfenen Datensätze sind keine Rundungsfehler:

| Art | Anzahl |
|---|---|
| Bruttopreis nicht positiv (0,00 €) | 41 |
| Nettopreis nicht positiv bei positivem Brutto | 5 |
| **netto größer als brutto** | 7 |

Der dritte Fall ist der interessanteste — in Polen etwa `netto 5.405,00 / brutto 4.800,00`.
Jeder verworfene Datensatz steht mit Artikelnummer und Begründung im `run_log`.

## Der Grundsatzfehler und seine Lösung: Aktionen leben am langen MCS (18.08.2026)

**Der Test, der alles auf den Kopf stellte.** GRUBE aktivierte auf der Integ für Artikel
`3049187041` (Markt DE) eine **20-%-Aktion**. Der Shop verlangte seitdem **159,20 €**.
Gelesen haben wir **199,00 €** — und zwar aus jeder Quelle, die wir kannten, auch aus
der, die „promotion" im Namen trägt.

**Der Fehler lag nicht am Shop, sondern an der Schlüssellänge.** Der Object Storage führt
Preise je MCS, und es gibt zwei Längen. Wir lasen die kurze:

| MCS | `promotionPrices` | `netPromotionPrices` |
|---|---|---|
| `[brand=grube country=de currency=EUR]` | 199,00 ← **das lasen wir** | 167,23 |
| `[brand=grube channel=web country=de currency=EUR language=de store=]` | **159,20** ✅ | **133,78** ✅ |
| `[… channel=webapp …]` | 159,20 | 133,78 |
| `[… channel=webwhitelabel …]` | 159,20 | 133,78 |

Auskunft GRUBE dazu, die den Weg entschied:

> „Preise gibt es für kurze, promos aber nur für lange. Wir brauchen ja beides im
> Zweifel. Wegspeichern müssen wir nur für die kurzen. Das ‚std‘ MCS ist das Standard-MCS
> bei den langen, deswegen nehmen wir immer das, um zu schauen, ob es Promopreise gibt."

Daraus folgt die Leseregel, die jetzt gilt:

| Feld | MCS | Inhalt |
|---|---|---|
| `promotionPrices` / `netPromotionPrices` | **lang** (`channel=web`, Sprache, `store` leer) | der angewendete Preis — **hat Vorrang** |
| `prices` / `netPrices` | **kurz** | Listenpreis — greift, wo keine Aktion gepflegt ist |

Netto kommt aus `netPromotionPrices` bzw. `netPrices`, also **aus derselben Quelle wie
Brutto**, nie gerechnet — sonst wäre die Konsistenzregel (§ 6.1) verletzt.

### Nachgemessen, nicht angenommen

Gegen den Affiliate-Export `/affiliateExport/preisschreiber_de/` — die Preise, mit denen
tatsächlich geworben wird:

| | |
|---|---|
| Artikel im Feed | 34.551 |
| brutto identisch mit `promotionPrices` am langen MCS | **34.551 (100 %)** |
| Abweichungen | **0** |
| Artikel in Aktion, die der alte Weg übersah | **3.331** (rund 10 % des Sortiments) |

Der Feed wird daher **nicht** gebraucht; er hat als unabhängige Gegenprobe gedient und
kann jederzeit wieder dafür benutzt werden.

### Zwei Fallen in der Auflösung

**Die Staffelkarte.** Eine `promotionPrices`-Zelle ist keine Liste, sondern eine Karte
`{0=[…], 10=[…]}`. Der Schlüssel ist die **Staffelmenge**; nur `0` ist die Grundmenge,
`10` der Mengenpreis ab zehn Stück. Ungefiltert wäre bei **1.692 Artikeln** ein
Mengenrabatt als Endkundenpreis in die Beweisgrundlage gewandert. Innerhalb von
Schlüssel 0 wurde über alle 34.866 DE-Artikel **kein einziger** Fall mit zwei gleichzeitig
gültigen Einträgen gefunden — die Auflösung ist eindeutig.

**Der Einzelabruf war ebenso blind.** `/admin/pssoverview/prices/shop/get/…` heißt „shop",
antwortet in 0,13 s und liefert trotzdem 199,00 €. Ein gezielter Nachlauf über diesen Weg
hätte den falschen Preis geschrieben — und zwar nur für den nachgelaufenen Artikel, also
besonders schwer zu bemerken. Einzelne Artikel werden deshalb über `os/info` gelesen, mit
**derselben** Auflösungsregel wie der Sammelweg.

### Was daraus für die Zukunft folgt

**Kurze MCS sind nie richtig** (Auskunft GRUBE) — weder beim Lesen von Aktionen noch als
Annahme über die Vollständigkeit einer Quelle. Umgekehrt kennt der **PSS nur die kurze
Form**: An 212 Einträgen des Artikels 3049187041 nachgesehen, kein einziger langer MCS
darunter. Gelesen wird also lang, geschrieben kurz — `Run::mcsPaar()` führt beide
nebeneinander, damit die Unterscheidung nicht an einer Aufrufstelle verlorengeht.

**`vatRate` und `priceUnit` brauchen keinen eigenen Abruf mehr.** `vatRate` steht an jeder
`prices`-Zeile im Sammelabzug; `priceUnit` ist im ganzen Sortiment `STCK` (Auskunft GRUBE:
„STCK ist fix, das ist keine Variable", nachgesehen an allen 212 PSS-Einträgen). Vorher
kostete jeder Artikel dafür eine eigene PSS-Anfrage — beim Erstlauf rund 231.000, die
nichts lieferten, was nicht schon vorlag.

## Der Schreibweg in den PSS (18.08.2026 ermittelt, TODO(setup) 2 und 6 erledigt)

**Es gibt eine Integrationsumgebung, und dort gehören solche Versuche hin:**
`https://integ.grube.de/admin/pss/api/v2_beta/prices`, erreichbar mit denselben
Zugangsdaten. `staging` schreibt dorthin, `prod` auf `admin.grube.app` — der Unterschied
steht in `.env` und ist keine Formalie: Ein Staging-Lauf gegen den Produktiv-PSS
schriebe echte Preiseinträge.

Eine API-Beschreibung gibt es nicht (alle üblichen Pfade antworten mit 500). Erlaubt sind
laut `OPTIONS`: `GET, HEAD, PUT, DELETE, PATCH` — **kein POST**. Die Semantik wurde
deshalb gemessen, nicht angenommen:

| Erkenntnis | Beleg |
|---|---|
| **`PATCH` ist ein echter Upsert** | derselbe Eintrag zweimal → **eine** Zeile mit neuem Wert |
| **`PATCH` lässt alles andere unberührt** | 96 Zeilen vorher, 97 nachher, **0 verschwunden** |
| **`DELETE` entfernt genau einen Eintrag** | danach Fingerabdruck (SHA-256 über alle Zeilen) **exakt wie vorher** |
| **Neue priceTypes brauchen keine Anmeldung** | `30_GROSS` wurde ohne Vorbereitung angenommen |
| mehrere Einträge je Aufruf | ein `PATCH` mit zwei Einträgen setzte beide |

Damit ist **Akzeptanzkriterium 5** belegt (Mehrfachläufe erzeugen keine Duplikate) und
**TODO(setup) 6** beantwortet: Die Leerung des Vorstufen-Ankers ist ein `DELETE`, die
`value: 0`-Konvention wird nicht gebraucht.

**Gelernt wurde all das an einer erfundenen Artikelnummer ohne jeden Preis**
(`Y5X-SCHREIBTEST-0001`). Erst als klar war, dass `PATCH` additiv arbeitet, ging ein
Testeintrag an einen echten Artikel — und der wurde anschließend so entfernt, dass der
Fingerabdruck wieder stimmte. Bei einem Preissystem lernt man die Semantik nicht an
echten Daten.

**`PUT` wurde nicht ausprobiert.** Es ist erlaubt, aber bei einer Sammlung ist die
naheliegende Lesart „ersetze den Bestand". `PATCH` tut nachweislich das Richtige; für
einen Versuch mit unklarem Ausgang gibt es hier keinen Anlass.

Der Schlüssel eines Eintrags ist `sku` + `priceType` + `customer` + `customerGroup` +
`amount` + `mcs`. `vatRate` und `priceUnit` werden aus einem vorhandenen Eintrag desselben
Artikels übernommen statt erfunden — der Steuersatz gehört zum Artikel, nicht zu unserem
Referenzwert.

## Umgebungstrennung — unvollständig, und das ist ein offener Punkt

| | staging | prod |
|---|---|---|
| PSS (**schreibend**) | `integ.grube.de` ✅ | `admin.grube.app` |
| Shop-Link im Nachweis | `integ.grube.de` ✅ | `www.grube.de` |
| iSHOP (**lesend**) | `integ.grube.de` ✅ | `admin.grube.app` |

**Seit dem 18.08.2026 sauber getrennt.** Zuvor las Staging den iSHOP aus der Produktion,
weil `/admin/os/overview` auf der Integ mit **401** antwortete — die Kennung
`seo-index-agent` gab es dort nicht. GRUBE hat sie angelegt; Integ liefert seitdem 34.866
Artikel (Produktion 35.650, die Abweichung ist für eine Integrationsumgebung erwartbar).

> ⚠️ **Der Wechsel der Quelle verfälscht die Historie.** Der erste Lauf nach der Umstellung
> meldete 27 „geänderte" Artikel — das sind Preisunterschiede zwischen Produktion und
> Integ, keine echten Preisänderungen. In `price_events` stünden sie trotzdem als
> Preisänderung. Wer die Quelle wechselt, muss die Historie verwerfen; sonst behauptet die
> Beweisgrundlage einen Vorgang, den es nie gab.

**Der Shop-Link folgt der Umgebung** (`url` / `url_staging` in `markets.yml`). Fehlt die
Staging-Adresse, wird **nicht** auf die Produktivadresse ausgewichen — lieber kein Link
als ein falscher. Ein Nachweis, der auf einer Staging-Seite eine Produktiv-URL nennt,
behauptet, unter dieser Adresse sei geworben worden, und im Abmahnfall ist genau die URL
der Streitgegenstand.

## Der erste Schreiblauf (18.08.2026, 20 Beispiele, Integ)

42 Einträge übertragen, **0 Fehler**, und aus dem PSS zurückgelesen stimmen alle 20
Artikel auf den Cent mit `price_state` überein. Der Delta-Write greift: Ein zweiter Lauf
schreibt nichts mehr.

**Der wichtigste Befund war nicht der Erfolg, sondern eine Zahl in der Statistik:**
Von 20 Beispielen ging genau **eines** in den Zustand `promo` — dasjenige, dessen Preis
**heute** fiel.

Das ist richtig und trotzdem folgenreich. Der Zustandsautomat erkennt eine Aktion daran,
dass der Preis gegenüber gestern fällt. Eine Aktion, die **vor** dem ersten Lauf begann,
sieht er nie: Für ihn steht der Preis seit jeher auf dem Aktionswert. Das rollierende
Fenster liefert dann als Referenz den Aktionspreis selbst — die ausgewiesene Ersparnis
ist null.

Rechtlich ist das die **sichere** Richtung (es wird zu wenig ausgewiesen, nie zu viel).
Betriebswirtschaftlich heißt es: **Am Tag des Scharfschaltens zeigt jede bereits laufende
Aktion keine Ersparnis**, bis sie endet und eine neue beginnt. Genau darum geht es bei
TODO(setup) 4 (Vorlauf vs. Backfill) — hier ist der Beleg dafür, warum die Entscheidung
nicht kosmetisch ist.

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

### Die Preise: zwei Anfragen statt 35.641 je Markt

**Der Einzel-Endpunkt war nicht bloß langsam, er war untauglich.**
`/admin/pssoverview/prices/shop/get/{sku}/…` kennt **keinen Markt-Parameter** und liefert
nur den Standard-Shop. Für acht Märkte gab es damit keinen Weg.

Der Object Storage führt die Preise als Attribute am Artikel (`prices`, `netPrices`,
daneben `promotionPrices`, `unrebatedPrices`, `rrpPrice`), und die Sammelsuche gibt sie
**je MCS** aus — also je Markt:

```
/admin/os/overview?…&searchEntries[0].name=prices&searchEntries[0].comp=EXISTS
-> 302.782 Zeilen, 191 MB, 9 s
```

| | Einzelabruf | Sammelabzug |
|---|---|---|
| Anfragen | 35.641 **je Markt** | **2 insgesamt** |
| Dauer | ~108 min gedrosselt | ~10 s laden, ~2 min zerlegen |
| Märkte | nur der Standard-Shop | **alle acht** |
| Ratenbegrenzung | kritisch | belanglos |

Enthalten sind `brand=grube` für `de/at/fr/pl/sk/se/dk/ch` (plus `eu`) sowie
`brand=dominicus` — Letzteres ist der **B2B-Shop** und bleibt außen vor (Auskunft GRUBE).

> **Die Auflösung ist der heikle Teil.** Der Abzug enthält rohe `PriceEntry`-Listen mit
> Zeitfenstern, Preisgruppen (`DEFAULT`, `DEFAULT_NOTLOGGEDIN`) und mehreren
> konkurrierenden Einträgen; welcher gilt, entscheidet sonst der Shop. Sie selbst
> nachzubilden ist genau die Sorte Cleverness, die dieses Projekt sich nicht leisten
> kann — **deshalb wurde sie gegen den autoritativen Einzel-Endpunkt gemessen:
> 200 zufällige Artikel, 200 Übereinstimmungen, null Abweichungen.**
> Gefiltert wird auf `priceGroup='DEFAULT'`, `customer='0'`, `amount=0` und ein
> Gültigkeitsfenster, das heute enthält.

Der Einzelabruf bleibt als Rückfall für einen einzelnen Artikel erhalten. 191 MB müssen
**streamend** verarbeitet werden — im Arbeitsspeicher gehalten stirbt `preg_match_all`
bei 512 MB.

### Die Preisgruppe ist nicht überall `DEFAULT`

**Schweden führt sie als `1`.** Alle anderen Märkte nutzen `DEFAULT` — gemessen am
Sammelabzug (Kunde 0, amount 0):

```
at DEFAULT=18.838   de DEFAULT=18.866   pl DEFAULT=11.701
ch DEFAULT=16.003   dk DEFAULT=13.988   sk DEFAULT=8.871
fr DEFAULT=18.866   se 1=5.167          (kein einziger DEFAULT-Eintrag)
```

Fest verdrahtet auf `DEFAULT` blieb der schwedische Markt beim ersten Volllauf
**vollständig leer** — 3 Sekunden Laufzeit, Status „ok", null Artikel. Kein Fehler, kein
Hinweis, nichts. Genau die Sorte Fehlschlag, an der man erst Monate später merkt, dass
ein Markt nie versorgt wurde.

Zwei Konsequenzen, und die zweite ist die wichtigere:

1. `price_group` steht je Markt in `markets.yml` (Vorgabe `DEFAULT`, Schweden `1`).
2. **Ein Markt ohne einen einzigen Preis ist ein Fehler**, kein leeres Sortiment. Der
   Lauf zählt ihn als `errors`, meldet ihn im Klartext und endet mit `partial`. Eine
   Zahl, die still auf null steht, ist schlimmer als eine Fehlermeldung.

### Die Ratenbegrenzung des Shops

`/admin/rate-limiting/status` verrät sie: **800 Anfragen in 2 Minuten**, aktiv, ohne
Trockenmodus, gezählt je **IP UND User-Agent** (`UserAgentMode`). Unser 1000er-Lauf lag
mit rund 500/min darüber und kam durch — worauf man sich nicht verlassen sollte.

Das wiegt hier schwerer als anderswo: Die ausgehende IP `176.9.21.74` gehört dem **ganzen
Webspace**. Eine Sperre träfe nicht nur dieses Werkzeug, sondern jedes andere Projekt am
selben Shop. Deshalb `requests_per_minute: 330` in `app.yml` und ein eigener User-Agent
(`y5x-Preisschreiber`), damit unser Verkehr im Protokoll des Shops erkennbar ist.

Mit dem Sammelabzug ist die Frage praktisch erledigt — es bleiben zwei große Anfragen
statt Zehntausender kleiner.

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

### Bezeichnungen: eine Anfrage für den ganzen Bestand

Die Artikelliste zeigt zwischen Artikelnummer und „seit … unverändert" die
**Bezeichnung** — und die Suche greift auf beides, denn wer einen Artikel im Kopf hat,
hat selten die Nummer parat.

Das ging nur über denselben Kniff wie bei den Artikelnummern: `import:E0074 EXISTS`
liefert **29.316 Bezeichnungen in 1,9 s** in einer einzigen Anfrage, weil die
Ergebnistabelle den Wert des gesuchten Attributs als eigene Spalte führt. Einzeln
abgerufen wären das 0,27 s und 389 KB je Artikel — für eine Liste mit hundert Zeilen
unbrauchbar. Gesammelt wird deshalb **einmal je Lauf**, nicht bei jeder Anzeige.

Rund 6.000 der 35.641 Artikel führen kein `E0074` und bleiben ohne Bezeichnung. Das ist
ehrlicher als ein erfundener Platzhalter. Einzel- und Sammelabruf putzen über dieselbe
Methode (`saubererName`) — sonst sähe derselbe Artikel je nach Weg anders aus.

Der Name ist **Anzeigehilfe, keine Beweisgrundlage**: Er steht in `article_meta` mit
Abrufzeitpunkt, `price_events` bleibt unberührt.

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

### Bedienung (18.08.2026)

* **Auswahlfelder feuern direkt ab** (`onchange`, eine Zeile, kein Framework). Ohne
  JavaScript bleibt ein Absendeknopf sichtbar — die Seite funktioniert in jedem Fall.
* **Ganze Zeilen sind anklickbar** — in der Artikelliste und in der Markttabelle der
  Übersicht (dort führt sie auf alle Artikel des Marktes). „Artikel" und „Fenster
  unvollständig" tragen deshalb keinen eigenen Link mehr; **„in Aktion" und „Risiko"
  behalten ihn**, weil sie auf eine *andere* Auswahl zeigen — ein eigener Link ist nur
  dann berechtigt, wenn er woanders hinführt als die Zeile.
* **Umsetzung der Zeilenklickbarkeit:** Umgesetzt als über die Zeile
  gespannter `::after`-Link in der ersten Zelle: Er bleibt ein echter Link — fokussierbar,
  kopierbar, in neuem Tab zu öffnen —, während ein `onclick` auf `<tr>` all das verlöre.
* **Auf der Nachweisseite gibt es keine Suche**, nur Stichtag, PDF und „zur Liste".
* **Kopfzeile zweizeilig:** oben Artikelnummer, Zustandszeichen und Shop-Link; darunter
  linksbündig die **Bezeichnung samt Variante** („T-Shirt Hunting, oliv, Gr. XXL" —
  `import:E0074` führt sie mit), dann Markt, Währung und der heute verlangte Preis. Ohne
  die Variante wäre auf einem Nachweis nicht bestimmbar, welcher Artikel gemeint ist.
* In der Wertetafel stehen Etikett und Betrag auf **einer** Zeile, die Erläuterung bricht
  darunter um (Raster statt Flex — vorher schob langer Erklärtext den Betrag weg).
* **Kein Menüpunkt „Konto"** — die E-Mail-Adresse im Kopf ist der Zugang.
* **Zwei Links im Kopf.** „Im Shop ansehen" führt auf die Produktseite, „im PSS ansehen"
  auf die Preiseinträge desselben Artikels
  (`…/admin/pss/api/v2_beta/prices?skus=<sku>`). Der PSS-Link wird **aus `PSS_BASE_URL`
  abgeleitet**, also aus genau der Adresse, auf die derselbe Lauf schreibt — zwei
  getrennt gepflegte Angaben liefen früher oder später auseinander, und dann zeigte der
  Nachweis auf eine andere Umgebung, als er beschreibt. Er steht bewusst **nicht im
  PDF**: Das ist ein Beweisdokument für Dritte, und ein Zugangspfad in ein internes
  System hat darin nichts zu suchen.
* **Artikelname und Shop-Link** im Kopf. Der Link geht über die Shop-Suche
  (`<url>/search/?q=<sku>`), weil die direkt auf die Produktseite mit vorgewähltem
  Artikel umleitet (geprüft: `/search/?q=1000172720` →
  `/p/erdbohrgeraet-vertex-g250/100017/?articleNo=1000172720#it`). Ein selbst gebauter
  Produktpfad bräuchte Slug und Produkt-ID, die wir gar nicht führen, und wäre bei jeder
  Umbenennung kaputt. Die Bezeichnung kommt aus `import:E0074` am Item und liegt in
  `article_meta` — **Anzeigehilfe, nicht Beweisgrundlage**, deshalb eigene Tabelle mit
  Abrufzeitpunkt.
* **Abmelden** im Kopf (räumt Sitzung, Cookie und Sitzungs-ID ab), **Konto** als eigene
  Seite: eigenes Passwort ändern, Zugänge anlegen und entfernen, Anmeldeprotokoll.

### Der Messschrieb: gelieferter Renderer (18.08.2026)

`Support\PriceChart` und `Support\PriceChartData` kamen **fertig von GRUBE**, samt
21 Prüfungen. Übernommen mit vier dokumentierten Eingriffen (siehe Klassenkopf):
Namensraum, `ohneCssVariablen()` fürs PDF, `usort` auf Kopien — und einer echten
Fehlerbehebung:

> **`static $prev` in einem Closure überlebt den Aufruf.** Im Dedup der Referenzschritte
> hätte das beim zweiten `render()` im selben Request den ersten Schritt des zweiten
> Diagramms verschluckt. Bei einem Diagramm je Seite fällt es nie auf — auf der
> Demoseite mit drei Fällen sofort, und in einem Beweisdokument wäre es ein stiller
> Fehler.

Dazu drei Beschriftungen, die aus dem Bild liefen (Stichtag am rechten Rand,
Fensterlabel, und Referenz/Vorstufe übereinander, wenn die Werte nah beieinander liegen)
— jetzt am Rand umgeschlagen bzw. auseinandergezogen.

**Die Referenz-Treppe stammt laut Entwurf aus `pss_write_log` — der ist im Trockenmodus
leer.** Es gäbe also nie eine Referenzlinie, obwohl der Wert längst berechnet ist.
`build()` nimmt deshalb über `refWrites`/`prevSegments` auch eine **nachgerechnete**
Reihe entgegen; sie wird im Schrieb als „Referenz (berechnet)" beschriftet und in der
Legende als „berechnet, noch nicht geschrieben". Auf keinem Ausdruck darf der Eindruck
entstehen, ein Wert sei übertragen worden, der es nicht wurde.

### Die Produkt-URL wird aufgelöst, nicht gebaut

**Eine Abmahnung nennt eine Adresse** (Hinweis GRUBE, 18.08.2026). Der Suchlink
`…/search/?q=<sku>` ist funktional, aber nicht die URL, unter der geworben wurde.

Selbst bauen ginge nicht sauber: Die Produkt-ID ließe sich aus den ersten sechs Stellen
der Artikelnummer ableiten (an drei Artikeln geprüft), der **Slug** steht aber in keinem
lesbaren Attribut des Produktobjekts. Eine aus einem Zahlenmuster geratene Adresse hat
auf einem Beweisdokument nichts verloren.

Also sagt es der Shop selbst: Ein Aufruf der Suche folgt der Weiterleitung, das Ziel
**ist** die kanonische URL. Sie steht mit Zeitstempel in `article_meta` — ändert der Shop
später den Slug, bleibt belegbar, welche Adresse zum Zeitpunkt des Nachweises galt.

```
1000172720 -> https://www.grube.de/p/erdbohrgeraet-vertex-g250/100017/?articleNo=1000172720
1000344342 -> keine Produktseite im Shop
```

Der zweite Fall ist echt und wird **ehrlich vermerkt** statt mit einer erfundenen Adresse
gefüllt.

### Der Stichtag muss ALLES bewegen

Gemeldet am 18.08.2026: Die Kennzahlen im Schrieb und das schattierte Band änderten sich
nicht, wenn man einen anderen Stichtag einstellte. Stempel, Karten und Zustand folgten
ihm — das Bild nicht.

Ursache: Der Chart bekam den **aktuellen** Zustand aus der Datenbank und `heute`. Damit
zeigte das Band immer das heutige Fenster und die Beschriftung immer den heutigen Wert,
gleich welchen Stichtag man wählte. Auf einem Nachweis zu einem zurückliegenden Datum ist
das keine Unschönheit, sondern eine Falschaussage.

Behoben an drei Stellen:

* Der Chart bekommt den **nachgerechneten Zustand zum Stichtag** (aus `Calc\Replay`),
  nicht den aus `price_state`.
* **Der Schrieb endet am Stichtag.** Ein Nachweis zum 10. Juli darf keine Augustpreise
  ausweisen — weder als Kurve noch über eine Zeitachse, die weiterläuft.
* Das **PDF nutzt denselben Renderer** wie der Bildschirm. Es zeichnete vorher über die
  alte `Chart`-Klasse — zwei Zeichenwege für dasselbe Bild sind die sicherste Art, dass
  Ausdruck und Anzeige irgendwann auseinanderlaufen.

Dazu zwei Kleinigkeiten aus derselben Ecke: Die Zeitachse lief über den Stichtag hinaus
(ein Intervall kann länger gelten als der Nachweis reicht), und der Begründungstext sagte
„rollierendes Fenster [heute−30, gestern]" — bei einem zurückliegenden Stichtag ist
„heute" schlicht das falsche Wort.

### Der Nachweis als PDF

„Drucken" ist ersetzt durch **Herunterladen**: Ein Browserausdruck hängt von Rändern,
Zoom und Druckdialog des jeweiligen Rechners ab; ein Beweismittel soll bei jedem gleich
aussehen und als Datei weitergegeben werden können.

Erzeugt mit **mpdf** (reines PHP). Auf diesem Webspace gibt es weder wkhtmltopdf noch
Chromium, und ein FPM-Prozess, der einen Browser startet, wäre für ein
Compliance-Werkzeug die falsche Abhängigkeit. `composer install` ist damit ein
Einrichtungsschritt; `deploy.sh` spiegelt `vendor/` mit.

Das Diagramm geht als SVG ins PDF — dieselbe `Chart`-Klasse wie am Bildschirm, nur mit
festen Farben statt CSS-Variablen. Geprüft an einem gerenderten PDF, nicht nur an der
Dateigröße.

> **Der Prüfdurchgang hat einen Fehler im Nachweis aufgedeckt.** Als Beleg für die
> Referenz stand dort das *laufende* Aktionsintervall (21.07.–18.08.), obwohl der Wert
> von 79,95 € aus einem Juni-Einbruch stammte. Ursache: Das Beleg-Fenster wurde zum
> **Stichtag** berechnet statt zum **Aktionsbeginn** — bei eingefrorener Referenz sind das
> verschiedene Zeiträume. `beleg_fenster()` löst das jetzt an einer Stelle für Bildschirm
> und PDF; das schattierte Band im Diagramm zeigt seitdem denselben Zeitraum, aus dem die
> Referenz tatsächlich stammt. In einem Beweisdokument war das keine Feinheit, sondern
> eine Falschangabe.

### Zugänge: Rollen und Einladungen (18.08.2026)

Zwei Rollen. **`user`** sieht alles und verwaltet nur den eigenen Zugang; **`admin`**
darf zusätzlich Zugänge vergeben. Geprüft wird serverseitig, nicht bloß in der Anzeige —
ein Formular, das man nicht sieht, kann man trotzdem abschicken. Die Rolle wird bei jedem
Aufruf frisch gelesen, **nicht** in der Sitzung gehalten: Ein Rechteentzug soll sofort
greifen, nicht erst beim nächsten Anmelden.

Beides liegt im **Kontobereich** — dieselbe Seite, auf der jeder sein eigenes Passwort
ändert. Ein eigener Menüpunkt fehlt bewusst; die E-Mail-Adresse im Kopf führt hin.

**Keine Startpasswörter, sondern Einladungen.** Wer ein Startpasswort vergibt, kennt es —
und dann belegt das `login_log` nicht mehr zuverlässig, wer gearbeitet hat. Bei einem
Werkzeug mit Beweisfunktion ist das der Punkt, an dem die Nachweiskette leise reißt. Also:

* Ein Admin lädt eine Adresse ein und wählt die Rolle.
* Der Schlüssel entsteht aus `random_bytes(32)` und liegt **nur als Hash** in der
  Datenbank — er ist ein Passwort-Äquivalent. Im Klartext existiert er genau einmal: im
  Link, der herausgeht. Die Oberfläche zeigt ihn deshalb nur unmittelbar nach dem Anlegen.
* Der Link gilt **7 Tage** und lässt sich **einmal** einlösen; der Eingeladene vergibt
  sein Passwort selbst (mindestens 12 Zeichen).
* **Der Mailversand darf nicht die Bedingung sein.** Klappt er nicht, ist die Einladung
  trotzdem gültig und der Link steht dem Admin auf der Seite. Ein Werkzeug, das einen
  Zugang von einer Zustellung abhängig macht, blockiert sich bei der ersten
  Spamfilter-Laune selbst. Die Liste offener Einladungen zeigt an, ob die Mail rausging.
* Ungültige Links melden **generisch** „abgelaufen, bereits verwendet oder
  zurückgezogen" — welcher Grund zutrifft, wird nicht verraten.

**Die letzte Administration lässt sich nicht herabstufen** und niemand kann den eigenen
Zugang entfernen — sonst sperrt sich die Installation aus und niemand vergibt mehr Zugänge.

`einladung.php` ist die einzige Seite ohne Anmeldung. Verifiziert: falscher Schlüssel,
fremde ID, zweite Verwendung und abgelaufene Einladung werden alle abgewiesen, und der
Klartextschlüssel steht nicht in der Datenbank.

CLI-Weg unverändert vorhanden: `php bin/user.php add <mail> <passwort> [admin|user] [admin|user]` —
für den allerersten Zugang, wenn es noch niemanden gibt, der einladen könnte.

### Eine Hülle für alles vor der Anmeldung

`zugangsseite()` trägt Anmeldemaske **und** Einladung: Motiv, Marke, Karte mit
Umgebungs-Chip, Fehlerkasten, Hilfetext, Fußzeile, Auftauch-Animation mit
`prefers-reduced-motion`. Vorher hatte jede Seite ihre eigene Kopie desselben Aufbaus —
und prompt fehlten der Einladungsseite Wasserzeichen, Umgebungs-Chip und Fußzeile. Zwei
Kopien einer Gestaltung driften immer; die Frage ist nur, wann es auffällt.

### Kopfleiste und Inhalt auf einer Achse

Die dunkle Fläche läuft über die volle Breite, ihr **Inhalt** nicht — er sitzt in
`.kopfinhalt` auf derselben Achse wie `main`. Sonst klebt die Marke am linken
Bildschirmrand, während die erste Tabellenspalte zweihundert Pixel weiter innen beginnt.
Beide Breiten kommen aus **einer** Variablen (`--breite`) und können nicht auseinanderlaufen.

### Anmeldung

Generische Fehlermeldung (die Maske darf kein Kontoverzeichnis werden), Versuchssperre
nach 5 Fehlversuchen je Konto+IP für 15 Minuten, `session_regenerate_id(true)` bei Erfolg
und **jeder** Versuch im `login_log` mit Zeit, Konto und IP. Gehasht wird mit
`PASSWORD_DEFAULT` (derzeit bcrypt); das Template nannte argon2id — der Unterschied ist
hier ohne Belang, wichtig ist das Verfahren, nicht die Marke.

## Gezielter Nachlauf für einen Artikel

```bash
php bin/run.php --market DE --sku 3049187041 [--write]
```

Der Einzelartikel-Lauf umgeht den Sammelabzug und nutzt den Einzel-Endpunkt: **3 s statt
115 s**. 382 MB zu laden und zu zerlegen lohnt für einen Artikel nicht.

**Nur für den Standard-Shop.** `/admin/pssoverview/prices/shop/get/…` kennt keinen
Markt-Parameter; für AT, FR, PL, SK, SE, DK und CH bleibt auch bei einem einzelnen
Artikel der Sammelweg der einzige. Das steht als `$standardMarkt` im Code, nicht als
stille Annahme.

## Befehle

```bash
composer install                             # einmalig: mpdf fuer den PDF-Nachweis
bash deploy.sh staging                       # Code -> Laufzeit + Statusseite
php bin/init-db.php --env staging            # Schema anlegen
php bin/migrate.php --env staging            # Spalten in bestehenden Tabellen nachziehen
php tests/run.php                            # 47 Szenarien, ohne Netz/DB/Composer
php bin/demo-seed.php [--loeschen]           # Beispieldaten (nur staging)
```

## Betrieb

* **Der Tageslauf wird über eine URL angestoßen**, nicht über einen Shell-Cron: Der
  ISPConfig-Panel-Cron ruft `trigger.php` auf. Weil ein Lauf rund elf Minuten dauert und
  PHP-FPM ihn abbräche, sobald der Aufrufer weg ist, startet der Trigger ihn per `setsid`
  abgelöst und antwortet sofort. `flock -n` verhindert Doppelläufe — ein Cron, der einmal
  zu oft feuert, richtet keinen Schaden an.
* **`--write` steht im Trigger, nicht in der `app.yml`.** Der Auslieferungszustand der
  Konfiguration bleibt der Trockenmodus, damit ein von Hand gestarteter Lauf nichts
  überträgt. Scharf ist genau ein sichtbarer Weg.
* **Der Token liegt außerhalb des Docroots** (`<laufzeit>/trigger.token`, 600) und wird
  mit `hash_equals` geprüft — ein Vergleich, der beim ersten Unterschied abbricht, verrät
  über die Antwortzeit, wie viele Zeichen stimmten.
* **Reihenfolge zwingend:** DiVA-Preisimport → iSHOP aktuell → Tracker-Lauf.
* `write_enabled: false` für **CH**: Die Schweizer Preisbekanntgabeverordnung folgt nicht
  der EU-30-Tage-Regel. Getrackt wird trotzdem, damit Historie vorliegt, wenn Legal
  entscheidet.
* **Delta-Writes:** `last_written_*` wird nur bei Erfolg gesetzt — dadurch holt der
  nächste Lauf einen fehlgeschlagenen Write von selbst nach. Das trägt auch die
  Blockbildung: Scheitert ein Block mit 500 Einträgen, bleiben alle 500 offen und gehen
  beim nächsten Lauf erneut hinaus.
* Statusseite liest den DB-Zugang aus der Laufzeit, nicht aus `$HOME/secrets/`: Der
  FPM-Pool darf nur `web/`, `private/` und `tmp/` lesen (Muster wie zw7/mbc/7he).

### Der Anlauf ist ein Vorlauf (Entscheidung GRUBE, 18.08.2026)

**Der erste Lauf schreibt nichts, und das ist die richtige Antwort.** Das Fenster des
§ 11 ist `[heute−30, gestern]` — am ersten Beobachtungstag liegt darin kein einziger Tag.
Es gibt keinen niedrigsten Preis der letzten 30 Tage, weil die 30 Tage nicht beobachtet
wurden. Der Rechenkern schreibt deshalb keinen Wert, statt einen zu behaupten.

Der naheliegende Ausweg — trotzdem schreiben und über `window_complete = 0` kennzeichnen —
geht in die **gefährliche** Richtung: War ein Artikel vor drei Wochen billiger und wir
wissen es nicht, fällt die Referenz zu **hoch** aus, und es würde eine zu große Ersparnis
beworben. Genau das wird abgemahnt.

Ein **Backfill** wurde geprüft und verworfen: Der Object Storage führt zwar zu jedem
Preiseintrag ein `startDate`, aber bei den Aktionseinträgen des Testartikels stand dort
`11.09.2025`, obwohl die Aktion am 18.08.2026 aktiviert wurde. Das Feld bildet den
Aktionsbeginn **nicht** ab. Ein Backfill darüber behauptete, der Aktionspreis gelte seit
einem Jahr — und vernichtete damit genau die Ersparnis, die belegt werden soll.

Es läuft daher ab dem 18.08.2026 täglich mit. Werte entstehen von selbst, sobald je
Artikel Historie vorliegt; ab dem **17.09.2026** ist das Fenster voll und
`window_complete` steht auf 1. Bis dahin ist das Dashboard bereits benutzbar — es zeigt
Preisverlauf und Zustand, nur eben noch keine 30-Tage-Referenz.

## Offen (TODO(setup))

Stand 18.08.2026, nach Produktivstellung.

1. **Zeitpunkt des täglichen DiVA-Preisimports je Shop.** Der Cron steht auf 05:30 und ist
   damit geraten, nicht abgestimmt. Läuft der Import später, liest der Tageslauf den Stand
   von gestern — für einen Tag ohne Preisänderung folgenlos, für den Tag einer Aktion
   falsch.
2. **Längste geplante Aktionsdauer**, damit `permanent_after_days` (derzeit 60) sicher
   darüberliegt. Ohne Aktionskennzeichen trägt diese Zahl allein die Unterscheidung
   zwischen „lange Aktion" und „neuer Dauerpreis".
3. **`prev_price_max_days` von Legal kalibrieren** (Vorgabe 42 Tage).
4. **Soll `PREV_*` überhaupt jemals leer sein?** Siehe „Vorstufen-Anker" — die Antwort
   entscheidet, ob der Tracker die Leerung schreibt oder das Template über die Anzeige.
5. `alert_email` und die Shop-Kennungen (`shop:`) je Markt in `markets.yml`.
6. **Verzeichnisschutz für `status/`** im ISPConfig-Panel — für beide Umgebungen. Aus der
   Shell ist das auf nginx nicht möglich; die Anmeldung der Statusseite schützt die Daten,
   aber der Ordner selbst ist ohne Panel-Schutz öffentlich erreichbar.
7. **CH-Freigabe.** `write_enabled: false`, weil die Schweizer Preisbekanntgabeverordnung
   nicht der EU-30-Tage-Logik folgt. Getrackt wird trotzdem, damit Historie vorliegt,
   sobald Legal entscheidet.

### Erledigt am 18.08.2026

| | |
|---|---|
| iSHOP-Endpunkt und Marktdimension | Sammelabzug liefert alle acht Märkte samt Währung |
| **Preisquelle, die Aktionen kennt** | `promotionPrices` am langen MCS — der Grundsatzfehler ist behoben |
| PSS-Schreibweg | `PATCH` = Upsert, `DELETE` = Leerung, 500 Einträge je Aufruf |
| Object-Storage-Rechte auf Integ | GRUBE hat `seo-index-agent` angelegt |
| Historie aus gemischten Quellen | verworfen und vollständig von Integ neu eingelesen |
| Anlaufentscheidung | **Vorlauf** (Entscheidung GRUBE, 18.08.2026) |
| Produktivumgebung, Cron, GitHub | steht |

## Was sich absichtlich NICHT bedienen lässt

Es gibt keine Oberfläche, über die sich **Preisdaten** ändern ließen. `price_events` ist
die Beweisgrundlage nach § 11 PAngV — wer nachträglich einen Preis ändern kann, kann
jeden Nachweis erfinden. Korrekturen laufen über einen dokumentierten Lauf
(`bin/run.php`) oder `backfill`, beides mit Spur im `run_log`. Änderbar ist nur, was die
Bedienung betrifft: Passwort und Zugänge.

## Zugang zur Statusseite

Benutzer werden mit `php bin/user.php add <mail> <passwort> [admin|user]` angelegt (nur der Hash wird
gespeichert). Angelegt in **staging**: `alexander.zindler@grube.de`.

Das ersetzt **keinen** Verzeichnisschutz: `.htaccess` wirkt auf nginx nicht, und die
Anmeldung schützt nur die Seiten selbst. Der Verzeichnisschutz für `status/` ist im
ISPConfig-Panel zu setzen.

## Abweichung vom Briefing, bewusst

`app.yml` steht auf `dry_run: true`, das Briefing nennt `false`. Solange die Adapter
fehlen, wäre `false` ohnehin wirkungslos; darüber hinaus soll das erste Schreiben in ein
Produktivsystem eine bewusste Handlung sein, kein Nebeneffekt eines Deploys. Beim
Scharfschalten umstellen.
