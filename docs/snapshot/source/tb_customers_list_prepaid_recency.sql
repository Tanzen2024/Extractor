/* =====================================================================
   TB_CUSTOMERS_LIST — colonnes 29 et 30 : ancienneté du dernier achat PREPAID
   (attributs d'analyse, voir customers_list.sql et docs/snapshot/README.md
   « 0 quinquies »). A executer par le DBA sur le serveur source, AVANT de
   deployer la nouvelle version de customers_list.sql. Rien n'est execute
   par Extractor.

   Ordre des colonnes : identique dans customers_list.sql (SELECT final),
   le CSV de ExtractData.gvy, le fichier de controle SQL*Loader et la table :
     ... 27 UPDATED_AT, 28 FAILURE_SUBCATEGORY,
         29 PREPAID_RECENCY_DAYS, 30 PREPAID_RECENCY_STATUS
   ===================================================================== */

/* ---------------------------------------------------------------------
   MONTEE DE VERSION
   NULL autorise : les deux colonnes sont NULL pour tout client non PREPAID,
   et PREPAID_RECENCY_DAYS est NULL des que le statut n'est pas KNOWN.
   Aucune valeur sentinelle (-1, 99999) n'est jamais ecrite.
   --------------------------------------------------------------------- */

ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST ADD (
    PREPAID_RECENCY_DAYS   NUMBER(10),
    PREPAID_RECENCY_STATUS VARCHAR2(20)
);

/* Facultatif, garde-fou de coherence (meme regle que customers_list.sql) :
   jours renseignes si et seulement si KNOWN, jamais negatifs.
   A creer seulement si le chargement direct SQL*Loader de la table ne
   desactive pas les contraintes (sinon ENABLE NOVALIDATE apres chargement). */
-- ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST ADD CONSTRAINT CK_TBCL_PREPAID_RECENCY CHECK (
--     (PREPAID_RECENCY_STATUS IS NULL AND PREPAID_RECENCY_DAYS IS NULL)
--  OR (PREPAID_RECENCY_STATUS = 'KNOWN' AND PREPAID_RECENCY_DAYS >= 0)
--  OR (PREPAID_RECENCY_STATUS IN ('NO_PURCHASE', 'POST_REFERENCE', 'UNKNOWN') AND PREPAID_RECENCY_DAYS IS NULL)
-- );

/* Verification */
SELECT COLUMN_ID, COLUMN_NAME, DATA_TYPE, DATA_PRECISION, DATA_LENGTH, NULLABLE
FROM ALL_TAB_COLUMNS
WHERE OWNER = 'CMS_RFC'
  AND TABLE_NAME = 'TB_CUSTOMERS_LIST'
  AND COLUMN_NAME IN ('FAILURE_SUBCATEGORY', 'PREPAID_RECENCY_DAYS', 'PREPAID_RECENCY_STATUS')
ORDER BY COLUMN_ID;

/* ---------------------------------------------------------------------
   FICHIER DE CONTROLE SQL*Loader : ajouter les deux champs A LA FIN de la
   liste, apres FAILURE_SUBCATEGORY, dans le meme ordre que le CSV. Le CSV
   est separe par '#', sans guillemets ; une valeur NULL y est un champ vide.

       ...
       FAILURE_SUBCATEGORY     CHAR(20),
       PREPAID_RECENCY_DAYS    INTEGER EXTERNAL NULLIF PREPAID_RECENCY_DAYS = BLANKS,
       PREPAID_RECENCY_STATUS  CHAR(20)
     )

   Si le fichier ne contient pas deja TRAILING NULLCOLS, l'ajouter apres
   FIELDS TERMINATED BY '#' : un champ final vide doit donner NULL, pas
   un rejet de ligne.
   --------------------------------------------------------------------- */

/* ---------------------------------------------------------------------
   RETOUR ARRIERE (uniquement apres avoir redeploye l'ancienne version de
   customers_list.sql et du fichier de controle : sinon le chargement
   suivant echoue). Extractor continue de fonctionner sans ces colonnes :
   le graphique affiche « Donnee non disponible ».
   --------------------------------------------------------------------- */

-- ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST DROP CONSTRAINT CK_TBCL_PREPAID_RECENCY;
-- ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST DROP (PREPAID_RECENCY_DAYS, PREPAID_RECENCY_STATUS);
