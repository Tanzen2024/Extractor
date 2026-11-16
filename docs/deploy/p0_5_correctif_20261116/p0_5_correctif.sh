#!/bin/bash
# P0-5 - Correctif minimal du chargement TB_CUSTOMERS_LIST (serveur 10.250.90.200, compte sdsa.user)
# Approuve par le responsable d'Extractor le 16/11/2026.
#
#   ./p0_5_correctif.sh              controles seulement, ne modifie rien
#   ./p0_5_correctif.sh --apply      applique (sauvegardes, verifications, auto-test)
#   ./p0_5_correctif.sh --rollback   restaure les fichiers sauvegardes par --apply
#
# Ce que fait --apply :
#   1. cree jobs/ExtractData_strict.sh, enveloppe d'ExtractData.gvy (non modifie). org.chigue.ExtractData
#      intercepte lui-meme l'erreur (5 tentatives) et rend toujours 0 : l'enveloppe rend 1 si le code
#      est != 0, si la sortie contient une erreur ou si le CSV est absent ou vide
#      (ExtractData.gvy n'est PAS modifie : les autres taches qui l'utilisent ne changent pas)
#   2. load_tb_customers_list.sh : utilise ExtractData_strict.sh, refuse le TRUNCATE si le CSV
#      manque ou est incomplet, corrige les guillemets des messages de fin
#   3. remet le couple qui a charge la table jusqu'au 14/11 : ancien SQL (pre32, 27 colonnes, ~25 min)
#      + ancien .ctl (pre32, 27 champs). Les versions 32 colonnes sont conservees
#      (customers_list.sql.v32_1852e0ff, control_tb_customers_list.ctl.v32_4dad75c4).
#      Retour au modele 27 colonnes (decision du 16/11) : la table est ramenee a 27 colonnes
#      par 03_revert_tb_customers_list_27.sql, a executer apres ce script.
# Ne touche ni au cron ni a la table. Ne lance aucun chargement.
set -u
R=${P05_ROOT:-/u01/COMMERCIALS_APPS/Nwameh}
EXPECTED_USER=${P05_USER:-sdsa.user}
J=$R/jobs
GVY=$J/ExtractData.gvy
STRICT=$J/ExtractData_strict.sh
LOAD=$J/load_tb_customers_list.sh
SQLD=$R/SqlQueries
SQL=$SQLD/customers_list.sql
CTL=$R/RECONCILLIATION_EneoPay-CMS/control_tb_customers_list.ctl
STATE=$R/p0_5_backup                      # sauvegardes + journal de ce correctif
SHA_SQL32=${P05_SHA_SQL32:-1852e0ff7c536de132e1b695f25cb46dbcc3d450d9eaf6957798ab42ca0b8b54}
SHA_SQLPRE=${P05_SHA_SQLPRE:-e175d654e86f61d8cef9113b28ca329c14d4fea8e893a5c98b4b5e366b6c49ea}
SHA_CTL=${P05_SHA_CTL:-4dad75c4a8dc79f29c2f8337b8a4739e716778534ac28c88c7da7f8905776b81}
MODE=${1:-check}
ERR=0
ok()   { echo "OK      $*"; }
ko()   { echo "ECHEC   $*"; ERR=1; }
info() { echo "INFO    $*"; }
die()  { echo "ARRET   $*"; exit 1; }
sha()  { sha256sum "$1" 2>/dev/null | awk '{print $1}'; }
mask() { sed -E 's#([A-Za-z0-9_$]+)/[^@[:space:]"]+@#\1/****@#g'; }

