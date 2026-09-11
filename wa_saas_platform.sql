-- MySQL dump 10.13  Distrib 8.0.46, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: wa_saas_platform
-- ------------------------------------------------------
-- Server version	8.0.41

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `accounts`
--

DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `brand_accent_color` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `primary_phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `is_platform_device` tinyint(1) NOT NULL DEFAULT '0',
  `api_rate_limit_per_minute` int unsigned NOT NULL DEFAULT '60',
  `allowed_modules` json DEFAULT NULL,
  `max_users_limit` int unsigned DEFAULT NULL,
  `module_assignment` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'both',
  `allow_facebook` tinyint(1) NOT NULL DEFAULT '0',
  `allow_instagram` tinyint(1) NOT NULL DEFAULT '0',
  `allow_linkedin` tinyint(1) NOT NULL DEFAULT '0',
  `allow_youtube` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `accounts_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts`
--

LOCK TABLES `accounts` WRITE;
/*!40000 ALTER TABLE `accounts` DISABLE KEYS */;
INSERT INTO `accounts` VALUES (1,'Demo Account',NULL,NULL,'+91 90000 00000','active',0,60,'[\"dashboard\", \"analytics\", \"billing\", \"team_management\", \"whatsapp_setup\", \"send_alert\", \"chatbot\", \"templates\", \"device_settings\", \"social_accounts\", \"meta_ads\", \"lead_crm\", \"social_inbox\", \"comment_automation\", \"reports\"]',NULL,'both',0,0,0,0,'2026-09-09 01:32:59','2026-09-11 02:46:50'),(2,'Super Admin — WhatsApp Test Device',NULL,NULL,NULL,'active',1,60,NULL,NULL,'both',0,0,0,0,'2026-09-10 01:15:22','2026-09-10 01:15:22');
/*!40000 ALTER TABLE `accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ad_campaign_daily_metrics`
--

DROP TABLE IF EXISTS `ad_campaign_daily_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ad_campaign_daily_metrics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `ad_campaign_id` bigint unsigned NOT NULL,
  `metric_date` date NOT NULL,
  `spend` decimal(10,2) NOT NULL DEFAULT '0.00',
  `impressions` int unsigned NOT NULL DEFAULT '0',
  `leads` int unsigned NOT NULL DEFAULT '0',
  `cpl` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ad_campaign_daily_metrics_ad_campaign_id_metric_date_unique` (`ad_campaign_id`,`metric_date`),
  KEY `ad_campaign_daily_metrics_account_id_metric_date_index` (`account_id`,`metric_date`),
  CONSTRAINT `ad_campaign_daily_metrics_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ad_campaign_daily_metrics_ad_campaign_id_foreign` FOREIGN KEY (`ad_campaign_id`) REFERENCES `ad_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ad_campaign_daily_metrics`
--

LOCK TABLES `ad_campaign_daily_metrics` WRITE;
/*!40000 ALTER TABLE `ad_campaign_daily_metrics` DISABLE KEYS */;
/*!40000 ALTER TABLE `ad_campaign_daily_metrics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ad_campaigns`
--

DROP TABLE IF EXISTS `ad_campaigns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ad_campaigns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `social_account_id` bigint unsigned DEFAULT NULL,
  `meta_campaign_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta_adset_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_ad_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `objective` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `daily_budget` decimal(10,2) NOT NULL,
  `cpl_threshold` decimal(10,2) DEFAULT NULL,
  `last_spend` decimal(10,2) NOT NULL DEFAULT '0.00',
  `last_impressions` int unsigned NOT NULL DEFAULT '0',
  `last_leads` int unsigned NOT NULL DEFAULT '0',
  `last_cpl` decimal(10,2) DEFAULT NULL,
  `last_checked_at` timestamp NULL DEFAULT NULL,
  `auto_paused_at` timestamp NULL DEFAULT NULL,
  `auto_pause_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ad_campaigns_meta_campaign_id_unique` (`meta_campaign_id`),
  KEY `ad_campaigns_social_account_id_foreign` (`social_account_id`),
  KEY `ad_campaigns_account_id_status_index` (`account_id`,`status`),
  CONSTRAINT `ad_campaigns_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ad_campaigns_social_account_id_foreign` FOREIGN KEY (`social_account_id`) REFERENCES `social_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ad_campaigns`
--

LOCK TABLES `ad_campaigns` WRITE;
/*!40000 ALTER TABLE `ad_campaigns` DISABLE KEYS */;
/*!40000 ALTER TABLE `ad_campaigns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `api_keys`
--

DROP TABLE IF EXISTS `api_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_keys` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_prefix` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_keys_key_hash_unique` (`key_hash`),
  KEY `api_keys_account_id_revoked_at_index` (`account_id`,`revoked_at`),
  CONSTRAINT `api_keys_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `api_keys`
--

LOCK TABLES `api_keys` WRITE;
/*!40000 ALTER TABLE `api_keys` DISABLE KEYS */;
INSERT INTO `api_keys` VALUES (1,1,'Client API Key','wasaas_live_mmArxb3A','4dbeb6674ee45ecf3499052425b85d585ffdf39ad9b1233505827f67c7e387ab',NULL,NULL,NULL,'2026-09-11 00:14:04','2026-09-11 00:14:04');
/*!40000 ALTER TABLE `api_keys` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chatbot_logs`
--

