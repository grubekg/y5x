-- Einladungen statt Startpasswoerter.
--
-- Ein Admin, der ein Startpasswort setzt, KENNT das Passwort des Kollegen — und damit
-- ist das login_log als Nachweis, wer gearbeitet hat, geschwaecht. Mit einer Einladung
-- vergibt der Eingeladene sein Passwort selbst; niemand sonst hat es je gesehen.
--
-- Der Einladungsschluessel wird NUR als Hash gespeichert. Er ist ein Passwort-Aequivalent:
-- Wer die Tabelle lesen kann, koennte sonst jede offene Einladung uebernehmen.
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
