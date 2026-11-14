# Kit v2 : segmentation PREPAID et décomposition du profil Défaillant (13/11/2026)

Serveur source : **10.250.90.200**, `REPORT_DIR=/u01/COMMERCIALS_APPS/Nwameh`.

Le kit couvre deux sujets, livrés **en un seul déploiement** :
1. la segmentation PREPAID (`4-Suspect Dormant`, etc.) ;
2. la colonne 28 `FAILURE_SUBCATEGORY`, qui alimente le graphique « Décomposition du profil Défaillant » d'Extractor.

Il remplace le kit v1 (`deploy_customers_list_20261113.tgz`, PREPAID seul, 27 colonnes), qui ne doit plus être utilisé.

La **phase A** (audit) ne modifie rien. La **phase B** comporte 3 étapes, à faire dans l'ordre et uniquement sur validation explicite. Aucun script ne lance de chargement.

## 1. Ce qui change

| | En place (01/10, `574d3dd`) | Kit v2 |
|---|---|---|
| PREPAID : date de référence | aucune | `TRUNC(MAX(LASTPURCHASE_DATE))` de `PREPAID_PROFILE_P` (31/10/2026 au 13/11) |
| PREPAID : récence de 30 à 59 jours | segment amont | `4-Suspect Dormant` |
| PREPAID : récence de 60 jours et plus | segment amont, ou NULL | `5-Dormant` |
| PREPAID : récence de moins de 30 jours | segment amont | segment amont (`1-Stable`, `2-InStable`, `3-At Risk`) |
| PREPAID : profil sans date d'achat | `6 Never Vending` | `6-Never Vending` |
| PREPAID : sans profil, premier achat après la date de référence | `6 Old_Dormant` | `1-Stable` |
| PREPAID : sans profil, présent dans ORDER_MASTER | `6 Old_Dormant` | `5-Dormant` |
| PREPAID : sans profil, absent d'ORDER_MASTER | `7 Never Vending` | `6-Never Vending` |
| POSTPAID et Compteurs Communicants : `SEGMENTATION` | `pp.PROFILE`, sinon `8 Autre` | **inchangé** |
| Colonne 28 `FAILURE_SUBCATEGORY` | absente | `ALWAYS_LATE` / `NEVER_PAID` / `SOMETIME_PAID` pour chaque client Défaillant ; NULL pour les autres |
| Colonnes | 27 | **28**. Les colonnes 1 à 27 sont inchangées, la colonne 28 est ajoutée en fin |