checks() {
  echo "== Controles prealables ($(date '+%Y-%m-%d %H:%M:%S'))"
  [ "$(id -un)" = "$EXPECTED_USER" ] && ok "compte $EXPECTED_USER" || ko "a lancer avec le compte $EXPECTED_USER (actuel : $(id -un))"
  if ! PSOUT=$(ps -eo args 2>/dev/null) || [ -z "$PSOUT" ]; then ko "liste des processus illisible (ps)"
  elif EN_COURS=$(echo "$PSOUT" | grep -E 'load_tb_customers_list|Nwameh/customers_list\.csv|control_tb_customers_list' | grep -vE 'grep|p0_5_correctif') && [ -n "$EN_COURS" ]; then
    ko "un chargement customers_list est en cours : attendre sa fin"; echo "$EN_COURS" | mask | cut -c1-200 | sed 's/^/        /'
  else ok "aucun chargement customers_list en cours (les autres taches ExtractData.gvy sont ignorees)"; fi
  for f in "$GVY" "$LOAD" "$SQL" "$SQL.pre32_20261114" "$CTL" "$CTL.pre32_20261114" "$J/truncate_proc.sh"; do
    [ -r "$f" ] && ok "present : $f" || ko "absent : $f"; done
  if [ "$MODE" != "--rollback" ]; then
    [ ! -e "$STRICT" ] && ok "ExtractData_strict.sh pas encore cree" || ko "$STRICT existe deja (correctif deja applique ?)"
    [ ! -e "$J/ExtractData_strict.gvy" ] && ok "pas de reste du premier essai (ExtractData_strict.gvy)" || ko "$J/ExtractData_strict.gvy existe : le supprimer d'abord"
    [ "$(sha "$SQL")" = "$SHA_SQL32" ] && ok "SQL actif = 32 colonnes (1852e0ff...)" || ko "SQL actif inattendu : $(sha "$SQL")"
    [ "$(sha "$SQL.pre32_20261114")" = "$SHA_SQLPRE" ] && ok "SQL pre32 = e175d654... (ancienne version de production)" || ko "SQL pre32 inattendu : $(sha "$SQL.pre32_20261114")"
    [ "$(sha "$CTL")" = "$SHA_CTL" ] && ok ".ctl = 4dad75c4... (32 champs)" || ko ".ctl inattendu : $(sha "$CTL")"
    N=$(grep -ciE '^[[:space:]]+[A-Z_0-9]+[[:space:]]+(CHAR|DATE|DECIMAL|INTEGER)' "$CTL.pre32_20261114")
    [ "$N" = 27 ] && ok ".ctl pre32 ($(stat -c %s "$CTL.pre32_20261114") octets) : 27 champs" || ko ".ctl pre32 : $N champs au lieu de 27"
    grep -qi 'customers_list.csv' "$CTL.pre32_20261114" && grep -qi 'TB_CUSTOMERS_LIST' "$CTL.pre32_20261114" && ok ".ctl pre32 : meme fichier et meme table" || ko ".ctl pre32 : INFILE ou table inattendus"
    grep -c 'ExtractData\.gvy ' "$LOAD" | grep -qx 1 && ok "load_tb_customers_list.sh appelle ExtractData.gvy une fois" || ko "appel d'ExtractData.gvy introuvable ou multiple dans $LOAD"
    grep -qE '^fi[[:space:]]*$' "$LOAD" && ok "bloc de controle de l'extraction trouve" || ko "ligne 'fi' du controle d'extraction introuvable"
    FREE=$(df -Pk "$R" | awk 'NR==2{print $4}'); [ "$FREE" -gt 3145728 ] && ok "espace libre $((FREE/1048576)) Go" || ko "moins de 3 Go libres sur $R"
  fi
  info "autres taches utilisant ExtractData.gvy (non modifiees) : $(grep -l 'ExtractData\.gvy' "$J"/*.sh 2>/dev/null | xargs -r -n1 basename | tr '\n' ' ')"
}

