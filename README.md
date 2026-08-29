# MARKETING (MI)

Plateforme interne d'extraction de données, spécialisée **CMS Data Extraction**.
Elle donne aux utilisateurs autorisés un accès aux outils du module CMS qui
interrogent la base Oracle `cmsprod` en lecture seule, avec filtres,
prévisualisation, export Excel/CSV, historique et audit.

L'architecture (modules/outils en base, moteur d'extraction générique à venir)
reste multi-module : un nouveau module métier pourrait être réactivé plus tard
sans changement de code, uniquement via le catalogue en base.

Construite avec CodeIgniter 4 (PHP 8.2+), une base MySQL/MariaDB locale pour les données
applicatives, et Oracle (OCI8) comme source de données métier.

## Périmètre fonctionnel

Seul le module **CMS** est actif. SMARTCASH, ICN CASHING, FACTURATION, POWERNET
et MRA ont été retirés du périmètre métier (migrations
`PruneRemovedModules` puis `PruneMraModule` dans
`app/Database/Migrations/`) : aucune donnée, route, outil ou permission liée à
ces modules ne subsiste. L'architecture reste générique — un futur module se
rattache via le catalogue `modules`/`tools` en base, sans code dédié.

## État du projet

Développement par phases. Voir la section "Avancement" ci-dessous pour ce qui est
livré et ce qui reste à faire.

## Prérequis

- PHP 8.2+ avec l'extension `oci8` activée
- Oracle Instant Client installé, avec `TNS_ADMIN` pointant vers un `tnsnames.ora`
  contenant l'alias configuré dans `.env` (`cmsprod` par défaut)
- MySQL/MariaDB (XAMPP fonctionne très bien)
- Composer
- Node.js + npm (uniquement pour re-générer les assets front-end vendorisés,
  voir "Assets front-end")

## Installation

1. Cloner le dépôt puis installer les dépendances PHP :

   ```
   composer install
   ```

2. Copier le fichier d'environnement d'exemple et le compléter :

   ```
   cp .env.example .env
   ```

   Renseigner au minimum :
   - `database.default.*` : connexion à la base MySQL locale de l'application
   - `database.oracle.*` : DSN (alias TNS), utilisateur et mot de passe Oracle
   - `app.baseURL` : URL de base de l'application

   **Ne jamais committer le fichier `.env`** (il est dans `.gitignore`). Le mot de
   passe Oracle ne doit jamais apparaître ailleurs que dans ce fichier.

3. Générer la clé de chiffrement de l'application :

   ```
   php spark key:generate
   ```

4. Créer la base MySQL locale (nom au choix, ex. `bscd_data_tools`), puis lancer
   les migrations et les données de démarrage :

   ```
   php spark migrate --all
   php spark db:seed DatabaseSeeder
   ```

   Le seeder crée un compte administrateur : identifiant `admin`, mot de passe
   affiché dans la sortie de la commande. **À changer immédiatement** une fois
   la gestion des utilisateurs disponible (phase 2).

5. Servir l'application via Apache (XAMPP) en pointant sur `public/`, ou en
   développement rapide :

   ```
   php spark serve
   ```

## Configuration Oracle

