# Snapshot CUSTOMERS_LIST — mise en place et exploitation

Toutes les fonctionnalités utilisateur d'Extractor sur la liste clients —
filtres, KPI, graphiques, compteurs de segmentation, tableau (recherche, tri,
pages), comptage, exports CSV/XLSX — lisent le **snapshot actif** de
`customers_list.csv`, et une même requête ou un même export ne lit qu'**une
seule version**. Aucune ne sollicite Oracle : le seul accès à
`CMS_RFC.TB_CUSTOMERS_LIST` est le refresh. Sans snapshot valide (ou sans son
index), réponse 503 « données de référence indisponibles », jamais de repli
Oracle. Deux producteurs alimentent le même mécanisme de versions :

- **Source quotidienne : `php spark customers:refresh`**, exécuté sur le
  serveur Extractor lui-même à **05:00** (+ rattrapage 06:00) — section 0.
- Secours : le push SFTP depuis le serveur source (sections 1 à 3).

Chaque version comprend son CSV (référence officielle) **et** l'index de
requête DuckDB construit à partir de ce CSV avant l'activation — section 0 bis.

## 0. Refresh quotidien Oracle → CSV (`customers:refresh`)

```
CMS_RFC.TB_CUSTOMERS_LIST (rechargée chaque nuit : génération 04:32, truncate 04:40, chargée à 04:42)
      │ 05:00  SELECT explicite (25 colonnes, dates DD/MM/YYYY), lecture en flux
      ▼
writable/data/customers_list.csv.tmp     (SHA-256, octets, lignes calculés à l'écriture)
      │ contrôles : fclose OK, taille disque = octets écrits, lignes = COUNT(*) Oracle,
      │             > 0, ≥ 90 % de la version active (sinon refus, --force pour passer)
      ▼
snapshot:install interne : validation complète, versions/<id>/, index DuckDB construit
depuis ce CSV (échec = version rejetée), bascule current.json, purge (jamais une
version attendue par un export en file), lien writable/data/customers_list.csv (Linux)
      ▼
dashboard:warm : pré-calcul du tableau de bord de la nouvelle version
```

