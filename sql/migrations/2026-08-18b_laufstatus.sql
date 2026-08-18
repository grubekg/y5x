-- Ein laufender Lauf ist kein fehlgeschlagener.
--
-- Bisher setzte der Lauf beim Start `status='failed'` mit der Notiz „laeuft" — als
-- absturzsichere Vorbelegung gedacht, auf der Statusseite aber schlicht falsch: Ein
-- System, das gerade arbeitet, meldete einen Vorfall. Rot muss echten Vorfaellen
-- vorbehalten bleiben, sonst ist Rot am Tag 30 bedeutungslos.
ALTER TABLE {{P}}run_log
  MODIFY COLUMN status ENUM('laeuft','ok','partial','failed') NOT NULL DEFAULT 'laeuft';
