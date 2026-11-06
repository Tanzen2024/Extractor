WITH lister AS (
    SELECT /*+ PARALLEL(8) */

        /* ============================================================
           INFORMATIONS CLIENT
           ============================================================ */

        tt.NOM_AREA                         AS REGION,
        tt.NOM_ZONA                         AS DIVISION,
        tt.NOM_UNICOM                       AS AGENCE,

        tt.CUST_NAME,
        tt.E_MAIL,
        tt.PHONE_NUMBERS,

        tt.COD_UNICOM,

        tt.NIS_RAD                          AS CONTRACT,

        tt.ACC_FINCA,
        tt.F_BAJA_SERV,

        tt.METER_NO,

        estado(tt.EST_SERV)                 AS STATUS,

        tt.F_ALTA,

        tt.VOLT_TP_ID                       AS VOLTAGE,

        rdd.SEGMENT_TRESOR,

        tt.REF_BRANCHEMENT,

        ss.NUM_FISCAL,
        ss.COD_CLI,

        ci.TIP_DOC,

        /* ============================================================
           NIU ACTUEL
           
           Priorité :
           1. DOC_ID de l'identifiant sélectionné
           2. NUM_FISCAL du client
           
           uniquement si le format est valide.
           ============================================================ */

        CASE
            WHEN REGEXP_LIKE(
                UPPER(TRIM(ci.DOC_ID)),
                '^(P|M)[0-9]{12}[A-Z]$'
            )
            THEN UPPER(TRIM(ci.DOC_ID))

            WHEN REGEXP_LIKE(
                UPPER(TRIM(ss.NUM_FISCAL)),
                '^(P|M)[0-9]{12}[A-Z]$'
            )
            THEN UPPER(TRIM(ss.NUM_FISCAL))

            ELSE NULL
        END AS NIU_RIGHT

    FROM cmsreport.tb_customers_infos tt

    /* ============================================================
       CLIENT
       ============================================================ */

    LEFT JOIN clientes ss
        ON ss.COD_CLI = tt.COD_CLI

    /* ============================================================
       IDENTIFIANT CLIENT
       
       Maximum un identifiant par COD_CLI.
       
       Priorité :
       1. SEC_ID DESC
       2. NIU au format correct
       3. F_ACTUAL DESC
       4. DOC_ID
       ============================================================ */

    LEFT JOIN (
        SELECT
            cod_cli,
            doc_id,
            tip_doc,
            sec_id,
            f_actual,

            ROW_NUMBER() OVER (
                PARTITION BY cod_cli
                ORDER BY
                    sec_id DESC,

                    CASE
                        WHEN REGEXP_LIKE(
                            UPPER(TRIM(doc_id)),
                            '^(P|M)[0-9]{12}[A-Z]$'
                        )
                        THEN 0
                        ELSE 1
                    END,

                    f_actual DESC NULLS LAST,

                    doc_id
            ) AS ord

        FROM cliente_identificador

        WHERE LENGTH(doc_id) = 14

          AND REGEXP_LIKE(
              doc_id,
              '^(P|M|m|p)[0-9]{12}\w{1}$'
          )
    ) ci
        ON ci.COD_CLI = tt.COD_CLI
       AND ci.ord = 1

    /* ============================================================
       SEGMENT TRESOR
       
       Dernier reporting disponible par SERVICE_NO.
       ============================================================ */

    LEFT JOIN (
        SELECT
            SERVICE_NO,
            SEGMENT_TRESOR,
            REPORTING_MONTH
        FROM (
            SELECT
                SERVICE_NO,
                SEGMENT_TRESOR,
                REPORTING_MONTH,

                ROW_NUMBER() OVER (
                    PARTITION BY SERVICE_NO
                    ORDER BY REPORTING_MONTH DESC
                ) AS RN

            FROM CMSREPORT.RDD_SEGMENT
        )
        WHERE RN = 1
    ) rdd
        ON rdd.SERVICE_NO = tt.NIS_RAD
),

/* ================================================================
   COMPTEURS PREPAID
   ================================================================= */

prep AS (
    SELECT /*+ PARALLEL(8) */

        bjjh AS METER_PREP

    FROM prepaid.da_bj@powernet_db_link

    UNION

    SELECT

        meterno AS METER_PREP

    FROM prepaid.order_master@powernet_db_link
),

/* ================================================================
   COMPTEURS PRESENTS DANS ORDER_MASTER
   ================================================================= */