La connexion Oracle est isolée dans `app/Config/Oracle.php`, qui lit exclusivement
les variables d'environnement `database.oracle.dsn`, `database.oracle.username`,
`database.oracle.password`. Le DSN est un alias résolu via `tnsnames.ora`
(variable d'environnement système `TNS_ADMIN`) — ce n'est pas une chaîne de
connexion en dur.

Une page d'administration (`/admin/oracle`, réservée aux comptes ADMIN) permet de
tester la connexion : elle affiche uniquement "connexion réussie" ou "connexion
échouée" à l'utilisateur, jamais le mot de passe ni le détail technique de
l'erreur (celui-ci est écrit dans `writable/logs/`).

## Assets front-end

L'application est pensée pour un réseau d'entreprise potentiellement sans accès
direct aux CDN publics : Bootstrap, AdminLTE, Font Awesome et jQuery sont donc
vendorisés localement sous `public/assets/vendor/`, committés dans le dépôt (pas
de build à faire pour lancer l'application).

Pour les régénérer (mise à jour de version, par exemple) :

```
mkdir -p .vendor-src && cd .vendor-src
npm init -y
npm install admin-lte@3.2.0 bootstrap@4.6.2 jquery@3.7.1 @fortawesome/fontawesome-free@6.5.2
# puis copier les fichiers dist/ nécessaires dans public/assets/vendor/
```

Le comportement JS de la sidebar (repli, sous-menus) est réimplémenté en une
vingtaine de lignes dans `public/assets/js/app.js` plutôt que de dépendre du
bundle `adminlte.js` complet.

## Architecture

```
app/
├── Controllers/
│   ├── AuthController.php          Connexion / déconnexion
│   ├── DashboardController.php     Tableau de bord dynamique
│   ├── ExtractionController.php    Résolution outil -> page (placeholder avant phase 3)
│   └── Admin/
│       └── OracleController.php    Test de connexion Oracle
├── Config/
│   └── Oracle.php                  Configuration Oracle dédiée (jamais de secret en dur)
├── Filters/
│   └── AuthFilter.php              Protège toutes les routes sauf /login
├── Helpers/
│   └── bscd_helper.php             UUID, sidebar dynamique, référence d'erreur
├── Models/
│   ├── UserModel.php, RoleModel.php, ModuleModel.php, ToolModel.php
├── Database/
│   ├── Migrations/                 roles, users, modules, tools
│   └── Seeds/                      Rôles, admin, catalogue modules/outils
└── Views/
    ├── layout/ (main.php, guest.php)
    ├── auth/, dashboard/, admin/oracle/, extraction/
```

Le moteur générique d'extraction (Service/OracleExtractionService/ExportService),
les tables `extraction_definitions`, `extraction_logs`, `audit_logs`, et
l'administration complète (utilisateurs, rôles, modules, outils, définitions
d'extraction) arrivent dans les phases suivantes — voir "Avancement".

## Ajouter un nouvel outil (modules/outils déjà existants)

Actuellement (phase 1), un outil se déclare en base :

```sql
INSERT INTO tools (uuid, module_id, code, name, route, display_order)
VALUES (UUID(), <module_id>, 'MON_OUTIL', 'Mon outil', 'extractions/<module_code>/mon_outil', 10);
```

Il apparaît alors automatiquement dans le dashboard et la sidebar (aucune ligne de
code à modifier). À partir de la phase 3, l'outil pourra aussi être rattaché à une
définition de requête Oracle paramétrée sans toucher au contrôleur générique.

## Avancement

- [x] Phase 1 — Squelette CI4, configuration, layout (sidebar/header dynamiques),
      authentification, connexion Oracle testable, connexion base locale, dashboard
      dynamique connecté à MySQL.
- [ ] Phase 2 — Administration utilisateurs/rôles/permissions/modules/outils (CRUD)
- [ ] Phase 3 — Moteur générique d'extraction Oracle
- [x] Phase 4 — Dashboard analytique Customer Data : filtres cascadés Région→Division→Agence,
      4 KPI, 4 graphes, table paginée server-side, tout branché en direct sur Oracle (cache court)
- [x] Phase 5 — Export Excel/CSV lié aux filtres du dashboard (streaming : fputcsv pour le CSV,
      OpenSpout pour le XLSX ; gros volumes via job asynchrone `spark export:process`)
- [ ] Phase 6 — Historique des extractions + audit
- [ ] Phase 7 — Administration des définitions d'extraction + catalogue Oracle
- [ ] Phase 8 — Tests, sécurité, optimisation, documentation finale

## Sécurité

- Mots de passe hashés (`password_hash`/`password_verify`), jamais en clair.
- CSRF activé globalement (filtre `csrf` sur toutes les routes).
- Toutes les pages (hors `/login`) exigent une session active (filtre `auth`).
- Les requêtes Oracle utilisent systématiquement des paramètres bindés (aucune
  concaténation de valeurs utilisateur dans le SQL) — appliqué dès la phase 3.
- Les erreurs techniques (Oracle ou autres) ne sont jamais montrées telles
  quelles à l'utilisateur ; une référence courte est affichée et le détail va
  dans les logs applicatifs.
