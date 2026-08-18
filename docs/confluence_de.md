# Preisschreiber — 30-Tage-Bestpreis nach § 11 PAngV

**Kürzel:** `y5x` · **Stand:** 18.08.2026 · **Betrieb:** grube.tools

Diese Seite ist Technikbeschreibung **und** Bedienungsanleitung. Wer nur wissen will, wie
man einen Nachweis holt, springt zu Kapitel 3.

---

## 1. Worum es geht

Wer einen Preis als reduziert bewirbt, muss den **niedrigsten Preis der letzten 30 Tage
vor der Ermäßigung** angeben (§ 11 PAngV, bestätigt durch EuGH C-330/23 „Aldi Süd"). Die
Angabe muss stimmen, und im Streitfall muss man sie **belegen** können — rückwirkend, für
einen bestimmten Tag, für einen bestimmten Artikel, in einem bestimmten Shop.

Genau das leistet der Preisschreiber:

1. Er liest **täglich** den Preis, den der Shop tatsächlich verlangt — in allen acht
   Märkten.
2. Er führt daraus eine lückenlose Preishistorie.
3. Er rechnet daraus den Referenzpreis und **schreibt ihn in den PSS**, wo das Frontend
   ihn als Streichpreis anzeigt.
4. Er kann jeden Wert **für jeden vergangenen Tag nachrechnen** und als PDF ausgeben.

**Hier arbeitet bewusst keine KI.** Das Ergebnis ist Teil einer Beweiskette. Es muss
reproduzierbar sein: Dieselben Daten müssen morgen dieselbe Zahl ergeben wie heute, und
jeder Schritt muss erklärbar sein. Ein Sprachmodell kann das nicht zusichern.

---

## 2. Wie der Preis ermittelt wird

### 2.1 Die Quelle ist der Shop, nie der PSS

Der PSS ist **Ziel, niemals Quelle**. Er führt zu einem Artikel dutzende konkurrierende
Einträge über Kundengruppen und Kanäle hinweg (212 allein für einen Testartikel), aus
denen erst der Shop den einen gültigen bildet. Gelesen wird deshalb der Object Storage
des iSHOP.

### 2.2 Zwei Felder, zwei Schlüssellängen

Der Shop führt Preise je **MCS** — einem Schlüssel aus Marke, Land, Währung und
gegebenenfalls Kanal, Sprache und Store. Es gibt zwei Längen, und beide werden gebraucht:

| Feld | MCS | Inhalt |
|---|---|---|
| `promotionPrices` / `netPromotionPrices` | **lang** (`channel=web`, Sprache, `store` leer) | der **angewendete** Preis — hat Vorrang |
| `prices` / `netPrices` | **kurz** | Listenpreis — greift, wo keine Aktion gepflegt ist |

**Das war der teuerste Irrtum des Projekts.** Zunächst wurde nur `prices` am kurzen
Schlüssel gelesen. Bei einem Testartikel mit 20-%-Aktion stand dort **199,00 €**, während
der Shop **159,20 €** kassierte — und `promotionPrices` am *kurzen* Schlüssel zeigte
ebenfalls 199,00 €. Erst der lange Schlüssel führt die Aktion. Ein Tracker auf der alten
Quelle hätte jede Aktion übersehen und den Nicht-Aktionspreis als „unverändert"
fortgeschrieben.

Gegengeprüft am Affiliate-Export (die Preise, mit denen tatsächlich geworben wird):
**34.551 von 34.551 Artikeln identisch, null Abweichungen** — und **3.331 Artikel in
Aktion**, rund 10 % des Sortiments, die der alte Weg allesamt übersah.

### 2.3 Brutto und Netto kommen immer zusammen

Netto wird **nie gerechnet**, sondern aus derselben Quelle gelesen wie Brutto
(`netPromotionPrices` bzw. `netPrices`). Ein Paar aus zwei verschiedenen Ereignissen wäre
ein Referenzpreis, den es so nie gegeben hat.

### 2.4 Zwei Fallen, die geprüft wurden

* **Staffelmengen.** Eine Preiszelle ist eine Karte `{0=…, 10=…}`; der Schlüssel ist die
  Menge. Nur `0` ist die Grundmenge, `10` der Mengenpreis ab zehn Stück. Ungefiltert wäre
  bei 1.692 Artikeln ein Mengenrabatt als Endkundenpreis in den Nachweis gewandert.
* **Der Einzelabruf.** `/admin/pssoverview/prices/shop/get/…` heißt „shop" und liefert
  trotzdem den Listenpreis ohne Aktion. Gezielte Nachläufe lesen deshalb `os/info`, mit
  derselben Auflösungsregel wie der Tageslauf.

### 2.5 Preisintervalle statt Tagesschnappschüsse

Gespeichert wird nicht „Preis am Tag X", sondern „Preis P galt von A bis B". Das ist
kompakter und beantwortet die einzige Frage, die zählt, direkt: *Welcher Preis galt an
diesem Tag?* `valid_to` ist dabei der letzte **Beobachtungstag**, nicht der letzte
Geltungstag — behauptet wird nur, was gemessen wurde.

---

## 3. Das Dashboard — Bedienung

**Adressen:**

| Umgebung | Adresse |
|---|---|
| Produktiv | `https://grube.tools/y5x/status/` |
| Staging | `https://grube.tools/staging/y5x/status/` |

Anmeldung mit E-Mail-Adresse und Passwort. Wer noch keinen Zugang hat, bekommt von einem
Administrator eine Einladung per Link.

### 3.1 Übersicht

Zeigt je Markt, wie viele Artikel geführt werden, wie viele davon gerade eine Ermäßigung
ausweisen und wann zuletzt gelaufen wurde. **Ein Klick auf die Zeile** öffnet den Markt —
nicht nur auf einzelne Wörter, die ganze Zeile ist die Schaltfläche.

Gelistet werden **alle** Artikel, nicht nur die in Aktion: Im Streitfall braucht man
Zugriff auf jeden Artikel, auch auf einen, der gerade nicht reduziert ist.

### 3.2 Artikelseite

Artikelnummer eingeben oder aus der Liste wählen. Markt und Stichtag werden über Auswahl
gesetzt; **es gibt keine Schaltfläche „Prüfen"** — die Auswahl löst sofort aus.

Angezeigt werden:

* **Bezeichnung** samt Variantenangabe (Farbe, Größe) und Link in den Shop
* **Link auf die PSS-Anzeige** des Artikels — dieselbe Adresse, auf die dieser Lauf auch
  schreibt
* **Preisverlauf als Diagramm** mit dem 30-Tage-Fenster als schattiertem Bereich
* **Referenzwerte** zum gewählten Stichtag
* **Nachweis herunterladen (PDF)**

### 3.3 Der Stichtag ist das Herzstück

Eine Abmahnung nennt ein **Datum**. Der Stichtag rechnet den Zustand dieses Tages neu —
und zwar **nur mit dem Wissen, das an diesem Tag vorlag**. Kein Blick in die Zukunft. Alle
Anzeigen folgen ihm: Diagramm, Fenster, Kennzahlen und PDF.

### 3.4 Konto und Zugänge

Klick auf die eigene E-Mail-Adresse oben rechts öffnet den Kontobereich: Passwort ändern,
und für Administratoren die Benutzerverwaltung samt **Einladungen** für neue Kolleginnen
und Kollegen.

---

## 4. Betrieb

### 4.1 Der Tageslauf

Angestoßen wird über eine URL aus dem ISPConfig-Panel-Cron:

```
Produktiv  https://grube.tools/y5x/trigger.php?token=…
Staging    https://grube.tools/staging/y5x/trigger.php?token=…
```

Empfohlene Zeit: **05:30**, in jedem Fall **nach** dem täglichen DiVA-Preisimport.
Staging versetzt, etwa 04:30.

Ein Lauf dauert rund **elf Minuten** für alle acht Märkte. Er überlebt den Web-Request
nicht und wird deshalb abgelöst gestartet; die Seite antwortet sofort. Feuert der Cron
doppelt, verhindert eine Sperre den zweiten Lauf — es entsteht kein Schaden.

### 4.2 Was ein Lauf tut

1. Vier Sammelabzüge aus dem Object Storage laden (rund 1 GB) — **einmal für alle acht
   Märkte**, nicht je Markt.
2. Je Artikel und Markt den angewendeten Preis auflösen.
3. Unplausible Werte verwerfen (Nullpreise, Netto über Brutto) — sie kommen **nicht** in
   die Beweisgrundlage, werden aber einzeln protokolliert.
4. Preisintervalle fortschreiben.
5. Referenzwerte rechnen und **nur die geänderten** in den PSS schreiben, in Blöcken von
   500 Einträgen.

Gemessen am 18.08.2026: 643 s, 278.928 gelesene Artikel×Markt, 180.239 Preisereignisse,
58 verworfene Anomalien, **0 Fehler**.

### 4.3 Der Anlauf ist ein Vorlauf

**Der erste Lauf schreibt bewusst nichts.** Das Fenster ist „die 30 Tage **vor** der
Ermäßigung" — am ersten Beobachtungstag liegt darin kein einziger Tag. Es gibt keinen
niedrigsten Preis der letzten 30 Tage, weil die 30 Tage nicht beobachtet wurden.

Trotzdem zu schreiben ginge in die **gefährliche** Richtung: War ein Artikel vor drei
Wochen billiger und wir wissen es nicht, fiele die Referenz zu **hoch** aus und es würde
eine zu große Ersparnis beworben. Genau das wird abgemahnt.

Ein Backfill aus dem Shop wurde geprüft und verworfen: Das Feld `startDate` bildet den
Aktionsbeginn nicht ab (es stand auf `11.09.2025`, obwohl die Aktion am 18.08.2026
aktiviert wurde) und hätte behauptet, der Aktionspreis gelte seit einem Jahr.

**Zeitplan:** Beobachtung ab 18.08.2026, Werte entstehen von selbst, sobald je Artikel
Historie vorliegt. Ab **17.09.2026** ist das Fenster voll. Das Dashboard ist von Anfang an
benutzbar — es zeigt Verlauf und Zustand, nur noch keine 30-Tage-Referenz.

### 4.4 Es gibt kein Aktionskennzeichen — und soll keines geben

Entscheidung GRUBE vom 18.08.2026: Aktionen können aus verschiedenen Stellen stammen; ein
Kennzeichen aus nur einer davon wäre schlimmer als keines, weil alle übrigen still
durchfielen. Der Preisschreiber liest **den angewendeten Preis, sonst nichts**, und
erkennt eine Ermäßigung daran, dass der Preis fällt.

Daraus folgt eine Einstellung, die getroffen werden muss: `permanent_after_days` (derzeit
60) entscheidet, ab wann ein gesenkter Preis als neuer Dauerpreis gilt. Sie **muss größer
sein als die längste geplante Aktionsdauer** — sonst kippt die Referenz mitten in einer
langen Aktion auf den Aktionspreis.

---

## 5. Was geschrieben wird

| Feld | Inhalt |
|---|---|
| `30_GROSS` / `30_NET` | der Referenzpreis nach § 11 — die rechtliche Grundlage |
| `PREV_GROSS` / `PREV_NET` | der Preis der unmittelbaren Vorstufe, als eigener Streichpreis für Abverkaufs-Preistreppen |

`30_*` und `PREV_*` laufen unabhängig voneinander; `PREV_*` ist freiwilliges
Frontend-Futter und berührt die Rechtsgrundlage nicht.

Geschrieben wird per `PATCH` am **kurzen** MCS — der PSS kennt nur diese Form. Gelesen
wird lang, geschrieben kurz.

**Delta-Write:** Nur tatsächlich geänderte Werte gehen hinaus. Ein fehlgeschlagener
Schreibvorgang wird beim nächsten Lauf von selbst nachgeholt, weil das Delta dann noch
offen ist.

---

## 6. Umgebungen

| | Staging | Produktiv |
|---|---|---|
| Shop / PSS | `integ.grube.de` | `admin.grube.app` |
| Tabellen | `y5x_stg_*` | `y5x_prod_*` |
| Shop-Link im Nachweis | `integ.`-Adressen | Produktivadressen |

**Der Shop-Link folgt der Umgebung.** Ein Nachweis, der auf einer Staging-Seite eine
Produktiv-URL nennt, behauptet, unter dieser Adresse sei geworben worden — und im
Abmahnfall ist genau die URL der Streitgegenstand.

### Die acht Märkte

| Markt | Währung | Shop |
|---|---|---|
| DE | EUR | www.grube.de |
| AT | EUR | www.grube.at |
| FR | EUR | www.grube.fr |
| PL | PLN | grube.pl |
| SK | EUR | www.grube.sk |
| SE | SEK | **skogma.se** |
| DK | DKK | **dansk-skovkontor.dk** |
| CH | CHF | **de.rolandschmid.ch** |

Drei Märkte laufen nicht unter `grube.<tld>` — eine aus dem Marktkürzel gebaute Adresse
wäre für sie falsch.

**Für die Schweiz wird nicht geschrieben.** Die Preisbekanntgabeverordnung folgt nicht der
EU-30-Tage-Logik. Getrackt wird trotzdem, damit Historie vorliegt, sobald Legal
entscheidet.

---

## 7. Offene Punkte

1. **Zeitpunkt des DiVA-Preisimports** — der Cron steht auf 05:30 und ist damit geraten.
2. **Längste geplante Aktionsdauer**, damit `permanent_after_days` sicher darüberliegt.
3. **`prev_price_max_days` von Legal kalibrieren** (Vorgabe 42 Tage).
4. **Soll `PREV_*` jemals leer sein?** Entscheidet, ob der Tracker die Leerung schreibt
   oder das Frontend über die Anzeige entscheidet.
5. `alert_email` und Shop-Kennungen je Markt.
6. **Verzeichnisschutz für `status/`** im ISPConfig-Panel (beide Umgebungen).
7. **CH-Freigabe** durch Legal.

---

## 8. Änderungshistorie

| Datum | Was |
|---|---|
| 18.08.2026 | Produktivstellung: Prod-Umgebung, Cron über `trigger.php`, Vorlauf gestartet |
| 18.08.2026 | **Preisquelle korrigiert** — `promotionPrices` am langen MCS; 3.331 übersehene Aktionen |
| 18.08.2026 | Schreibweg gebündelt (500 Einträge je Aufruf statt 2), Steuerabrufe entfallen |
| 18.08.2026 | Staging-Historie verworfen und vollständig von Integ neu eingelesen |
| 18.08.2026 | Dashboard: Nachweis-PDF, Preisdiagramm, Stichtag, Benutzerverwaltung, Einladungen |
| 18.08.2026 | PSS-Schreibweg auf der Integration ermittelt und belegt |
| 18.08.2026 | Rechenkern, Datenmodell, Dashboard-Grundgerüst |