DROP TABLE IF EXISTS `chatbot_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chatbot_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `chatbot_rule_id` bigint unsigned DEFAULT NULL,
  `sender_phone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `incoming_message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `reply_sent` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chatbot_logs_chatbot_rule_id_foreign` (`chatbot_rule_id`),
  KEY `chatbot_logs_account_id_created_at_index` (`account_id`,`created_at`),
  CONSTRAINT `chatbot_logs_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chatbot_logs_chatbot_rule_id_foreign` FOREIGN KEY (`chatbot_rule_id`) REFERENCES `chatbot_rules` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chatbot_logs`
--

LOCK TABLES `chatbot_logs` WRITE;
/*!40000 ALTER TABLE `chatbot_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `chatbot_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chatbot_rules`
--

DROP TABLE IF EXISTS `chatbot_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chatbot_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `match_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `keywords` json NOT NULL,
  `response_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `response_payload` json NOT NULL,
  `priority` int unsigned NOT NULL DEFAULT '100',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chatbot_rules_account_id_is_active_priority_index` (`account_id`,`is_active`,`priority`),
  CONSTRAINT `chatbot_rules_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chatbot_rules`
--

LOCK TABLES `chatbot_rules` WRITE;
/*!40000 ALTER TABLE `chatbot_rules` DISABLE KEYS */;
/*!40000 ALTER TABLE `chatbot_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comment_automation_events`
--

DROP TABLE IF EXISTS `comment_automation_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comment_automation_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `comment_automation_rule_id` bigint unsigned DEFAULT NULL,
  `platform` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `post_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `commenter_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_replied_at` timestamp NULL DEFAULT NULL,
  `public_reply_error` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `private_message_sent_at` timestamp NULL DEFAULT NULL,
  `private_message_error` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `comment_automation_events_comment_id_unique` (`comment_id`),
  KEY `comment_automation_events_account_id_foreign` (`account_id`),
  KEY `comment_automation_events_comment_automation_rule_id_foreign` (`comment_automation_rule_id`),
  CONSTRAINT `comment_automation_events_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comment_automation_events_comment_automation_rule_id_foreign` FOREIGN KEY (`comment_automation_rule_id`) REFERENCES `comment_automation_rules` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comment_automation_events`
--

LOCK TABLES `comment_automation_events` WRITE;
/*!40000 ALTER TABLE `comment_automation_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `comment_automation_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comment_automation_rules`
--

DROP TABLE IF EXISTS `comment_automation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comment_automation_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `public_reply_template` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `private_dm_template` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `comment_automation_rules_account_id_is_active_index` (`account_id`,`is_active`),
  CONSTRAINT `comment_automation_rules_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comment_automation_rules`
--

LOCK TABLES `comment_automation_rules` WRITE;
/*!40000 ALTER TABLE `comment_automation_rules` DISABLE KEYS */;
/*!40000 ALTER TABLE `comment_automation_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `in_app_notifications`
--

DROP TABLE IF EXISTS `in_app_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `in_app_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `broadcast_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `in_app_notifications_broadcast_id_foreign` (`broadcast_id`),
  KEY `in_app_notifications_user_id_is_read_index` (`user_id`,`is_read`),
  CONSTRAINT `in_app_notifications_broadcast_id_foreign` FOREIGN KEY (`broadcast_id`) REFERENCES `notification_broadcasts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `in_app_notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `in_app_notifications`
--

LOCK TABLES `in_app_notifications` WRITE;
/*!40000 ALTER TABLE `in_app_notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `in_app_notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoices`
--

DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `invoice_number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `plan_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `plan_label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `tax_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INR',
  `payment_gateway` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gateway_order_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway_payment_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `paid_at` timestamp NULL DEFAULT NULL,
  `gateway_raw_response` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoices_invoice_number_unique` (`invoice_number`),
  UNIQUE KEY `invoices_gateway_order_id_unique` (`gateway_order_id`),
  KEY `invoices_account_id_status_index` (`account_id`,`status`),
  KEY `invoices_account_id_created_at_index` (`account_id`,`created_at`),
  KEY `invoices_status_paid_at_idx` (`status`,`paid_at`),
  CONSTRAINT `invoices_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoices`
--

LOCK TABLES `invoices` WRITE;
/*!40000 ALTER TABLE `invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leads`
--

DROP TABLE IF EXISTS `leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `social_account_id` bigint unsigned DEFAULT NULL,
  `provider` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'meta',
  `provider_lead_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `form_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ad_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lead_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lead_phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lead_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_field_data` json DEFAULT NULL,
  `tenant_notified_at` timestamp NULL DEFAULT NULL,
  `lead_welcomed_at` timestamp NULL DEFAULT NULL,
  `tenant_notify_error` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lead_welcome_error` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leads_provider_lead_id_unique` (`provider_lead_id`),
  KEY `leads_social_account_id_foreign` (`social_account_id`),
  KEY `leads_account_id_lead_phone_created_at_index` (`account_id`,`lead_phone`,`created_at`),
  CONSTRAINT `leads_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leads_social_account_id_foreign` FOREIGN KEY (`social_account_id`) REFERENCES `social_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leads`
--

LOCK TABLES `leads` WRITE;
/*!40000 ALTER TABLE `leads` DISABLE KEYS */;
/*!40000 ALTER TABLE `leads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_audit_logs`
--

DROP TABLE IF EXISTS `login_audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `account_id` bigint unsigned DEFAULT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `logged_in_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `login_audit_logs_account_id_logged_in_at_index` (`account_id`,`logged_in_at`),
  KEY `login_audit_logs_user_id_logged_in_at_index` (`user_id`,`logged_in_at`),
  KEY `login_audit_logs_status_index` (`status`),
  CONSTRAINT `login_audit_logs_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `login_audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_audit_logs`
--

LOCK TABLES `login_audit_logs` WRITE;
/*!40000 ALTER TABLE `login_audit_logs` DISABLE KEYS */;
INSERT INTO `login_audit_logs` VALUES (1,2,1,'admin','admin@demo-account.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','success','2026-09-09 04:29:58','2026-09-09 04:29:58','2026-09-09 04:29:58'),(2,2,1,'admin','admin@demo-account.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','success','2026-09-09 05:04:27','2026-09-09 05:04:27','2026-09-09 05:04:27'),(3,1,NULL,'super_admin','superadmin@wa-saas.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','success','2026-09-09 06:28:03','2026-09-09 06:28:03','2026-09-09 06:28:03'),(4,1,NULL,'super_admin','superadmin@wa-saas.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','success','2026-09-09 06:29:36','2026-09-09 06:29:36','2026-09-09 06:29:36'),(5,1,NULL,'super_admin','superadmin@wa-saas.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','success','2026-09-09 06:35:02','2026-09-09 06:35:02','2026-09-09 06:35:02'),(6,1,NULL,'super_admin','superadmin@wa-saas.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','success','2026-09-09 06:48:34','2026-09-09 06:48:34','2026-09-09 06:48:34'),(7,NULL,NULL,NULL,'admin@demo-account.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','failed','2026-09-09 06:53:50','2026-09-09 06:53:50','2026-09-09 06:53:50'),(8,2,1,'admin','admin@demo-account.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','success','2026-09-09 06:53:59','2026-09-09 06:53:59','2026-09-09 06:53:59'),(9,2,1,'admin','admin@demo-account.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','success','2026-09-09 07:41:14','2026-09-09 07:41:14','2026-09-09 07:41:14'),(10,1,NULL,'super_admin','superadmin@wa-saas.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','success','2026-09-10 01:11:56','2026-09-10 01:11:56','2026-09-10 01:11:56'),(11,2,1,'admin','admin@demo-account.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','success','2026-09-10 01:12:17','2026-09-10 01:12:17','2026-09-10 01:12:17'),(12,1,NULL,'super_admin','superadmin@wa-saas.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','success','2026-09-10 05:16:48','2026-09-10 05:16:48','2026-09-10 05:16:48'),(13,NULL,NULL,NULL,'admin@demo-account.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','failed','2026-09-10 05:16:56','2026-09-10 05:16:56','2026-09-10 05:16:56'),(14,2,1,'admin','admin@demo-account.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','success','2026-09-10 05:17:09','2026-09-10 05:17:09','2026-09-10 05:17:09'),(15,NULL,NULL,NULL,'marketer@demo-account.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','failed','2026-09-10 05:48:35','2026-09-10 05:48:35','2026-09-10 05:48:35'),(16,3,1,'social_marketer','marketer@demo-account.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','success','2026-09-10 05:48:44','2026-09-10 05:48:44','2026-09-10 05:48:44'),(17,2,1,'admin','admin@demo-account.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','success','2026-09-10 06:52:44','2026-09-10 06:52:44','2026-09-10 06:52:44'),(18,2,1,'admin','admin@demo-account.local','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','success','2026-09-10 06:53:44','2026-09-10 06:53:44','2026-09-10 06:53:44');
/*!40000 ALTER TABLE `login_audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mail_logs`
--

DROP TABLE IF EXISTS `mail_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `broadcast_id` bigint unsigned DEFAULT NULL,
  `account_id` bigint unsigned DEFAULT NULL,
  `recipient_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('sent','failed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `sent_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mail_logs_account_id_sent_at_index` (`account_id`,`sent_at`),
  KEY `mail_logs_broadcast_id_index` (`broadcast_id`),
  KEY `mail_logs_status_index` (`status`),
  CONSTRAINT `mail_logs_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mail_logs_broadcast_id_foreign` FOREIGN KEY (`broadcast_id`) REFERENCES `notification_broadcasts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mail_logs`
--

LOCK TABLES `mail_logs` WRITE;
/*!40000 ALTER TABLE `mail_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `mail_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mail_settings`
--

DROP TABLE IF EXISTS `mail_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `mailer` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'smtp',
  `host` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `port` int unsigned DEFAULT NULL,
  `username` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` text COLLATE utf8mb4_unicode_ci,
  `encryption` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mail_settings`
--

LOCK TABLES `mail_settings` WRITE;
/*!40000 ALTER TABLE `mail_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `mail_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `message_templates`
--

DROP TABLE IF EXISTS `message_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned DEFAULT NULL,
  `industry_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `variables_schema` json DEFAULT NULL,
  `status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `is_super_admin_tested` tinyint(1) NOT NULL DEFAULT '0',
  `tested_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `message_templates_created_by_foreign` (`created_by`),
  KEY `message_templates_account_id_status_index` (`account_id`,`status`),
  KEY `message_templates_status_index` (`status`),
  KEY `message_templates_industry_type_index` (`industry_type`),
  CONSTRAINT `message_templates_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_templates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `message_templates`
--

LOCK TABLES `message_templates` WRITE;
/*!40000 ALTER TABLE `message_templates` DISABLE KEYS */;
/*!40000 ALTER TABLE `message_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_09_08_100505_create_personal_access_tokens_table',1),(5,'2026_09_08_100507_create_permission_tables',1),(6,'2026_09_08_100510_create_accounts_table',1),(7,'2026_09_08_100511_add_account_id_and_is_active_to_users_table',1),(8,'2026_09_08_100520_create_subscriptions_table',1),(9,'2026_09_08_100521_update_accounts_table_for_provisioning',1),(10,'2026_09_08_100530_create_whatsapp_sessions_table',1),(11,'2026_09_08_100540_add_meta_config_to_whatsapp_sessions_table',1),(12,'2026_09_08_120000_create_payment_alerts_table',1),(13,'2026_09_08_130000_add_raw_response_and_analytics_index_to_payment_alerts',1),(14,'2026_09_08_140000_create_payment_gateway_settings_table',1),(15,'2026_09_08_140001_create_invoices_table',1),(16,'2026_09_09_150000_create_api_keys_table',1),(17,'2026_09_09_150001_create_webhook_subscriptions_table',1),(18,'2026_09_09_150002_create_webhook_deliveries_table',1),(19,'2026_09_09_150003_add_gateway_message_id_to_payment_alerts_table',1),(20,'2026_09_09_150004_add_api_rate_limit_to_accounts_table',1),(21,'2026_09_09_160000_create_chatbot_rules_table',1),(22,'2026_09_09_160001_create_chatbot_logs_table',1),(23,'2026_09_09_170000_add_allowed_modules_to_accounts_table',2),(24,'2026_09_09_170001_create_mail_settings_table',3),(25,'2026_09_09_180000_create_login_audit_logs_table',4),(26,'2026_09_09_180001_create_notification_templates_table',4),(27,'2026_09_09_180002_create_notification_broadcasts_table',4),(28,'2026_09_09_180003_create_in_app_notifications_table',4),(29,'2026_09_09_190000_create_mail_logs_table',5),(30,'2026_09_09_200000_add_performance_indexes',6),(31,'2026_09_09_210000_create_message_templates_table',7),(32,'2026_09_09_220000_add_variables_schema_to_message_templates_table',8),(33,'2026_09_09_230000_add_status_paid_at_index_to_invoices_table',9),(34,'2026_09_09_230100_add_status_index_to_whatsapp_sessions_table',9),(35,'2026_09_09_240000_add_testing_gate_to_message_templates_table',10),(36,'2026_09_09_250000_add_is_platform_device_to_accounts_table',11),(37,'2026_09_10_090000_add_social_platform_flags_to_accounts_table',12),(38,'2026_09_10_090001_create_social_provider_configs_table',12),(39,'2026_09_10_090002_create_social_accounts_table',12),(40,'2026_09_10_100000_make_subscriptions_expires_at_nullable',13),(41,'2026_09_10_110000_create_leads_table',14),(42,'2026_09_10_120000_create_ad_campaigns_table',15),(43,'2026_09_10_130000_create_comment_automation_rules_table',16),(44,'2026_09_10_130001_create_comment_automation_events_table',16),(45,'2026_09_10_140000_add_branding_fields_to_accounts_table',17),(46,'2026_09_10_140001_create_ad_campaign_daily_metrics_table',17),(47,'2026_09_11_090000_add_phone_number_to_users_table',18),(48,'2026_09_11_100000_add_provisioning_fields_to_accounts_table',18),(49,'2026_09_11_120000_create_quota_requests_table',19);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_permissions`
--

DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_permissions`
--

LOCK TABLES `model_has_permissions` WRITE;
/*!40000 ALTER TABLE `model_has_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `model_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_roles`
--

DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_roles`
--

LOCK TABLES `model_has_roles` WRITE;
/*!40000 ALTER TABLE `model_has_roles` DISABLE KEYS */;
INSERT INTO `model_has_roles` VALUES (1,'App\\Models\\User',1),(2,'App\\Models\\User',2),(4,'App\\Models\\User',3);
/*!40000 ALTER TABLE `model_has_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_broadcasts`
--

DROP TABLE IF EXISTS `notification_broadcasts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_broadcasts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `template_id` bigint unsigned DEFAULT NULL,
  `account_id` bigint unsigned DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `channels` json NOT NULL,
  `target_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_summary` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_count` int unsigned NOT NULL DEFAULT '0',
  `email_sent_count` int unsigned NOT NULL DEFAULT '0',
  `email_failed_count` int unsigned NOT NULL DEFAULT '0',
  `sent_by` bigint unsigned DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notification_broadcasts_template_id_foreign` (`template_id`),
  KEY `notification_broadcasts_sent_by_foreign` (`sent_by`),
  KEY `notification_broadcasts_account_id_sent_at_index` (`account_id`,`sent_at`),
  CONSTRAINT `notification_broadcasts_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `notification_broadcasts_sent_by_foreign` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `notification_broadcasts_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `notification_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_broadcasts`
--

LOCK TABLES `notification_broadcasts` WRITE;
/*!40000 ALTER TABLE `notification_broadcasts` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_broadcasts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_templates`
--

DROP TABLE IF EXISTS `notification_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'rich_text',
  `category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notification_templates_created_by_foreign` (`created_by`),
  CONSTRAINT `notification_templates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_templates`
--

LOCK TABLES `notification_templates` WRITE;
/*!40000 ALTER TABLE `notification_templates` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_alerts`
--

DROP TABLE IF EXISTS `payment_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_alerts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `recipient_phone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_ref` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `cost_deducted` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `gateway_message_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_reason` text COLLATE utf8mb4_unicode_ci,
  `raw_response` text COLLATE utf8mb4_unicode_ci,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_alerts_account_id_payment_ref_unique` (`account_id`,`payment_ref`),
  KEY `payment_alerts_account_id_status_index` (`account_id`,`status`),
  KEY `payment_alerts_account_created_idx` (`account_id`,`created_at`),
  KEY `payment_alerts_gateway_message_id_index` (`gateway_message_id`),
  CONSTRAINT `payment_alerts_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_alerts`
--

LOCK TABLES `payment_alerts` WRITE;
/*!40000 ALTER TABLE `payment_alerts` DISABLE KEYS */;
INSERT INTO `payment_alerts` VALUES (1,1,'917977180500','Saurav',1500.00,'pau-dskjjks','sent',0.0000,'3EB0BF04409A486EEAB9D5',NULL,'{\"success\":true,\"message_id\":\"3EB0BF04409A486EEAB9D5\"}','2026-09-09 04:12:22','2026-09-09 04:12:17','2026-09-09 04:12:22');
/*!40000 ALTER TABLE `payment_alerts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_gateway_settings`
--

DROP TABLE IF EXISTS `payment_gateway_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_gateway_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `gateway` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mode` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'test',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `test_key_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `test_key_secret` text COLLATE utf8mb4_unicode_ci,
  `test_webhook_secret` text COLLATE utf8mb4_unicode_ci,
  `live_key_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `live_key_secret` text COLLATE utf8mb4_unicode_ci,
  `live_webhook_secret` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_gateway_settings_gateway_unique` (`gateway`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_gateway_settings`
--

LOCK TABLES `payment_gateway_settings` WRITE;
/*!40000 ALTER TABLE `payment_gateway_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_gateway_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'manage-accounts','web','2026-09-09 01:32:58','2026-09-09 01:32:58'),(2,'manage-subscriptions','web','2026-09-09 01:32:58','2026-09-09 01:32:58'),(3,'send-messages','web','2026-09-09 01:32:58','2026-09-09 01:32:58'),(4,'view-analytics','web','2026-09-09 01:32:58','2026-09-09 01:32:58'),(5,'view-logs','web','2026-09-09 01:32:58','2026-09-09 01:32:58'),(6,'manage-billing-settings','web','2026-09-09 01:32:58','2026-09-09 01:32:58'),(7,'manage-developer-settings','web','2026-09-09 01:32:58','2026-09-09 01:32:58'),(8,'manage-chatbot','web','2026-09-09 01:32:58','2026-09-09 01:32:58'),(9,'manage-team','web','2026-09-09 01:32:58','2026-09-09 01:32:58'),(10,'manage-roles','web','2026-09-09 01:32:58','2026-09-09 01:32:58'),(11,'manage-templates','web','2026-09-09 06:15:35','2026-09-09 06:15:35'),(12,'view-audit-logs','web','2026-09-09 06:15:35','2026-09-09 06:15:35'),(13,'manage-notifications','web','2026-09-09 06:15:35','2026-09-09 06:15:35'),(14,'manage-social-settings','web','2026-09-10 01:18:43','2026-09-10 01:18:43'),(15,'manage-social-accounts','web','2026-09-10 01:18:43','2026-09-10 01:18:43'),(16,'launch-meta-ads','web','2026-09-10 01:18:43','2026-09-10 01:18:43'),(17,'manage-social-leads','web','2026-09-10 01:18:43','2026-09-10 01:18:43'),(18,'view-social-analytics','web','2026-09-10 01:18:43','2026-09-10 01:18:43'),(19,'manage-comment-automation','web','2026-09-10 04:02:50','2026-09-10 04:02:50'),(20,'whatsapp.view','web','2026-09-10 06:34:19','2026-09-10 06:34:19'),(21,'whatsapp.create','web','2026-09-10 06:34:19','2026-09-10 06:34:19'),(22,'whatsapp.edit','web','2026-09-10 06:34:19','2026-09-10 06:34:19'),(23,'whatsapp.delete','web','2026-09-10 06:34:19','2026-09-10 06:34:19'),(24,'social_ads.view','web','2026-09-10 06:34:19','2026-09-10 06:34:19'),(25,'social_ads.launch','web','2026-09-10 06:34:19','2026-09-10 06:34:19'),(26,'social_ads.edit_budget','web','2026-09-10 06:34:19','2026-09-10 06:34:19'),(27,'social_ads.delete_rules','web','2026-09-10 06:34:19','2026-09-10 06:34:19');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
INSERT INTO `personal_access_tokens` VALUES (3,'App\\Models\\User',1,'api-token','60263ed0f823032f26f0fdb8312e958ad14205d7233406ea4c3a0acf952a28e9','[\"*\"]','2026-09-09 06:27:34',NULL,'2026-09-09 03:51:41','2026-09-09 06:27:34'),(6,'App\\Models\\User',2,'api-token','7d7d70b45d203d05dccc44336e5d004a6a8cf80e61ff15646d36fd37d75661bc','[\"manage-subscriptions\",\"send-messages\",\"view-analytics\",\"view-logs\",\"manage-developer-settings\",\"manage-chatbot\",\"manage-team\",\"manage-roles\"]','2026-09-09 06:43:03',NULL,'2026-09-09 05:04:27','2026-09-09 06:43:03'),(7,'App\\Models\\User',1,'api-token','b18fe3ecd4e5f8073e97f6125efd96f10a55b983564009b326bed3f9946f644e','[\"*\"]',NULL,NULL,'2026-09-09 06:28:03','2026-09-09 06:28:03'),(8,'App\\Models\\User',1,'api-token','a702b763afe66cf29414b75c74d6fc6a67adae6701f4fe7deed01fc38a31ecb7','[\"*\"]',NULL,NULL,'2026-09-09 06:29:36','2026-09-09 06:29:36'),(9,'App\\Models\\User',1,'api-token','f00ca6e86c97e24506244916d255b1b1c9280a90f9637ca86ea1665556454ebb','[\"*\"]',NULL,NULL,'2026-09-09 06:35:02','2026-09-09 06:35:02'),(11,'App\\Models\\User',2,'api-token','074bf0431264c90689da79caf8b2d61cb78d8d76f038a490b43461e6fd16115f','[\"manage-subscriptions\",\"send-messages\",\"view-analytics\",\"view-logs\",\"manage-developer-settings\",\"manage-chatbot\",\"manage-team\",\"manage-roles\",\"view-audit-logs\",\"manage-notifications\"]','2026-09-09 07:33:41',NULL,'2026-09-09 06:53:59','2026-09-09 07:33:41'),(12,'App\\Models\\User',2,'api-token','e803f5aa32cb6ca27b9659b12b6183e070bd2056671d91638fd445af5636a5ba','[\"manage-subscriptions\",\"send-messages\",\"view-analytics\",\"view-logs\",\"manage-developer-settings\",\"manage-chatbot\",\"manage-team\",\"manage-roles\",\"view-audit-logs\",\"manage-notifications\"]','2026-09-09 07:46:04',NULL,'2026-09-09 07:41:14','2026-09-09 07:46:04'),(13,'App\\Models\\User',1,'api-token','7704fba3042b328f8457cca1b8f740cc8001bd3f3c164f3c374fcf8634d6674c','[\"*\"]','2026-09-10 05:04:57',NULL,'2026-09-10 01:11:56','2026-09-10 05:04:57'),(14,'App\\Models\\User',2,'api-token','093120056bf3bf4ad5307c86195398826eea4773da83463f07173e60f7249c7e','[\"manage-subscriptions\",\"send-messages\",\"view-analytics\",\"view-logs\",\"manage-developer-settings\",\"manage-chatbot\",\"manage-team\",\"manage-roles\",\"view-audit-logs\",\"manage-notifications\"]','2026-09-10 05:05:33',NULL,'2026-09-10 01:12:17','2026-09-10 05:05:33'),(15,'App\\Models\\User',1,'api-token','00cd8b4b00a665bab1b93f8006c5f4f0663260f38955ca747b1c39c76f079d7c','[\"*\"]','2026-09-11 04:18:24',NULL,'2026-09-10 05:16:48','2026-09-11 04:18:24'),(19,'App\\Models\\User',2,'api-token','270a754d9191baec65caa98dd89130451b2700909c5bde060931673f34bb2799','[\"manage-subscriptions\",\"send-messages\",\"view-analytics\",\"view-logs\",\"manage-developer-settings\",\"manage-chatbot\",\"manage-team\",\"manage-roles\",\"view-audit-logs\",\"manage-notifications\",\"manage-social-accounts\",\"launch-meta-ads\",\"manage-social-leads\",\"view-social-analytics\",\"manage-comment-automation\"]','2026-09-11 04:18:25',NULL,'2026-09-10 06:53:44','2026-09-11 04:18:25');
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quota_requests`
--

DROP TABLE IF EXISTS `quota_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quota_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `requested_by` bigint unsigned NOT NULL,
  `requested_extra_messages` int unsigned NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `invoice_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quota_requests_requested_by_foreign` (`requested_by`),
  KEY `quota_requests_reviewed_by_foreign` (`reviewed_by`),
  KEY `quota_requests_invoice_id_foreign` (`invoice_id`),
  KEY `quota_requests_account_id_status_index` (`account_id`,`status`),
  KEY `quota_requests_status_created_at_index` (`status`,`created_at`),
  CONSTRAINT `quota_requests_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quota_requests_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quota_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quota_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quota_requests`
--

LOCK TABLES `quota_requests` WRITE;
/*!40000 ALTER TABLE `quota_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `quota_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_has_permissions`
--

DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_has_permissions`
--

LOCK TABLES `role_has_permissions` WRITE;
/*!40000 ALTER TABLE `role_has_permissions` DISABLE KEYS */;
INSERT INTO `role_has_permissions` VALUES (1,1),(2,1),(3,1),(4,1),(5,1),(6,1),(7,1),(8,1),(9,1),(10,1),(11,1),(12,1),(13,1),(14,1),(15,1),(16,1),(17,1),(18,1),(19,1),(20,1),(21,1),(22,1),(23,1),(24,1),(25,1),(26,1),(27,1),(2,2),(3,2),(4,2),(5,2),(7,2),(8,2),(9,2),(10,2),(12,2),(13,2),(15,2),(16,2),(17,2),(18,2),(19,2),(3,3),(4,3),(12,3),(9,4),(15,4),(16,4),(17,4),(18,4),(19,4);
/*!40000 ALTER TABLE `role_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'super_admin','web','2026-09-09 01:32:58','2026-09-09 02:16:26'),(2,'admin','web','2026-09-09 01:32:58','2026-09-11 02:58:02'),(3,'user','web','2026-09-09 01:32:58','2026-09-11 02:58:02'),(4,'social_marketer','web','2026-09-10 01:18:43','2026-09-10 01:18:43');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `social_accounts`
--

DROP TABLE IF EXISTS `social_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `social_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `provider` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `asset_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `access_token` text COLLATE utf8mb4_unicode_ci,
  `refresh_token` text COLLATE utf8mb4_unicode_ci,
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `health_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'connected',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `social_accounts_account_id_provider_provider_id_unique` (`account_id`,`provider`,`provider_id`),
  KEY `social_accounts_account_id_asset_type_index` (`account_id`,`asset_type`),
  CONSTRAINT `social_accounts_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `social_accounts`
--

LOCK TABLES `social_accounts` WRITE;
/*!40000 ALTER TABLE `social_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `social_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `social_provider_configs`
--

DROP TABLE IF EXISTS `social_provider_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `social_provider_configs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_id` text COLLATE utf8mb4_unicode_ci,
  `client_secret` text COLLATE utf8mb4_unicode_ci,
  `redirect_uri` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `webhook_verify_token` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `social_provider_configs_provider_unique` (`provider`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `social_provider_configs`
--

LOCK TABLES `social_provider_configs` WRITE;
/*!40000 ALTER TABLE `social_provider_configs` DISABLE KEYS */;
INSERT INTO `social_provider_configs` VALUES (1,'meta',NULL,NULL,NULL,'eyJpdiI6IkpZbFlFRW45ZkZ0SnhjYmJSY3lMNHc9PSIsInZhbHVlIjoiUFlUcTlCd0hVZGJDc2llZkxKeGNqVU9kb0Qxc1FENithQ1g5cExFbnhPbHVOVXBoZnU1YThva3V2Nk5NSUtPSiIsIm1hYyI6IjUwOTlmNzE4N2ZmYzAwZDExMTJhN2Y2MmE1ZjJkYTgzNzIxNDI2ZTUyMDkyZGU3ZmViYjY0YzRhNGYwZjBhMzMiLCJ0YWciOiIifQ==',0,'2026-09-10 01:36:25','2026-09-10 01:56:08'),(2,'google',NULL,NULL,NULL,'eyJpdiI6IktZRTJVRm5lT2FwYmo4aS95SGV6d1E9PSIsInZhbHVlIjoibmxNRkFpZ3ZNc1RxbE5BMzFQdlRLU1hpRndyM21VT0g1RVZGT0tsQlZZRWdoSG04bVJybFdRdnZUSW5HcE9JciIsIm1hYyI6ImVkYjRmYmQ1OGEzMTliYjQwYTIyNjdhODAzNmY2ZjU2YThhMTMxYjZjMTQ0NGM4ZmRjMzNjNWQzNjI4Mjc1NDAiLCJ0YWciOiIifQ==',0,'2026-09-10 02:06:52','2026-09-10 02:07:14');
/*!40000 ALTER TABLE `social_provider_configs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscriptions`
--

DROP TABLE IF EXISTS `subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscriptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `engine_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'qr',
  `billing_model` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'flat_quota',
  `rate_per_message` decimal(8,4) DEFAULT NULL,
  `total_allocated_messages` int unsigned DEFAULT NULL,
  `used_messages` int unsigned NOT NULL DEFAULT '0',
  `price_paid` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payment_mode` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `starts_at` timestamp NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscriptions_account_id_starts_at_index` (`account_id`,`starts_at`),
  KEY `subscriptions_status_index` (`status`),
  CONSTRAINT `subscriptions_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscriptions`
--

LOCK TABLES `subscriptions` WRITE;
/*!40000 ALTER TABLE `subscriptions` DISABLE KEYS */;
INSERT INTO `subscriptions` VALUES (1,1,'qr','flat_quota',NULL,5000,1,4999.00,'razorpay','2026-09-08 18:30:00','2027-09-08 18:30:00','active','2026-09-09 01:32:59','2026-09-09 04:26:45'),(2,2,'qr','unlimited',NULL,NULL,0,0.00,'cash','2026-09-10 01:27:54',NULL,'active','2026-09-10 01:27:54','2026-09-10 01:27:54');
/*!40000 ALTER TABLE `subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone_number` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_account_id_is_active_index` (`account_id`,`is_active`),
  CONSTRAINT `users_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,NULL,'Super Admin','superadmin@wa-saas.local',NULL,NULL,'$2y$12$P4K1bmezR3hUisSKTPXR9.50ORfirIIjcE1Vzp15TgJpHp3X0Qfre',1,NULL,'2026-09-09 01:32:59','2026-09-09 05:21:27'),(2,1,'Demo Account Admin','admin@demo-account.local',NULL,NULL,'$2y$12$rGl.zGpsZjorefwGQ52bAePb96OFpUYib.ddTV94zfaLuaDV4qokK',1,NULL,'2026-09-09 01:32:59','2026-09-09 01:32:59'),(3,1,'Demo Social Marketer','marketer@demo-account.local',NULL,NULL,'$2y$12$cBae70kyJi9zeBUgW6LCw.08pp6OXZAz8NqNthAHd.rNRnSHBQeiu',1,NULL,'2026-09-10 05:47:55','2026-09-10 05:47:55');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `webhook_deliveries`
--

DROP TABLE IF EXISTS `webhook_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `webhook_subscription_id` bigint unsigned NOT NULL,
  `event` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json NOT NULL,
  `response_code` smallint unsigned DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempt` tinyint unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `webhook_deliveries_webhook_subscription_id_created_at_index` (`webhook_subscription_id`,`created_at`),
  CONSTRAINT `webhook_deliveries_webhook_subscription_id_foreign` FOREIGN KEY (`webhook_subscription_id`) REFERENCES `webhook_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `webhook_deliveries`
--

LOCK TABLES `webhook_deliveries` WRITE;
/*!40000 ALTER TABLE `webhook_deliveries` DISABLE KEYS */;
/*!40000 ALTER TABLE `webhook_deliveries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `webhook_subscriptions`
--

DROP TABLE IF EXISTS `webhook_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_subscriptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `events` json NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `webhook_subscriptions_account_id_is_active_index` (`account_id`,`is_active`),
  CONSTRAINT `webhook_subscriptions_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `webhook_subscriptions`
--

LOCK TABLES `webhook_subscriptions` WRITE;
/*!40000 ALTER TABLE `webhook_subscriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `webhook_subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `whatsapp_sessions`
--

DROP TABLE IF EXISTS `whatsapp_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `whatsapp_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `meta_phone_number_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_waba_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_access_token` text COLLATE utf8mb4_unicode_ci,
  `meta_webhook_verify_token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'disconnected',
  `last_connected_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `whatsapp_sessions_account_id_unique` (`account_id`),
  UNIQUE KEY `whatsapp_sessions_meta_webhook_verify_token_unique` (`meta_webhook_verify_token`),
  KEY `whatsapp_sessions_status_index` (`status`),
  CONSTRAINT `whatsapp_sessions_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `whatsapp_sessions`
--

LOCK TABLES `whatsapp_sessions` WRITE;
/*!40000 ALTER TABLE `whatsapp_sessions` DISABLE KEYS */;
INSERT INTO `whatsapp_sessions` VALUES (1,1,NULL,NULL,NULL,NULL,'connecting','2026-09-09 04:11:44','2026-09-09 04:11:39','2026-09-09 05:17:49'),(2,2,NULL,NULL,NULL,NULL,'connecting',NULL,'2026-09-10 01:30:44','2026-09-10 01:30:44');
/*!40000 ALTER TABLE `whatsapp_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'wa_saas_platform'
--

--
-- Dumping routines for database 'wa_saas_platform'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-11 15:20:22