| Élément | Valeur |
|---|---|
| Source | Oracle `CMS_RFC.TB_CUSTOMERS_LIST` |
| Projet (prod Linux) | `/var/www/extractor` |
| Lancement manuel | `cd /var/www/extractor && php spark customers:refresh` (`--force` : accepter une forte baisse de volume) |
| Fréquence / heure | quotidienne, **05:00** heure serveur (= Africa/Douala, UTC+1), rattrapage **06:00** |
| Pourquoi 05:00 | besoin métier ; mesuré le 2026-09-29 dans Oracle : `UPDATED_AT` 04:32:48, truncate (`LAST_DDL_TIME`) 04:40:46, 3 303 512 insertions enregistrées à 04:41:44 (`ALL_TAB_MODIFICATIONS`) → ~18 min de marge. Un chargement amont pas fini à 05:00 ne peut pas devenir le snapshot : lignes extraites ≠ `COUNT(*)` initial (`row_count_mismatch`) ou < 90 % de la version active (`volume_drop`) → refus, version active conservée. Le rattrapage de 06:00 (`--skip-if-fresh`) relance alors ; il ne fait rien si 05:00 a réussi (version du jour déjà active) |
| Installation du cron (root, prod) | `sh docs/snapshot/extractor/install_customers_refresh_cron.sh --user <utilisateur web>` (vérifie `command -v php`, PHP ≥ 8.2 + oci8, `duckdb`, droits sur `writable/`, fuseau +0100, puis écrit `/etc/cron.d/extractor-customers-refresh`) ; `--dry-run` pour voir les lignes sans les écrire ; `--time HH:MM`, `--catch-up HH:MM\|none`, `--duckdb <chemin>` |
| Lignes cron écrites | `0 5 * * * <utilisateur> cd /var/www/extractor && <php> spark customers:refresh >> …/customers_refresh.log 2>&1 && <php> spark dashboard:warm >> …` puis la même à `0 6` avec `customers:refresh --skip-if-fresh` |
| Dev Windows | pas de planification : lancement manuel si besoin |
| Log | `writable/logs/customers_refresh.log` (écrit par la commande, même en manuel ; le `>>` du cron n'ajoute que les erreurs fatales PHP) |
| Codes retour | `0` nouvelle version active (ou, avec `--skip-if-fresh`, version du jour déjà active) · `1` échec (version précédente conservée) · `3` déjà en cours |
| Verrou | `writable/data/customers_refresh.lock` (`flock` noyau pris par la commande — cron **et** manuel ; libéré par l'OS si le process meurt ; 2e instance → code 3 `SKIPPED`, sans requête Oracle). Pas de `flock(1)` en plus dans le cron |
| En cas d'échec | code 1, `FAILED raison=…` dans le log, fichier temporaire supprimé, **version active conservée** (dashboard, filtres et exports continuent sur l'ancien snapshot — jamais sur Oracle). Nouvel essai automatique au rattrapage de 06:00 ; sinon relancer à la main après correction |
| Fichier de travail | version active = `writable/data/snapshots/customers/versions/<id>/customers_list.csv` (via `current.json`) ; lien pratique `writable/data/customers_list.csv` |
| Arrêt (SIGTERM/SIGINT) | extraction stoppée, `.tmp` supprimé, version active conservée (pcntl) ; un `kill -9` laisse un `.tmp` supprimé au lancement suivant |
| Retour arrière | `php spark snapshot:rollback` (version précédente conservée sur disque) |

## 0 bis. Index de requête DuckDB (tableau de bord)

Le tableau de bord filtre, agrège, trie et pagine 3,3 M lignes à chaque clic :
un parcours du CSV en PHP prend 15 à 22 s par opération. Chaque version
reçoit donc, à l'installation, un index **dérivé de son propre CSV** :
`versions/<id>/customers_list.duckdb` (+ `.info.json`), base DuckDB
interrogée par le binaire `duckdb` (aucune extension PHP ; SQL passé sur
l'entrée standard via `proc_open`, jamais par un shell ; processus en lecture
seule, concurrents possibles). Le CSV reste la référence : l'index est
reconstruit à l'identique depuis lui (`php spark snapshot:index --rebuild`) et
supprimé avec sa version.

Les règles de filtrage sont exactement celles des exports (`RowMatcher`) : la
construction normalise chaque ligne avec le même code (valeurs de filtre
`trim`ées, dates lues par `SnapshotDate`) ; des tests de parité comparent
DuckDB à un parcours PHP de référence et au comptage des exports.

Choix mesuré le 2026-10-02 sur le vrai fichier (3 302 841 lignes, 811 Mo,
poste de dev Windows, 8 Go RAM) :

| Moteur | Construction | KPI filtrés | 4 répartitions | page 1 triée | recherche | page 401 |
|---|---|---|---|---|---|---|
| PHP (lecture du CSV à chaque requête) | — | 18–22 s (tout en une passe) | (même passe) | 15–17 s | 15–17 s | — |
| MariaDB 10.4 (table + index) | chargement 89 s + index > 17 min (interrompu) | 3,4–5,8 s (sans index) | 13–21 s | 3,1–6,8 s | 6,8–15 s | 9–26 s |
| **DuckDB (retenu)** | **109–230 s, 181 Mo** | **0,14–0,24 s** | **0,18–0,5 s** | **0,5–1,2 s** | **0,5–1,4 s** | 0,8–5 s |

Mesures finales via le service réel (cache désactivé) : `count` 50–120 ms,
`stats` 0,3–0,7 s, compteurs de segmentation 80–110 ms, page de tableau
0,4–1,2 s ; toute réponse est ensuite servie depuis le cache (clé = version +
filtres). Mémoire PHP : 8 Mo ; DuckDB : `snapshot.duckdbThreads` (4) et
`snapshot.duckdbMemoryLimit` (1GB) par requête.

Installation du binaire (prod Linux, une fois, root) :

```
cd /tmp && curl -fsSLO https://github.com/duckdb/duckdb/releases/download/v1.1.3/duckdb_cli-linux-amd64.zip
unzip duckdb_cli-linux-amd64.zip && install -o root -g root -m 0755 duckdb /usr/local/bin/duckdb
sudo -u www-data /usr/local/bin/duckdb -version
```

Le binaire qui construit un index est le même qui l'interroge (même serveur) ;
après un changement de version de DuckDB : `php spark snapshot:index --rebuild`.

Avant d'installer le cron, vérifier le fuseau du serveur (`timedatectl`) : le
cron suit l'heure système, l'application est en `Africa/Douala` (UTC+1).
Réglages `.env` : `snapshot.refreshMinRowRatio`, `snapshot.refreshProgressEvery`,
`snapshot.refreshTmpFile`, `snapshot.refreshLockFile`, `snapshot.refreshLogFile`,
`snapshot.publishedLink` (vide = pas de lien).

```
10.250.90.200                                   Serveur Extractor
ExtractData.gvy ─► customers_list.csv.tmp
      │ rc=0 + contrôle
      ▼
customers_list.csv ─► push_customers_list.sh ─SFTP─► incoming/*.part ─► renommage
      │                     │ (manifeste : taille, SHA-256, lignes, colonnes, en-tête)
      │                     └─ssh (clé restreinte)─► php spark snapshot:install
      ▼                                                 │ taille, SHA-256, en-tête, colonnes,
truncate + SQL*Loader (inchangés,                       │ UTF-8, nb de lignes
indépendants du snapshot)                               ├─ OK : versions/<id> + current.json
                                                        └─ KO : rejected/<id>, snapshot actif conservé
```

## 1. Protocole de livraison

| Étape | Qui | Détail |
|---|---|---|
| Génération | source | `ExtractData.gvy` écrit `customers_list.csv.tmp` ; succès = code retour 0 **et** fichier non vide avec en-tête |
| Publication locale | source | `mv customers_list.csv.tmp customers_list.csv` (atomique) |
| Manifeste | source | `size` (stat), `sha256` (sha256sum), `lines` (wc -l), `columns` (champs de l'en-tête), `delimiter=#`, `encoding=UTF-8`, `header` (head -1), `generated_at` (mtime du fichier), `source_host` |
| Transfert | source | `customers_list.csv.part` + `customers_list.manifest.part`, puis renommage ; **le manifeste est renommé en dernier** (= livraison complète) |
| Activation | Extractor | `php spark snapshot:install`, déclenché par la source via une clé SSH qui ne peut exécuter que cette commande |

Aucune taille, aucun nombre de lignes ou de colonnes n'est codé en dur dans
Extractor : chaque livraison est comparée à **son propre manifeste**. Extractor
exige seulement que les colonnes qu'il exporte ou filtre existent dans l'en-tête.

## 2. Serveur source (10.250.90.200)

### 2.1 Installer le script de push

```bash
cd /u01/COMMERCIALS_APPS/Nwameh/jobs
cp load_tb_customers_list.sh load_tb_customers_list.sh.bak.$(date +%Y%m%d)   # rollback
# copier docs/snapshot/source/push_customers_list.sh ici, puis :
chmod 750 push_customers_list.sh
cp push_customers_list.conf.example push_customers_list.conf && chmod 600 push_customers_list.conf
vi push_customers_list.conf        # hôte Extractor, comptes, chemins des clés
```

### 2.2 Modifier `load_tb_customers_list.sh`

Remplacer l'appel actuel à `ExtractData.gvy` par le bloc ci-dessous, **sans
toucher** au bloc `truncate_proc.sh` + `sqlldr` qui suit. À adapter après
lecture du script réel (variables `$REPORT_DIR`, `$Nohup_File`).

```bash
log_cl() { echo "$(date '+%F %T') [customers_list] $*" >> "$Nohup_File"; }
CL_OUT=$REPORT_DIR/customers_list.csv
CL_TMP=$CL_OUT.tmp
rm -f "$CL_TMP"

log_cl "génération démarrée"
$REPORT_DIR/jobs/ExtractData.gvy \
    "$CL_TMP" \
    $REPORT_DIR/SqlQueries/customers_list.sql \
    >> $Nohup_File 2>&1
GEN_RC=$?

if [ $GEN_RC -eq 0 ] && [ -s "$CL_TMP" ] && head -1 "$CL_TMP" | grep -q '#'; then
    mv -f "$CL_TMP" "$CL_OUT"
    log_cl "génération OK taille=$(stat -c%s "$CL_OUT") lignes=$(wc -l < "$CL_OUT")"
    $REPORT_DIR/jobs/push_customers_list.sh "$CL_OUT" >> $Nohup_File 2>&1
    PUSH_RC=$?
    log_cl "push Extractor rc=$PUSH_RC"
else
    log_cl "génération ECHEC rc=$GEN_RC — pas de SFTP, snapshot Extractor inchangé"
    rm -f "$CL_TMP"
    PUSH_RC=skipped
fi

# ... bloc existant INCHANGÉ : cd RECONCILIATION_EneoPay-CMS, truncate_proc.sh, sqlldr ...
SQLLDR_RC=$?          # juste après sqlldr
log_cl "bilan génération=$GEN_RC push=$PUSH_RC sqlldr=$SQLLDR_RC"
```

**À vérifier dans les fichiers réels avant mise en production :**

1. `ExtractData.gvy` renvoie bien un code ≠ 0 en cas d'erreur (pas d'exception
   interceptée qui finirait en `exit 0`) et ferme le fichier avant de terminer.
   Sinon, le contrôle `[ -s ] + en-tête` reste le seul garde-fou.
2. Le fichier de contrôle SQL*Loader (`INFILE`) lit bien
   `$REPORT_DIR/customers_list.csv`. Avec le `.tmp`, un échec de génération
   laisse l'**ancien fichier complet** en place : SQL*Loader rechargerait donc
   les données précédentes au lieu d'un fichier partiel. Si ce rechargement
   n'est pas souhaité, sauter `truncate` + `sqlldr` quand `GEN_RC ≠ 0` (décision
   métier, non appliquée ici).