order_master_meters AS (
    SELECT DISTINCT

        meterno AS METER_PREP

    FROM prepaid.order_master@powernet_db_link
),

/* ================================================================
   DERNIER PROFIL PREPAID

   Dernier profil REEL par METER : prepaid_profile_p contient une
   ligne par METER pour chaque mois du calendrier, y compris des mois
   futurs sans aucun achat (2026-10-01 : VC_DATE jusqu'au 2027-09-01,
   dernier achat au 2026-08-31). Seuls les mois jusqu'au mois du
   dernier achat connu de la table sont retenus.
   ================================================================= */

prepaid_last AS (
    SELECT
        METER,
        VC_DATE,
        RECENSE,
        SEGMENT_RFM_2

    FROM (
        SELECT

            METER,
            VC_DATE,
            RECENSE,
            SEGMENT_RFM_2,

            ROW_NUMBER() OVER (
                PARTITION BY METER
                ORDER BY VC_DATE DESC NULLS LAST
            ) AS RN

        FROM cms_rfc.prepaid_profile_p

        WHERE VC_DATE <= (
            SELECT TRUNC(MAX(LASTPURCHASE_DATE), 'MM')
            FROM cms_rfc.prepaid_profile_p
        )
    )

    WHERE RN = 1
),

/* ================================================================
   SEGMENT PREPAID

   Regle metier unique (rapport Marketing Intelligence, juillet 2026) :
   la recence decide d'abord ; sous 30 jours, le segment comportemental
   calcule en amont est repris. Toute autre valeur amont reste non
   classee plutot que d'etre affectee arbitrairement.
   ================================================================= */

prepaid_rfm AS (
    SELECT
        r.METER,
        r.VC_DATE,

        CASE
            WHEN r.RECENSE IS NULL
            THEN '6-Never Vending'

            WHEN r.RECENSE >= 60
            THEN '5-Dormant'

            WHEN r.RECENSE >= 30
            THEN '4-Suspect Dormant'

            WHEN r.SEGMENT_RFM_2 IN ('1-Stable', '2-InStable', '3-At Risk')
            THEN r.SEGMENT_RFM_2

            ELSE NULL

        END AS SEGMENT_RFM_2

    FROM prepaid_last r
),

/* ================================================================
   DERNIER ETAT POSTPAID

   Derniere ligne de chaque contrat dans postpaid_profile_p, y compris
   une ligne sans profil : c'est bill_number de cette ligne qui decide
   si le client est classe. La colonne PROFILE de la table (anciens
   libelles) n'est plus lue.
   ================================================================= */

calculated AS (
    SELECT
        SERVICE_NO,
        DATE_,
        BILL_NUMBER,
        RATE,
        BAD_COUNT,
        NO_PAID_COUNT

    FROM (
        SELECT

            SERVICE_NO,
            DATE_,
            BILL_NUMBER,
            RATE,
            BAD_COUNT,
            NO_PAID_COUNT,

            ROW_NUMBER() OVER (
                PARTITION BY SERVICE_NO
                ORDER BY DATE_ DESC NULLS LAST
            ) AS RN

        FROM cms_rfc.postpaid_profile_p
    )

    WHERE RN = 1
),

/* ================================================================
   PROFIL POSTPAID / COMPTEURS COMMUNICANTS

   Regle metier unique des deux populations : seule definition du
   projet. Bornes et libelles a ne pas modifier ailleurs.
   ================================================================= */

profile_calculation AS (
    SELECT
        c.*,

        CASE
            WHEN c.bill_number = 0
            THEN NULL

            WHEN c.rate = 1
            THEN '1 PERFECT'

            WHEN c.rate < 1
             AND c.rate >= 0.75
            THEN '2 RELIABLE'

            WHEN c.rate < 0.75
             AND c.rate >= 0.50
            THEN '3 Occasionnal'

            WHEN c.rate < 0.50
             AND c.rate >= 0.25
            THEN '4 At-Risk'

            WHEN c.rate < 0.25
             AND c.rate > 0
            THEN '5 Delinquant'

            ELSE '6 Défaillant'

        END AS profile

    FROM calculated c
),

/* ================================================================
   COMPTEURS COMMUNICANTS
   ================================================================= */

