#!/bin/sh
# Installe le refresh quotidien Oracle -> snapshot (php spark customers:refresh)
# sur le serveur Linux de PRODUCTION d'Extractor, dans /etc/cron.d.
#
# Rien n'est supposé : le PHP utilisé est résolu (command -v php) puis vérifié
# (version, oci8), l'utilisateur doit pouvoir écrire dans writable/, et le
# fuseau du serveur doit être celui de l'application (Africa/Douala, UTC+1).
# Le verrou anti-concurrence est celui de la commande elle-même (flock noyau
# sur writable/data/customers_refresh.lock, libéré par l'OS si le process
# meurt) : une 2e instance sort aussitôt en code 3, sans toucher Oracle.
#
# Usage (root) :
#   sh install_customers_refresh_cron.sh [--app-dir /var/www/extractor] [--user www-data]
#                                        [--time 05:30] [--php /usr/bin/php] [--dry-run]
set -eu

APP_DIR=/var/www/extractor
RUN_USER=www-data
AT=05:30
PHP_BIN=
DRY_RUN=0
CRON_FILE=/etc/cron.d/extractor-customers-refresh   # pas de '.' : ignoré par cron sinon

die() { echo "ERREUR : $*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --app-dir) APP_DIR=$2; shift 2 ;;
        --user)    RUN_USER=$2; shift 2 ;;
        --time)    AT=$2; shift 2 ;;
        --php)     PHP_BIN=$2; shift 2 ;;
        --dry-run) DRY_RUN=1; shift ;;
        *) die "option inconnue : $1" ;;
    esac
done

case "$AT" in
    [0-2][0-9]:[0-5][0-9]) ;;
    *) die "--time attend HH:MM (reçu : $AT)" ;;
esac
HOUR=$(echo "$AT" | cut -d: -f1 | sed 's/^0\(.\)$/\1/')
MINUTE=$(echo "$AT" | cut -d: -f2 | sed 's/^0\(.\)$/\1/')

# 1. PHP réellement utilisé (chemin absolu), version et extension Oracle.
[ -n "$PHP_BIN" ] || PHP_BIN=$(command -v php) || die "php introuvable dans le PATH (utiliser --php)"
case "$PHP_BIN" in /*) ;; *) die "chemin PHP non absolu : $PHP_BIN" ;; esac
[ -x "$PHP_BIN" ] || die "$PHP_BIN n'est pas exécutable"
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || die "$PHP_BIN : PHP >= 8.2 requis ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"
"$PHP_BIN" -r 'exit(extension_loaded("oci8") ? 0 : 1);' || die "$PHP_BIN : extension oci8 absente (la commande lit Oracle)"

# 2. Application et droits de l'utilisateur qui exécutera le cron.
[ -f "$APP_DIR/spark" ] || die "$APP_DIR/spark introuvable (--app-dir)"
id "$RUN_USER" >/dev/null 2>&1 || die "utilisateur $RUN_USER inexistant (--user)"
for d in "$APP_DIR/writable/data" "$APP_DIR/writable/logs"; do
    [ -d "$d" ] || die "$d manquant"
    if [ "$(id -u)" -eq 0 ]; then
        su -s /bin/sh "$RUN_USER" -c "test -w '$d'" || die "$RUN_USER ne peut pas écrire dans $d"
    fi
done

# 3. cron suit l'heure système : elle doit être celle de l'application (UTC+1).
OFFSET=$(date +%z)
[ "$OFFSET" = "+0100" ] || die "fuseau serveur $OFFSET ($(date +%Z)) ≠ +0100 (Africa/Douala) : ajuster --time en heure serveur ou le fuseau (timedatectl)"

LOG="$APP_DIR/writable/logs/customers_refresh.log"
LINE="$MINUTE $HOUR * * * $RUN_USER cd $APP_DIR && $PHP_BIN spark customers:refresh >> $LOG 2>&1"

echo "PHP        : $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;'), oci8 OK)"
echo "Projet     : $APP_DIR"
echo "Utilisateur: $RUN_USER"
echo "Heure      : $AT ($OFFSET)"
echo "Log        : $LOG"
echo "Cron       : $LINE"

if [ "$DRY_RUN" -eq 1 ]; then
    echo "(--dry-run : $CRON_FILE non écrit)"
    exit 0
fi

[ "$(id -u)" -eq 0 ] || die "exécuter en root pour écrire $CRON_FILE (ou --dry-run)"
TMP=$(mktemp)
{
    echo "# Extractor — refresh quotidien Oracle CMS_RFC.TB_CUSTOMERS_LIST -> snapshot actif."
    echo "# Rechargement Oracle amont : génération ~04:32, truncate ~04:40, chargement fini ~04:42"
    echo "# (mesuré le 2026-09-29). Échec = ancienne version conservée ; 2e instance = code 3."
    echo "# Installé par docs/snapshot/extractor/install_customers_refresh_cron.sh le $(date '+%Y-%m-%d %H:%M')."
    echo "SHELL=/bin/sh"
    echo "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
    echo "$LINE"
} > "$TMP"
chmod 0644 "$TMP"
mv "$TMP" "$CRON_FILE"
echo "Installé : $CRON_FILE"
echo "Test immédiat conseillé : su -s /bin/sh $RUN_USER -c 'cd $APP_DIR && $PHP_BIN spark customers:refresh'; echo \$?"
