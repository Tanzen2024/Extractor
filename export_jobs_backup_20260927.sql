-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: bscd_data_tools
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
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `export_jobs`
--

LOCK TABLES `export_jobs` WRITE;
/*!40000 ALTER TABLE `export_jobs` DISABLE KEYS */;
INSERT INTO `export_jobs` VALUES (20,'4b3356e7-6575-4a1e-9a05-754aec03231e','admin','xlsx','{\"regions\":[\"DRSOM\"]}','Région : DRSOM','pending',277638,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-29 18:18:17','2026-08-29 18:18:17'),(21,'cda80f72-2348-447f-bf39-d5840bf3c686','hugues.nwameh','csv','[]','Aucun filtre (tout le référentiel)','pending',3301629,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 10:28:07','2026-09-25 10:28:07'),(22,'59e89a17-cca2-4e6c-a3df-1847a7e70233','hugues.nwameh','csv','[]','Aucun filtre (tout le référentiel)','pending',3301629,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 11:56:14','2026-09-25 11:56:14'),(23,'969832f8-6a18-445e-9dcf-0226179f9283','hugues.nwameh','csv','[]','Aucun filtre (tout le référentiel)','pending',3302841,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-27 20:19:25','2026-09-27 20:19:25'),(24,'67b17900-ad86-4b6b-bee5-c7bef5dc9782','hugues.nwameh','csv','[]','Aucun filtre (tout le référentiel)','pending',3302841,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-27 21:13:28','2026-09-27 21:13:28');
/*!40000 ALTER TABLE `export_jobs` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-27 21:25:37
