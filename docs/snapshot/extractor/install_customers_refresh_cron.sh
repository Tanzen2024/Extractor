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
# Horaire : 05:00 (besoin métier), le rechargement Oracle amont étant fini
# vers 04:42 (mesuré le 2026-09-29). Un rechargement encore en cours à 05:00
# est refusé par la commande (lignes extraites ≠ COUNT(*) initial, ou chute de
# volume) et l'ancienne version reste active ; le rattrapage de 06:00
# (--skip-if-fresh) relance alors le refresh, et ne fait rien si celui de
# 05:00 a réussi. Après un refresh réussi, dashboard:warm pré-calcule le
# tableau de bord de la nouvelle version.
#
# Chaque refresh construit aussi l'index DuckDB de la nouvelle version : le
# binaire duckdb doit être exécutable par l'utilisateur du cron.
#
# Usage (root) :
#   sh install_customers_refresh_cron.sh [--app-dir /var/www/extractor] [--user www-data]
#                                        [--time 05:00] [--catch-up 06:00|none]
#                                        [--php /usr/bin/php] [--duckdb /usr/local/bin/duckdb] [--dry-run]
set -eu

APP_DIR=/var/www/extractor
RUN_USER=www-data
AT=05:00
CATCH_UP=06:00
PHP_BIN=
DUCKDB_BIN=
DRY_RUN=0
CRON_FILE=/etc/cron.d/extractor-customers-refresh   # pas de '.' : ignoré par cron sinon

die() { echo "ERREUR : $*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --app-dir) APP_DIR=$2; shift 2 ;;
        --user)    RUN_USER=$2; shift 2 ;;
        --time)    AT=$2; shift 2 ;;
        --catch-up) CATCH_UP=$2; shift 2 ;;
        --duckdb)  DUCKDB_BIN=$2; shift 2 ;;
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
case "$CATCH_UP" in
    none) ;;
    [0-2][0-9]:[0-5][0-9]) [ "$CATCH_UP" \> "$AT" ] || die "--catch-up ($CATCH_UP) doit être après --time ($AT)" ;;
    *) die "--catch-up attend HH:MM ou none (reçu : $CATCH_UP)" ;;
esac

# 1. PHP réellement utilisé (chemin absolu), version et extension Oracle.
[ -n "$PHP_BIN" ] || PHP_BIN=$(command -v php) || die "php introuvable dans le PATH (utiliser --php)"
case "$PHP_BIN" in /*) ;; *) die "chemin PHP non absolu : $PHP_BIN" ;; esac
[ -x "$PHP_BIN" ] || die "$PHP_BIN n'est pas exécutable"
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || die "$PHP_BIN : PHP >= 8.2 requis ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"
"$PHP_BIN" -r 'exit(extension_loaded("oci8") ? 0 : 1);' || die "$PHP_BIN : extension oci8 absente (la commande lit Oracle)"

# 1 bis. DuckDB (index de requête de chaque nouvelle version).
[ -n "$DUCKDB_BIN" ] || DUCKDB_BIN=$(command -v duckdb) || die "duckdb introuvable dans le PATH (utiliser --duckdb, voir docs/snapshot/README.md)"
"$DUCKDB_BIN" -version >/dev/null 2>&1 || die "$DUCKDB_BIN ne s'exécute pas"

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
WARM="$PHP_BIN spark dashboard:warm >> $LOG 2>&1"
LINE="$MINUTE $HOUR * * * $RUN_USER cd $APP_DIR && $PHP_BIN spark customers:refresh >> $LOG 2>&1 && $WARM"
CATCH_LINE=
if [ "$CATCH_UP" != none ]; then
    CH=$(echo "$CATCH_UP" | cut -d: -f1 | sed 's/^0\(.\)$/\1/')
    CM=$(echo "$CATCH_UP" | cut -d: -f2 | sed 's/^0\(.\)$/\1/')
    CATCH_LINE="$CM $CH * * * $RUN_USER cd $APP_DIR && $PHP_BIN spark customers:refresh --skip-if-fresh >> $LOG 2>&1 && $WARM"
fi

echo "PHP        : $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;'), oci8 OK)"
echo "Projet     : $APP_DIR"
echo "Utilisateur: $RUN_USER"
echo "DuckDB     : $DUCKDB_BIN ($("$DUCKDB_BIN" -version 2>/dev/null))"
echo "Heure      : $AT ($OFFSET), rattrapage : $CATCH_UP"
echo "Log        : $LOG"
echo "Cron       : $LINE"
[ -z "$CATCH_LINE" ] || echo "Rattrapage : $CATCH_LINE"

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
    [ -z "$CATCH_LINE" ] || echo "$CATCH_LINE"
} > "$TMP"
chmod 0644 "$TMP"
mv "$TMP" "$CRON_FILE"
echo "Installé : $CRON_FILE"
echo "Test immédiat conseillé : su -s /bin/sh $RUN_USER -c 'cd $APP_DIR && $PHP_BIN spark customers:refresh'; echo \$?"
