-- Anmeldeversuche protokollieren — passend zum Auditcharakter des Werkzeugs.
-- Dient zugleich als Speicher für die Versuchssperre (5 Fehlversuche je Konto+IP).
CREATE TABLE IF NOT EXISTS {{P}}login_log (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  username   VARCHAR(190) NOT NULL,
  ip         VARCHAR(45)  NOT NULL,
  erfolg     TINYINT(1)   NOT NULL DEFAULT 0,
  versucht_at DATETIME    NOT NULL,
  KEY idx_sperre (username, ip, versucht_at),
  KEY idx_zeit (versucht_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
