#!/usr/bin/env bash
# y5x — Code in die Laufzeit spiegeln. Secrets, Logs und vendor/ bleiben unberuehrt.
set -euo pipefail
env="${1:?staging oder prod fehlt}"
[ "$env" = staging ] || [ "$env" = prod ] || { echo "env muss staging oder prod sein"; exit 1; }

SITE=/var/www/clients/client1/web81
HOME_DIR="$SITE/home/c106635tools"
REPO="$HOME_DIR/repos/y5x"
RUN="$SITE/private/apps/y5x/$env"
[ "$env" = prod ] && WEB="$SITE/web/y5x" || WEB="$SITE/web/staging/y5x"

mkdir -p "$RUN"/{logs,config} "$WEB/status"
rsync -a --delete "$REPO/src/" "$RUN/src/"
rsync -a --delete "$REPO/sql/" "$RUN/sql/"
rsync -a --delete "$REPO/bin/" "$RUN/bin/"
rsync -a --delete "$REPO/tests/" "$RUN/tests/"
rsync -a          "$REPO/config/" "$RUN/config/"     # ohne --delete: lokale Anpassungen bleiben
cp "$REPO/autoload.php" "$RUN/autoload.php"

# Statusseite: Der FPM-Pool dieses Webspace darf nur web/, private/ und tmp/ lesen —
# ein require auf $HOME/secrets/db.php endet mit "Permission denied" und einem 500er.
# Deshalb wird der zentrale Zugang in die Laufzeit gespiegelt (600) und die Seite liest
# nur von dort. Generiert statt gepflegt: Eine handgefuehrte zweite Kopie eines Passworts
# ist immer die, die bei der naechsten Rotation vergessen wird.
rsync -a --delete "$REPO/public/status/" "$WEB/status/"
install -m 600 "$HOME_DIR/secrets/db.php" "$RUN/db.php"
printf '%s' "$env" > "$RUN/ENV"

echo "deploy ok ($env):"
echo "  Code       -> $RUN/{src,bin,sql,tests,config}"
echo "  Statusseite-> $WEB/status/   (Verzeichnisschutz im ISPConfig-Panel setzen!)"
echo "  DB-Zugang  -> $RUN/db.php (aus secrets/db.php gespiegelt, 600)"
echo "Hinweis: .env, logs/ und vendor/ bleiben in $RUN."
