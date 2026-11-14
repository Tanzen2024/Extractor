#!/bin/bash
# =============================================================================
# deploy_customers_list.sh - remplacement de customers_list.sql (serveur source)
#
#   ./deploy_customers_list.sh                     = --check (lecture seule)
#   ./deploy_customers_list.sh --check
#   ORA_CONNECT=CMS_RFC@cmsprod ./deploy_customers_list.sh --apply --confirm=DEPLOY-SQL-V2-20261113
#   ./deploy_customers_list.sh --rollback <repertoire_sauvegarde> --confirm=ROLLBACK-SQL-V2-20261113
#
# ETAPE 3 de la phase B : --apply refuse tant que la table (etape 1) et le
# .ctl (etape 2) n'ont pas leurs 28 colonnes (check_compat STRICT=1).
# --apply et --rollback sont DESACTIVES sans le jeton --confirm exact ET une
# confirmation tapee au terminal. Aucun chargement n'est jamais lance.
# A executer avec le compte proprietaire de SqlQueries/customers_list.sql.
# =============================================================================
set -euo pipefail

NEW_SHA=f85882369407b53da65331ba0429230325463a2ac261501cadae086bd3ece6ca   # kit v2 : PREPAID + colonne 28, 28 colonnes
OLD_SHA=5f18d89e077b8ab72a68096a3a0eec5ce7c5c84ee78ce1b8a4633009d7c90431   # 574d3dd, version du 01/10
APPLY_TOKEN=DEPLOY-SQL-V2-20261113
ROLLBACK_TOKEN=ROLLBACK-SQL-V2-20261113

KIT="$(cd "$(dirname "$0")" && pwd)"
REPORT_DIR="${REPORT_DIR:-/u01/COMMERCIALS_APPS/Nwameh}"
TARGET="$REPORT_DIR/SqlQueries/customers_list.sql"
NEW_SQL="$KIT/customers_list.sql"
BACKUP_ROOT="$REPORT_DIR/SqlQueries/backup"

MODE="--check"; BK=""; TOKEN=""
for a in "$@"; do
    case "$a" in
        --check|--apply|--rollback) MODE="$a" ;;
        --confirm=*) TOKEN="${a#--confirm=}" ;;
        -*) echo "option inconnue : $a" >&2; exit 2 ;;
        *) BK="$a" ;;
    esac
done

log() { echo "$(date '+%F %T') [deploy_customers_list] $*"; }
die() { log "ARRET : $*"; exit 1; }
sha() { sha256sum "$1" | cut -d' ' -f1; }
info() { stat -c '%U:%G mode=%a %s octets mtime=%y' "$1"; }
loading() { ps -ef | grep -E 'load_tb_customers|ExtractData|sqlldr' | grep -v grep || true; }
confirm() {   # $1 = jeton attendu
    [ "$TOKEN" = "$1" ] || die "desactive : relancer avec --confirm=$1"
    ( : < /dev/tty ) 2>/dev/null || die "confirmation interactive impossible (pas de terminal)"
    printf 'Taper OUI pour confirmer %s sur %s : ' "$MODE" "$TARGET" > /dev/tty
    read -r ans < /dev/tty
    [ "$ans" = "OUI" ] || die "confirmation refusee"
}
window_warning() {
    h=$(date +%H%M)
    if [ "$h" -ge 0245 ] && [ "$h" -le 0545 ]; then
        log "ALERTE : fenetre du chargement nocturne (generateurs 03:00 et 04:00, chargement 05:00-05:15 ; plage 02:45-05:45) - preferer un autre moment"
    fi
}

log "mode=$MODE REPORT_DIR=$REPORT_DIR"
[ -f "$TARGET" ] || die "cible absente : $TARGET"
log "cible   : $TARGET ($(info "$TARGET"))"
CUR=$(sha "$TARGET"); log "cible sha256=$CUR"
case "$CUR" in
    "$OLD_SHA") log "cible = version du 01/10 (574d3dd)" ;;
    "$NEW_SHA") log "cible = version du kit (deja deployee)" ;;
    *)          log "ALERTE cible = version INCONNUE : ne rien remplacer, comparer d'abord" ;;
esac
[ "$(sha "$NEW_SQL")" = "$NEW_SHA" ] || die "empreinte du SQL du kit incorrecte"
RUN=$(loading); [ -n "$RUN" ] && { log "chargement en cours :"; echo "$RUN"; }

