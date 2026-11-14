#!/bin/bash
# =============================================================================
# 02_ctl_add_failure_subcategory.sh - ETAPE 2 de la phase B (sur validation)
#
# Ajoute le champ 28 FAILURE_SUBCATEGORY au fichier de controle SQL*Loader,
# juste apres UPDATED_AT (meme ordre que le CSV et la table).
#
#   ./02_ctl_add_failure_subcategory.sh                       = --check (lecture seule)
#   ORA_CONNECT=CMS_RFC@cmsprod ./02_ctl_add_failure_subcategory.sh --apply --confirm=CTL-FAILURE-20261113
#   ./02_ctl_add_failure_subcategory.sh --rollback <repertoire_sauvegarde> --confirm=CTL-ROLLBACK-20261113
#
# --apply exige : .ctl actuel = 27 champs attendus, table deja a 28 colonnes
# (etape 1 faite, verifiee par sqlplus), aucun chargement en cours, jeton
# --confirm et saisie de OUI au terminal. Aucun chargement n'est lance.
# =============================================================================
set -euo pipefail

APPLY_TOKEN=CTL-FAILURE-20261113
ROLLBACK_TOKEN=CTL-ROLLBACK-20261113
KIT="$(cd "$(dirname "$0")" && pwd)"
REPORT_DIR="${REPORT_DIR:-/u01/COMMERCIALS_APPS/Nwameh}"
if [ -z "${CTL:-}" ]; then
    for c in "$REPORT_DIR"/RECONCIL*IATION_EneoPay-CMS/control_tb_customers_list.ctl; do [ -f "$c" ] && CTL="$c"; done
fi
: "${CTL:?fichier .ctl introuvable : definir CTL=<chemin>}"
EXPECTED="$KIT/expected_columns.txt"

MODE="--check"; BK=""; TOKEN=""
for a in "$@"; do
    case "$a" in
        --check|--apply|--rollback) MODE="$a" ;;
        --confirm=*) TOKEN="${a#--confirm=}" ;;
        -*) echo "option inconnue : $a" >&2; exit 2 ;;
        *) BK="$a" ;;
    esac
done

log() { echo "$(date '+%F %T') [ctl_add_failure] $*"; }
die() { log "ARRET : $*"; exit 1; }
sha() { sha256sum "$1" | cut -d' ' -f1; }
# Liste des champs : apres « trailing nullcols », casse indifferente (le .ctl de
# production est en minuscules : fields terminated by "#" ... trailing nullcols)
fields() {
    tr -d '\r' < "$1" | awk 'f; tolower($0) ~ /trailing[ \t]+nullcols/ {f=1}' \
        | sed -nE 's/^\s*([A-Za-z_0-9]+)\s+(CHAR|INTEGER|DATE|DECIMAL|FILLER|char|integer|date|decimal|filler)\b.*/\1/p' | tr 'a-z' 'A-Z'
}
loading() { ps -ef | grep -E 'load_tb_customers|ExtractData|sqlldr' | grep -v grep || true; }
confirm() {
    [ "$TOKEN" = "$1" ] || die "desactive : relancer avec --confirm=$1"
    ( : < /dev/tty ) 2>/dev/null || die "confirmation interactive impossible (pas de terminal)"
    printf 'Taper OUI pour confirmer %s sur %s : ' "$MODE" "$CTL" > /dev/tty
    read -r ans < /dev/tty
    [ "$ans" = "OUI" ] || die "confirmation refusee"
}
state() {   # 27 | 28 | autre
    local f; f=$(fields "$1")
    if diff <(printf '%s\n' "$f") "$EXPECTED" >/dev/null; then echo 28
    elif diff <(printf '%s\n' "$f") <(head -27 "$EXPECTED") >/dev/null; then echo 27
    else echo autre; fi
}
table_cols() {
    command -v sqlplus >/dev/null || die "sqlplus absent : impossible de verifier la table"
    : "${ORA_CONNECT:?definir ORA_CONNECT=CMS_RFC@<tns> : la table doit etre verifiee avant --apply}"
    sqlplus -S "$ORA_CONNECT" <<'EOF' | grep -E '^[A-Z_0-9]+$'
SET HEADING OFF FEEDBACK OFF PAGESIZE 0 LINESIZE 200
SELECT column_name FROM all_tab_columns WHERE owner = 'CMS_RFC' AND table_name = 'TB_CUSTOMERS_LIST' ORDER BY column_id;
EXIT
EOF
}

[ -f "$CTL" ] || die ".ctl absent : $CTL"
ST=$(state "$CTL")
log "ctl : $CTL ($(stat -c '%U:%G mode=%a %s octets' "$CTL")) sha256=$(sha "$CTL") etat=$ST champs"

