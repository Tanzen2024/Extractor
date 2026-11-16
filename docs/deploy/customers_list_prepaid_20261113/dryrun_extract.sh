#!/bin/bash
# =============================================================================
# dryrun_extract.sh - ESSAI A BLANC du SQL du kit v3, SANS chargement
#
#   ./dryrun_extract.sh                 (compte sdsa.user, sur le serveur source)
#   OUT=/chemin/dryrun ./dryrun_extract.sh
#
# Lance ExtractData.gvy - exactement comme load_tb_customers_list.sh - avec le
# SQL du kit, mais vers un CSV d'essai dans $OUT (jamais vers
# $REPORT_DIR/customers_list.csv). Ni truncate, ni sqlldr, ni table touchee.
# Mesure la duree (le chargement nocturne echoue au-dela de 5 400 s :
# query.time.out d'ExtractData.gvy), puis controle le CSV (check_compat.sh) et
# compte les valeurs sur les 4 statuts actifs.
#
# Lecture seule cote Oracle, mais charge comparable au chargement nocturne :
# a lancer en journee creuse, jamais entre 01:00 et 05:45.
# Le CSV contient des donnees personnelles : le supprimer apres controle
# (rm -rf "$OUT").
# =============================================================================
set -euo pipefail
KIT="$(cd "$(dirname "$0")" && pwd)"
REPORT_DIR="${REPORT_DIR:-/u01/COMMERCIALS_APPS/Nwameh}"
GVY="$REPORT_DIR/jobs/ExtractData.gvy"
OUT="${OUT:-$REPORT_DIR/dryrun_v3_$(date +%Y%m%d_%H%M%S)}"
CSV="$OUT/customers_list.csv"
TIMEOUT_S=5400
NEED_KB=$((4 * 1024 * 1024))     # 4 Go libres exiges

log() { echo "$(date '+%F %T') [dryrun_extract] $*"; }
die() { log "ARRET : $*"; exit 1; }

[ -x "$GVY" ] || [ -r "$GVY" ] || die "ExtractData.gvy introuvable : $GVY"
[ -f "$KIT/customers_list.sql" ] || die "SQL du kit absent"
[ "$(cd "$(dirname "$CSV")" 2>/dev/null && pwd || echo "$OUT")" != "$REPORT_DIR" ] || die "OUT ne doit pas etre REPORT_DIR (fichier de production)"
case "$CSV" in "$REPORT_DIR/customers_list.csv") die "CSV d'essai = CSV de production : refuse" ;; esac
if ps -ef | grep -E 'load_tb_customers|ExtractData|sqlldr' | grep -v grep; then die "extraction ou chargement en cours"; fi
h=$(date +%H%M)
if [ "$h" -ge 0100 ] && [ "$h" -le 0545 ]; then die "plage 01:00-05:45 : l'essai chevaucherait la chaine nocturne"; fi

mkdir -p "$OUT"
FREE=$(df -Pk "$OUT" | awk 'NR==2 {print $4}')
[ "$FREE" -ge "$NEED_KB" ] || { rmdir "$OUT" 2>/dev/null || true; die "espace libre insuffisant dans $(dirname "$OUT") : $((FREE/1024)) Mo (4 Go exiges)"; }
sha256sum "$KIT/customers_list.sql" > "$OUT/sql.sha256"

log "debut : $GVY -> $CSV"
T0=$(date +%s)
set +e
"$GVY" "$CSV" "$KIT/customers_list.sql" > "$OUT/extract.log" 2>&1
RC=$?
set -e
T1=$(date +%s); DUR=$((T1 - T0))
log "fin : code retour $RC, duree ${DUR} s ($((DUR / 60)) min) - log : $OUT/extract.log"
[ "$RC" -eq 0 ] || { tail -20 "$OUT/extract.log"; die "ExtractData en echec : le chargement nocturne s'arreterait de la meme facon (table conservee)"; }
[ -s "$CSV" ] || die "CSV vide"
if [ "$DUR" -ge $((TIMEOUT_S * 2 / 3)) ]; then
    log "ALERTE duree ${DUR} s >= 2/3 du delai de ${TIMEOUT_S} s : marge insuffisante pour la nuit, NE PAS DEPLOYER en l'etat"
