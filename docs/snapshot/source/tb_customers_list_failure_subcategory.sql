/* =====================================================================
   TB_CUSTOMERS_LIST — colonne 28 : FAILURE_SUBCATEGORY
   (decomposition du profil 6 Défaillant en Always Late / Never Paid /
   Sometime Paid ; regle dans customers_list.sql, docs/snapshot/README.md
   « Decomposition du profil Defaillant »). A executer par le DBA sur le
   serveur source, AVANT tb_customers_list_prepaid_recency.sql et AVANT de
   deployer la nouvelle version de customers_list.sql. Rien n'est execute
   par Extractor.

   Constat du 13/11/2026 : la table s'arrete a la colonne 27 (UPDATED_AT) ;
   cette colonne n'existe pas encore, d'ou les 366 864 clients Defaillant
   « non decomposes » affiches par Extractor.

   Ordre des colonnes : identique dans customers_list.sql (SELECT final),
   le CSV de ExtractData.gvy, le fichier de controle SQL*Loader et la table :
     ... 27 UPDATED_AT, 28 FAILURE_SUBCATEGORY,
         29 PREPAID_RECENCY_DAYS, 30 PREPAID_RECENCY_STATUS
   Executer CE script en premier : la colonne 28 doit preceder les 29-30.
   ===================================================================== */

/* ---------------------------------------------------------------------
   MONTEE DE VERSION
   NULL autorise : la colonne est NULL pour tout client hors 6 Défaillant
   (PREPAID compris). Valeurs ecrites par customers_list.sql : ALWAYS_LATE,
   NEVER_PAID, SOMETIME_PAID (ASCII, 13 caracteres au plus).
   --------------------------------------------------------------------- */

ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST ADD (
    FAILURE_SUBCATEGORY VARCHAR2(20)
);

/* Facultatif, garde-fou de nomenclature. A creer seulement si le chargement
   direct SQL*Loader ne desactive pas les contraintes (sinon ENABLE
   NOVALIDATE apres chargement). La coherence avec SEGMENTATION (composante
   si et seulement si 6 Défaillant) est verifiee par
   control_postpaid_segments.sql, requete 4, pas par une contrainte : le
   libelle accentue rendrait la contrainte dependante de l'encodage. */
-- ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST ADD CONSTRAINT CK_TBCL_FAILURE_SUBCATEGORY CHECK (
--     FAILURE_SUBCATEGORY IS NULL
--  OR FAILURE_SUBCATEGORY IN ('ALWAYS_LATE', 'NEVER_PAID', 'SOMETIME_PAID')
-- );

/* Verification : une ligne, COLUMN_ID 28 si execute avant le script des
   colonnes 29-30 */
SELECT COLUMN_ID, COLUMN_NAME, DATA_TYPE, DATA_LENGTH, NULLABLE
FROM ALL_TAB_COLUMNS
WHERE OWNER = 'CMS_RFC'
  AND TABLE_NAME = 'TB_CUSTOMERS_LIST'
  AND COLUMN_NAME = 'FAILURE_SUBCATEGORY';

/* ---------------------------------------------------------------------
   FICHIER DE CONTROLE SQL*Loader : ajouter le champ apres UPDATED_AT,
   dans le meme ordre que le CSV (separateur '#', sans guillemets ; une
   valeur NULL y est un champ vide). Avec les colonnes 29-30 :

       ...
       UPDATED_AT              ...,          -- inchange
       FAILURE_SUBCATEGORY     CHAR(20),
       PREPAID_RECENCY_DAYS    INTEGER EXTERNAL NULLIF PREPAID_RECENCY_DAYS = BLANKS,
       PREPAID_RECENCY_STATUS  CHAR(20)
     )

   TRAILING NULLCOLS obligatoire (apres FIELDS TERMINATED BY '#') : la
   plupart des lignes ont des champs finaux vides, qui doivent donner NULL,
   pas un rejet.
   --------------------------------------------------------------------- */

/* ---------------------------------------------------------------------
   RETOUR ARRIERE (uniquement apres avoir redeploye l'ancien
   customers_list.sql et l'ancien fichier de controle : sinon le
   chargement suivant echoue). Extractor fonctionne sans la colonne : il
   revient a l'affichage actuel (clients non decomposes signales sous le
   graphique).
   --------------------------------------------------------------------- */

-- ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST DROP CONSTRAINT CK_TBCL_FAILURE_SUBCATEGORY;
-- ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST DROP (FAILURE_SUBCATEGORY);
