-- =====================================================================
-- Retour au modele 27 colonnes : retire les colonnes 28 a 32 de
-- CMS_RFC.TB_CUSTOMERS_LIST (decision du 16/11/2026).
--
-- A executer APRES ./p0_5_correctif.sh --apply (SQL et .ctl 27 colonnes)
-- et AVANT de relancer le chargement, tant que la table est vide :
--   sqlplus CMS_RFC@CMSPROD @03_revert_tb_customers_list_27.sql
--
-- Garde-fous : ne fait rien si la table a deja 27 colonnes ; refuse si
-- elle n'a pas exactement les 32 colonnes attendues, si les colonnes
-- 28-32 contiennent la moindre valeur, ou si la table est verrouillee
-- (chargement en cours : ORA-00054, relancer plus tard).
-- Retour arriere : 01_alter_tb_customers_list.sql du kit v3 (re-ajoute
-- les 5 colonnes, idempotent).
-- =====================================================================
SET SERVEROUTPUT ON
WHENEVER SQLERROR EXIT FAILURE ROLLBACK
DECLARE
    n_cols   NUMBER;
    n_new    NUMBER;
    n_rows   NUMBER;
    n_filled NUMBER;
BEGIN
    SELECT COUNT(*) INTO n_cols
      FROM all_tab_columns
     WHERE owner = 'CMS_RFC' AND table_name = 'TB_CUSTOMERS_LIST';

    IF n_cols = 27 THEN
        DBMS_OUTPUT.PUT_LINE('DEJA FAIT : la table a 27 colonnes, rien a modifier');
        RETURN;
    END IF;
    IF n_cols <> 32 THEN
        RAISE_APPLICATION_ERROR(-20001, 'ARRET : ' || n_cols || ' colonnes (32 attendues)');
    END IF;

    SELECT COUNT(*) INTO n_new
      FROM all_tab_columns
     WHERE owner = 'CMS_RFC' AND table_name = 'TB_CUSTOMERS_LIST'
       AND column_id BETWEEN 28 AND 32
       AND column_name IN ('FAILURE_SUBCATEGORY', 'PREPAID_RECENCY_DAYS', 'PREPAID_RECENCY_STATUS',
                           'AMOUNT_BILLED_12M', 'VALUE_SEGMENT');
    IF n_new <> 5 THEN
        RAISE_APPLICATION_ERROR(-20002, 'ARRET : les colonnes 28-32 ne sont pas celles attendues');
    END IF;

    EXECUTE IMMEDIATE
        'SELECT COUNT(*), COUNT(FAILURE_SUBCATEGORY) + COUNT(PREPAID_RECENCY_DAYS) + COUNT(PREPAID_RECENCY_STATUS)'
        || ' + COUNT(AMOUNT_BILLED_12M) + COUNT(VALUE_SEGMENT) FROM CMS_RFC.TB_CUSTOMERS_LIST'
        INTO n_rows, n_filled;
    IF n_filled > 0 THEN
        RAISE_APPLICATION_ERROR(-20003, 'ARRET : les colonnes 28-32 contiennent ' || n_filled || ' valeur(s)');
    END IF;

    EXECUTE IMMEDIATE 'ALTER SESSION SET DDL_LOCK_TIMEOUT = 0';
    EXECUTE IMMEDIATE 'ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST DROP (FAILURE_SUBCATEGORY, PREPAID_RECENCY_DAYS,'
                      || ' PREPAID_RECENCY_STATUS, AMOUNT_BILLED_12M, VALUE_SEGMENT)';
    DBMS_OUTPUT.PUT_LINE('OK : colonnes 28-32 supprimees (' || n_rows || ' ligne(s) dans la table)');
END;
/
SELECT COUNT(*) AS NB_COLONNES, MAX(column_id) AS DERNIERE
  FROM all_tab_columns
 WHERE owner = 'CMS_RFC' AND table_name = 'TB_CUSTOMERS_LIST';
EXIT
