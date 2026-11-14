#!/bin/bash
# =============================================================================
# dryrun_counts.sh - LECTURE SEULE - execute le SQL du kit SANS charger
#
#   ./dryrun_counts.sh CMS_RFC@cmsprod  > dryrun_$(date +%Y%m%d_%H%M).txt
#
# Emballe customers_list.sql dans un SELECT de comptage : aucune ecriture,
# aucun fichier CSV, aucune table touchee. Duree = celle de l'extraction
# complete (liens base a base) : ne PAS lancer pendant le chargement nocturne.
# =============================================================================
set -eu
KIT="$(cd "$(dirname "$0")" && pwd)"
CONNECT="${1:?usage: dryrun_counts.sh <user@tns>}"
SQL="$KIT/customers_list.sql"
ACTIVE="'ACTIVE','ACTIVE (PENDING BILLING)','SUSPENDED (DELINQUENT ACCOUNT)','INACTIVATION IN PROCESS.'"
if ps -ef | grep -E 'load_tb_customers|ExtractData|sqlldr' | grep -v grep; then
    echo "chargement en cours : abandon" >&2; exit 1
fi
CHECK=$(mktemp /tmp/dryrun_customers_list.XXXXXX.sql)
trap 'rm -f "$CHECK"' EXIT
{
    echo "SET LINESIZE 160 PAGESIZE 300 TIMING ON FEEDBACK OFF"
    echo "COLUMN UNIVERS FORMAT A24"
    echo "COLUMN SEGMENTATION FORMAT A26"
    echo "COLUMN COMPOSANTE FORMAT A14"
    echo "SELECT UNIVERS, SEGMENTATION, COMPOSANTE, COUNT(*) N, COUNT(DISTINCT CONTRACT) CONTRATS FROM ("
    echo "  SELECT METER UNIVERS, NVL(SEGMENTATION, '(NON CLASSE)') SEGMENTATION, NVL(FAILURE_SUBCATEGORY, '-') COMPOSANTE, CONTRACT FROM ("
    tr -d '\r' < "$SQL" | sed -e 's/;[[:space:]]*$//'
    echo "  ) WHERE TRIM(STATUS) IN ($ACTIVE)"
    echo ") GROUP BY ROLLUP (UNIVERS, SEGMENTATION, COMPOSANTE) ORDER BY 1, 2, 3;"
    echo "EXIT"
} > "$CHECK"
sqlplus -S "$CONNECT" @"$CHECK"
