-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: extractor_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `app_audit_logs`
--

DROP TABLE IF EXISTS `app_audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `module` varchar(60) DEFAULT NULL,
  `target_type` varchar(60) DEFAULT NULL,
  `target_id` varchar(60) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `action` (`action`),
  KEY `username` (`username`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_audit_logs`
--

LOCK TABLES `app_audit_logs` WRITE;
/*!40000 ALTER TABLE `app_audit_logs` DISABLE KEYS */;
INSERT INTO `app_audit_logs` VALUES (1,NULL,'admin','LOGIN_FAILURE','auth',NULL,NULL,'Echec bind Active Directory','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 19:05:27'),(2,NULL,'admin','LOGIN_FAILURE','auth',NULL,NULL,'Echec bind Active Directory','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 19:06:03'),(3,NULL,'hugues.nwameh','LOGIN_FAILURE','auth',NULL,NULL,'Echec bind Active Directory','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 19:07:43'),(4,NULL,'hugues.nwameh','LOGIN_FAILURE','auth',NULL,NULL,'Echec bind Active Directory','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 19:13:52'),(5,NULL,'admin','LOGIN_FAILURE','auth',NULL,NULL,'Echec bind Active Directory','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 19:26:10'),(6,NULL,'hugues.nwameh','LOGIN_FAILURE','auth',NULL,NULL,'Compte AD valide mais absent de app_users','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 19:26:21'),(7,NULL,'hugues.nwameh@camlight.cm','LOGIN_FAILURE','auth',NULL,NULL,'Echec bind Active Directory','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 19:26:33'),(8,3,'hugues.nwameh','LOGIN_SUCCESS','auth',NULL,NULL,'Roles: ADMIN','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 19:35:36'),(9,1,'demo','LOGIN_SUCCESS','auth',NULL,NULL,'test format date','10.0.0.1',NULL,'2026-09-03 14:30:05'),(11,3,'hugues.nwameh','USER_CREATED','users','app_user','4','Utilisateur sandrine.njoh cree','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 20:02:44'),(12,3,'hugues.nwameh','USER_CREATED','users','app_user','5','Utilisateur Aaron.Bayig cree','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 20:03:54'),(13,3,'hugues.nwameh','USER_UPDATED','users','app_user','5','Roles: aucun','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 20:04:16'),(14,3,'hugues.nwameh','USER_UPDATED','users','app_user','5','Roles: ADMIN','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 20:04:30'),(15,3,'hugues.nwameh','USER_UPDATED','users','app_user','4','Roles: ADMIN','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 20:04:38'),(16,3,'hugues.nwameh','LOGIN_SUCCESS','auth',NULL,NULL,'Roles: ADMIN','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 22:08:06'),(17,3,'hugues.nwameh','LOGOUT','auth',NULL,NULL,NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 22:31:35'),(18,3,'hugues.nwameh','LOGIN_SUCCESS','auth',NULL,NULL,'Roles: ADMIN','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 22:31:44');
/*!40000 ALTER TABLE `app_audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_permissions`
--

DROP TABLE IF EXISTS `app_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_permissions` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(80) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_permissions`
--

LOCK TABLES `app_permissions` WRITE;
/*!40000 ALTER TABLE `app_permissions` DISABLE KEYS */;
INSERT INTO `app_permissions` VALUES (1,'DASHBOARD_VIEW','Consulter le tableau de bord','Accès au dashboard CUSTOMERS_LIST et aux extractions.',1,'2026-09-03 18:52:07','2026-09-03 18:52:07'),(2,'USER_VIEW','Consulter les utilisateurs','Voir la liste des utilisateurs applicatifs.',1,'2026-09-03 18:52:07','2026-09-03 18:52:07'),(3,'USER_CREATE','Créer un utilisateur','Autoriser un compte AD existant à accéder à l\'application.',1,'2026-09-03 18:52:07','2026-09-03 18:52:07'),(4,'USER_EDIT','Modifier un utilisateur','Modifier le nom affiché et les rôles.',1,'2026-09-03 18:52:07','2026-09-03 18:52:07'),(5,'USER_DISABLE','Activer / désactiver un utilisateur','Couper ou rétablir l\'accès applicatif.',1,'2026-09-03 18:52:07','2026-09-03 18:52:07'),(6,'ROLE_VIEW','Consulter les rôles','Voir la liste des rôles.',1,'2026-09-03 18:52:07','2026-09-03 18:52:07'),(7,'ROLE_CREATE','Créer un rôle','Ajouter un rôle applicatif.',1,'2026-09-03 18:52:07','2026-09-03 18:52:07'),(8,'ROLE_EDIT','Modifier un rôle','Modifier un rôle et ses permissions.',1,'2026-09-03 18:52:07','2026-09-03 18:52:07'),(9,'PERMISSION_VIEW','Consulter les permissions','Voir le catalogue des permissions.',1,'2026-09-03 18:52:07','2026-09-03 18:52:07'),(10,'PERMISSION_EDIT','Modifier les permissions','Activer / désactiver une permission.',1,'2026-09-03 18:52:07','2026-09-03 18:52:07'),(11,'AUDIT_VIEW','Consulter l\'audit','Accès au journal d\'audit de sécurité.',1,'2026-09-03 18:52:07','2026-09-03 18:52:07');
/*!40000 ALTER TABLE `app_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_role_permissions`
--

DROP TABLE IF EXISTS `app_role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_role_permissions` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int(11) unsigned NOT NULL,
  `permission_id` int(11) unsigned NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_id_permission_id` (`role_id`,`permission_id`),
  KEY `app_role_permissions_permission_id_foreign` (`permission_id`),
  CONSTRAINT `app_role_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `app_permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `app_role_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `app_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_role_permissions`
--

LOCK TABLES `app_role_permissions` WRITE;
/*!40000 ALTER TABLE `app_role_permissions` DISABLE KEYS */;
INSERT INTO `app_role_permissions` VALUES (1,1,11,'2026-09-03 18:52:07'),(2,1,1,'2026-09-03 18:52:07'),(3,1,10,'2026-09-03 18:52:07'),(4,1,9,'2026-09-03 18:52:07'),(5,1,7,'2026-09-03 18:52:07'),(6,1,8,'2026-09-03 18:52:07'),(7,1,6,'2026-09-03 18:52:07'),(8,1,3,'2026-09-03 18:52:07'),(9,1,5,'2026-09-03 18:52:07'),(10,1,4,'2026-09-03 18:52:07'),(11,1,2,'2026-09-03 18:52:07'),(12,2,1,'2026-09-03 18:52:07'),(13,3,1,'2026-09-03 18:52:07'),(14,4,1,'2026-09-03 18:52:07');
/*!40000 ALTER TABLE `app_role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_roles`
--

DROP TABLE IF EXISTS `app_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_roles` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_roles`
--

LOCK TABLES `app_roles` WRITE;
/*!40000 ALTER TABLE `app_roles` DISABLE KEYS */;
INSERT INTO `app_roles` VALUES (1,'ADMIN','Administrateur','Accès complet à l\'application et à l\'administration.',1,'2026-09-03 18:52:07','2026-09-03 18:52:07'),(2,'DATA_ANALYST','Analyste de données','Lance des extractions, exporte et consulte le tableau de bord.',1,'2026-09-03 18:52:07','2026-09-03 18:52:07'),(3,'USER','Utilisateur','Accès au tableau de bord.',1,'2026-09-03 18:52:07','2026-09-03 18:52:07'),(4,'VIEWER','Lecteur','Consultation du tableau de bord uniquement.',1,'2026-09-03 18:52:07','2026-09-03 18:52:07');
/*!40000 ALTER TABLE `app_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_user_roles`
--

DROP TABLE IF EXISTS `app_user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_user_roles` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `role_id` int(11) unsigned NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id_role_id` (`user_id`,`role_id`),
  KEY `app_user_roles_role_id_foreign` (`role_id`),
  CONSTRAINT `app_user_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `app_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `app_user_roles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_user_roles`
--

LOCK TABLES `app_user_roles` WRITE;
/*!40000 ALTER TABLE `app_user_roles` DISABLE KEYS */;
INSERT INTO `app_user_roles` VALUES (1,1,1,'2026-09-03 18:52:07'),(3,3,1,'2026-09-03 19:34:47'),(5,5,1,'2026-09-03 20:04:30'),(6,4,1,'2026-09-03 20:04:38');
/*!40000 ALTER TABLE `app_user_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_users`
--

DROP TABLE IF EXISTS `app_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `display_name` varchar(190) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_users`
--

LOCK TABLES `app_users` WRITE;
/*!40000 ALTER TABLE `app_users` DISABLE KEYS */;
INSERT INTO `app_users` VALUES (1,'admin','Administrateur',1,'2026-09-03 18:52:07','2026-09-03 18:52:07'),(3,'hugues.nwameh','Hugues Roland NWAMEH',1,'2026-09-03 19:34:47','2026-09-03 19:35:36'),(4,'sandrine.njoh',NULL,1,'2026-09-03 20:02:44','2026-09-03 20:02:44'),(5,'Aaron.Bayig','Aaron Wiliam Bayig Imandi',1,'2026-09-03 20:03:54','2026-09-03 20:03:54');
/*!40000 ALTER TABLE `app_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers_list_snapshots`
--

DROP TABLE IF EXISTS `customers_list_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers_list_snapshots` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'running',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `duration_seconds` int(10) unsigned DEFAULT NULL,
  `row_count` int(10) unsigned DEFAULT NULL,
  `distinct_client_count` int(10) unsigned DEFAULT NULL,
  `source` varchar(60) NOT NULL DEFAULT 'CMS_RFC',
  `error_reference` varchar(40) DEFAULT NULL,
  `totals` longtext DEFAULT NULL,
  `dimensions` longtext DEFAULT NULL,
  `cube` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `status_finished_at` (`status`,`finished_at`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers_list_snapshots`
--

LOCK TABLES `customers_list_snapshots` WRITE;
/*!40000 ALTER TABLE `customers_list_snapshots` DISABLE KEYS */;
INSERT INTO `customers_list_snapshots` VALUES (1,'b2849f86-020e-412b-92f9-da1dc2766e00','error','2026-08-26 18:05:52','2026-08-26 18:10:54',302,NULL,NULL,'CMS_RFC','DASH-20260826-17973',NULL,NULL,NULL,'2026-08-26 18:05:52','2026-08-26 18:10:54'),(4,'245cc363-80c9-4bfa-abf9-bd0aeea4a339','running','2026-08-29 10:43:37',NULL,NULL,NULL,NULL,'CMS_RFC',NULL,NULL,NULL,NULL,'2026-08-29 10:43:37','2026-08-29 10:43:37');
/*!40000 ALTER TABLE `customers_list_snapshots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `export_jobs`
--

DROP TABLE IF EXISTS `export_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `export_jobs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `requested_by` varchar(100) DEFAULT NULL,
  `format` varchar(8) NOT NULL,
  `filters` longtext DEFAULT NULL,
  `filters_label` varchar(500) DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `row_count` int(10) unsigned DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_name` varchar(150) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `error_reference` varchar(40) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `status_id` (`status`,`id`),
  KEY `requested_by` (`requested_by`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `export_jobs`
--

LOCK TABLES `export_jobs` WRITE;
/*!40000 ALTER TABLE `export_jobs` DISABLE KEYS */;
INSERT INTO `export_jobs` VALUES (20,'4b3356e7-6575-4a1e-9a05-754aec03231e','admin','xlsx','{\"regions\":[\"DRSOM\"]}','Région : DRSOM','pending',277638,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-29 18:18:17','2026-08-29 18:18:17');
/*!40000 ALTER TABLE `export_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(255) NOT NULL,
  `class` varchar(255) NOT NULL,
  `group` varchar(255) NOT NULL,
  `namespace` varchar(255) NOT NULL,
  `time` int(11) NOT NULL,
  `batch` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'2026-08-26-122812','App\\Database\\Migrations\\CreateModulesTable','default','App',1787747374,1),(2,'2026-08-26-122812','App\\Database\\Migrations\\CreateRolesTable','default','App',1787747374,1),(3,'2026-08-26-122812','App\\Database\\Migrations\\CreateUsersTable','default','App',1787747374,1),(4,'2026-08-26-122813','App\\Database\\Migrations\\CreateToolsTable','default','App',1787747374,1),(5,'2026-08-26-141252','App\\Database\\Migrations\\PruneRemovedModules','default','App',1787753601,2),(6,'2026-08-26-142501','App\\Database\\Migrations\\PruneMraModule','default','App',1787754322,3),(7,'2026-08-26-150017','App\\Database\\Migrations\\AddCustomersListTool','default','App',1787756629,4),(8,'2026-08-26-155402','App\\Database\\Migrations\\SetCustomersListQuery','default','App',1787759758,5),(9,'2026-08-26-170000','App\\Database\\Migrations\\CreateCustomersListSnapshotsTable','default','App',1787763246,6),(10,'2026-08-26-190348','App\\Database\\Migrations\\SyncCustomersListQuery','default','App',1787771184,7),(11,'2026-08-29-120000','App\\Database\\Migrations\\CreateExportJobsTable','default','App',1788001453,8),(12,'2026-09-03-190000','App\\Database\\Migrations\\CreateAppUsersTable','default','App',1788457927,9),(13,'2026-09-03-190001','App\\Database\\Migrations\\CreateAppRolesTable','default','App',1788457927,9),(14,'2026-09-03-190002','App\\Database\\Migrations\\CreateAppPermissionsTable','default','App',1788457927,9),(15,'2026-09-03-190003','App\\Database\\Migrations\\CreateAppUserRolesTable','default','App',1788457927,9),(16,'2026-09-03-190004','App\\Database\\Migrations\\CreateAppRolePermissionsTable','default','App',1788457927,9),(17,'2026-09-03-190005','App\\Database\\Migrations\\CreateAppAuditLogsTable','default','App',1788457927,9);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `modules`
--

DROP TABLE IF EXISTS `modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `modules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `code` varchar(40) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `icon` varchar(60) NOT NULL DEFAULT 'fa-solid fa-layer-group',
  `color` varchar(20) NOT NULL DEFAULT 'primary',
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `modules`
--

LOCK TABLES `modules` WRITE;
/*!40000 ALTER TABLE `modules` DISABLE KEYS */;
INSERT INTO `modules` VALUES (1,'b65b8fe4-b04d-4c41-aef7-6df6fe5f91e6','CMS','CMS',NULL,'fa-solid fa-database','primary',0,1,'2026-08-26 13:49:06','2026-08-26 13:49:06');
/*!40000 ALTER TABLE `modules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'ADMIN','Administrateur','Accès complet à l\'application et à l\'administration.','2026-08-26 13:49:06','2026-08-26 13:49:06'),(2,'DATA_ANALYST','Analyste de données','Peut lancer des extractions, exporter et consulter l\'historique.','2026-08-26 13:49:06','2026-08-26 13:49:06'),(3,'USER','Utilisateur','Accès uniquement aux outils qui lui sont autorisés.','2026-08-26 13:49:06','2026-08-26 13:49:06'),(4,'VIEWER','Lecteur','Consultation uniquement, sans export.','2026-08-26 13:49:06','2026-08-26 13:49:06');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tools`
--

DROP TABLE IF EXISTS `tools`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tools` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `module_id` int(10) unsigned NOT NULL,
  `code` varchar(60) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `icon` varchar(60) NOT NULL DEFAULT 'fa-regular fa-file-lines',
  `route` varchar(150) DEFAULT NULL,
  `query_definition` text DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `module_id_code` (`module_id`,`code`),
  KEY `module_id` (`module_id`),
  CONSTRAINT `tools_module_id_foreign` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tools`
--

LOCK TABLES `tools` WRITE;
/*!40000 ALTER TABLE `tools` DISABLE KEYS */;
INSERT INTO `tools` VALUES (1,'0209488f-bfb7-4951-ac54-0219f2c92027',1,'LISTE_UTILISATEURS','Liste des utilisateurs',NULL,'fa-regular fa-file-lines','extractions/cms/liste_utilisateurs',NULL,0,0,'2026-08-26 13:49:06','2026-08-26 13:49:06'),(2,'c57aee03-6155-4c9d-bad6-737486fe6b18',1,'EXTRACTION_ASC','Extraction ASC',NULL,'fa-regular fa-file-lines','extractions/cms/extraction_asc',NULL,1,0,'2026-08-26 13:49:06','2026-08-26 13:49:06'),(3,'466341f2-1474-420f-adc2-c01008febdb7',1,'ANNULATION_BT_MT','Annulation BT et MT',NULL,'fa-regular fa-file-lines','extractions/cms/annulation_bt_mt',NULL,2,0,'2026-08-26 13:49:06','2026-08-26 13:49:06'),(4,'69765863-70e4-48e5-8f97-fae0f387401c',1,'ACI_INTEGRE','ACI intégré',NULL,'fa-regular fa-file-lines','extractions/cms/aci_integre',NULL,3,0,'2026-08-26 13:49:06','2026-08-26 13:49:06'),(18,'776e2bbf-92f9-4aa9-99ac-89a1940cd0b6',1,'CUSTOMERS_LIST','Customers List',NULL,'fa-regular fa-address-card','extractions/cms/customers_list','SELECT\r\n    REGION,\r\n    DIVISION,\r\n    AGENCE,\r\n    COD_UNICOM,\r\n    COD_CLI,\r\n    CONTRACT,\r\n    STATUS,\r\n    METER_NO,\r\n    CUST_NAME,\r\n    PHONE_NUMBERS,\r\n    E_MAIL,\r\n    REF_GEO,\r\n    DATE_AB,\r\n    DATE_RESILIATION,\r\n    VOLTAGE,\r\n    SEGMENT_TRESOR,\r\n    METER,\r\n    NIU_RIGHT,\r\n    NIU_QC,\r\n    LAST_VC_DATE,\r\n    SEGMENT_RFM_2,\r\n    POSTPAID_PROFILE_DATE,\r\n    SEGMENTATION\r\nFROM CMS_RFC.TB_CUSTOMERS_LIST',0,1,'2026-08-26 16:03:49','2026-08-26 16:03:49');
/*!40000 ALTER TABLE `tools` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  `username` varchar(60) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `username` (`username`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'2aff8845-5679-4688-9ef3-64fc323c5a55',1,'admin',NULL,'$2y$10$pfCcfedXS3t896MzmMqmLOyEYBhLBrhpoXd0kL0Z3DYWad5eNi39i','Administrateur BSCD',1,'2026-09-03 21:50:42','2026-08-26 13:49:06','2026-09-03 21:50:42',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-03 23:18:39
