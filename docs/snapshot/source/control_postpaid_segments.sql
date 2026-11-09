/* Contrôles POSTPAID / COMPTEURS COMMUNICANTS en lecture seule, à lancer
   après un chargement de TB_CUSTOMERS_LIST par customers_list.sql. Aucune
   écriture. Périmètre : les 4 statuts actifs d'Extractor.

   Règle du 09/11/2026 : six profils exactement, aucun client non classé ;
   un client sans historique de facturation exploitable (dernière ligne de
   POSTPAID_PROFILE_P avec BILL_NUMBER = 0, ou aucune ligne) est classé en
   6 Défaillant / NEVER_PAID par convention. Les requêtes 1 à 5 doivent
   donner : somme des six profils = population, 0 non classé, 0 valeur hors
   nomenclature, Défaillant = Always Late + Never Paid + Sometime Paid. */

/* 1. Effectifs par profil et total de la population (ROLLUP) :
      aucune ligne '(NON CLASSE)' ne doit apparaître */
SELECT NVL(SEGMENTATION, '(NON CLASSE)') AS PROFIL, COUNT(*) AS CLIENTS
FROM CMS_RFC.TB_CUSTOMERS_LIST
WHERE METER IN ('POSTPAID', 'Compteurs Communicants')
  AND TRIM(STATUS) IN ('ACTIVE', 'ACTIVE (PENDING BILLING)', 'SUSPENDED (DELINQUENT ACCOUNT)', 'INACTIVATION IN PROCESS.')
GROUP BY ROLLUP (NVL(SEGMENTATION, '(NON CLASSE)'))
ORDER BY 1;

/* 2. Valeurs hors nomenclature (dont non classés) : doit ne retourner aucune ligne */
SELECT SEGMENTATION, COUNT(*) AS CLIENTS
FROM CMS_RFC.TB_CUSTOMERS_LIST
WHERE METER IN ('POSTPAID', 'Compteurs Communicants')
  AND (SEGMENTATION IS NULL OR SEGMENTATION NOT IN
       ('1 PERFECT', '2 RELIABLE', '3 Occasionnal', '4 At-Risk', '5 Delinquant', '6 Défaillant'))
GROUP BY SEGMENTATION;

/* 3. Concordance des deux diagrammes : une ligne par composante, puis le
      total Défaillant. TOTAL_COMPOSANTES doit égaler TOTAL_DEFAILLANT. */
SELECT
    SUM(CASE WHEN SEGMENTATION = '6 Défaillant' THEN 1 ELSE 0 END)                                       AS TOTAL_DEFAILLANT,
    SUM(CASE WHEN SEGMENTATION = '6 Défaillant' AND FAILURE_SUBCATEGORY = 'ALWAYS_LATE'   THEN 1 ELSE 0 END) AS ALWAYS_LATE,
    SUM(CASE WHEN SEGMENTATION = '6 Défaillant' AND FAILURE_SUBCATEGORY = 'NEVER_PAID'    THEN 1 ELSE 0 END) AS NEVER_PAID,
    SUM(CASE WHEN SEGMENTATION = '6 Défaillant' AND FAILURE_SUBCATEGORY = 'SOMETIME_PAID' THEN 1 ELSE 0 END) AS SOMETIME_PAID,
    SUM(CASE WHEN SEGMENTATION = '6 Défaillant' AND FAILURE_SUBCATEGORY IN ('ALWAYS_LATE', 'NEVER_PAID', 'SOMETIME_PAID') THEN 1 ELSE 0 END) AS TOTAL_COMPOSANTES
FROM CMS_RFC.TB_CUSTOMERS_LIST
WHERE METER IN ('POSTPAID', 'Compteurs Communicants')
  AND TRIM(STATUS) IN ('ACTIVE', 'ACTIVE (PENDING BILLING)', 'SUSPENDED (DELINQUENT ACCOUNT)', 'INACTIVATION IN PROCESS.');

