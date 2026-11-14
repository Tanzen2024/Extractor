/* =====================================================================
   TB_CUSTOMERS_LIST — colonnes 31 et 32 : montant facture 12 mois et VALUE
   (voir customers_list.sql, CTE billing_12m et colonne VALUE_SEGMENT, et
   docs/snapshot/README.md « 0 sexies »). A executer par le DBA sur le
   serveur source, AVANT de deployer la nouvelle version de
   customers_list.sql. Rien n'est execute par Extractor.

   Ordre des colonnes, identique dans le SELECT final de customers_list.sql,
   le CSV de ExtractData.gvy, le fichier de controle SQL*Loader et la table :
     ... 28 FAILURE_SUBCATEGORY, 29 PREPAID_RECENCY_DAYS,
         30 PREPAID_RECENCY_STATUS, 31 AMOUNT_BILLED_12M, 32 VALUE_SEGMENT
   Les colonnes 28-30 doivent exister avant (tb_customers_list_failure_
   subcategory.sql, tb_customers_list_prepaid_recency.sql).
   ===================================================================== */

/* ---------------------------------------------------------------------
   MONTEE DE VERSION
   AMOUNT_BILLED_12M : FCFA entiers (ROUND dans la requete), jamais NULL
   en sortie de customers_list.sql (0 sans facture retenue), signe permis
   (net negatif). NUMBER(15) : jusqu'a 999 999 milliards de FCFA.
   VALUE_SEGMENT : 'Top 20%' | 'Medium 50%' | 'Bottom 30%', NULL hors
   population classee.
   --------------------------------------------------------------------- */

ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST ADD (
    AMOUNT_BILLED_12M NUMBER(15),
    VALUE_SEGMENT     VARCHAR2(12)
);

/* Facultatif, garde-fou (meme regle que customers_list.sql). Si le
   chargement SQL*Loader est en DIRECT, il desactive les contraintes :
   ENABLE NOVALIDATE apres chargement. */
-- ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST ADD CONSTRAINT CK_TBCL_VALUE_SEGMENT CHECK (
--     VALUE_SEGMENT IS NULL OR (VALUE_SEGMENT IN ('Top 20%', 'Medium 50%', 'Bottom 30%') AND AMOUNT_BILLED_12M > 0)
-- );

/* Verification */
SELECT COLUMN_ID, COLUMN_NAME, DATA_TYPE, DATA_PRECISION, DATA_LENGTH, NULLABLE
FROM ALL_TAB_COLUMNS
WHERE OWNER = 'CMS_RFC'
  AND TABLE_NAME = 'TB_CUSTOMERS_LIST'
  AND COLUMN_NAME IN ('FAILURE_SUBCATEGORY', 'PREPAID_RECENCY_DAYS', 'PREPAID_RECENCY_STATUS', 'AMOUNT_BILLED_12M', 'VALUE_SEGMENT')
ORDER BY COLUMN_ID;

/* Droit de lecture requis par customers_list.sql (le compte qui l'execute,
   CMS_RFC, l'a deja : verifie le 13/11/2026 en lecture sur RECIBOS). */
-- GRANT SELECT ON CMSADMIN.RECIBOS TO CMS_RFC;

/* ---------------------------------------------------------------------
   FICHIER DE CONTROLE SQL*Loader : ajouter les deux champs A LA FIN de la
   liste, apres PREPAID_RECENCY_STATUS, dans le meme ordre que le CSV
   ('#', sans guillemets ; NULL = champ vide) :

       ...
       PREPAID_RECENCY_STATUS  CHAR(20),
       AMOUNT_BILLED_12M       INTEGER EXTERNAL NULLIF AMOUNT_BILLED_12M = BLANKS,
       VALUE_SEGMENT           CHAR(12)
     )

   TRAILING NULLCOLS obligatoire : VALUE_SEGMENT, dernier champ, est vide
   pour la plupart des lignes.
   --------------------------------------------------------------------- */

/* ---------------------------------------------------------------------
   RETOUR ARRIERE (apres avoir redeploye l'ancien customers_list.sql et
   l'ancien fichier de controle, sinon le chargement suivant echoue).
   Extractor fonctionne sans ces colonnes : tableau et exports les montrent
   vides, le filtre Value n'est pas propose.
   --------------------------------------------------------------------- */

-- ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST DROP CONSTRAINT CK_TBCL_VALUE_SEGMENT;
-- ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST DROP (AMOUNT_BILLED_12M, VALUE_SEGMENT);
