# Worker d'export asynchrone — déploiement Linux (Ubuntu 22.04)

Le worker `php spark export:process --watch` génère les exports CSV/XLSX mis en file
par le tableau de bord (table `export_jobs`). Ce document permet à un administrateur
qui ne connaît pas l'historique du projet d'installer, mettre à jour, vérifier et
dépanner le worker.

Valeurs utilisées ci‑dessous (à adapter partout à l'identique si elles diffèrent) :

| | |
|---|---|
| Projet | `/var/www/extractor` |
| Utilisateur du serveur web **et** du worker | `www-data` (groupe `www-data`) |
| Service systemd | `extractor-export-worker` |
| PHP CLI | `/usr/bin/php` (8.2+, extensions : voir `php spark export:doctor`) |

## 0. Principe : qui prépare quoi

Le worker écrit dans ces répertoires (tous sous `writable/`) :

| Répertoire | Usage |
|---|---|
| `writable/logs` | logs CodeIgniter |
| `writable/cache` | cache CodeIgniter |
| `writable/uploads/exports` | fichiers d'export finaux |
| `writable/tmp/openspout_tmp` | brouillons OpenSpout des exports XLSX (`export_<date>_<rand>/`, supprimés en fin d'export) |
| `writable/export-worker` | verrou (un seul worker) + heartbeat |

`writable/tmp/` et `writable/export-worker/` ne sont **pas** dans Git (`.gitignore`) : ils
n'existent pas après un `git clone`. Le premier processus qui les crée en devient
propriétaire. Si c'est une commande lancée en root (`sudo php spark …` sans
`-u www-data`), le dossier appartient à root en `0755` et www-data ne peut plus y écrire.
C'est l'erreur `dir.openspout_tmp … NON accessible en écriture pour www-data`.

Désormais :

- **systemd prépare les répertoires avant chaque démarrage** du worker (`ExecStartPre=+`,
  en root) avec `/usr/local/sbin/extractor-prepare-writable`. Le script est idempotent,
  ne fait jamais de `chmod -R` ni de `777`, et conserve le propriétaire des dossiers
  existants (il fixe seulement le groupe `www-data` + `g+rwxs`). Il crée les dossiers
  absents en `www-data:www-data 2775`, puis vérifie par un vrai test d'écriture en tant
  que www-data.
- **puis un préflight PHP en www-data** (`php spark export:doctor --preflight`) crée et
  supprime réellement un fichier et un dossier dans chaque répertoire. En cas d'échec,
  le service ne démarre pas et la raison est dans `journalctl`.
- **le code PHP ne crée plus de dossiers en root** : `export:doctor` ne crée rien sans
  `--fix` (et jamais en root), et `export:process` refuse de tourner en root.
- le chemin OpenSpout est configurable (`export.openSpoutTempPath` dans `.env`, défaut
  `writable/tmp/openspout_tmp`). Voir `app/Config/Export.php`.

Le worker redémarre de lui‑même après un `git pull` (il détecte le changement de code).
Les `ExecStartPre` sont alors rejoués : **un déploiement normal répare les permissions
de ces répertoires sans aucune commande manuelle.**

## 1. Première installation (nouvelle VM)

```bash
# Paquets (adapter la version de PHP si besoin)
sudo apt-get install -y php8.3-cli php8.3-mysql php8.3-intl php8.3-mbstring php8.3-xml php8.3-zip php8.3-curl git unzip
# (pcntl et posix sont inclus dans php-cli sur Ubuntu : arrêt propre sur SIGTERM)

# Code
sudo mkdir -p /var/www/extractor
sudo chown "$USER":www-data /var/www/extractor
git clone <dépôt> /var/www/extractor
cd /var/www/extractor
composer install --no-dev --optimize-autoloader
cp .env.example .env         # puis renseigner CI_ENVIRONMENT=production, base, LDAP...
sudo chgrp www-data .env && sudo chmod 640 .env

# Base de données
sudo -u www-data php spark migrate

# Script de préparation (copie root, jamais exécutée depuis le dépôt)
sudo install -o root -g root -m 0755 docs/deploy/prepare-writable.sh /usr/local/sbin/extractor-prepare-writable
sudo extractor-prepare-writable /var/www/extractor www-data www-data

# Service
sudo install -o root -g root -m 0644 docs/deploy/extractor-export-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now extractor-export-worker
```

Puis passer à la **vérification** (§4).

Supprimer toute autre façon de lancer `--watch` (crontab, `nohup`, `screen`…) : un seul
worker peut tourner (verrou `writable/export-worker/worker.lock`).

```bash
crontab -l | grep export:process ; sudo crontab -u www-data -l | grep export:process
pgrep -af "export:process"
```

## 2. Mise à jour (à chaque `git pull`)

```bash
cd /var/www/extractor
git pull
composer install --no-dev --optimize-autoloader        # si composer.lock a changé
sudo -u www-data php spark migrate                      # idempotent
sudo -u www-data php spark cache:clear

# Si docs/deploy/ a changé (sans risque de le faire à chaque fois) :
sudo install -o root -g root -m 0755 docs/deploy/prepare-writable.sh /usr/local/sbin/extractor-prepare-writable
sudo install -o root -g root -m 0644 docs/deploy/extractor-export-worker.service /etc/systemd/system/
sudo systemctl daemon-reload

sudo systemctl restart extractor-export-worker          # rejoue la préparation + le préflight
sudo -u www-data php spark export:doctor
```

Règle d'or : **toujours `sudo -u www-data php spark …`**, jamais `sudo php spark …`.
Une commande spark en root peut créer des fichiers dont root est propriétaire. Le worker
refuse désormais de tourner en root, et le doctor ne crée plus rien en root.

### Mise à jour depuis une version antérieure à ce correctif (une seule fois)

L'ancien répertoire `writable/tmp/openspout` (sans `_tmp`) n'est plus utilisé :

```bash
sudo rm -rf /var/www/extractor/writable/tmp/openspout
```

## 3. Redémarrer / arrêter

```bash
sudo systemctl restart extractor-export-worker
sudo systemctl stop extractor-export-worker
```

Sur SIGTERM, le job en cours se termine avant l'arrêt (extension `pcntl`). Un export
XLSX complet peut prendre jusqu'à 30 min (`TimeoutStopSec=1800`). Sans `pcntl`, le job
est interrompu et passé en `error` au démarrage suivant (job `running` sans progression
depuis plus de `--stale-after` secondes, 3600 par défaut). Dans tous les cas, le
brouillon OpenSpout d'un export interrompu est supprimé par le balayage des orphelins
(dossiers `export_*` de plus de 6 h) au prochain export.

## 4. Vérifier (après installation, mise à jour ou reboot)

```bash
systemctl status extractor-export-worker --no-pager      # active (running)
journalctl -u extractor-export-worker -b --no-pager | tail -n 30
sudo -u www-data php spark export:doctor                 # code retour 0 attendu
```

Dans le journal, au démarrage :

```
extractor-prepare-writable: ok      /var/www/extractor/writable/tmp/openspout_tmp (www-data:www-data 2775)
...
OK      dir.openspout_tmp  /var/www/extractor/writable/tmp/openspout_tmp
                           exists=yes writable=yes write_test=yes delete_test=yes subdir_test=yes user=www-data (uid 33) ...
Préflight worker : OK
[EXPORT WORKER] start pid=... user=www-data (uid 33) ... mode=watch
```

Lecture de `export:doctor` :

| Niveau | Sens |
|---|---|
| `OK` | conforme |
| `INFO` | historique, jamais bloquant (ex. `jobs.last_error` : dernier job en erreur) |
| `WARNING` | à regarder. Ex. `worker.watch : aucun worker --watch actif` est normal **avant** le démarrage du service et doit devenir `OK` une fois le service démarré |
| `ERROR` | problème actuel → code retour 1 |

La synthèse en fin de sortie sépare *Configuration*, *Filesystem*, *Données (snapshot)*,
*Worker* et *Historique des jobs*.

Les répertoires sont jugés sur le **comportement réel** (création + suppression d'un
fichier et d'un sous‑dossier en tant qu'utilisateur courant), pas sur leur propriétaire.
Un dossier appartenant à root ou à un autre compte est `OK` si www-data peut réellement
y écrire (groupe, ACL). Exemple : `root:www-data 2775` est `OK` pour www-data.

Un répertoire géré qui est lui‑même un lien symbolique (ex. `openspout_tmp -> /ailleurs`)
est en revanche une `ERROR`, comme dans le script de préparation : configurer le chemin
réel (`export.openSpoutTempPath`). `writable/` ou le projet peuvent, eux, être des liens.

Test fonctionnel : lancer un petit export XLSX depuis le tableau de bord, puis :

```sql
SELECT id, format, status, rows_total, rows_processed, rows_exported, error_reference, started_at, finished_at
FROM export_jobs ORDER BY id DESC LIMIT 5;
```

```bash
ls -la /var/www/extractor/writable/tmp/openspout_tmp     # vide une fois l'export terminé
```

### Après un reboot

Rien à faire : le service est `enabled`. Au démarrage, il repasse par la préparation des
répertoires et le préflight. Vérifier avec `systemctl status` et
`journalctl -u extractor-export-worker -b`.

## 5. Problèmes de permissions

Symptôme : `ERROR dir.<nom> … NON utilisable par www-data`, ou le service est `failed`
avec `extractor-prepare-writable: ERREUR …` ou `Préflight worker : ERROR` dans le journal.

```bash
sudo extractor-prepare-writable /var/www/extractor www-data www-data   # répare et dit ce qu'il a fait
sudo -u www-data php spark export:doctor
sudo systemctl reset-failed extractor-export-worker                    # si 5 échecs ont figé l'unité
sudo systemctl restart extractor-export-worker
```

Si le script échoue malgré tout (`toujours non inscriptible pour www-data`), la cause
n'est pas le propriétaire ni le mode :

```bash
namei -l /var/www/extractor/writable/tmp/openspout_tmp   # droit x sur chaque parent ?
getfacl /var/www/extractor/writable/tmp/openspout_tmp    # ACL qui refuse ?
findmnt -T /var/www/extractor/writable                   # monté en lecture seule ?
sudo aa-status | grep -i php                             # profil AppArmor ?
```

`php spark export:doctor --fix` (lancé en www-data) crée les répertoires manquants
qu'il a le droit de créer. Il ne modifie jamais de droits et n'exécute aucune commande
système. Ce qui demande root est signalé avec la commande à lancer.

Propriétaires existants : le script ne change **jamais** le propriétaire d'un dossier
existant (par ex. le compte de déploiement). Il garantit seulement le groupe `www-data`
avec écriture + setgid : les fichiers créés dedans héritent du groupe `www-data`. Les
fichiers de log `log-*.log` créés par un autre compte reçoivent le groupe `www-data` et
`g+rw`. Les brouillons OpenSpout et l'état du worker (verrou, heartbeat) laissés par une
exécution en root sont rendus à www-data. Pour que le compte de déploiement puisse lui
aussi écrire dans ces dossiers : `sudo usermod -aG www-data <compte>`.

## 6. Diagnostiquer un job en `error`

La référence affichée à l'utilisateur (`EXPJOB-AAAAMMJJ-NNNNN`, colonne `error_reference`)
se retrouve dans le rapport complet (classe, message, fichier, ligne, trace) :

```bash
grep -A40 "reference=EXPJOB-20261001-24093" /var/www/extractor/writable/logs/log-*.log
journalctl -u extractor-export-worker | grep -A40 "EXPJOB-20261001-24093"
```

Le rapport est écrit dans les deux. Si le fichier de log n'est pas accessible en écriture
pour le worker, le journal systemd l'a quand même, et `export:doctor` le signale
(`dir.logs.today`).

| Symptôme dans le rapport | Cause | Correction |
|---|---|---|
| `Impossible de créer le fichier d'export CSV.` | `writable/uploads/exports` non inscriptible | §5 |
| `répertoire temporaire OpenSpout … n'est pas accessible en écriture` / `Impossible de créer le répertoire temporaire OpenSpout` | `writable/tmp/openspout_tmp` (ou `export.openSpoutTempPath`) absent / root | §5, puis `systemctl restart` |
| `Dossier temporaire OpenSpout inaccessible` / `… is not a writable folder` | idem | idem |
| `Unknown column ...` | migration non jouée | `sudo -u www-data php spark migrate` |
| `SnapshotUnavailableException` / fichier illisible | snapshot absent ou illisible pour le worker | `php spark snapshot:status`, droits sur `writable/data` |
| `TypeError` / méthode inconnue juste après un déploiement | ancien worker sur l'ancien code | `systemctl restart` (automatique normalement) |
| Service `failed`, `Refus de démarrer en root` | worker lancé à la main en root | utiliser le service ou `sudo -u www-data` |
| Le worker s'arrête aussitôt : `Un autre worker --watch tourne déjà` | autre worker lancé à la main, ou verrou laissé par root | `pgrep -af export:process`, puis `sudo extractor-prepare-writable` |

### OpenSpout (XLSX) en échec

```bash
sudo -u www-data php spark export:doctor | grep -A2 openspout   # chemin, write/delete/subdir tests
grep -E "openSpoutTempPath" /var/www/extractor/.env             # surcharge éventuelle du chemin
ls -la /var/www/extractor/writable/tmp/openspout_tmp            # brouillons restants ? (export_*)
df -h /var/www/extractor/writable                               # ~2x la taille du XLSX pendant l'écriture
grep "OpenSpout" /var/www/extractor/writable/logs/log-$(date +%F).log
```

Un dossier `export_*` récent appartient à un export en cours, il ne faut pas le supprimer.
Au‑delà de 6 h, c'est un orphelin : il est supprimé automatiquement au prochain export.

Si le chemin est déplacé hors de `writable/tmp` (`export.openSpoutTempPath`), son dossier
parent doit être inscriptible par www-data (le préflight le crée et le vérifie). Il faut
alors aussi ajouter ce chemin au script de préparation.

## 7. Développement local (Windows / XAMPP)

Rien de tout cela n'est nécessaire : `writable/tmp/openspout_tmp` est créé à la demande
sous le `writable/` du projet. `php spark export:doctor --fix` crée les répertoires
manquants. Le service systemd et le script `prepare-writable.sh` ne concernent que Linux.

Test du script de préparation (sans root, Linux ou Git Bash) :

```bash
sh tests/deploy/prepare-writable.test.sh
```