else
    log "duree OK : ${DUR} s < 2/3 de ${TIMEOUT_S} s"
fi
log "CSV : $(wc -l < "$CSV") lignes (en-tete comprise), $(du -h "$CSV" | cut -f1)"

echo; log "== check_compat sur le CSV d'essai"
CSV="$CSV" REPORT_DIR="$REPORT_DIR" bash "$KIT/check_compat.sh" || log "check_compat : echec(s) (voir ci-dessus)"

echo; log "== Comptages sur les 4 statuts actifs (a comparer a control_load.sql apres chargement)"
awk -F'#' '
    function v(name,  x) { x = $(col[name]); gsub(/["\047]/, "", x); sub(/[ \t\r]+$/, "", x); return x }
    NR == 1 { for (i = 1; i <= NF; i++) { h = $i; gsub(/["\047\r]/, "", h); col[h] = i }; next }
    {
        st = v("STATUS")
        if (st != "ACTIVE" && st != "ACTIVE (PENDING BILLING)" && st != "SUSPENDED (DELINQUENT ACCOUNT)" && st != "INACTIVATION IN PROCESS.") next
        m = v("METER"); s = v("SEGMENTATION"); if (s == "") s = "(NON CLASSE)"
        seg[m " | " s]++; tot[m]++
        if (m != "PREPAID") { f = v("FAILURE_SUBCATEGORY"); if (f != "" || s ~ /^6 D/) fs[s " | " (f == "" ? "<vide>" : f)]++ }
        else if (v("FAILURE_SUBCATEGORY") != "") fsbad++
        if (m == "PREPAID") {
            r = v("PREPAID_RECENCY_STATUS"); d = v("PREPAID_RECENCY_DAYS")
            if (r == "KNOWN") b = (d < 30 ? "0-29" : d < 60 ? "30-59" : d < 90 ? "60-89" : d < 180 ? "90-179" : d < 360 ? "180-359" : "360+")
            else b = (r == "" ? "<vide>" : r)
            rec[b]++
        } else if (v("PREPAID_RECENCY_STATUS") != "") recbad++
        if (m != "PREPAID") { a = v("AMOUNT_BILLED_12M"); vs = v("VALUE_SEGMENT"); val[(vs == "" ? (a + 0 > 0 ? "<vide, montant > 0 : ANOMALIE>" : "<vide, montant 0>") : vs)]++ }
        else if (v("VALUE_SEGMENT") != "") valbad++
    }
    END {
        print "-- univers x segmentation"; for (k in seg) printf "   %-50s %9d\n", k, seg[k] | "sort"; close("sort")
        print "-- total par univers";       for (k in tot) printf "   %-50s %9d\n", k, tot[k] | "sort"; close("sort")
        print "-- Defaillant x composante (non PREPAID)"; for (k in fs) printf "   %-50s %9d\n", k, fs[k] | "sort"; close("sort")
        print "-- PREPAID : anciennete du dernier achat"; for (k in rec) printf "   %-50s %9d\n", k, rec[k] | "sort"; close("sort")
        print "-- non PREPAID : VALUE_SEGMENT"; for (k in val) printf "   %-50s %9d\n", k, val[k] | "sort"; close("sort")
        printf "-- anomalies : composante sur PREPAID %d, anciennete hors PREPAID %d, VALUE sur PREPAID %d (0 attendu)\n", fsbad, recbad, valbad
    }' "$CSV" | tee "$OUT/summary.txt"

echo
log "termine. Envoyer : sortie de ce script (ou $OUT/summary.txt + $OUT/extract.log)."
log "puis SUPPRIMER le CSV d'essai (donnees personnelles) : rm -rf $OUT"
