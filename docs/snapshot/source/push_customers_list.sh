#!/bin/bash
# =============================================================================
# push_customers_list.sh — serveur source 10.250.90.200
#
# Pousse un customers_list.csv COMPLET vers Extractor puis déclenche sa
# validation/activation côté Extractor. Appelé par load_tb_customers_list.sh
# UNIQUEMENT après le succès d'ExtractData.gvy.
#
#   push_customers_list.sh /u01/COMMERCIALS_APPS/Nwameh/customers_list.csv
#
# Codes retour :
#   0  snapshot transféré, validé et activé par Extractor
#   10 fichier source absent/vide      11 préparation outbox/manifeste KO
#   20 transfert SFTP KO               30 Extractor a refusé/pas activé
#   31 déclenchement snapshot:install KO (réseau/SSH)
#
# Aucun secret ici : seulement des CHEMINS vers les clés privées, lues depuis
# push_customers_list.conf (hors Git, chmod 600, propriété du compte du job).
# =============================================================================
set -u
set -o pipefail

CSV="${1:?usage: push_customers_list.sh <customers_list.csv>}"
BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
CONF="${PUSH_CONF:-$BASE_DIR/jobs/push_customers_list.conf}"
OUTBOX="$BASE_DIR/outbox"

log() { echo "$(date '+%F %T') [push_customers_list] $*"; }

# shellcheck source=/dev/null
[ -r "$CONF" ] || { log "ECHEC configuration illisible : $CONF"; exit 11; }
. "$CONF"   # EXTRACTOR_HOST, PUSH_USER, PUSH_KEY, REMOTE_DIR, INSTALL_USER, INSTALL_KEY, KNOWN_HOSTS
REMOTE_DIR="${REMOTE_DIR:-incoming}"

SSH_OPTS=(-o BatchMode=yes -o StrictHostKeyChecking=yes -o "UserKnownHostsFile=$KNOWN_HOSTS"
          -o ConnectTimeout=30 -o ServerAliveInterval=30 -o ServerAliveCountMax=4)

[ -s "$CSV" ] || { log "ECHEC fichier absent ou vide : $CSV — pas de SFTP"; exit 10; }

# 1. Figer le fichier à envoyer : lien physique (instantané, même inode). Une
#    génération suivante qui remplace customers_list.csv (mv) ne modifie pas
#    ce que l'on est en train d'envoyer.
mkdir -p "$OUTBOX" || { log "ECHEC création $OUTBOX"; exit 11; }
STAMP="$(date +%Y%m%d_%H%M%S)"
SNAP="$OUTBOX/customers_list_$STAMP.csv"
MAN="$OUTBOX/customers_list_$STAMP.manifest"
ln "$CSV" "$SNAP" 2>/dev/null || cp -p "$CSV" "$SNAP" || { log "ECHEC préparation outbox"; exit 11; }

# 2. Métadonnées calculées sur le fichier réel — rien n'est estimé.
SIZE="$(stat -c%s "$SNAP")"
LINES="$(wc -l < "$SNAP")"
HEADER="$(head -1 "$SNAP" | tr -d '\r')"
COLUMNS="$(printf '%s\n' "$HEADER" | awk -F'#' '{print NF}')"
GENERATED_AT="$(date -r "$SNAP" '+%Y-%m-%dT%H:%M:%S%:z')"   # mtime = fin d'écriture par ExtractData.gvy
log "manifeste : taille=$SIZE lignes=$LINES colonnes=$COLUMNS genere_le=$GENERATED_AT — calcul SHA-256..."
SHA256="$(sha256sum "$SNAP" | cut -d' ' -f1)" || { log "ECHEC sha256sum"; exit 11; }

{
    echo "file=customers_list.csv"
    echo "size=$SIZE"
    echo "sha256=$SHA256"
    echo "lines=$LINES"
    echo "columns=$COLUMNS"
    echo "delimiter=#"
    echo "encoding=UTF-8"
    echo "header=$HEADER"
    echo "generated_at=$GENERATED_AT"
    echo "source_host=$(hostname)"
} > "$MAN" || { log "ECHEC écriture manifeste"; exit 11; }
log "sha256=$SHA256"

# 3. SFTP : envoi en .part, puis renommage. Le manifeste est renommé EN
#    DERNIER : sa présence signifie « livraison complète » pour Extractor.
#    Un transfert interrompu ne laisse que des .part, jamais consommés.
T0=$(date +%s)
log "début SFTP vers $EXTRACTOR_HOST"
sftp "${SSH_OPTS[@]}" -i "$PUSH_KEY" -b - "$PUSH_USER@$EXTRACTOR_HOST" <<EOF
cd $REMOTE_DIR
-rm customers_list.csv.part
-rm customers_list.manifest.part
put $SNAP customers_list.csv.part
put $MAN customers_list.manifest.part
-rm customers_list.csv
-rm customers_list.manifest
rename customers_list.csv.part customers_list.csv
rename customers_list.manifest.part customers_list.manifest
EOF
RC=$?
T1=$(date +%s)
if [ $RC -ne 0 ]; then
    log "ECHEC SFTP rc=$RC après $((T1 - T0)) s — le snapshot actif d'Extractor est conservé"
    rm -f "$SNAP" "$MAN"
    exit 20
fi
log "fin SFTP : $SIZE octets en $((T1 - T0)) s"

# 4. Déclenchement de la validation/activation. La clé INSTALL_KEY est
#    restreinte côté Extractor à UNE commande (snapshot:install) : rien
#    d'autre ne peut être exécuté avec elle.
OUT="$(ssh "${SSH_OPTS[@]}" -i "$INSTALL_KEY" "$INSTALL_USER@$EXTRACTOR_HOST" 2>&1)"
RC=$?
T2=$(date +%s)
log "extractor : $OUT"
rm -f "$SNAP" "$MAN"

case $RC in
    0)   log "SUCCES snapshot activé (validation $((T2 - T1)) s, total $((T2 - T0)) s)"; exit 0 ;;
    255) log "ECHEC déclenchement snapshot:install (SSH rc=255)"; exit 31 ;;
    *)   log "ECHEC Extractor a refusé le snapshot (rc=$RC) — snapshot précédent conservé"; exit 30 ;;
esac
