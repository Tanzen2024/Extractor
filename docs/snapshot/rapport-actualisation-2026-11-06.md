# Rapport — remise en service de l'actualisation quotidienne

Intervention du 2026-11-06, instance de développement `ENEO01LKOU2203`
(`C:\xampp\htdocs\extractor`). La production n'a pas pu être auditée.

## A. État initial

**Architecture réelle.** Les clients ne sont pas dans MySQL. Le chemin des données est :

```
CMS_RFC.TB_CUSTOMERS_LIST (Oracle, tronquée puis rechargée chaque nuit vers 04:32-04:56)
  -> php spark customers:refresh  (seul accès Oracle)
  -> CSV temporaire -> validation -> versions/<id>/ + index DuckDB
  -> bascule atomique de current.json
  -> tableau de bord et exports (lisent la version active, jamais Oracle)
```

MariaDB ne porte que les comptes, l'audit, les jobs d'export et désormais
l'historique des synchronisations.

**Dernière synchronisation réelle.** 2026-10-03, lancée à la main, terminée à 09:32.

| Mesure | Oracle (06/11) | Extractor avant intervention |
|---|---|---|
| Lignes | 3 328 478 | 3 306 937 |
| Clients actifs (4 statuts) | 2 453 111 | 2 433 286 |
| `UPDATED_AT` | 2026-11-06 04:32:26 | 2026-10-03 04:32:44 |

**La colonne « Mis à jour le ».** Ce n'est ni une date de modification client
ni une date de synchronisation : `UPDATED_AT` porte une valeur unique sur toutes
les lignes (MIN = MAX, 1 valeur distincte sur 3,3 M). C'est l'horodatage du
chargement Oracle amont.

## B. Causes racines