3. Le push dure le temps du transfert (≈ 0,9 Go). S'il ne doit pas retarder
   SQL*Loader, le lancer en arrière-plan :
   `nohup push_customers_list.sh "$CL_OUT" >> $Nohup_File 2>&1 &`
   (sûr : le script envoie un lien physique figé, pas le fichier vivant).

### 2.3 Clés SSH (compte qui exécute le job)

```bash
ssh-keygen -t ed25519 -N '' -C 'push customers_list -> extractor' -f ~/.ssh/extractor_push_ed25519
ssh-keygen -t ed25519 -N '' -C 'trigger snapshot:install'        -f ~/.ssh/extractor_install_ed25519
chmod 600 ~/.ssh/extractor_*_ed25519
ssh-keyscan -t ed25519 <hôte-extractor> > ~/.ssh/known_hosts_extractor   # vérifier l'empreinte avec l'admin Extractor
cat ~/.ssh/extractor_push_ed25519.pub ~/.ssh/extractor_install_ed25519.pub   # à transmettre à l'admin Extractor
```

Les clés privées ne quittent jamais ce serveur, ne vont jamais dans Git, et ne
sont jamais connues des utilisateurs d'Extractor.

## 3. Serveur Extractor

### 3.1 Deux comptes techniques (moindre privilège)

