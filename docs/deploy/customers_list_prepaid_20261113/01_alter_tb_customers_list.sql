/* =============================================================================
   01_alter_tb_customers_list.sql - ETAPE 1 de la phase B (DBA, sur validation)

   Ajoute la colonne 28 FAILURE_SUBCATEGORY a CMS_RFC.TB_CUSTOMERS_LIST.
       sqlplus CMS_RFC@cmsprod @01_alter_tb_customers_list.sql

   - NULL autorise : la colonne est NULL pour tout client hors Defaillant.
   - Valeurs ecrites par customers_list.sql : ALWAYS_LATE, NEVER_PAID,
     SOMETIME_PAID (ASCII, 13 caracteres au plus).
   - Ajout en fin de table : les colonnes 1 a 27 ne bougent pas. Operation
     de dictionnaire (pas de reecriture des lignes existantes).
   - A faire HORS de la fenetre de chargement (02:45-05:45 : generateurs 03:00 et 04:00, load_tb_customers_list.sh 05:00) et AVANT
     l'etape 2 (.ctl) : un .ctl a 28 champs sur une table a 27 colonnes
     ferait echouer SQL*Loader.
   - Effet de bord : jusqu'au chargement suivant, la colonne existe mais est
     vide. Ne pas lancer « php spark customers:refresh » entre cette etape et
     le chargement : Extractor importerait une colonne vide (graphique
     Defaillant entierement « non decompose ») jusqu'a la synchro suivante.
   ============================================================================= */

WHENEVER SQLERROR EXIT FAILURE
SET SERVEROUTPUT ON FEEDBACK ON

/* Garde-fou : la table doit avoir exactement 27 colonnes, sans la 28e */
DECLARE
    n_cols  NUMBER;
    n_fs    NUMBER;
BEGIN
    SELECT COUNT(*), SUM(CASE WHEN COLUMN_NAME = 'FAILURE_SUBCATEGORY' THEN 1 ELSE 0 END)
      INTO n_cols, n_fs
      FROM ALL_TAB_COLUMNS
     WHERE OWNER = 'CMS_RFC' AND TABLE_NAME = 'TB_CUSTOMERS_LIST';
    IF n_fs > 0 THEN
        RAISE_APPLICATION_ERROR(-20001, 'FAILURE_SUBCATEGORY existe deja : rien a faire');
    END IF;
    IF n_cols <> 27 THEN
        RAISE_APPLICATION_ERROR(-20002, 'TB_CUSTOMERS_LIST a ' || n_cols || ' colonnes (27 attendues) : arret');
    END IF;
    DBMS_OUTPUT.PUT_LINE('Controle OK : 27 colonnes, FAILURE_SUBCATEGORY absente');
END;
/

ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST ADD (
    FAILURE_SUBCATEGORY VARCHAR2(20)
);

/* Verification : COLUMN_ID 28 attendu */
SELECT COLUMN_ID, COLUMN_NAME, DATA_TYPE, DATA_LENGTH, NULLABLE
FROM ALL_TAB_COLUMNS
WHERE OWNER = 'CMS_RFC'
  AND TABLE_NAME = 'TB_CUSTOMERS_LIST'
  AND COLUMN_NAME = 'FAILURE_SUBCATEGORY';

EXIT

/* -----------------------------------------------------------------------------
   RETOUR ARRIERE (a executer a la main, seulement apres avoir restaure le .ctl
   a 27 champs, sinon le chargement suivant echoue) :

       ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST DROP COLUMN FAILURE_SUBCATEGORY;

   Les donnees de la colonne sont perdues (elles sont recalculees a chaque
   chargement) ; les colonnes 1 a 27 ne sont pas touchees.
   ----------------------------------------------------------------------------- */
