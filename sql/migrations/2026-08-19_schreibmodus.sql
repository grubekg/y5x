-- Der Schreibmodus gehoert zum LAUF, nicht in eine Konfigurationsdatei.
--
-- Bis zum 19.08.2026 schrieb `laufBeenden()` fest `pss_writes = 0` und die Notiz
-- "kein PSS-Write (Schreib-Adapter noch nicht gebaut)" — ein Rest aus der Zeit vor dem
-- Schreibadapter. Am 19.08.2026 gingen 391.968 Schreibsaetze fehlerfrei an den PSS,
-- waehrend run_log und Statusseite "0, Trockenmodus" behaupteten. Fuer ein Werkzeug,
-- das eine Beweiskette traegt, ist ein falsches Protokoll schlimmer als gar keines.
--
-- `--write` steht im Trigger, `dry_run: true` bleibt bewusst in der app.yml stehen
-- (Auslieferungszustand Trockenmodus). Eine Statusseite, die den Modus aus der Datei
-- liest, kann deshalb nur falsch liegen. Ab hier steht er je Lauf in der Zeile.
--
-- 'unbekannt' ist kein Platzhalter zum Aufraeumen, sondern die ehrliche Aussage fuer
-- alle Laeufe vor dieser Migration: Fuer sie wurde der Modus nie festgehalten.

ALTER TABLE {{P}}run_log
  ADD COLUMN write_mode ENUM('unbekannt','scharf','trocken','gesperrt')
      NOT NULL DEFAULT 'unbekannt' AFTER pss_writes;

ALTER TABLE {{P}}run_log
  ADD COLUMN write_errors INT NOT NULL DEFAULT 0 AFTER write_mode;

-- Rekonstruktion aus dem Schreibprotokoll: Was tatsaechlich uebertragen wurde, steht in
-- pss_write_log mit Markt und Zeitpunkt — das ist die belastbare Quelle, der Zaehler im
-- run_log war es nie. Grenze: Gaebe es zwei Laeufe desselben Marktes an einem Tag,
-- erhielten beide dieselbe Tagessumme. Am 19.08.2026 gab es je Markt genau einen.
UPDATE {{P}}run_log r
   SET r.pss_writes = (SELECT COUNT(*) FROM {{P}}pss_write_log w
                        WHERE w.market = r.market
                          AND DATE(w.written_at) = r.run_date
                          AND w.success = 1),
       r.write_mode = 'scharf'
 WHERE r.pss_writes = 0
   AND EXISTS (SELECT 1 FROM {{P}}pss_write_log w
                WHERE w.market = r.market
                  AND DATE(w.written_at) = r.run_date
                  AND w.success = 1);

UPDATE {{P}}run_log r
   SET r.write_errors = (SELECT COUNT(*) FROM {{P}}pss_write_log w
                          WHERE w.market = r.market
                            AND DATE(w.written_at) = r.run_date
                            AND w.success = 0)
 WHERE r.write_mode = 'scharf';

-- Die falsche Aussage darf nicht stehen bleiben; sie stuende sonst im Zweifel in einem
-- Schriftsatz. Ersetzt wird sie durch das, was wirklich bekannt ist.
UPDATE {{P}}run_log
   SET note = REPLACE(note, 'kein PSS-Write (Schreib-Adapter noch nicht gebaut)',
        'pss_writes am 19.08.2026 aus pss_write_log rekonstruiert — der Zähler wurde bis dahin nicht geführt')
 WHERE note LIKE '%Schreib-Adapter noch nicht gebaut%' AND pss_writes > 0;

UPDATE {{P}}run_log
   SET note = REPLACE(note, 'kein PSS-Write (Schreib-Adapter noch nicht gebaut)',
        'keine Schreibsätze protokolliert — der Schreibmodus dieses Laufs wurde nicht festgehalten')
 WHERE note LIKE '%Schreib-Adapter noch nicht gebaut%';