coms AS (

    SELECT DISTINCT

        'Compteurs Communicants' AS GROUPE,
        a.SERVICE_NO,
        a.SUPPLY_REF

    FROM CMSREPORT.TB_BS_CUSTOMER_LIST a

    JOIN (
        SELECT
            a.SERVICE_NO

        FROM CMSREPORT.TB_BS_CUSTOMER_LIST a

        JOIN APARATOS b
            ON b.NUM_SUM = a.SP_POINT_NO

        WHERE
            (
                a.STATUS NOT LIKE 'INACTIVE%'
                OR (
                    a.STATUS LIKE 'INACTIVE%'
                    AND a.TOT_KWH IS NOT NULL
                )
            )

            AND (
                a.NUM_ITIN IS NULL
                OR a.NUM_ITIN NOT IN (
                    86975,
                    123730,
                    123731,
                    123732,
                    123733
                )
            )

            AND b.CO_MARCA IN (
                'MC023',
                'MC019'
            )

            AND b.CO_MODELO IN (
                'MO194',
                'MO720',
                'MO729'
            )

    ) gc
        ON gc.SERVICE_NO = a.SERVICE_NO

    WHERE
        (
            a.STATUS NOT LIKE 'INACTIVE%'
            OR (
                a.STATUS LIKE 'INACTIVE%'
                AND a.TOT_KWH IS NOT NULL
            )
        )
)

/* =================================================================
   RESULTAT FINAL — 27 COLONNES
   ================================================================= */

