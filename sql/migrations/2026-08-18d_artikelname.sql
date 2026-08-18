-- Artikelbezeichnung aus dem iSHOP, zwischengespeichert.
-- Der Name gehoert nicht in die Beweisgrundlage (`price_events` bleibt unberuehrt) —
-- er ist Anzeigehilfe. Deshalb eine eigene Tabelle mit Abrufzeitpunkt: Aendert der Shop
-- die Bezeichnung, ist nachvollziehbar, welchen Stand ein gedruckter Nachweis zeigte.
CREATE TABLE IF NOT EXISTS {{P}}article_meta (
  sku        VARCHAR(64) NOT NULL,
  market     CHAR(2)     NOT NULL,
  name       VARCHAR(255) NULL,
  fetched_at DATETIME    NOT NULL,
  PRIMARY KEY (sku, market)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
