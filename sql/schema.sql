-- y5x — 30-Tage-Bestpreis-Tracker
--
-- {{P}} wird beim Anlegen durch y5x_prod_ bzw. y5x_stg_ ersetzt. Auf diesem Webspace
-- teilen sich ALLE Projekte und BEIDE Umgebungen eine einzige MySQL-Datenbank; getrennt
-- wird ausschliesslich ueber den Tabellennamen. Ohne den env-Teil wuerde ein
-- Staging-Test die Produktionsdaten ueberschreiben — hier waere das der Verlust einer
-- Beweisgrundlage, nicht bloss ein Datenfehler.

-- Die Beweisgrundlage: Preisintervalle statt taeglicher Momentaufnahmen.
-- `valid_to` ist der letzte Tag, an dem dieser Preis BEOBACHTET wurde (nicht "bis auf
-- Weiteres"): Belegen laesst sich nur, was gemessen wurde. Aufbewahrung unbegrenzt.
CREATE TABLE IF NOT EXISTS {{P}}price_events (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  sku        VARCHAR(64) NOT NULL,
  market     CHAR(2) NOT NULL,
  currency   CHAR(3) NOT NULL,
  net        DECIMAL(12,4) NOT NULL,
  gross      DECIMAL(12,2) NOT NULL,
  valid_from DATE NOT NULL,
  valid_to   DATE NULL,
  UNIQUE KEY uq (sku, market, valid_from),
  KEY idx_open (sku, market, valid_to),
  KEY idx_fenster (sku, market, valid_from, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {{P}}price_state (
  sku                   VARCHAR(64) NOT NULL,
  market                CHAR(2) NOT NULL,
  mode                  ENUM('normal','promo') NOT NULL DEFAULT 'normal',
  promo_started         DATE NULL,
  pre_promo_gross       DECIMAL(12,2) NULL,
  frozen_ref_net        DECIMAL(12,4) NULL,
  frozen_ref_gross      DECIMAL(12,2) NULL,
  -- 1, sobald das Fenster lueckenlos belegt ist. Vorher wird trotzdem geschrieben
  -- (bei Neuartikeln ist "niedrigster Preis seit Angebotsbeginn" genau richtig),
  -- aber die Belastbarkeit der Aussage ist markiert.
  window_complete       TINYINT(1) NOT NULL DEFAULT 0,
  -- Nur bei ERFOLGREICHEM Write gesetzt. Dadurch holt der naechste Lauf einen
  -- fehlgeschlagenen Write von selbst nach — die Delta-Erkennung greift wieder.
  last_written_30_net   DECIMAL(12,4) NULL,
  last_written_30_gross DECIMAL(12,2) NULL,
  last_written_at       DATETIME NULL,
  last_transition       VARCHAR(160) NULL,
  updated_at            DATETIME NULL,
  PRIMARY KEY (sku, market),
  KEY idx_mode (market, mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Belegt, dass TAEGLICH geprueft wurde — der zweite Teil der Beweiskette neben den
-- Intervallen. Aufbewahrung 24 Monate.
CREATE TABLE IF NOT EXISTS {{P}}run_log (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  run_date       DATE NOT NULL,
  market         CHAR(2) NOT NULL,
  started_at     DATETIME NULL,
  finished_at    DATETIME NULL,
  items_fetched  INT NOT NULL DEFAULT 0,
  price_changes  INT NOT NULL DEFAULT 0,
  pss_writes     INT NOT NULL DEFAULT 0,
  anomalies      INT NOT NULL DEFAULT 0,
  errors         INT NOT NULL DEFAULT 0,
  status         ENUM('ok','partial','failed') NOT NULL DEFAULT 'failed',
  note           TEXT NULL,
  KEY idx_tag (run_date, market)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Jede Aenderung am PSS-Eintrag, mit altem UND neuem Wert. Grundlage fuer `rollback`
-- und fuer die Frage "was stand an Tag X im Shop". Aufbewahrung 13 Monate.
CREATE TABLE IF NOT EXISTS {{P}}pss_write_log (
  id               BIGINT AUTO_INCREMENT PRIMARY KEY,
  sku              VARCHAR(64) NOT NULL,
  market           CHAR(2) NOT NULL,
  price_type       ENUM('30_NET','30_GROSS') NOT NULL,
  old_value        DECIMAL(12,4) NULL,
  new_value        DECIMAL(12,4) NOT NULL,
  currency         CHAR(3) NOT NULL,
  written_at       DATETIME NOT NULL,
  http_status      SMALLINT NULL,
  success          TINYINT(1) NOT NULL DEFAULT 0,
  attempt          TINYINT NOT NULL DEFAULT 1,
  response_excerpt VARCHAR(512) NULL,
  KEY idx_sku (sku, market, price_type, written_at),
  KEY idx_zeit (written_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {{P}}users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME NULL,
  last_login    DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
