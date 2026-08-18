-- Kanonische Produkt-URL je Artikel.
--
-- Eine Abmahnung nennt eine URL. Ein Suchlink, der auf die Produktseite weiterleitet,
-- ist funktional, aber es ist NICHT die Adresse, unter der geworben wurde — auf einem
-- Nachweisdokument muss die echte stehen. Sie wird deshalb einmal aufgeloest und mit
-- Zeitstempel festgehalten; aendert der Shop spaeter den Slug, bleibt belegbar, welche
-- Adresse zum Zeitpunkt des Nachweises galt.
ALTER TABLE {{P}}article_meta
  ADD COLUMN url            VARCHAR(1024) NULL AFTER name,
  ADD COLUMN url_checked_at DATETIME      NULL AFTER url;
