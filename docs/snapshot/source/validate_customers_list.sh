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
    echo "COLUMN ANCIENNETE FORMAT A26"
    echo "COLUMN VALUE_SEGMENT FORMAT A12"
    # One pass of the query, three groupings: segments (+ Défaillant components),
    # PREPAID last-purchase recency classes (columns 29-30), VALUE (columns 31-32).
    echo "SELECT UNIVERS, SEGMENTATION, FAILURE_SUBCATEGORY, ANCIENNETE, VALUE_SEGMENT, COUNT(*) N, MIN(AMOUNT_BILLED_12M) MONTANT_MIN, MAX(AMOUNT_BILLED_12M) MONTANT_MAX"
    echo "FROM ("
    echo "  SELECT CASE WHEN METER = 'PREPAID' THEN 'PREPAID' ELSE 'POSTPAID' END UNIVERS,"
    echo "         SEGMENTATION, FAILURE_SUBCATEGORY, VALUE_SEGMENT, AMOUNT_BILLED_12M,"
    echo "         CASE WHEN METER <> 'PREPAID' THEN NULL"
    echo "              WHEN PREPAID_RECENCY_STATUS = 'KNOWN' AND PREPAID_RECENCY_DAYS BETWEEN 0 AND 29 THEN 'KNOWN 0-29'"
    echo "              WHEN PREPAID_RECENCY_STATUS = 'KNOWN' AND PREPAID_RECENCY_DAYS BETWEEN 30 AND 59 THEN 'KNOWN 30-59'"
    echo "              WHEN PREPAID_RECENCY_STATUS = 'KNOWN' AND PREPAID_RECENCY_DAYS BETWEEN 60 AND 89 THEN 'KNOWN 60-89'"
    echo "              WHEN PREPAID_RECENCY_STATUS = 'KNOWN' AND PREPAID_RECENCY_DAYS BETWEEN 90 AND 179 THEN 'KNOWN 90-179'"
    echo "              WHEN PREPAID_RECENCY_STATUS = 'KNOWN' AND PREPAID_RECENCY_DAYS BETWEEN 180 AND 359 THEN 'KNOWN 180-359'"
    echo "              WHEN PREPAID_RECENCY_STATUS = 'KNOWN' AND PREPAID_RECENCY_DAYS >= 360 THEN 'KNOWN 360+'"
    echo "              WHEN PREPAID_RECENCY_STATUS IN ('NO_PURCHASE', 'POST_REFERENCE', 'UNKNOWN') AND PREPAID_RECENCY_DAYS IS NULL THEN PREPAID_RECENCY_STATUS"
    echo "              ELSE 'INCOHERENT' END ANCIENNETE"
    echo "  FROM ("
    cat "$SQL_FILE"
    echo "  )"
    echo "  WHERE STATUS IN ($ACTIVE)"
    echo ")"
    echo "GROUP BY GROUPING SETS ((UNIVERS, SEGMENTATION, FAILURE_SUBCATEGORY), (UNIVERS, ANCIENNETE), (UNIVERS, VALUE_SEGMENT))"
    echo "ORDER BY 1, 4 NULLS FIRST, 5 NULLS FIRST, 2, 3;"
    echo "EXIT"
} > "$CHECK"

sqlplus -s "$CONNECT" @"$CHECK"
