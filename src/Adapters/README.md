# Adapter — Lesen aus dem Shop, Schreiben in den PSS

Die Richtung ist keine Geschmacksfrage, sie ist die Architektur:

* **`IshopPriceAdapter` (lesend)** — die einzige Preisquelle. Der maßgebliche Preis ist
  der, den ein anonymer Standardkunde im Shop tatsächlich zahlt: `amount: 0`,
  `customer: "0"`, `customerGroup: "DEFAULT"`, brutto und netto.
* **`PssPriceAdapter` (schreibend)** — das Ziel. `30_NET` / `30_GROSS` per Upsert.

**Niemals umgekehrt.** Der PSS führt Preise ohne Aktionen (`excludePromotions: true`),
und es gibt Aktionen, die dort gar nicht abgebildet sind. Ein aus dem PSS gespeister
Tracker übersähe Aktionszeiträume stillschweigend und wiese einen zu hohen Referenzpreis
aus — genau die Verfälschung, die das Werkzeug verhindern soll.

Beide Adapter sind noch nicht gebaut: TODO(setup) 1 und 2 in der `CLAUDE.md`.
