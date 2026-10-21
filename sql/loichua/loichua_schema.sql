-- MariaDB dump 10.19  Distrib 10.4.22-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: tntt_lap_tri
-- ------------------------------------------------------
-- Server version	10.4.22-MariaDB

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
-- Table structure for table `loichua_daily`
--

DROP TABLE IF EXISTS `loichua_daily`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loichua_daily` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ngay` date NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gospel` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reflection` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `feast` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `liturgy_calendar` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `readings_summary` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `liturgical_color` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reading_1` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gospel_ref` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `liturgy_year` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reading_psalm` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reading_alleluia` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ngay` (`ngay`),
  KEY `idx_ngay` (`ngay`)
) ENGINE=InnoDB AUTO_INCREMENT=202 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `liturgy_calendar`
--

DROP TABLE IF EXISTS `liturgy_calendar`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `liturgy_calendar` (
  `ngay` date NOT NULL,
  `feast` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `liturgy_calendar` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `readings_summary` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `liturgical_color` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `liturgy_year` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reading_1_ref` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gospel_ref` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ngay`),
  KEY `idx_liturgy_ngay` (`ngay`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-11 10:09:27