apply() {
  checks; [ "$ERR" -eq 0 ] || die "controles en echec : rien n'a ete modifie"
  TS=$(date +%Y%m%d_%H%M%S); B=$STATE/$TS; mkdir -p "$B" || die "impossible de creer $B"
  cp -p "$LOAD" "$B/load_tb_customers_list.sh" && cp -p "$SQL" "$B/customers_list.sql" && cp -p "$GVY" "$B/ExtractData.gvy" && cp -p "$CTL" "$B/control_tb_customers_list.ctl" || die "sauvegarde impossible"
  echo "$B" > "$STATE/LAST"; sha256sum "$B"/* > "$B/SHA256SUMS"
  ok "sauvegardes dans $B"

  # 1. ExtractData_strict.sh : enveloppe qui rend un vrai code d'erreur
  cat > "$STRICT" <<'STRICT_EOF'
#!/bin/bash
# Enveloppe P0-5 (16/11/2026) d'ExtractData.gvy, qui n'est pas modifie.
# org.chigue.ExtractData intercepte lui-meme les erreurs SQL (5 tentatives,
# "Erreur lors de l'extraction des donnees (Tentative Numero : N)") et le
# script rend toujours 0. Ici : echec (code 1) si le code est != 0, si la
# sortie contient une erreur, ou si le fichier de sortie est absent ou vide.
# Usage identique : ExtractData_strict.sh <fichier_sortie> <fichier_sql>
OUT=${1:-}
LOG=$(mktemp /tmp/extractdata_strict.XXXXXX) || { echo "ExtractData_strict : mktemp impossible"; exit 1; }
"$(dirname "$0")/ExtractData.gvy" "$@" > "$LOG" 2>&1
RC=$?
cat "$LOG"
if [ "$RC" -ne 0 ] \
   || grep -qE "Erreur lors de l'extraction|Error while running|ORA-[0-9]{5}|Exception" "$LOG" \
   || [ -z "$OUT" ] || [ ! -s "$OUT" ]; then
    echo "ExtractData_strict : ECHEC de l'extraction (code=$RC, fichier de sortie $( [ -n "$OUT" ] && [ -s "$OUT" ] && echo present || echo 'absent ou vide'))"
    rm -f "$LOG"
    exit 1
fi
rm -f "$LOG"
exit 0
STRICT_EOF
  chmod 755 "$STRICT"
  bash -n "$STRICT" && ok "ExtractData_strict.sh cree (enveloppe, ExtractData.gvy inchange)" || { rollback_files "$B"; die "creation de ExtractData_strict.sh ratee : restaure"; }
  echo "SELECT * FROM table_inexistante_p05_audit" > "$B/test_erreur.sql"
  "$STRICT" "$B/test_erreur.csv" "$B/test_erreur.sql" > "$B/test_erreur.log" 2>&1; RC=$?
  [ "$RC" -ne 0 ] && ok "auto-test : une requete en erreur rend le code $RC (avant : 0)" || { rollback_files "$B"; die "auto-test : code 0 malgre l'erreur ; restaure (voir $B/test_erreur.log)"; }
  echo "SELECT 1 AS X FROM dual" > "$B/test_ok.sql"
  "$STRICT" "$B/test_ok.csv" "$B/test_ok.sql" > "$B/test_ok.log" 2>&1; RC=$?
  [ "$RC" -eq 0 ] && [ -s "$B/test_ok.csv" ] && ok "auto-test : une requete correcte rend 0 et ecrit le fichier" || { rollback_files "$B"; die "auto-test requete correcte en echec (code $RC) ; restaure (voir $B/test_ok.log)"; }

  # 2. load_tb_customers_list.sh
  cat > "$B/garde.txt" <<'GARDE'

# --- Garde P0-5 (audit du 16/11/2026) : aucun TRUNCATE sans CSV complet ---
CSV=$REPORT_DIR/customers_list.csv
MIN_LIGNES=3000000
NB_LIGNES=$(wc -l 2>/dev/null < "$CSV" || echo 0)
NB_CHAMPS=$(head -1 "$CSV" 2>/dev/null | awk -F'#' '{print NF}')
NB_DERNIERE=$(tail -1 "$CSV" 2>/dev/null | awk -F'#' '{print NF}')
if [ "${NB_LIGNES:-0}" -lt "$MIN_LIGNES" ] || [ "${NB_CHAMPS:-0}" -lt 27 ] || [ "${NB_DERNIERE:-0}" -ne "${NB_CHAMPS:-0}" ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') - ARRET : CSV absent ou incomplet (lignes=${NB_LIGNES:-0}, champs en-tete=${NB_CHAMPS:-0}, champs derniere ligne=${NB_DERNIERE:-0}) : aucun TRUNCATE, table conservee" >> $Nohup_File 2>&1
    exit 1
fi
echo "$(date '+%Y-%m-%d %H:%M:%S') - CSV controle : $NB_LIGNES lignes, $NB_CHAMPS champs" >> $Nohup_File 2>&1
# --- fin garde P0-5 ---
GARDE
  sed -i -e 's#\$REPORT_DIR/jobs/ExtractData\.gvy #$REPORT_DIR/jobs/ExtractData_strict.sh #' \
         -e 's#supprimés >> \$Nohup_File#supprimés" >> $Nohup_File#' \
         -e 's#conservés >> \$Nohup_File#conservés" >> $Nohup_File#' \
         -e 's#^\(\s*echo "\.\.\. ERREUR gération customers_list\.csv \.\.\."\)\s*$#\1 >> $Nohup_File 2>\&1#' "$LOAD"
  sed -i "0,/^fi[[:space:]]*\$/{/^fi[[:space:]]*\$/r $B/garde.txt
}" "$LOAD"
  LG=$(grep -n 'Garde P0-5' "$LOAD" | cut -d: -f1); LT=$(grep -n 'truncate_proc.sh' "$LOAD" | head -1 | cut -d: -f1)
  V=0
  [ "$(grep -c 'ExtractData_strict\.sh' "$LOAD")" = 1 ] || V=1
  [ "$(grep -c 'Garde P0-5' "$LOAD")" = 1 ] && [ -n "$LT" ] && [ "$LG" -lt "$LT" ] || V=1
  [ "$(grep -c 'supprimés" >>' "$LOAD")" = 1 ] && [ "$(grep -c 'conservés" >>' "$LOAD")" = 1 ] || V=1
  bash -n "$LOAD" || V=1
  [ "$(grep '^sqlldr ' "$LOAD" | sha256sum)" = "$(grep '^sqlldr ' "$B/load_tb_customers_list.sh" | sha256sum)" ] || V=1
  [ "$V" -eq 0 ] && ok "load_tb_customers_list.sh modifie et verifie (garde ligne $LG, avant le TRUNCATE ligne $LT)" || { rollback_files "$B"; die "verification de load_tb_customers_list.sh en echec : restaure"; }

  # 3. SQL + .ctl : couple qui a fonctionne jusqu'au 14/11
  cp -p "$SQL" "$SQL.v32_1852e0ff" && cp -p "$SQL.pre32_20261114" "$SQL"
  [ "$(sha "$SQL")" = "$SHA_SQLPRE" ] && [ "$(sha "$SQL.v32_1852e0ff")" = "$SHA_SQL32" ] && ok "SQL actif = ancien SQL e175d654... ; SQL 32 colonnes conserve sous customers_list.sql.v32_1852e0ff" || { rollback_files "$B"; die "remplacement du SQL en echec : restaure"; }
  cp -p "$CTL" "$CTL.v32_4dad75c4" && cp -p "$CTL.pre32_20261114" "$CTL"
  [ "$(sha "$CTL")" = "$(sha "$CTL.pre32_20261114")" ] && [ "$(sha "$CTL.v32_4dad75c4")" = "$SHA_CTL" ] && ok ".ctl actif = ancien .ctl 27 champs ($(sha "$CTL" | cut -c1-8)...) ; .ctl 32 champs conserve sous control_tb_customers_list.ctl.v32_4dad75c4" || { rollback_files "$B"; die "remplacement du .ctl en echec : restaure"; }

  echo; echo "== Differences de load_tb_customers_list.sh (mot de passe masque)"
  diff -u "$B/load_tb_customers_list.sh" "$LOAD" | mask
  echo; echo "== ExtractData.gvy inchange : $(sha "$GVY" | cut -c1-12)... = sauvegarde $(sha "$B/ExtractData.gvy" | cut -c1-12)..."
  echo; echo "CORRECTIF APPLIQUE. Sauvegardes : $B   Retour arriere : $0 --rollback"
  echo "Aucun chargement n'a ete lance. Le prochain cron (05:00, heure du serveur) utilisera le correctif."
}

rollback_files() {
  B=$1
  cp -p "$B/load_tb_customers_list.sh" "$LOAD"; cp -p "$B/customers_list.sql" "$SQL"; cp -p "$B/control_tb_customers_list.ctl" "$CTL"; rm -f "$STRICT"
  [ "$(sha "$LOAD")" = "$(sha "$B/load_tb_customers_list.sh")" ] && [ "$(sha "$SQL")" = "$(sha "$B/customers_list.sql")" ] && [ "$(sha "$CTL")" = "$(sha "$B/control_tb_customers_list.ctl")" ] \
    && echo "RESTAURE  load_tb_customers_list.sh, customers_list.sql, control_tb_customers_list.ctl ; ExtractData_strict.sh supprime" \
    || echo "ATTENTION restauration incomplete : comparer avec $B"
}

case "$MODE" in
  check)      checks; echo; [ "$ERR" -eq 0 ] && echo "PRET : relancer avec --apply" || echo "NON PRET : corriger les ECHEC ci-dessus (rien n'a ete modifie)";;
  --apply)    apply;;
  --rollback) [ -r "$STATE/LAST" ] || die "aucune sauvegarde trouvee ($STATE/LAST)"; checks; rollback_files "$(cat "$STATE/LAST")";;
  *)          die "usage : $0 [--apply|--rollback]";;
esac