# Version cible : insertion apres la ligne UPDATED_AT (virgule ajoutee si absente)
render() {
    tr -d '\r' < "$1" | awk '
        tolower($0) ~ /trailing[ \t]+nullcols/ { inlist = 1 }
        inlist && toupper($0) ~ /^[[:space:]]*UPDATED_AT[[:space:]]/ && !done {
            line = $0; sub(/[[:space:]]*$/, "", line)
            if (line !~ /,$/) line = line ","
            print line
            print "    FAILURE_SUBCATEGORY     CHAR(20)"
            done = 1; next
        }
        { print }
        END { if (!done) exit 3 }'
}

case "$MODE" in
--check)
    case "$ST" in
        27) log "ctl a adapter (27 champs). Apercu de la modification :"
            diff <(tr -d '\r' < "$CTL") <(render "$CTL") || true ;;
        28) log "ctl deja a 28 champs : rien a faire" ;;
        *)  log "ALERTE ctl ni 27 ni 28 champs attendus : ne rien modifier"; fields "$CTL" | diff - "$EXPECTED" || true ;;
    esac
    ls -d "$(dirname "$CTL")"/backup_ctl/*/ 2>/dev/null | sed 's/^/  sauvegarde existante : /' || true
    log "check termine : rien n'a ete modifie"
    ;;
--apply)
    [ "$ST" = 27 ] || die "le .ctl n'est pas a 27 champs attendus (etat=$ST)"
    [ -z "$(loading)" ] || { loading; die "chargement en cours : attendre sa fin"; }
    h=$(date +%H%M)
    if [ "$h" -ge 0245 ] && [ "$h" -le 0545 ]; then
        log "ALERTE : fenetre du chargement nocturne (02:45-05:45) - preferer un autre moment"
    fi
    TC=$(table_cols) || die "lecture des colonnes de la table impossible (sqlplus, ORA_CONNECT ?)"
    diff <(printf '%s\n' "$TC") "$EXPECTED" >/dev/null || die "la table n'a pas les 28 colonnes attendues : faire l'etape 1 (01_alter_tb_customers_list.sql) d'abord"
    log "table : 28 colonnes attendues (etape 1 faite)"
    TMP="$(dirname "$CTL")/.$(basename "$CTL").new.$$"
    trap 'rm -f "$TMP"' EXIT
    render "$CTL" > "$TMP" || die "ligne UPDATED_AT introuvable dans la liste des champs"
    diff <(fields "$TMP") "$EXPECTED" >/dev/null || die "rendu incoherent (champs != 28 attendus)"
    confirm "$APPLY_TOKEN"

    BK="$(dirname "$CTL")/backup_ctl/$(date +%Y%m%d_%H%M%S)"
    mkdir -p "$BK"
    cp -p "$CTL" "$BK/$(basename "$CTL")"
    OLD_SHA=$(sha "$CTL")
    [ "$(sha "$BK/$(basename "$CTL")")" = "$OLD_SHA" ] || die "sauvegarde non conforme : $BK"
    { echo "target=$CTL"; echo "sha256=$OLD_SHA"; echo "owner=$(stat -c %U:%G "$CTL")"; echo "mode=$(stat -c %a "$CTL")"; echo "saved_at=$(date -Is)"; } > "$BK/SAVED.txt"
    chmod a-w "$BK/$(basename "$CTL")"
    log "sauvegarde OK : $BK"

    chown --reference="$CTL" "$TMP" 2>/dev/null || log "chown non applique (compte different)"
    chmod --reference="$CTL" "$TMP"
    # CRLF d'origine conserve si le fichier en avait
    if grep -q $'\r' "$CTL"; then sed -i 's/$/\r/' "$TMP"; fi
    NEW_SHA=$(sha "$TMP")
    mv -f "$TMP" "$CTL"
    [ "$(sha "$CTL")" = "$NEW_SHA" ] && [ "$(state "$CTL")" = 28 ] || die "verification apres remplacement en echec : lancer --rollback $BK"
    log "ADAPTE : $CTL (28 champs) sha256=$NEW_SHA"
    log "retour arriere : $0 --rollback $BK --confirm=$ROLLBACK_TOKEN"
    ;;
--rollback)
    [ -n "$BK" ] || die "usage : --rollback <repertoire_sauvegarde> --confirm=$ROLLBACK_TOKEN"
    SAVED="$BK/$(basename "$CTL")"
    [ -f "$SAVED" ] && [ "$(sha "$SAVED")" = "$(sed -n 's/^sha256=//p' "$BK/SAVED.txt")" ] || die "sauvegarde invalide : $BK"
    [ -z "$(loading)" ] || { loading; die "chargement en cours : attendre sa fin"; }
    confirm "$ROLLBACK_TOKEN"
    TMP="$(dirname "$CTL")/.$(basename "$CTL").rb.$$"
    trap 'rm -f "$TMP"' EXIT
    cp -p "$SAVED" "$TMP"; chmod "$(sed -n 's/^mode=//p' "$BK/SAVED.txt")" "$TMP"
    mv -f "$TMP" "$CTL"
    log "RESTAURE : $CTL (etat=$(state "$CTL") champs) - sauvegarde conservee : $BK"
    ;;
esac
