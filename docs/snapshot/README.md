# Snapshot CUSTOMERS_LIST — mise en place et exploitation

Les exports utilisateur d'Extractor (CSV/XLSX) lisent un **snapshot local** de
`customers_list.csv`. Un export ne sollicite **jamais Oracle**. Deux producteurs
alimentent le même mécanisme de versions :

- **Source quotidienne (depuis 2026-09-27) : `php spark customers:refresh`**,
  exécuté sur le serveur Extractor lui-même à 05:00 — voir section 0.
- Secours : le push SFTP depuis le serveur source (sections 1 à 3).

## 0. Refresh quotidien Oracle → CSV (`customers:refresh`)

```
CMS_RFC.TB_CUSTOMERS_LIST (rechargée ~04:30 par truncate + SQL*Loader)
      │ 05:00  SELECT explicite (25 colonnes, dates DD/MM/YYYY), lecture en flux
      ▼
writable/data/customers_list.csv.tmp     (SHA-256, octets, lignes calculés à l'écriture)
      │ contrôles : fclose OK, taille disque = octets écrits, lignes = COUNT(*) Oracle,
      │             > 0, ≥ 90 % de la version active (sinon refus, --force pour passer)
      ▼
snapshot:install interne : validation complète, versions/<id>/, bascule current.json,
lien writable/data/customers_list.csv → version active (Linux)
```

| Élément | Valeur |
|---|---|
| Lancement manuel | `cd /var/www/MyMemo && php spark customers:refresh` (`--force` : accepter une forte baisse de volume) |
| Cron | `0 5 * * * cd /var/www/MyMemo && /usr/bin/php spark customers:refresh >> /var/www/MyMemo/writable/logs/customers_refresh.log 2>&1` |
| Log | `writable/logs/customers_refresh.log` (écrit par la commande, même en manuel ; le `>>` du cron n'ajoute que les erreurs fatales PHP) |
| Codes retour | `0` nouvelle version active · `1` échec (version précédente conservée) · `3` déjà en cours |
| Verrou | `writable/data/customers_refresh.lock` (`flock`, libéré par l'OS si le process meurt) |
| Fichier de travail | version active = `writable/data/snapshots/customers/versions/<id>/customers_list.csv` (via `current.json`) ; lien pratique `writable/data/customers_list.csv` |
| Arrêt (SIGTERM/SIGINT) | extraction stoppée, `.tmp` supprimé, version active conservée (pcntl) ; un `kill -9` laisse un `.tmp` supprimé au lancement suivant |
| Retour arrière | `php spark snapshot:rollback` (version précédente conservée sur disque) |

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
snapshot.exportSource = snapshot                 # 'oracle' = retour immédiat à l'ancien export
snapshot.incomingDir  = /srv/extractor_sftp/incoming   # si SFTP chrooté ; même volume que writable/
# snapshot.baseDir    = (défaut : writable/data/snapshots/customers)
```

`svc_snapshot_install` doit pouvoir écrire dans `writable/data/snapshots/`,
`writable/logs/` et lire/supprimer dans `incoming/` ; le compte du serveur web
doit pouvoir **lire** `writable/data/snapshots/`.

## 4. Exploitation

| Commande | Rôle |
|---|---|
| `php spark snapshot:status` | Snapshot actif, métadonnées, versions, rejets récents |
| `php spark snapshot:install` | Valider/activer la livraison présente dans `incoming/` (codes : 0 activé, 1 refusé, 2 rien/incomplet, 3 déjà en cours) |
| `php spark snapshot:rollback [<version>]` | Réactiver la version validée précédente (bascule du pointeur uniquement) |
| `php spark export:benchmark` | Benchmark CSV puis XLSX depuis le snapshot (aucune requête Oracle) ; `--source oracle` pour la référence historique |

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
| Nouveau snapshot pendant un export | l'export termine sur **sa** version (fichier immuable, pointeur lu une fois) |
| Aucun snapshot valide | l'export répond « données de référence indisponibles » (503, journalisé) — **aucune** extraction Oracle de secours |

## 6. Rollback

1. Données : `php spark snapshot:rollback`.
2. Code : `snapshot.exportSource = oracle` dans `.env` → exports de nouveau
   depuis Oracle (le code Oracle n'a pas été supprimé).
3. Source : restaurer `load_tb_customers_list.sh.bak.AAAAMMJJ`.