/* 4. Composante incohérente : Défaillant sans composante (dont OTHER), ou
      composante hors Défaillant. Doit ne retourner aucune ligne. */
SELECT SEGMENTATION, FAILURE_SUBCATEGORY, COUNT(*) AS CLIENTS
FROM CMS_RFC.TB_CUSTOMERS_LIST
WHERE METER IN ('POSTPAID', 'Compteurs Communicants')
  AND (   (SEGMENTATION = '6 Défaillant'
           AND (FAILURE_SUBCATEGORY IS NULL OR FAILURE_SUBCATEGORY NOT IN ('ALWAYS_LATE', 'NEVER_PAID', 'SOMETIME_PAID')))
       OR (SEGMENTATION <> '6 Défaillant' AND FAILURE_SUBCATEGORY IS NOT NULL))
GROUP BY SEGMENTATION, FAILURE_SUBCATEGORY;

/* 5. Unicité : chaque contrat une seule fois, doit ne retourner aucune ligne */
SELECT CONTRACT, COUNT(*) AS LIGNES
FROM CMS_RFC.TB_CUSTOMERS_LIST
WHERE METER IN ('POSTPAID', 'Compteurs Communicants')
GROUP BY CONTRACT
HAVING COUNT(*) > 1;

/* 6. Traçabilité du dernier recours : origine de chaque client, d'après la
      dernière ligne de POSTPAID_PROFILE_P (même lecture que customers_list.sql).
      AUCUNE LIGNE et BILL_NUMBER = 0 sont les clients reclassés en
      6 Défaillant / NEVER_PAID ; PROFIL CALCULE garde son profil des seuils.
      Lancée AVANT le déploiement de la règle, la même requête donne les
      anciens non classés (SEGMENTATION vide ou '8 Autre') par origine ;
      si la colonne FAILURE_SUBCATEGORY n'existe pas encore, retirer la
      colonne COMPOSANTE (SELECT et GROUP BY). */
WITH last_row AS (
    SELECT SERVICE_NO, BILL_NUMBER
    FROM (
        SELECT SERVICE_NO, BILL_NUMBER,
               ROW_NUMBER() OVER (PARTITION BY SERVICE_NO ORDER BY DATE_ DESC NULLS LAST) AS RN
        FROM CMS_RFC.POSTPAID_PROFILE_P
    )
    WHERE RN = 1
)
SELECT
    CASE
        WHEN r.SERVICE_NO IS NULL THEN 'AUCUNE LIGNE'
        WHEN r.BILL_NUMBER = 0    THEN 'BILL_NUMBER = 0'
        ELSE 'PROFIL CALCULE'
    END                                   AS ORIGINE,
    NVL(t.SEGMENTATION, '(NON CLASSE)')   AS PROFIL,
    NVL(t.FAILURE_SUBCATEGORY, '-')       AS COMPOSANTE,
    COUNT(*)                              AS CLIENTS
FROM CMS_RFC.TB_CUSTOMERS_LIST t
LEFT JOIN last_row r ON r.SERVICE_NO = t.CONTRACT
WHERE t.METER IN ('POSTPAID', 'Compteurs Communicants')
  AND TRIM(t.STATUS) IN ('ACTIVE', 'ACTIVE (PENDING BILLING)', 'SUSPENDED (DELINQUENT ACCOUNT)', 'INACTIVATION IN PROCESS.')
GROUP BY
    CASE
        WHEN r.SERVICE_NO IS NULL THEN 'AUCUNE LIGNE'
        WHEN r.BILL_NUMBER = 0    THEN 'BILL_NUMBER = 0'
        ELSE 'PROFIL CALCULE'
    END,
    NVL(t.SEGMENTATION, '(NON CLASSE)'),
    NVL(t.FAILURE_SUBCATEGORY, '-')
ORDER BY 1, 2, 3;
