# Déploiement en production — snapshot actif + index DuckDB

Serveur : **10.250.90.169**, application `/var/www/extractor`, utilisateur
`www-data`, worker `extractor-export-worker.service`.

Ce que le déploiement change : filtres, KPI, graphiques, segmentations,
tableau, comptage et exports lisent tous le **snapshot actif** (CSV versionné
+ son index DuckDB) ; Oracle n'est plus lu que par `customers:refresh`
(05:00, rattrapage 06:00). Voir `docs/snapshot/README.md`.

Le code n'est pas commité : il est livré par un **paquet** construit sur le
poste de développement (`writable/data/deploy/extractor-snapshot-engine-*.tgz`,
avec son SHA-256), appliqué par `docs/deploy/deploy_snapshot_engine.sh` qui
refuse un serveur qui n'est pas au commit de base `b642811`.

## 0. Prérequis (poste avec accès SSH au serveur)

```
scp extractor-snapshot-engine-AAAAMMJJ_HHMM.tgz <compte>@10.250.90.169:/tmp/
scp docs/deploy/deploy_snapshot_engine.sh       <compte>@10.250.90.169:/tmp/
ssh <compte>@10.250.90.169
sha256sum /tmp/extractor-snapshot-engine-*.tgz        # = empreinte fournie avec le paquet
```

DuckDB (si `command -v duckdb` ne répond rien) — binaire officiel, une fois :

```
uname -m          # x86_64 -> amd64, aarch64 -> aarch64
cd /tmp && curl -fsSLO https://github.com/duckdb/duckdb/releases/download/v1.1.3/duckdb_cli-linux-amd64.zip
```

## 1. Inspection (lecture seule)

```
cd /tmp && sudo sh deploy_snapshot_engine.sh --check --package /tmp/extractor-snapshot-engine-*.tgz
```

Contrôle : commit déployé et arbre propre, PHP ≥ 8.2 et extensions (oci8
pour le refresh), espace disque ≥ 5 Go, architecture, `duckdb` exécutable par
`www-data`, worker, `snapshot:status`, `current.json`, CSV actif lisible,
index présent ou non, crons existants, intégrité du paquet. **Rien n'est modifié.**

Si le serveur n'est pas à `b642811` : `cd /var/www/extractor && sudo -u <compte de déploiement> git pull`
jusqu'à `b642811` d'abord (le script s'arrête sinon : pas d'état partiel).

## 2. Déploiement

```
sudo sh deploy_snapshot_engine.sh --apply --package /tmp/extractor-snapshot-engine-*.tgz \
     [--install-duckdb /tmp/duckdb_cli-linux-amd64.zip]
```

Enchaîne, en s'arrêtant à la première erreur :

1. sauvegarde dans `/var/backups/extractor/<horodatage>/` : fichiers touchés,
   `current.json`, table `export_jobs` (mysqldump), cron et crontab `www-data` ;
2. arrêt **propre** du worker (le job en cours se termine) ;
3. application du paquet (35 fichiers, 5 suppressions de code mort) ;
4. `php spark migrate` (ajoute `export_jobs.snapshot_version`, additif, idempotent) ;
5. `php spark snapshot:index` : index DuckDB du snapshot actif, construit
   depuis **son** CSV (≈ 2 à 4 min, ~180 Mo), nombre de lignes vérifié ;
6. `php spark dashboard:warm` (lit l'index, jamais Oracle) ;
7. `export:doctor --preflight` puis `export:doctor` ;
8. cron unique `/etc/cron.d/extractor-customers-refresh` : 05:00 + rattrapage
   06:00 (`--skip-if-fresh`), puis `dashboard:warm` ; refus si un autre cron
   `customers:refresh` existe ;
9. redémarrage du worker, contrôle `active (running)` et du journal.

## 3. Vérifications après déploiement

```
sudo -u www-data php spark snapshot:status
sudo -u www-data php spark export:doctor            # snapshot, snapshot.index, db.export_jobs : OK
systemctl status extractor-export-worker --no-pager
journalctl -u extractor-export-worker -n 50 --no-pager
cat /etc/cron.d/extractor-customers-refresh
```

Zéro Oracle pendant les parcours utilisateur — dans un second terminal,
pendant que l'on utilise le tableau de bord (filtres simples et combinés,
KPI, graphiques, segmentations, tableau : tri, pages, recherche) et lance un
export CSV puis XLSX :

```
watch -n1 "ss -tnp '( dport = :1521 )' | grep -E 'apache|php-fpm|php' || echo 'aucune connexion Oracle'"
```

Exports : la ligne `export_jobs` du job doit porter `snapshot_version` = la
version active au moment du clic :

```
mysql -e "SELECT id, format, status, snapshot_version, rows_total, rows_processed, rows_exported, file_size FROM export_jobs ORDER BY id DESC LIMIT 5" <base>
```

Premier refresh réel (hors heures de pointe ; ≈ 5 min la nuit, jusqu'à 1 h en
journée selon la charge Oracle) :

```
sudo -u www-data php spark customers:refresh ; echo "code=$?"     # 0 = nouvelle version active
tail -n 30 /var/www/extractor/writable/logs/customers_refresh.log
sudo -u www-data php spark snapshot:status                         # nouvelle version + index
```

Échec (code 1) = ancienne version toujours active (comportement attendu,
rien à réparer côté utilisateurs).

## 4. Rollback

```
sudo sh deploy_snapshot_engine.sh --rollback /var/backups/extractor/<horodatage>
```

Remet les fichiers de code et le cron d'avant, retire les fichiers ajoutés,
redémarre le worker. Les snapshots ne sont pas touchés (les index `.duckdb`
sont ignorés par l'ancien code) ; la colonne `snapshot_version` est laissée
(additive). Données : `php spark snapshot:rollback` réactive la version
précédente, avec son index.
