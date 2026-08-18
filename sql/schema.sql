-- y5x — Preisschreiber
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
  -- Tag der letzten Preissenkung. Jede weitere Stufe setzt ihn zurueck; er ist der
  -- Timer fuer die Leerung des Vorstufen-Ankers (§ 6.4, UWG-Verschleiss).
  last_reduction_at     DATE NULL,
  -- Paar aus DEMSELBEN Event (§ 6.1) — speist PREV_NET/PREV_GROSS.
  pre_promo_gross       DECIMAL(12,2) NULL,
  pre_promo_net         DECIMAL(12,4) NULL,
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
  -- NULL heisst hier ausdruecklich "PREV ist im PSS geleert", nicht "nie gesetzt".
  last_written_prev_net   DECIMAL(12,4) NULL,
  last_written_prev_gross DECIMAL(12,2) NULL,
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
  -- 'laeuft' ist der Startzustand. Ein Lauf ohne Abschluss bleibt darauf stehen und
  -- wird vom naechsten Lauf desselben Marktes als 'failed' geschlossen — so ist der
  -- Unterschied zwischen "arbeitet gerade" und "abgestuerzt" belegt, nicht geraten.
  status         ENUM('laeuft','ok','partial','failed') NOT NULL DEFAULT 'laeuft',
  note           TEXT NULL,
  KEY idx_tag (run_date, market)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Jede Aenderung am PSS-Eintrag, mit altem UND neuem Wert. Grundlage fuer `rollback`
-- und fuer die Frage "was stand an Tag X im Shop". Aufbewahrung 13 Monate.
CREATE TABLE IF NOT EXISTS {{P}}pss_write_log (
  id               BIGINT AUTO_INCREMENT PRIMARY KEY,
  sku              VARCHAR(64) NOT NULL,
  market           CHAR(2) NOT NULL,
  price_type       ENUM('30_NET','30_GROSS','PREV_NET','PREV_GROSS') NOT NULL,
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
  -- 'admin' darf Zugaenge verwalten, 'user' nur den eigenen. Die Zugangsvergabe gehoert
  -- bei einem Werkzeug mit Beweisfunktion in wenige Haende — und das login_log ist nur
  -- aussagekraeftig, wenn sich niemand still einen zweiten Zugang anlegen kann.
  role          ENUM('admin','user') NOT NULL DEFAULT 'user',
  created_at    DATETIME NULL,
  last_login    DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Anmeldeversuche — Auditspur und Speicher der Versuchssperre.
CREATE TABLE IF NOT EXISTS {{P}}login_log (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  username    VARCHAR(190) NOT NULL,
  ip          VARCHAR(45)  NOT NULL,
  erfolg      TINYINT(1)   NOT NULL DEFAULT 0,
  versucht_at DATETIME     NOT NULL,
  KEY idx_sperre (username, ip, versucht_at),
  KEY idx_zeit (versucht_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Artikelbezeichnung (Anzeigehilfe, nicht Beweisgrundlage).
CREATE TABLE IF NOT EXISTS {{P}}article_meta (
  sku            VARCHAR(64)   NOT NULL,
  market         CHAR(2)       NOT NULL,
  name           VARCHAR(255)  NULL,
  -- Die kanonische Produkt-URL, wie der Shop sie selbst aufloest. Eine Abmahnung nennt
  -- eine Adresse; ein Suchlink waere funktional, aber nicht die beworbene URL.
  url            VARCHAR(1024) NULL,
  url_checked_at DATETIME      NULL,
  fetched_at     DATETIME      NOT NULL,
  PRIMARY KEY (sku, market)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Einladungen statt Startpasswoerter: Der Eingeladene vergibt sein Passwort selbst,
-- niemand sonst hat es je gesehen. Der Schluessel liegt nur als Hash vor.
CREATE TABLE IF NOT EXISTS {{P}}invitations (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(190) NOT NULL,
  role        ENUM('admin','user') NOT NULL DEFAULT 'user',
  token_hash  VARCHAR(255) NOT NULL,
  created_by  VARCHAR(190) NOT NULL,
  created_at  DATETIME     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  revoked_at  DATETIME     NULL,
  mail_sent   TINYINT(1)   NOT NULL DEFAULT 0,
  KEY idx_offen (email, used_at, revoked_at),
  KEY idx_zeit (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