| Priorité | Problème | Composant | Preuve | Conséquence | Correction |
|---|---|---|---|---|---|
| Critique | **Aucune planification sur cette instance** | Planificateur Windows, `docs/snapshot/README.md` (« Dev Windows : pas de planification ») | Seule tâche Extractor : le worker d'export. Journal : 5 lancements, tous manuels, aucun après le 03/10 | 34 jours sans actualisation alors qu'Oracle se recharge chaque nuit | Tâche planifiée installée |
| Élevée | La purge aurait supprimé la version du 27/09 à la première réussite | `SnapshotStore::prune`, `keepVersions=2` | Lecture du code | Perte de données, contraire à la règle n° 8 | Marqueur `.retain` |
| Élevée | Aucun historique ni indicateur de fraîcheur | Application | Aucune table, aucun écran | L'arrêt n'a été vu qu'à l'œil | Table, commande, indicateur |
| Élevée | **Mémoire du poste** (constaté pendant l'intervention) | Poste 8 Go, 22,9 Go engagés | 26 321 pages relues/s, 338 Mo disponibles ; index 79 min au lieu de 2 à 4 | Une synchronisation peut dépasser la limite de 4 h | Non résolu, voir section F |
| Moyenne | Le poste dort certains matins | Portable | Aucune activité à 05:xx sur 12 jours sur 35 | Passage de 05:00 manqué | Réveil, rattrapage et relance horaire |
| Moyenne | Une source non rechargée aurait été réextraite et comptée comme réussie | `customers:refresh` | Lecture du code | Fausse réussite, jusqu'à 1 h de charge Oracle | Statut `skipped` |
| Moyenne | Le rattrapage se fiait à l'heure d'extraction, pas au chargement Oracle | `activeExtractedToday` | Lecture du code | Rattrapage sauté à tort | Compare `source_updated_at` |
| Faible | Pas de contrôle d'espace disque avant extraction | `CustomersRefresher` | Saturations passées | Échec tardif | Refus `disk_space` sous 3 Gio |

**Hors cause :** Oracle (joignable, rechargé cette nuit), cron (n'existe pas
sous Windows), les droits (aucune erreur), et le poste éteint (allumé à 05:xx
23 jours sur 35 : une tâche aurait tourné).

**Incrémental impossible.** Oracle est tronqué chaque nuit
(`ALL_TAB_MODIFICATIONS.TRUNCATED=YES`) et n'a aucune date par client. La
stratégie retenue reste complète avec staging, validation et bascule atomique ;
ajoutés, modifiés et retirés sont calculés après coup entre deux versions.

## C. Corrections effectuées

**Créés**
- `app/Database/Migrations/2026-11-06-090000_CreateCustomersSyncRunsTable.php` (additive, appliquée)
- `app/Models/CustomersSyncRunModel.php`
- `app/Services/Refresh/SyncRunRecorder.php` : une panne de MariaDB n'arrête jamais la synchronisation
- `app/Services/Snapshot/SnapshotDiff.php`, `SyncFreshness.php`, `SyncStatusReader.php`
- `app/Commands/CustomersSyncStatus.php`
- `docs/snapshot/extractor/install_customers_refresh_task.ps1`
- `public/assets/js/sync-status.js`, `public/assets/css/sync-status.css`
- `tests/unit/CustomersSyncFreshnessTest.php`, `tests/database/CustomersSyncRunsTest.php`, `tests/js/sync-status.test.js`

**Modifiés**
- `app/Commands/CustomersRefresh.php` (`--scheduled`, historique, comparaison)
- `app/Services/Refresh/CustomersRefresher.php`, `OracleSnapshotExtractor.php` (source inchangée, disque)
- `app/Services/Snapshot/SnapshotStore.php` (`.retain`), `app/Config/Snapshot.php`, `app/Config/Routes.php`
- `app/Controllers/DashboardController.php` (`/dashboard/sync-status`, `/dashboard/sync-history`)
- `app/Views/dashboard/index.php`, `app/Language/{fr,en}/Dashboard.php`, `public/assets/i18n/{fr,en}.json`
- `docs/snapshot/extractor/install_customers_refresh_cron.sh` (`--scheduled`, relance horaire, déclaration de l'heure)
- `docs/snapshot/README.md`, `docs/deploy/production-snapshot-engine.md`, `.env.example`

**Tâche planifiée.** « Extractor - Customers refresh » : 05:00 puis toutes les
heures pendant 17 h, avec `--skip-if-fresh`, réveil du poste, rattrapage, une
seule instance, limite de 4 h.

**Données.** Rien n'a été supprimé. Les versions du 27/09 et du 03/10 sont
conservées (`.retain`, environ 2 Go). Aucune écriture dans Oracle.

## D. Résultats des tests

**Synchronisation réelle lancée par le planificateur (run n° 1)**

| Élément | Valeur |
|---|---|
| Début, fin | 10:05:38, 12:09:39 (123 min 48 s) |
| Extraction | 24 min 53 s |
| Validation | 19 min 42 s |
| Index | environ 79 min (poste saturé) |
| Lus | 3 328 478 (= `COUNT(*)` Oracle) |
| Actifs | 2 453 111 (= comptage Oracle) |
| Ajoutés | 21 541 |
| Modifiés | 2 217 050 |
| Inchangés | 1 089 887 |
| Retirés | 0 |
| Devenus inactifs | 1 970 |
| Doublons | 0 |

Le volume de modifiés couvre 34 jours d'écart ; sa répartition par colonne n'a
pas été analysée.

| Vérification | Moyen | Résultat |
|---|---|---|
| Lancement via le planificateur | Réel | OK |
| Connexion Oracle et MySQL | Réel | OK |
| Nouveaux clients, clients modifiés | Réel | OK (chiffres ci-dessus) |
| Absence de doublons | Réel | OK (0) |
| Relance sans effet (idempotence) | Réel, 12:12 | OK : `SKIPPED`, code 0, version inchangée, aucune ligne d'historique |
| Tableau de bord, comptage | Réel, service | OK : 2 453 111 |
| Filtres, pagination, recherche | Réel, service | OK : DCUD/PREPAID 451 674 ; pages 1 et 2 disjointes ; recherche 1 résultat |
| Export | Réel, flux de lignes | OK : 451 674 = tableau de bord |
| `export:doctor` | Réel | OK |
| Suites automatiques | Réel | 473 PHP (5 ignorés) et 171 JS, 0 échec |
| Interruption, reprise | **Simulé** | OK (tests) ; pas de coupure réelle provoquée |
| Erreurs Oracle, disque | **Simulé** | OK (tests) |
| Indicateur | **Banc** (vrais JS, CSS, catalogue ; réponses simulées) | 3 états corrects, clair et sombre |
| Fenêtre Historique | **Tests JS et contrôleur** | OK ; clic jamais exercé dans un navigateur |
| Page réelle connectée | **Non testé** | Connexion AD indisponible pour moi |
| Cron Linux | **`--dry-run` sous Git Bash** | Lignes correctes, jamais exécuté |
| Déclenchement à 05:00 | **Non observé** | Première occurrence : 07/11 |

## E. Exploitation

| Besoin | Commande |
|---|---|
| Synchronisation manuelle | `php spark customers:refresh` (`--force` : extraire même sans changement Oracle, accepter une baisse de volume) |
| Fraîcheur et historique | `php spark customers:sync-status` (code 0 à jour, 1 en retard ou échec) |
| Journal | `writable/logs/customers_refresh.log` |
| État de la tâche | `Get-ScheduledTaskInfo -TaskName 'Extractor - Customers refresh'` |
| Changer l'heure | `powershell -NoProfile -ExecutionPolicy Bypass -File docs\snapshot\extractor\install_customers_refresh_task.ps1 -At 05:00` |
| Lancer la tâche maintenant | `Start-ScheduledTask -TaskName 'Extractor - Customers refresh'` |
| Retour à la version précédente | `php spark snapshot:rollback` |

**Relance après échec.** Lire la raison, corriger, relancer. Il n'y a rien à
nettoyer : la version active n'a pas changé, et une exécution restée « en cours »
est clôturée en échec par la suivante. La tâche retente seule chaque heure.

**Vérifier que le 05:00 a tourné.** `php spark customers:sync-status` doit
montrer une ligne `scheduled` démarrée vers 05:00, et l'état `fresh`.

**Linux.** Procédure dans `docs/deploy/production-snapshot-engine.md` §5.

## F. Risques et points ouverts

1. **Production non vérifiée.** `10.250.90.169` : SSH filtré, Extractor introuvable
   sur ses ports HTTP. Rien n'y a été constaté ni modifié.
2. **Un poste de travail n'est pas un serveur.** La tâche exige une session
   ouverte et le poste allumé. Sur 8 Go, la réussite dépend des applications
   ouvertes.
3. **Code non commité**, mêlé dans le même répertoire aux travaux de deux autres sessions.
4. **MariaDB n'est pas un service** : à l'arrêt, la synchronisation continue
   mais l'historique ne s'écrit pas ; l'indicateur retombe sur la date de validation.
5. **`.retain`** : 2 Go retenus jusqu'à décision de les libérer.
