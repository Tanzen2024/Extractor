# Worker d'export asynchrone — déploiement Linux

Le worker `php spark export:process --watch` génère les exports CSV/XLSX mis en file
par le tableau de bord (table `export_jobs`). Ce document décrit le déploiement,
le redémarrage et la vérification sur le serveur.

Valeurs supposées ci-dessous, à adapter : projet dans `/var/www/extractor`,
serveur web sous l'utilisateur `www-data`.

## 1. Déployer

```bash
cd /var/www/extractor
git pull
composer install --no-dev --optimize-autoloader   # si composer.json / composer.lock ont changé
sudo -u www-data php spark migrate                 # idempotent : ne fait rien si le schéma est déjà à jour
sudo -u www-data php spark cache:clear

# writable/ doit appartenir à l'utilisateur du serveur web ET du worker
sudo chown -R www-data:www-data writable
sudo find writable -type d -exec chmod 775 {} \;
sudo find writable -type f -exec chmod 664 {} \;
```

## 2. Worker en service systemd (une seule fois)

```bash
sudo cp docs/deploy/extractor-export-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now extractor-export-worker
```

Supprimer toute autre façon de lancer `--watch` (ligne crontab, `nohup`, `screen`…) :
un seul worker `--watch` peut tourner (verrou `writable/export-worker/worker.lock`), les
autres s'arrêtent immédiatement — mais un ancien processus lancé à la main garderait le verrou
avec un vieux code.

```bash
crontab -l | grep export:process ; sudo crontab -u www-data -l | grep export:process
pgrep -af "export:process"
```

## 3. Redémarrer le worker

Après un `git pull`, le worker s'arrête tout seul au plus tard à la fin du job en cours
(il détecte que le code a changé) et systemd le relance. Pour forcer :

```bash
sudo systemctl restart extractor-export-worker
```

Avec l'extension `pcntl`, le job en cours se termine avant l'arrêt ; sans elle, il est
interrompu et sera passé en `error` au prochain démarrage (job `running` sans progression
depuis plus de `--stale-after` secondes, 3600 par défaut).

## 4. Vérifier

```bash
sudo -u www-data php spark export:doctor        # doit finir par "Résultat : OK" ou "WARNING" (code retour 0)
sudo systemctl status extractor-export-worker
sudo journalctl -u extractor-export-worker -n 50
```

Puis lancer un export volumineux depuis le tableau de bord : la barre doit progresser
(`rows_processed` augmente), le fichier se télécharge à la fin.

```sql
SELECT id, status, rows_total, rows_processed, rows_exported, error_reference, started_at, finished_at
FROM export_jobs ORDER BY id DESC LIMIT 5;
```

## 5. Diagnostiquer un job en `error`

La référence affichée à l'utilisateur (`EXPJOB-AAAAMMJJ-NNNNN`, colonne `error_reference`)
se retrouve dans le rapport complet (classe, message, fichier, ligne, trace) :

```bash
grep -A40 "reference=EXPJOB-20261001-24093" writable/logs/log-*.log
journalctl -u extractor-export-worker | grep -A40 "EXPJOB-20261001-24093"
```

Le rapport est écrit dans les deux : si le fichier de log n'est pas accessible en écriture
pour l'utilisateur du worker (CodeIgniter perd alors la ligne sans rien dire), le journal
systemd l'a quand même, et `export:doctor` signale le problème (`dir.logs.today`).

Causes typiques d'un échec immédiat (`started_at` = `finished_at`, `rows_total` NULL) :

| Symptôme dans le rapport | Cause | Correction |
|---|---|---|
| `Impossible de créer le fichier d'export CSV.` | `writable/uploads/exports` non inscriptible par le worker | §1 (chown/chmod), worker sous `www-data` |
| `Unknown column ...` | migration non jouée | `php spark migrate` (le worker refuse désormais de prendre des jobs tant que le schéma est incomplet) |
| `SnapshotUnavailableException` / fichier illisible | snapshot absent ou illisible pour le worker | `php spark snapshot:status`, droits sur `writable/data` |
| `TypeError` / méthode inconnue juste après un déploiement | ancien worker `--watch` sur l'ancien code | `systemctl restart` (automatique désormais) |