SELECT /*+ PARALLEL(8) */

    /* 01 */
    l.REGION,

    /* 02 */
    l.DIVISION,

    /* 03 */
    l.AGENCE,

    /* 04 */
    l.COD_UNICOM,

    /* 05 */
    l.COD_CLI,

    /* 06 */
    l.CONTRACT,

    /* 07 */
    l.STATUS,

    /* 08 */
    l.METER_NO,

    /* 09 */
    l.CUST_NAME,

    /* 10 */
    l.PHONE_NUMBERS,

    /* 11 */
    l.E_MAIL,

    /* 12 */
    l.ACC_FINCA AS REF_GEO,

    /* 13 */
    TO_CHAR(
        l.F_ALTA,
        'YYYY-MM-DD HH24:MI:SS'
    ) AS DATE_AB,

    /* 14 */
    TO_CHAR(
        CASE
            WHEN l.F_BAJA_SERV > TRUNC(SYSDATE)
            THEN NULL
            ELSE l.F_BAJA_SERV
        END,
        'YYYY-MM-DD HH24:MI:SS'
    ) AS DATE_RESILIATION,

    /* 15 */
    l.VOLTAGE,

    /* 16 */
    l.SEGMENT_TRESOR,

    /* 17
       Type de compteur
       PREPAID > COMPTEURS COMMUNICANTS > POSTPAID
    */
    CASE
        WHEN p.METER_PREP IS NOT NULL
            THEN 'PREPAID'

        WHEN c.SERVICE_NO IS NOT NULL
            THEN 'Compteurs Communicants'

        ELSE 'POSTPAID'
    END AS METER,

    /* 18 */
    l.NIU_RIGHT,

    /* 19 */
    txy.XCOORD,

    /* 20 */
    txy.YCOORD,

    /* =============================================================
       21 — NIU TO RECLASS
       ============================================================= */

    CASE

        /*
         * Si le NIU actuel existe déjà,
         * aucun NIU de reclassification.
         */
        WHEN l.NIU_RIGHT IS NOT NULL
            THEN NULL

        /*
         * Sinon recherche d'un DOC_ID valide
         * pour le même COD_CLI.
         */
        ELSE (
            SELECT MAX(
                UPPER(TRIM(ci2.DOC_ID))
            )

            FROM cliente_identificador ci2

            WHERE ci2.COD_CLI = l.COD_CLI

              AND ci2.TIP_DOC IN (
                  'TD001',
                  'TD021'
              )

              AND REGEXP_LIKE(
                  UPPER(TRIM(ci2.DOC_ID)),
                  '^(P|M)[0-9]{12}[A-Z]$'
              )

              AND UPPER(TRIM(ci2.DOC_ID))
                    <> NVL(
                        UPPER(TRIM(l.NIU_RIGHT)),
                        '###'
                    )
        )

    END AS NIU_TO_RECLASS,

    /* =============================================================
       22 — QUALITE NIU
       ============================================================= */

    CASE

        WHEN l.NIU_RIGHT IS NOT NULL
             AND REGEXP_LIKE(
                 UPPER(TRIM(l.NIU_RIGHT)),
                 '^(P|M)[0-9]{12}[A-Z]$'
             )
        THEN 'NUI CORRECT'

        ELSE 'NUI à RECLASSER'

    END AS NUI_QC,

    /* 23 */
    TO_CHAR(
        rfm.VC_DATE,
        'YYYY-MM-DD HH24:MI:SS'
    ) AS LAST_VC_DATE,

    /* 24 */
    rfm.SEGMENT_RFM_2,

    /* 25 */
    TO_CHAR(
        pp.DATE_,
        'YYYY-MM-DD HH24:MI:SS'
    ) AS POSTPAID_PROFILE_DATE,

    /* =============================================================
       26 — SEGMENTATION
       ============================================================= */

    CASE

        /*
         * PREPAID avec profil RFM
         */
        WHEN p.METER_PREP IS NOT NULL
             AND rfm.METER IS NOT NULL
        THEN rfm.SEGMENT_RFM_2

        /*
         * PREPAID sans profil RFM mais présent dans ORDER_MASTER
         */
        WHEN p.METER_PREP IS NOT NULL
             AND rfm.METER IS NULL
             AND om.METER_PREP IS NOT NULL
        THEN '5-Dormant'

        /*
         * PREPAID sans profil RFM
         * et absent de ORDER_MASTER
         */
        WHEN p.METER_PREP IS NOT NULL
             AND rfm.METER IS NULL
             AND om.METER_PREP IS NULL
        THEN '6-Never Vending'

        /*
         * POSTPAID / COMPTEURS COMMUNICANTS
         * NULL si bill_number = 0 (client non classe)
         */
        WHEN p.METER_PREP IS NULL
             AND pp.SERVICE_NO IS NOT NULL
        THEN pp.PROFILE

        /*
         * POSTPAID / COMPTEURS COMMUNICANTS sans historique de
         * facturation : non classe
         */
        ELSE NULL

    END AS SEGMENTATION,

    /* 27 */
    TO_CHAR(
        SYSDATE,
        'YYYY-MM-DD HH24:MI:SS'
    ) AS UPDATED_AT,

    /* =============================================================
       28 — FAILURE_SUBCATEGORY

       Attribut d'analyse, distinct de SEGMENTATION : decompose le profil
       6 Défaillant, NULL pour tout autre client. Ce n'est pas un profil.
       Les conditions se chevauchent (BAD_COUNT = BILL_NUMBER implique en
       general NO_PAID_COUNT < BILL_NUMBER) : l'ordre est la priorite. Le
       ELSE garantit une composante a chaque client du profil.
       ============================================================= */

    CASE
        WHEN p.METER_PREP IS NULL
             AND pp.PROFILE = '6 Défaillant'
        THEN
            CASE
                WHEN pp.RATE = 0
                 AND pp.BAD_COUNT = pp.BILL_NUMBER
                 AND pp.BILL_NUMBER > 0
                THEN 'ALWAYS_LATE'

                WHEN pp.RATE = 0
                 AND pp.NO_PAID_COUNT = pp.BILL_NUMBER
                 AND pp.BILL_NUMBER > 0
                THEN 'NEVER_PAID'

                WHEN pp.RATE = 0
                 AND pp.NO_PAID_COUNT < pp.BILL_NUMBER
                 AND pp.BILL_NUMBER > 0
                THEN 'SOMETIME_PAID'

                ELSE 'OTHER'
            END
    END AS FAILURE_SUBCATEGORY

FROM lister l

/* ================================================================
   PREPAID
   ================================================================= */

LEFT JOIN prep p
    ON p.METER_PREP = l.METER_NO

/* ================================================================
   ORDER MASTER
   ================================================================= */

LEFT JOIN order_master_meters om
    ON om.METER_PREP = l.METER_NO

/* ================================================================
   COMPTEURS COMMUNICANTS
   ================================================================= */

LEFT JOIN coms c
    ON c.SERVICE_NO = l.CONTRACT

/* ================================================================
   PROFIL PREPAID / RFM
   ================================================================= */

LEFT JOIN prepaid_rfm rfm
    ON rfm.METER = l.METER_NO

/* ================================================================
   PROFIL POSTPAID
   ================================================================= */

LEFT JOIN profile_calculation pp
    ON pp.SERVICE_NO = l.CONTRACT

/* ================================================================
   COORDONNEES GEOGRAPHIQUES
   ================================================================= */

LEFT JOIN f045_000.temp_xy_coordinate txy
    ON txy.CONTRAT = l.CONTRACT