| Compte | Peut faire | Ne peut pas faire |
|---|---|---|
| `svc_snapshot_push` | SFTP, chrooté, écriture dans `incoming/` uniquement | shell, lecture des snapshots, exécution |
| `svc_snapshot_install` | exécuter `php spark snapshot:install` (commande forcée) | shell, SFTP, toute autre commande |

**Linux (OpenSSH)** — `/etc/ssh/sshd_config` :

```
Match User svc_snapshot_push
    ChrootDirectory /srv/extractor_sftp
    ForceCommand internal-sftp
    PasswordAuthentication no
    AllowTcpForwarding no
    X11Forwarding no
```

```bash
install -d -o root -g root -m 755 /srv/extractor_sftp
install -d -o svc_snapshot_push -g extractor -m 2770 /srv/extractor_sftp/incoming
```

`~svc_snapshot_install/.ssh/authorized_keys` (une ligne) :

```
restrict,command="cd /chemin/extractor && /usr/bin/php spark snapshot:install" ssh-ed25519 AAAA... trigger snapshot:install
```

**Windows (OpenSSH Server)** : fonctionnalité facultative « Serveur OpenSSH »
(droits admin), service `sshd` en démarrage automatique, port 22 ouvert
**uniquement** depuis 10.250.90.200. Mêmes blocs dans
`C:\ProgramData\ssh\sshd_config` (`ChrootDirectory D:\extractor_sftp`), et pour
le compte d'installation :
`restrict,command="C:\xampp\php\php.exe C:\xampp\htdocs\extractor\spark snapshot:install" ssh-ed25519 AAAA...`

