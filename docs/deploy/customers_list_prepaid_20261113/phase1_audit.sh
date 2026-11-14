#!/bin/bash
# =============================================================================
# phase1_audit.sh - LECTURE SEULE - serveur source (10.250.90.200 attendu)
# REPORT_DIR par defaut : /u01/COMMERCIALS_APPS/Nwameh
#
# A lancer avec le compte qui execute le chargement de TB_CUSTOMERS_LIST :
#   bash phase1_audit.sh > phase1_audit_$(hostname)_$(date +%Y%m%d_%H%M).txt 2>&1
#
# N'ecrit rien hors de sa sortie standard. Ne lance aucun chargement.
# =============================================================================
set -u
REPORT_DIR="${REPORT_DIR:-/u01/COMMERCIALS_APPS/Nwameh}"
EXPECTED_OLD_SHA=5f18d89e077b8ab72a68096a3a0eec5ce7c5c84ee78ce1b8a4633009d7c90431   # 574d3dd (01/10)

h() { printf '\n===== %s =====\n' "$*"; }

h "1. Serveur";   hostname; hostname -I 2>/dev/null; id; date; timedatectl 2>/dev/null | grep -i 'time zone'

h "8. Ordonnanceur (crontab du compte courant + systeme)"
crontab -l 2>/dev/null | grep -v '^\s*#' | grep -n . || echo "(crontab vide pour $(id -un))"
grep -rsn -e customers_list -e load_tb_customers /etc/crontab /etc/cron.d/ 2>/dev/null

h "8. Script de chargement"
LOADER=$(crontab -l 2>/dev/null | grep -o '[^ ]*load_tb_customers_list[^ ]*' | head -1)
[ -z "$LOADER" ] && LOADER=$(find / -xdev -name 'load_tb_customers_list*.sh' 2>/dev/null | head -1)
echo "LOADER=$LOADER"
[ -n "$LOADER" ] && [ -r "$LOADER" ] && { ls -l --time-style=full-iso "$LOADER"; echo '--- contenu ---'; cat -n "$LOADER"; }

h "2. REPORT_DIR (environnement courant + definition dans le script)"
echo "env: REPORT_DIR=${REPORT_DIR:-<non defini dans ce shell>}"
[ -n "$LOADER" ] && grep -n 'REPORT_DIR=' "$LOADER" 2>/dev/null
grep -sn 'REPORT_DIR=' ~/.bash_profile ~/.profile ~/.bashrc 2>/dev/null

h "3-5. Fichier execute"
for f in "${REPORT_DIR:-/nonexistent}/SqlQueries/customers_list.sql"; do
    [ -e "$f" ] || { echo "ABSENT : $f (definir REPORT_DIR puis relancer)"; continue; }
    ls -l --time-style=full-iso "$f"; stat -c 'proprietaire=%U:%G mode=%a taille=%s inode=%i' "$f"
    file "$f"
    SHA=$(sha256sum "$f" | cut -d' ' -f1); echo "sha256=$SHA"
    [ "$SHA" = "$EXPECTED_OLD_SHA" ] && echo "=> IDENTIQUE a la version 01/10 (574d3dd)" \
                                     || echo "=> DIFFERENT de 574d3dd : NE PAS DEPLOYER, envoyer ce fichier pour comparaison"
    grep -c $'\r' "$f" | sed 's/^/lignes CRLF: /'
    grep -n "Old_Dormant\|Never Vending\|8 Autre\|RESULTAT FINAL" "$f"
done

h "Autres customers_list.sql presents (concurrents eventuels)"
find / -xdev -name 'customers_list*.sql' -printf '%TY-%Tm-%Td %TH:%TM %u:%g %m %s %p\n' 2>/dev/null

h "10. Dependances : qui reference customers_list.sql / customers_list.csv"
[ -n "${REPORT_DIR:-}" ] && grep -rsln -e 'customers_list' "$REPORT_DIR" --include='*.sh' --include='*.gvy' --include='*.ctl' --include='*.conf' 2>/dev/null

h "Execution SQL : ExtractData.gvy (driver, encodage)"
[ -n "${REPORT_DIR:-}" ] && GVY="$REPORT_DIR/jobs/ExtractData.gvy" && [ -r "$GVY" ] && \
    grep -n -i -e 'jdbc' -e 'charset' -e 'encoding' -e 'readLines\|getText\|\.text' -e 'NLS' -e 'System.exit' "$GVY"
echo "NLS_LANG=${NLS_LANG:-<non defini>}  LANG=${LANG:-}  LC_ALL=${LC_ALL:-}"
java -XshowSettings:properties -version 2>&1 | grep -i 'file.encoding'

h "Fichier de controle SQL*Loader (attendu : RECONCIL*IATION_EneoPay-CMS/control_tb_customers_list.ctl)"
ls -l --time-style=full-iso "$REPORT_DIR"/RECONCIL*IATION_EneoPay-CMS/control_tb_customers_list.ctl 2>/dev/null
[ -n "${REPORT_DIR:-}" ] && find "$REPORT_DIR" "$HOME" -xdev -name '*.ctl' 2>/dev/null | xargs -r grep -l 'TB_CUSTOMERS_LIST' 2>/dev/null | sort -u | while read -r c; do
    echo "--- $c"; ls -l "$c"; grep -n -i 'CHARACTERSET\|INFILE\|TERMINATED\|TRAILING' "$c"
done

h "Truncate + SQL*Loader appeles par le script de chargement"
[ -n "$LOADER" ] && grep -n -i 'sqlldr\|truncate\|control=\|\.ctl\|RECONCIL' "$LOADER"

h "Chargement en cours ?"
ps -ef | grep -E 'load_tb_customers|ExtractData|sqlldr' | grep -v grep || echo "aucun"

h "Derniers journaux (si nohup)"
[ -n "$LOADER" ] && grep -o 'Nohup_File=[^ ]*' "$LOADER" | head -1
echo "FIN audit $(date)"
