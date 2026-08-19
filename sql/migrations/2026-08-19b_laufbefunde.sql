-- Verworfene Datensaetze und Fehler eines Laufs vollstaendig, statt zehn davon in einer
-- Notizspalte.
--
-- Die Notiz im run_log traegt nur die ersten zehn Anomalien und schneidet den Rest mit
-- "… (+31 weitere)" ab. Auf der Statusseite bleiben davon 120 Zeichen uebrig. Genau die
-- Zeile, die man im Zweifel braucht — "welcher Artikel wurde warum verworfen" — ist damit
-- die, die fehlt. Hier steht jeder Befund einzeln und laesst sich als CSV herunterladen.
--
-- `sku` ist NULL erlaubt: Ein Fehler kann auch den ganzen Markt treffen, nicht nur einen
-- Artikel.

CREATE TABLE IF NOT EXISTS {{P}}run_issue (
  id        BIGINT AUTO_INCREMENT PRIMARY KEY,
  run_id    INT NOT NULL,
  run_date  DATE NOT NULL,
  market    CHAR(2) NOT NULL,
  kind      ENUM('anomalie','fehler') NOT NULL,
  sku       VARCHAR(64) NULL,
  detail    VARCHAR(512) NOT NULL,
  net       VARCHAR(32) NULL,
  gross     VARCHAR(32) NULL,
  KEY idx_lauf (run_id),
  KEY idx_tag (run_date, market)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
