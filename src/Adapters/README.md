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

**Kein Aktionskennzeichen aus dem Shop lesen**, auch wenn eines auftauchen sollte
(Entscheidung GRUBE, 18.08.2026): Aktionen stammen aus verschiedenen Stellen, ein
Kennzeichen aus nur einer davon liesse alle uebrigen still durchfallen. Der Rechenkern
nimmt deshalb gar keinen Flag-Parameter mehr entgegen. Zu lesen sind ausschliesslich
`sku`, `net`, `gross`, `currency`.

Beide Adapter sind noch nicht gebaut: TODO(setup) 1 und 2 in der `CLAUDE.md`.
