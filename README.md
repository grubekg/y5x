# y5x — Preisschreiber

Taegliches Tracking der Shop-Verkaufspreise je Markt und Schreiben des niedrigsten
Preises der letzten 30 Tage als `30_NET` / `30_GROSS` in den PSS (§ 11 PAngV).

**Bewusst ohne KI.** Das Werkzeug ist Teil einer rechtlichen Beweiskette und muss
vollstaendig deterministisch und nachrechenbar sein.

    php tests/run.php        # Rechenkerne pruefen — ohne Netz, ohne DB, ohne Composer

Klarname, Kuerzel, Betrieb und Entscheidungen: siehe `CLAUDE.md`.
