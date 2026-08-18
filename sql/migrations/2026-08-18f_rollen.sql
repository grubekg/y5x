-- Rollen: Wer darf Zugaenge verwalten, wer nur den eigenen?
--
-- Bisher durfte jeder Angemeldete Konten anlegen und loeschen. Bei einem Werkzeug mit
-- Beweisfunktion gehoert die Zugangsvergabe in wenige Haende — und das `login_log` ist
-- nur dann aussagekraeftig, wenn nicht jeder sich still einen zweiten Zugang anlegen kann.
ALTER TABLE {{P}}users
  ADD COLUMN role ENUM('admin','user') NOT NULL DEFAULT 'user' AFTER password_hash;

-- Bestehende Zugaenge werden Admin: Sonst sperrte sich die Installation selbst aus.
UPDATE {{P}}users SET role = 'admin';