**Règle de la colonne 28.** Elle s'applique aux clients non PREPAID classés Défaillant par la règle **en place** :
- profil amont `6 D…faillant`, quel que soit l'encodage du libellé ;
- ou client sans profil (`8 Autre`, qu'Extractor rattache déjà au Défaillant).

Pour ces clients, la valeur est calculée sur la **dernière ligne** du contrat dans `POSTPAID_PROFILE_P` (étape `postpaid_last`) :
- `ALWAYS_LATE` si `RATE = 0` et `BAD_COUNT = BILL_NUMBER > 0` ;
- sinon `NEVER_PAID` si `RATE = 0` et `NO_PAID_COUNT = BILL_NUMBER > 0` ;
- sinon `SOMETIME_PAID` si `RATE = 0` et `NO_PAID_COUNT < BILL_NUMBER` ;
- sinon `NEVER_PAID` (décision du 09/11).

Chaque client Défaillant reçoit donc une composante et une seule. La segmentation POSTPAID elle-même ne change pas.

**Pourquoi ce n'est pas `docs/snapshot/source/customers_list.sql` du dépôt.** Ce fichier change aussi la règle POSTPAID (`profile_calculation`) et ajoute les colonnes 29 et 30 (`PREPAID_RECENCY_*`), ce qui est hors périmètre. Le kit reprend à l'identique ses étapes PREPAID et ses composantes, mais les branche sur la règle POSTPAID en place.

**Mesures du 13/11** (Oracle en lecture seule, plus un snapshot Extractor de test) :
- colonne 28 : `ALWAYS_LATE` 48 080, `NEVER_PAID` 310 096 (184 184 + 125 912 `8 Autre`), `SOMETIME_PAID` 134 600, soit **492 776** ;
- aucun Défaillant sans composante, aucune composante hors Défaillant, une ligne par contrat ;
- dans Extractor : graphique = détail affiché au clic = référence, 0 « non décomposé », autres segmentations identiques.

Pour voir le diff : `diff customers_list.previous_574d3dd.sql customers_list.sql`.

## 2. Contenu

| Fichier | Rôle | Modifie quelque chose ? |
|---|---|---|
| `customers_list.sql` | SQL à déployer (28 colonnes, UTF-8 sans BOM, fins de ligne LF) | — |
| `customers_list.previous_574d3dd.sql` | version du 01/10, attendue en place | — |
| `expected_columns.txt` | les 28 colonnes, dans l'ordre | — |
| `phase1_audit.sh` | audit du serveur (crontab, script de chargement, `REPORT_DIR`, empreinte, `.ctl`, ExtractData.gvy) | non |
| `check_compat.sh` | SQL ↔ `.ctl` ↔ CSV ↔ table. Avec `STRICT=1`, le `.ctl` et la table doivent déjà avoir 28 colonnes | non |
| `01_alter_tb_customers_list.sql` | **étape 1** : `ALTER TABLE ... ADD FAILURE_SUBCATEGORY VARCHAR2(20)`, avec garde-fou à 27 colonnes et retour arrière en commentaire | table |
| `02_ctl_add_failure_subcategory.sh` | **étape 2** : `--check` / `--apply` / `--rollback` du `.ctl` (+ `FAILURE_SUBCATEGORY CHAR(20)` après `UPDATED_AT`) | `.ctl` |
| `deploy_customers_list.sh` | **étape 3** : `--check` / `--apply` / `--rollback` du SQL | SQL |
| `dryrun_counts.sh` | exécute le SQL du kit dans un SELECT de comptage (segments et composantes), sans chargement | non |
| `control_load.sql` | contrôles avant et après chargement (sections 0 à 9) | non |
| `reference_avant_20261113.txt` | résultat de `control_load.sql` le 13/11 avant déploiement | — |
| `SHA256SUMS` | empreintes de tous les fichiers | — |

Empreintes clés :
- SQL du kit : `f85882369407b53da65331ba0429230325463a2ac261501cadae086bd3ece6ca`
- version du 01/10 attendue en place : `5f18d89e077b8ab72a68096a3a0eec5ce7c5c84ee78ce1b8a4633009d7c90431`

## 3. Transfert

Le poste Extractor n'atteint pas 10.250.90.200 en SSH. Transférez depuis un poste qui y a accès :

```bash
scp deploy_customers_list_20261113_v2.tgz deploy_customers_list_20261113_v2.tgz.sha256 <compte>@10.250.90.200:/u01/COMMERCIALS_APPS/Nwameh/
```

Sur le serveur, avec le compte du job :

```bash
cd /u01/COMMERCIALS_APPS/Nwameh
sha256sum -c deploy_customers_list_20261113_v2.tgz.sha256
mkdir -p deploy_kits && tar xzf deploy_customers_list_20261113_v2.tgz -C deploy_kits
cd deploy_kits/deploy_customers_list_20261113_v2
sha256sum -c SHA256SUMS
```

## 4. Phase A : audit (lecture seule)

```bash
export REPORT_DIR=/u01/COMMERCIALS_APPS/Nwameh
./phase1_audit.sh > phase1_$(date +%Y%m%d_%H%M).txt 2>&1          # a renvoyer pour analyse
./deploy_customers_list.sh --check
./02_ctl_add_failure_subcategory.sh --check                         # montre la ligne qui sera ajoutee
ORA_CONNECT=CMS_RFC@cmsprod ./check_compat.sh                       # attendu : 0 echec, alertes « 27 colonnes »
sqlplus -S CMS_RFC@cmsprod @control_load.sql > control_avant_$(date +%Y%m%d_%H%M).txt
./dryrun_counts.sh CMS_RFC@cmsprod > dryrun_$(date +%Y%m%d_%H%M).txt   # facultatif, hors 02:45-05:45
```

Conditions pour passer en phase B :
- la cible correspond à la version du 01/10 ;
- le `.ctl` réel a les 27 champs attendus ;
- `check_compat` donne 0 échec ;
- aucun autre `customers_list.sql` n'est utilisé ;
- aucun chargement n'est en cours.

## 5. Phase B : déploiement (sur validation explicite, hors 02:45-05:45, en une seule séance)

```bash
export REPORT_DIR=/u01/COMMERCIALS_APPS/Nwameh ORA_CONNECT=CMS_RFC@cmsprod

# Etape 1 - table (DBA)
sqlplus CMS_RFC@cmsprod @01_alter_tb_customers_list.sql             # COLUMN_ID 28 attendu

# Etape 2 - fichier de controle SQL*Loader (refuse si l'etape 1 n'est pas faite)
./02_ctl_add_failure_subcategory.sh --apply --confirm=CTL-FAILURE-20261113        # puis taper OUI

# Etape 3 - SQL (refuse si les etapes 1 et 2 ne sont pas faites)
./deploy_customers_list.sh --apply --confirm=DEPLOY-SQL-V2-20261113               # puis taper OUI

STRICT=1 ./check_compat.sh                                           # attendu : 0 echec
```

Ordre obligatoire :
- un `.ctl` à 28 champs sur une table à 27 colonnes fait échouer SQL*Loader ;
- le SQL à 28 colonnes n'est utile qu'une fois le `.ctl` adapté.

Chaque script sauvegarde l'ancien fichier (`cp -p`, empreinte vérifiée, `SAVED.txt` avec propriétaire et mode, copie en lecture seule), remplace le fichier par un `mv` atomique et vérifie le résultat.

**Entre l'étape 1 et le chargement suivant**, ne pas lancer `php spark customers:refresh` côté Extractor : la colonne existe mais est vide, et Extractor afficherait tout le Défaillant comme « non décomposé » jusqu'à la synchronisation suivante.

## 6. Chargement suivant

- Recommandé : laisser la tâche planifiée de la nuit charger la table. Crontab de `sdsa.user` : `postpaid_profile_payer.sh` à 03:00, `prepaid_profile_payer.sh` à 04:00, `load_tb_customers_list.sh` à **05:00**. D'après `NOHUP_DIR`, le chargement complet dure 9 à 15 minutes (fin entre 05:09 et 05:15 du 09 au 13/11), loin du délai maximal de 5 400 s d'`ExtractData.gvy`. Les synchronisations Extractor de rattrapage (toutes les heures de 06:00 à 12:00) prennent ensuite le chargement du jour. Ne lancer à la main ni `load_tb_customers_list.sh` ni un second chargement sans validation.
- Extractor détecte seul la colonne 28 (`OracleSnapshotExtractor::OPTIONAL_COLUMNS`) et reconstruit l'index avec elle. Aucun déploiement Extractor n'est nécessaire.
- `--skip-if-fresh` concerne uniquement la synchronisation Extractor (une fois par jour), pas le serveur source.

## 7. Après le chargement

```bash
sqlplus -S CMS_RFC@cmsprod @control_load.sql > control_apres_$(date +%Y%m%d_%H%M).txt
diff reference_avant_20261113.txt control_apres_*.txt
```

| Section | Attendu (référence au 13/11 ; les données peuvent varier) |
|---|---|
| 0 | **28 colonnes**, volume cohérent avec la veille |
| 1 | 6 libellés PREPAID, aucun non classé, total = PREPAID actifs ; Suspect Dormant ≈ 86 075 |
| 2, 3 | aucune ligne |
| 4 | 30-59 → `4-Suspect Dormant` seul ; 60+ → `5-Dormant` seul |
| 5 | `1-Stable` ≈ 4 603, `5-Dormant` ≈ 110 448, `6-Never Vending` ≈ 9 192 |
| 6 | POSTPAID et Compteurs Communicants : mêmes libellés et effectifs proches de la référence |
| **8** | `ALWAYS_LATE` ≈ 48 080, `NEVER_PAID` ≈ 310 096, `SOMETIME_PAID` ≈ 134 600, total ≈ 492 776, **aucun `<NULL>`**, clients = contrats |
| **9** | aucune ligne |

Dans Extractor (vue Postpaid), le graphique doit afficher 3 composantes et aucune mention « non décomposés ». Un clic sur une composante doit afficher le même nombre de clients dans le détail.

## 8. Retour arrière (sur décision, jamais automatique)

Restaurer dans l'ordre inverse :

```bash
./deploy_customers_list.sh --rollback $REPORT_DIR/SqlQueries/backup/<horodatage> --confirm=ROLLBACK-SQL-V2-20261113
./02_ctl_add_failure_subcategory.sh --rollback <dossier_ctl>/backup_ctl/<horodatage> --confirm=CTL-ROLLBACK-20261113
# facultatif, DBA, seulement apres le .ctl : ALTER TABLE CMS_RFC.TB_CUSTOMERS_LIST DROP COLUMN FAILURE_SUBCATEGORY;
./deploy_customers_list.sh --check && ./02_ctl_add_failure_subcategory.sh --check
```

- On peut restaurer seulement le SQL. Le `.ctl` à 28 champs reste alors compatible avec un CSV à 27 colonnes (`TRAILING NULLCOLS` : la colonne 28 vaut NULL).
- Si la table a déjà été rechargée, elle garde les nouvelles valeurs jusqu'au chargement suivant.
- Extractor accepte les deux états : les anciens libellés sont renommés à la lecture. Sans colonne 28, il revient à l'affichage actuel (Défaillant partiellement non décomposé).

## 9. Risques

1. **Bascule PREPAID** : environ −222 800 clients sortent de `3-At Risk` vers Suspect Dormant et Dormant. C'est l'effet voulu, mais il est visible : à annoncer.
2. **Durée du chargement** : deux étapes s'ajoutent, `prepaid_new_buyers` (lien de base à base, environ 30 s) et `postpaid_last` (même lecture de `POSTPAID_PROFILE_P` que `postpaid_profile`). Surveiller le premier chargement.
3. **Libellé amont `6 D…faillant` endommagé** : il vient du calculateur `POSTPAID_PROFILE_P` et reste tel quel. La colonne 28 le reconnaît par `LIKE '6 D%faillant'`, qui fonctionnera aussi le jour où l'encodage sera corrigé. Extractor l'affiche déjà sous le libellé `6 Défaillant`.
4. **`8 Autre`** : 125 912 clients sans profil exploitable, rattachés au Défaillant en `NEVER_PAID` (classement de dernier recours décidé le 09/11, pas une preuve de facture impayée).
5. **Clients PREPAID avec profil mais sans date d'achat** (1 824) : classés `6-Never Vending`, selon la règle du dépôt.
6. **`.ctl` de production** (`RECONCILLIATION_EneoPay-CMS/control_tb_customers_list.ctl`, 1 504 octets, 17/09) : `options (direct=true, errors=0, readsize=2097152, skip=1)`, `characterset utf8`, mode INSERT par défaut, `fields terminated by "#" optionally enclosed by "'" trailing nullcols`, 27 champs. Les scripts du kit ont été testés sur une copie exacte de ce fichier : l'étape 2 ne change qu'une ligne (virgule après `UPDATED_AT` et ajout de `FAILURE_SUBCATEGORY CHAR(20)`), et le retour arrière le restaure à l'identique.
7. **`errors=0` et vidage préalable** : `load_tb_customers_list.sh` vide la table (`truncate_proc.sh`) **avant** `sqlldr`. Avec `errors=0`, la moindre ligne rejetée arrête le chargement et la table reste vide ou partielle jusqu'au lendemain. D'où l'ordre imposé (table, puis `.ctl`, puis SQL). Côté Extractor, le garde-fou `refreshMinRowRatio` (0,9) refuse d'activer un snapshot vide ou tronqué. Les valeurs ajoutées (13 caractères ASCII au plus dans un `VARCHAR2(20)`, libellés PREPAID de 17 caractères au plus dans un `VARCHAR2(30)`) ne peuvent pas provoquer de rejet.
8. **Format CSV** : la colonne 28 ne contient que des valeurs ASCII sans `#` ni guillemet, et un champ vide quand elle est NULL. Les colonnes 1 à 27 ne changent pas.