case "$MODE" in
--check)
    REPORT_DIR="$REPORT_DIR" bash "$KIT/check_compat.sh" || log "check_compat : echec(s) a corriger avant --apply"
    ls -d "$BACKUP_ROOT"/*/ 2>/dev/null | sed 's/^/  sauvegarde existante : /' || true
    log "check termine : rien n'a ete modifie"
    ;;
--apply)
    [ "$CUR" = "$OLD_SHA" ] || die "la cible n'est pas la version 01/10 attendue"
    [ -z "$RUN" ] || die "chargement en cours : attendre sa fin"
    [ -n "${ORA_CONNECT:-}" ] || die "definir ORA_CONNECT=CMS_RFC@<tns> : la table doit etre verifiee (28 colonnes) avant --apply"
    REPORT_DIR="$REPORT_DIR" STRICT=1 bash "$KIT/check_compat.sh" >/dev/null \
        || die "check_compat STRICT en echec (table ou .ctl pas encore a 28 colonnes ?) : STRICT=1 ./check_compat.sh pour le detail"
    window_warning
    confirm "$APPLY_TOKEN"

    # 1. Sauvegarde horodatee, verifiee, proprietaire et permissions conserves
    BK="$BACKUP_ROOT/$(date +%Y%m%d_%H%M%S)"
    mkdir -p "$BK"
    cp -p "$TARGET" "$BK/customers_list.sql"
    [ "$(sha "$BK/customers_list.sql")" = "$OLD_SHA" ] || die "sauvegarde non conforme : $BK"
    { echo "target=$TARGET"; echo "sha256=$OLD_SHA"; echo "owner=$(stat -c %U:%G "$TARGET")";
      echo "mode=$(stat -c %a "$TARGET")"; echo "saved_at=$(date -Is)"; echo "saved_by=$(id -un)"; } > "$BK/SAVED.txt"
    sha256sum "$BK/customers_list.sql" > "$BK/SHA256SUMS"
    chmod a-w "$BK/customers_list.sql"
    log "sauvegarde OK : $BK"

    # 2. Temporaire dans le meme repertoire : mv = rename(2) atomique
    TMP="$(dirname "$TARGET")/.customers_list.sql.new.$$"
    trap 'rm -f "$TMP"' EXIT
    cp "$NEW_SQL" "$TMP"
    chown --reference="$TARGET" "$TMP" 2>/dev/null || log "chown non applique (compte different) : $(stat -c %U:%G "$TMP")"
    chmod --reference="$TARGET" "$TMP"
    [ "$(sha "$TMP")" = "$NEW_SHA" ] || die "empreinte du temporaire incorrecte"

    # 3. Remplacement
    mv -f "$TMP" "$TARGET"
    [ "$(sha "$TARGET")" = "$NEW_SHA" ] || die "empreinte apres remplacement incorrecte : lancer --rollback $BK"
    log "DEPLOYE : $TARGET ($(info "$TARGET"))"
    log "retour arriere : $0 --rollback $BK --confirm=$ROLLBACK_TOKEN"
    log "AUCUN chargement lance : le prochain load_tb_customers_list.sh planifie utilisera ce fichier."
    ;;
--rollback)
    [ -n "$BK" ] || die "usage : --rollback <repertoire_sauvegarde> --confirm=$ROLLBACK_TOKEN"
    [ -f "$BK/customers_list.sql" ] && [ "$(sha "$BK/customers_list.sql")" = "$OLD_SHA" ] || die "sauvegarde invalide : $BK"
    [ -z "$RUN" ] || die "chargement en cours : attendre sa fin"
    confirm "$ROLLBACK_TOKEN"
    TMP="$(dirname "$TARGET")/.customers_list.sql.rb.$$"
    trap 'rm -f "$TMP"' EXIT
    cp -p "$BK/customers_list.sql" "$TMP"
    chmod "$(sed -n 's/^mode=//p' "$BK/SAVED.txt")" "$TMP"
    mv -f "$TMP" "$TARGET"
    [ "$(sha "$TARGET")" = "$OLD_SHA" ] || die "restauration non conforme"
    log "RESTAURE : $TARGET ($(info "$TARGET")) - sauvegarde conservee : $BK"
    log "TB_CUSTOMERS_LIST garde les donnees du dernier chargement jusqu'au chargement suivant."
    ;;
esac
