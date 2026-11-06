#!/bin/bash
# Dry run of customers_list.sql: runs the query itself and prints counts only.
# Writes nothing to Oracle. To run before deploying a new version of the query.
#
#   ./validate_customers_list.sh customers_list.sql CMS_RFC@cmsprod
set -eu

SQL_FILE=${1:?usage: validate_customers_list.sh <customers_list.sql> <user@tns>}
CONNECT=${2:?usage: validate_customers_list.sh <customers_list.sql> <user@tns>}
ACTIVE="'ACTIVE','ACTIVE (PENDING BILLING)','SUSPENDED (DELINQUENT ACCOUNT)','INACTIVATION IN PROCESS.'"
CHECK=$(mktemp /tmp/validate_customers_list.XXXXXX.sql)
trap 'rm -f "$CHECK"' EXIT

{
    echo "SET LINESIZE 120 PAGESIZE 200 TIMING ON"
    echo "COLUMN UNIVERS FORMAT A10"
    echo "COLUMN SEGMENTATION FORMAT A22"
    echo "COLUMN FAILURE_SUBCATEGORY FORMAT A20"
    echo "SELECT CASE WHEN METER = 'PREPAID' THEN 'PREPAID' ELSE 'POSTPAID' END UNIVERS,"
    echo "       SEGMENTATION, FAILURE_SUBCATEGORY, COUNT(*) N"
    echo "FROM ("
    cat "$SQL_FILE"
    echo ")"
    echo "WHERE STATUS IN ($ACTIVE)"
    echo "GROUP BY CASE WHEN METER = 'PREPAID' THEN 'PREPAID' ELSE 'POSTPAID' END, SEGMENTATION, FAILURE_SUBCATEGORY"
    echo "ORDER BY 1, 2, 3;"
    echo "EXIT"
} > "$CHECK"

sqlplus -s "$CONNECT" @"$CHECK"