> Constaté le 2026-09-25 : le poste de dev (10.249.102.77) n'a **pas** de
> service `sshd`. La réception SFTP suppose un serveur SSH sur la machine
> Extractor de production.

### 3.2 Configuration Extractor (`.env`)

```
snapshot.incomingDir  = /srv/extractor_sftp/incoming   # si SFTP chrooté ; même volume que writable/
# snapshot.baseDir    = (défaut : writable/data/snapshots/customers)
# snapshot.duckdbBinary      = /usr/local/bin/duckdb   # défaut : duckdb (PATH)
# snapshot.duckdbThreads     = 4
# snapshot.duckdbMemoryLimit = 1GB
```

`snapshot.exportSource = oracle` (ancien interrupteur de retour à Oracle) n'est
plus appliqué : s'il reste dans un `.env`, `php spark export:doctor` le signale
et toutes les fonctionnalités continuent de lire le snapshot.

`svc_snapshot_install` doit pouvoir écrire dans `writable/data/snapshots/`,
`writable/logs/` et lire/supprimer dans `incoming/` ; le compte du serveur web
doit pouvoir **lire** `writable/data/snapshots/`.

## 4. Exploitation

| Commande | Rôle |
|---|---|
| `php spark snapshot:status` | Snapshot actif, métadonnées, versions, rejets récents |
| `php spark snapshot:install` | Valider/activer la livraison présente dans `incoming/` (codes : 0 activé, 1 refusé, 2 rien/incomplet, 3 déjà en cours) |
| `php spark snapshot:rollback [<version>]` | Réactiver la version validée précédente (bascule du pointeur uniquement) |
| `php spark snapshot:index [--rebuild]` | Construire l'index DuckDB manquant de la version active (et des versions d'exports en file) — après un premier déploiement ou un changement de binaire |
| `php spark dashboard:warm` | Pré-calculer le tableau de bord de la version active (lancé par le cron après un refresh réussi) |
| `php spark export:doctor` | Vérifier snapshot actif, binaire DuckDB, index de la version active, schéma `export_jobs`, droits |
| `php spark export:benchmark` | Benchmark CSV puis XLSX depuis le snapshot (aucune requête Oracle) ; `--source oracle` = outil de mesure CLI uniquement |

Journaux Extractor : `writable/logs/log-*.log`, préfixe `[SNAPSHOT]`
(installation démarrée, activation, échec de validation + raison, snapshot
conservé, rollback, export refusé faute de snapshot). Journaux source :
`$Nohup_File`, préfixes `[customers_list]` et `[push_customers_list]`.
Aucun mot de passe ni clé n'est jamais journalisé.

## 5. Cas d'erreur

| Cas | Résultat |
|---|---|
| `ExtractData.gvy` échoue | pas de SFTP ; snapshot Extractor inchangé |
| SFTP échoue / coupé | seuls des `.part` restent ; jamais consommés ; snapshot inchangé |
| Validation échoue | livraison dans `rejected/<id>/reason.json` (CSV supprimé) ; snapshot inchangé ; rc=1 renvoyé à la source |
| Validation réussit | nouvelle version active ; la précédente reste disponible pour rollback |
| SQL*Loader échoue ensuite | aucun effet sur le snapshot (chaînes indépendantes) |
| Index DuckDB impossible à construire | version rejetée ; snapshot actif inchangé |
| Nouveau snapshot pendant un export | l'export termine sur **sa** version (fichier immuable, pointeur lu une fois) |
| Nouveau snapshot avant qu'un export en file soit traité | le job a enregistré sa version (`export_jobs.snapshot_version`) : le worker lit **cette** version, que la purge ne supprime pas tant que le job est en attente ou en cours |
| Version d'un job supprimée à la main | job en erreur (référence journalisée), jamais une autre version ni Oracle |
| Aucun snapshot valide, ou pas d'index | filtres, tableau de bord et exports répondent « données de référence indisponibles » (503, journalisé) — **aucune** extraction Oracle de secours |

## 6. Rollback

1. Données : `php spark snapshot:rollback` (version précédente, avec son index).
2. Code : revenir au commit précédent (il n'y a plus d'interrupteur vers
   Oracle : les fonctionnalités utilisateur lisent le snapshot par conception).
3. Source : restaurer `load_tb_customers_list.sh.bak.AAAAMMJJ`.
