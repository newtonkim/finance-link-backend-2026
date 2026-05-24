# ************************************************************
# Sequel Ace SQL dump
# Version 20095
#
# https://sequel-ace.com/
# https://github.com/Sequel-Ace/Sequel-Ace
#
# Host: 127.0.0.1 (MySQL 9.4.0)
# Database: mfukopro
# Generation Time: 2026-03-02 08:14:00 +0000
# ************************************************************


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
SET NAMES utf8mb4;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE='NO_AUTO_VALUE_ON_ZERO', SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


# Dump of table cache
# ------------------------------------------------------------

DROP TABLE IF EXISTS `cache`;

CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;

INSERT INTO `cache` (`key`, `value`, `expiration`)
VALUES
	('mfukopro-cache-8db1cc9cf40b1e5c31e9dba83b31e6ee','i:1;',1772431704),
	('mfukopro-cache-8db1cc9cf40b1e5c31e9dba83b31e6ee:timer','i:1772431703;',1772431704),
	('mfukopro-cache-c0ecbb88c16321cdc453de21739f0037','i:1;',1772256103),
	('mfukopro-cache-c0ecbb88c16321cdc453de21739f0037:timer','i:1772256103;',1772256103);

/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;


# Dump of table cache_locks
# ------------------------------------------------------------

DROP TABLE IF EXISTS `cache_locks`;

CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Dump of table coa_template_accounts
# ------------------------------------------------------------

DROP TABLE IF EXISTS `coa_template_accounts`;

CREATE TABLE `coa_template_accounts` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gl_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_type` enum('ASSET','LIABILITY','EQUITY','INCOME','EXPENSE') COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_subtype` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normal_balance` enum('DR','CR') COLLATE utf8mb4_unicode_ci NOT NULL,
  `level` smallint NOT NULL,
  `parent_template_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_control` tinyint(1) NOT NULL DEFAULT '0',
  `is_postable` tinyint(1) NOT NULL DEFAULT '1',
  `allow_manual` tinyint(1) NOT NULL DEFAULT '1',
  `ifrs_category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `coa_template_accounts_template_id_gl_code_unique` (`template_id`,`gl_code`),
  KEY `coa_template_accounts_parent_template_id_foreign` (`parent_template_id`),
  CONSTRAINT `coa_template_accounts_parent_template_id_foreign` FOREIGN KEY (`parent_template_id`) REFERENCES `coa_template_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `coa_template_accounts_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `coa_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `coa_template_accounts` WRITE;
/*!40000 ALTER TABLE `coa_template_accounts` DISABLE KEYS */;

INSERT INTO `coa_template_accounts` (`id`, `template_id`, `gl_code`, `name`, `account_type`, `account_subtype`, `normal_balance`, `level`, `parent_template_id`, `is_control`, `is_postable`, `allow_manual`, `ifrs_category`, `created_at`, `updated_at`)
VALUES
	('01b9ba77-5d0d-485b-9ad8-a51aece266f8','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1121','Mandatory Savings – Members','ASSET','Member Savings','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('0234d436-dd04-4b5b-8c5d-690c9ba42323','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','4300','Other Operating Income','INCOME','Other Income','CR',2,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('039c1fe4-6fc3-48f0-8430-e6e796a132ed','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','3120','Share Premium','EQUITY','Share Capital','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('03f457f3-187f-4417-88af-97abf7360e42','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2113','Fixed Deposits','LIABILITY','Member Deposit','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('0569c0b0-c0f7-4907-8686-8f137186cc6e','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5310','Rent & Occupancy','EXPENSE','Admin Expense','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('08fa89d8-628c-4ca5-964d-7c7fcae4b2ee','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1111','Petty Cash','ASSET','Cash','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('0bee21e7-5634-4297-9059-ed735956d9af','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2111','Mandatory Savings Deposits','LIABILITY','Member Deposit','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('0db808d2-7e7b-42c0-9610-368c1bc67c22','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','3300','Retained Earnings / Surplus','EQUITY','Retained Earnings','CR',2,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('0f7728fb-c5a8-4d9f-90dc-629ed5b85e1f','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1150','Interest Receivable','ASSET','Accrued Asset','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('0faa5a17-1389-44a2-a88b-705e794ea2b9','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','3230','General Reserve','EQUITY','General Reserve','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('109364a7-5759-4a90-8781-e3a021360581','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','4230','Account Maintenance Fees','INCOME','Fee Income','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('1448d7f1-f7d3-441c-9a99-5a22e56d41ab','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1143','Stage 3 ECL Provision','ASSET','Contra Asset','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('1686c148-4d36-4487-a54a-b19b5e5dc659','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1000','ASSETS','ASSET','Header','DR',1,NULL,1,0,1,NULL,'2026-02-27 07:38:43','2026-02-27 07:38:43'),
	('1bf8d0ad-87ba-471d-9c67-23717903702e','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','4240','Late Payment Penalties','INCOME','Fee Income','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('1c8a30ea-d272-4683-b6e4-1edf1d867392','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2112','Voluntary Savings Deposits','LIABILITY','Member Deposit','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('1edb99a9-4057-40d0-9851-5f800fe2fc1d','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1133','Agriculture Loans','ASSET','Loan','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('25604447-1f06-476e-a331-ad7473ea004d','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5120','Interest Expense on Borrowings','EXPENSE','Financial Cost','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('2ac48568-a920-4a4e-bef0-112727b4cb7c','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5100','Financial Expenses','EXPENSE','Financial Cost','DR',2,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('2b2fd818-70d0-49e9-b9b9-be214ff74f66','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','3210','Statutory Reserve (SACCO Act)','EQUITY','Regulatory Reserve','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('2b4e30bc-4d56-49e0-8fee-2a1d5b034bc7','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2110','Member Savings Liability','LIABILITY','Member Deposit','CR',3,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('33a5ef7d-4957-4820-8169-7c7f8b8fe334','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5410','Depreciation – Buildings','EXPENSE','Non-Cash Expense','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('35497b58-78f0-41ee-9bf6-5d514339c7e2','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1160','Prepayments & Other Receivables','ASSET','Current Asset','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('35fccade-f847-413c-8b35-c35500862b33','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5130','ECL Provision Expense (IFRS 9)','EXPENSE','Provision Expense','DR',3,NULL,1,0,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('3607c07c-9706-4996-bc9d-487b9d56f4b6','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2130','Accrued Interest on Savings','LIABILITY','Accrued Liability','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('38eb638c-5cf0-453e-8b8b-e1993ba15f56','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1224','Accum Depr – Furniture','ASSET','Contra Asset','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('3d6ca322-4567-4af5-9377-27179815fa43','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1123','Fixed Deposit – Members','ASSET','Member Savings','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('4383292a-60d0-43fa-8c13-7ea6b7df90f7','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','3220','Institutional Capital Reserve','EQUITY','Regulatory Reserve','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('463e2f4d-d267-47b3-9030-16168be92588','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5300','Administrative Expenses','EXPENSE','Operating Expense','DR',2,NULL,1,0,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('46e73ac3-c2ba-471e-9a64-608e4e7d4a11','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','3240','Loan Loss Reserve','EQUITY','General Reserve','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('4c2b5ac6-bbf2-41e7-85b1-843d71c1c98f','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2200','Non-Current Liabilities','LIABILITY','Non-Current Liability','CR',2,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('4d525690-89f1-48a3-ba07-e99456f33ca6','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5134','Loan Write-off Expense','EXPENSE','Provision Expense','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('4ea0d26e-681f-43b6-9fec-d94efd8bef4a','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1140','Loan Loss Provisions (ECL)','ASSET','Contra Asset','CR',3,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('4fbf72ee-4f41-4e53-a008-2dbf6c16b532','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1100','Current Assets','ASSET','Current Asset','DR',2,NULL,1,0,1,NULL,'2026-02-27 07:38:43','2026-02-27 07:38:43'),
	('53494807-ce6d-41de-9af5-f59a1b2efbac','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5500','Other Operating Expenses','EXPENSE','Operating Expense','DR',2,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('539c75f1-fcc7-45ff-a944-ccd4d120b8aa','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1131','Personal / Consumer Loans','ASSET','Loan','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('53a86685-b9d5-4d00-bd34-8d31801f0db0','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1122','Voluntary Savings – Members','ASSET','Member Savings','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('548d05ed-2184-4a18-a87f-b53c0c8ac2e4','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1230','Intangible Assets','ASSET','Intangible','DR',3,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('57552b32-0c68-4142-b228-596302830dad','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','3310','Retained Earnings – Prior Years','EQUITY','Retained Earnings','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('59de5085-27be-46bb-8a21-93329a5f23ad','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1200','Non-Current Assets','ASSET','Non-Current Asset','DR',2,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('5a7fb81b-7ed7-4092-af37-cdeb6dfd903a','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2120','Share Capital Subscriptions','LIABILITY','Share Capital','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('5d5aa297-ae81-42af-be04-8e19cd6babf8','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','4130','Interest on Agriculture Loans','INCOME','Loan Interest','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('610e92ec-8457-4998-9a05-977c507befab','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5350','Regulatory Fees & Levies','EXPENSE','Admin Expense','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('644571d7-1272-4b48-aeb8-09feb9c93d2c','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5340','Audit & Professional Fees','EXPENSE','Admin Expense','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('64589192-8f42-4212-8b34-a3395f204c6b','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5360','Board Allowances','EXPENSE','Admin Expense','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('66914a1d-1831-4caf-9141-49a0891c83d8','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5000','EXPENSES','EXPENSE','Header','DR',1,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('676a41ad-ed46-4772-9015-44b394fc4016','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1130','Loans Receivable (Gross)','ASSET','Loan','DR',3,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('67f83efc-0f3c-49eb-b061-874813a0e202','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','3100','Share Capital','EQUITY','Share Capital','CR',2,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('680f61b3-3588-45c5-a9f5-ae945be9ea7a','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','4112','Cash Interest – Personal Loans','INCOME','Cash Income','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('68e9b75d-dc8f-4282-ba7f-af81e5161f4d','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1142','Stage 2 ECL Provision','ASSET','Contra Asset','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('6b054e1a-e271-4878-9c14-d7d0fd3c4eb1','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1132','Business Loans','ASSET','Loan','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('6cc03cb3-d23e-4ba3-8267-ec286dd53505','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2160','Tax Payable','LIABILITY','Payable','CR',3,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('6f551364-5f08-408c-bbe6-cabbea273ca6','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1211','Land & Buildings (Cost)','ASSET','Fixed Asset','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('736b7d7d-be37-4109-9527-f6b052c4c0f6','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1115','Mobile Money – Airtel','ASSET','Bank','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('798911a0-2d4e-45db-9f38-adaf116675ca','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1141','Stage 1 ECL Provision','ASSET','Contra Asset','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('79a96830-d877-4dd3-89cc-7e68e48c2003','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','3320','Surplus/Deficit – Current Year','EQUITY','Retained Earnings','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('79e13ce4-4b1c-49df-aceb-21eab000fe4c','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','4100','Interest Income','INCOME','Operating Income','CR',2,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('79f044b0-3c4e-4933-8d17-22dfeb59db63','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5220','NSSF Contributions','EXPENSE','Staff Cost','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('7e597364-84c7-4cd7-bcd4-877cda1c6b47','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2212','MFI Apex Borrowings','LIABILITY','Borrowings','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('7eca6af6-68eb-4a08-998b-c280e2c4be08','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5110','Interest Expense on Savings','EXPENSE','Financial Cost','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('7edb5f39-1e32-4d70-8d7c-7d26145c9ebe','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2162','VAT Payable','LIABILITY','Payable','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('85323ba8-aca5-47d8-8e74-25ceac1572d4','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1134','Salary Loans','ASSET','Loan','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('86b8b352-9a7e-46f7-ac37-5973fa2bf7aa','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','3000','EQUITY / MEMBERS\' FUNDS','EQUITY','Header','CR',1,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('90c640aa-06d9-4313-be57-42c53d35ff7e','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1112','Cash at Bank – Operating Account','ASSET','Bank','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('90ebd72d-2e1c-499d-905f-9e43f201fa2e','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1214','Furniture & Fittings (Cost)','ASSET','Fixed Asset','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('927be0cf-f109-43af-832a-194ead3c54c1','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5440','Amortisation – Software','EXPENSE','Non-Cash Expense','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('92fa532c-b525-4440-b99c-2bdaebf8a4fb','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','4110','Interest on Personal Loans','INCOME','Loan Interest','CR',3,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('940505a5-25d0-45c9-b742-de65c39f2d0e','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5131','ECL Charge – Stage 1','EXPENSE','Provision Expense','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('9fbc0996-b282-4013-bfa4-159dc509f9ce','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5320','Utilities','EXPENSE','Admin Expense','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('a34b89b5-53f2-4900-941c-c295bf28f89a','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2000','LIABILITIES','LIABILITY','Header','CR',1,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('a3f8338d-614a-484e-a74b-0ebcfd6d64cd','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','4210','Loan Application Fees','INCOME','Fee Income','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('a430767e-dbf3-4350-85c4-b771eb1e45da','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','3200','Reserves','EQUITY','Reserves','CR',2,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('a602d8f7-d645-4b16-8644-13817c1a4cad','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5210','Salaries & Wages','EXPENSE','Staff Cost','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('a627f478-7928-4f14-a45d-926ade74a012','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','4400','Investment Income','INCOME','Investment Income','CR',2,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('a7430a01-5b63-40d4-a244-6d9cd3f19f89','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2140','Dividends Payable','LIABILITY','Accrued Liability','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('ab2f7b76-446c-40b9-ad5d-e47c38fa57d0','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5600','Tax Expense','EXPENSE','Tax','DR',2,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('ac246e5f-6587-4510-b394-e9bde28bade0','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1212','Motor Vehicles (Cost)','ASSET','Fixed Asset','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('ac96a666-63fd-4586-a059-d58d13979c71','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1222','Accum Depr – Motor Vehicles','ASSET','Contra Asset','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('ada15b53-9d3e-4574-932a-e096bf68c186','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','4200','Fee Income','INCOME','Operating Income','CR',2,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('b8adfe7c-0c12-42c7-aa73-28d5b63a80b9','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1210','Property, Plant & Equipment','ASSET','Fixed Asset','DR',3,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('b9daffb5-cd47-470b-82bb-f2b24cfcd4ea','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1232','Accum Amortisation – Software','ASSET','Contra Asset','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('bade5fe2-9f3a-4308-bbcf-e0de28b3016d','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1221','Accum Depr – Land & Buildings','ASSET','Contra Asset','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('bc2f0deb-414e-4b2c-a35f-a6d89c7adbb9','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5330','Communications & Internet','EXPENSE','Admin Expense','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('c4093c54-9575-445a-a52d-bef32a874bda','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','3110','Ordinary Share Capital','EQUITY','Share Capital','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('c41ace4f-1f42-4f07-b810-1cca06c1fd6b','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5400','Depreciation & Amortisation','EXPENSE','Non-Cash Expense','DR',2,NULL,1,0,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('c72975e5-ffdb-4202-aaca-cc0ddc0a8adc','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1110','Cash & Cash Equivalents','ASSET','Current Asset','DR',3,NULL,1,0,1,NULL,'2026-02-27 07:38:43','2026-02-27 07:38:43'),
	('c8e9ba0e-aff4-4598-9ce0-1d640ef58555','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5420','Depreciation – Motor Vehicles','EXPENSE','Non-Cash Expense','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('ca0643e1-f6eb-49e7-9d61-f0028fe508bb','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2100','Current Liabilities','LIABILITY','Current Liability','CR',2,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('cdc6801f-fc0d-4800-941c-3fb793ab854d','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5133','ECL Charge – Stage 3','EXPENSE','Provision Expense','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('cf38439b-d2dd-4c1c-a6ab-b269dd4b1ead','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5430','Depreciation – IT Equipment','EXPENSE','Non-Cash Expense','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('cf427f07-648c-46f8-afe5-1938abbf6063','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1213','Computers & IT Equipment (Cost)','ASSET','Fixed Asset','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('d49fbd3e-edd5-4f0a-bede-58702e5dbf76','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1223','Accum Depr – IT Equipment','ASSET','Contra Asset','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('d5d0dfba-d94f-492e-8900-fe7364bd8753','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','4000','INCOME','INCOME','Header','CR',1,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('d8b700d6-0864-4891-951d-58b00d4ee00a','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','3330','Dividends Declared','EQUITY','Retained Earnings','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('d9d07482-9e07-49ad-aaf5-49bd2dd11e1f','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','4140','Penalty / Default Interest','INCOME','Loan Interest','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('da474d31-9002-4463-9bba-ae6b519b9477','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1120','Member Savings Control','ASSET','Current Asset','DR',3,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('dc0fffed-4bb2-4a08-8a5e-f18be1739b64','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1113','Cash at Bank – Loan Disbursement','ASSET','Bank','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('e2c7a15f-af27-49c8-99c9-c11e54738e4a','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1114','Mobile Money – MTN','ASSET','Bank','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('e4a19aea-003f-4cbc-aa25-97db6708b113','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','4220','Loan Processing Fees','INCOME','Fee Income','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('e6506bc2-d416-4784-bbe5-1663520e5d94','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5132','ECL Charge – Stage 2','EXPENSE','Provision Expense','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('e824010e-c29b-4db6-a8f1-fe04def9f157','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1220','Accumulated Depreciation','ASSET','Contra Asset','CR',3,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('eab8dcab-d47e-4d8d-81db-b1c9c8ad6e29','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','4120','Interest on Business Loans','INCOME','Loan Interest','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('eb81de6a-69cf-4407-b7f1-bb40a9e2b923','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1135','Group / VSLA Loans','ASSET','Loan','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('ec98cb3b-26fa-431c-887f-8efa2274e1d3','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1231','Software & Licences (Cost)','ASSET','Intangible','DR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('ed926b91-83bb-42a3-8b00-cb0cd3aeeb70','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2161','PAYE Payable','LIABILITY','Payable','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('eda173a0-5d75-4ad1-97b5-f761c20c646c','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','1170','Investment in Govt Securities','ASSET','Investment','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('eeedc075-e116-4363-aa44-5f8c32d1e855','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2211','Bank Loans Payable','LIABILITY','Borrowings','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('ef6fd793-3b69-4119-a16c-a54de502ccb9','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5200','Staff Costs','EXPENSE','Operating Expense','DR',2,NULL,1,0,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('f71f1681-c66c-49f3-a27f-07cc6bd35283','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','5230','Staff Training','EXPENSE','Staff Cost','DR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:45','2026-02-27 07:38:45'),
	('f72f04a7-41e5-4599-acd7-5d61d209ffb9','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2210','External Borrowings','LIABILITY','Borrowings','CR',3,NULL,1,0,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('f9af49fe-57d6-40f7-b3d9-f451af4d0dd2','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2150','Accounts Payable & Accruals','LIABILITY','Payable','CR',3,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('f9c9fde3-e3e8-4216-9976-ceb990548fee','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','4111','Accrued Interest – Personal Loans','INCOME','Accrued Income','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44'),
	('fc5e1589-672b-490d-b390-5951726b70db','19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','2163','Withholding Tax Payable','LIABILITY','Payable','CR',4,NULL,0,1,1,NULL,'2026-02-27 07:38:44','2026-02-27 07:38:44');

/*!40000 ALTER TABLE `coa_template_accounts` ENABLE KEYS */;
UNLOCK TABLES;


# Dump of table coa_templates
# ------------------------------------------------------------

DROP TABLE IF EXISTS `coa_templates`;

CREATE TABLE `coa_templates` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `coa_templates_template_type_unique` (`template_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `coa_templates` WRITE;
/*!40000 ALTER TABLE `coa_templates` DISABLE KEYS */;

INSERT INTO `coa_templates` (`id`, `template_type`, `name`, `is_active`, `created_at`, `updated_at`)
VALUES
	('19c907ba-2fa6-43f7-b7b2-0ee52015e8e8','SACCO_UGANDA','Standard SACCO Uganda Chart of Accounts',1,'2026-02-27 07:38:43','2026-02-27 07:38:43');

/*!40000 ALTER TABLE `coa_templates` ENABLE KEYS */;
UNLOCK TABLES;


# Dump of table failed_jobs
# ------------------------------------------------------------

DROP TABLE IF EXISTS `failed_jobs`;

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



# Dump of table job_batches
# ------------------------------------------------------------

DROP TABLE IF EXISTS `job_batches`;

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



# Dump of table jobs
# ------------------------------------------------------------

DROP TABLE IF EXISTS `jobs`;

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



# Dump of table licenses
# ------------------------------------------------------------

DROP TABLE IF EXISTS `licenses`;

CREATE TABLE `licenses` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `plan` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` date NOT NULL,
  `expires_at` date NOT NULL,
  `grace_ends_at` date DEFAULT NULL,
  `max_members` int DEFAULT NULL,
  `max_users` int DEFAULT NULL,
  `features` json DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `licenses_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `licenses_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `licenses` WRITE;
/*!40000 ALTER TABLE `licenses` DISABLE KEYS */;

INSERT INTO `licenses` (`id`, `tenant_id`, `plan`, `starts_at`, `expires_at`, `grace_ends_at`, `max_members`, `max_users`, `features`, `status`, `created_at`, `updated_at`)
VALUES
	('019c86e2-13ae-7022-a507-0ad746968ff0','9b1ef55f-8ccf-44fb-9306-f4e9a5b8fa2a','basic','2026-02-22','2026-03-24',NULL,NULL,NULL,NULL,'expired','2026-02-22 19:44:49','2026-02-25 05:45:51'),
	('019c86f5-0260-739e-9ae9-fdb3ff6c72db','934e1262-40f3-4f02-97cd-eb33d408eab7','basic','2026-02-22','2026-03-24',NULL,NULL,NULL,NULL,'active','2026-02-22 20:05:29','2026-02-22 20:05:29'),
	('019c898d-902b-72c9-8430-59eb4542fd72','3f6c0eca-d715-47f7-8e23-41d697614138','professional','2026-02-23','2026-03-25',NULL,NULL,NULL,NULL,'active','2026-02-23 08:11:22','2026-02-23 08:11:22'),
	('019c8eb5-961a-700c-94f9-c7a2642daac3','ad29672a-96de-4aa5-b0ee-9f4d5c157fd4','enterprise','2026-02-24','2026-03-26',NULL,NULL,NULL,NULL,'active','2026-02-24 08:13:11','2026-02-24 08:13:11'),
	('019c8ee4-e96f-7160-b0b9-514593f5bc10','0cb9af8b-b116-441e-b7c5-380e7f33ce62','basic','2026-02-24','2026-03-26',NULL,NULL,NULL,NULL,'active','2026-02-24 09:04:52','2026-02-24 09:04:52'),
	('019c9355-1141-7379-a6b0-5eae0a48ec52','9b1ef55f-8ccf-44fb-9306-f4e9a5b8fa2a','basic','2026-02-25','2026-02-28',NULL,NULL,NULL,NULL,'active','2026-02-25 05:45:51','2026-02-25 05:45:51');

/*!40000 ALTER TABLE `licenses` ENABLE KEYS */;
UNLOCK TABLES;


# Dump of table migrations
# ------------------------------------------------------------

DROP TABLE IF EXISTS `migrations`;

CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;

INSERT INTO `migrations` (`id`, `migration`, `batch`)
VALUES
	(1,'2026_02_19_130852_create_tenants_table',1),
	(2,'2026_02_19_140000_create_platform_users_table',1),
	(3,'2026_02_19_140001_add_two_factor_columns_to_platform_users_table',1),
	(4,'2026_02_19_170346_create_licenses_table',1),
	(5,'2026_02_20_000001_create_plans_table',1),
	(6,'2026_02_21_000004_create_auth_support_tables',1),
	(7,'2026_02_21_000005_create_cache_tables',1),
	(8,'2026_02_21_000006_create_jobs_table',1),
	(9,'2026_02_22_000001_enhance_tenants_table',1),
	(10,'2026_02_22_000002_enhance_licenses_table',1),
	(11,'2026_02_22_000003_enhance_plans_table',1),
	(12,'2026_02_27_000001_create_coa_templates_tables',2);

/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;


# Dump of table password_reset_tokens
# ------------------------------------------------------------

DROP TABLE IF EXISTS `password_reset_tokens`;

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Dump of table plans
# ------------------------------------------------------------

DROP TABLE IF EXISTS `plans`;

CREATE TABLE `plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `billing_cycle` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `max_members` int DEFAULT NULL,
  `max_users` int DEFAULT NULL,
  `features` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plans_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `plans` WRITE;
/*!40000 ALTER TABLE `plans` DISABLE KEYS */;

INSERT INTO `plans` (`id`, `name`, `slug`, `price`, `billing_cycle`, `max_members`, `max_users`, `features`, `created_at`, `updated_at`)
VALUES
	(1,'Basic Plan','basic',29.99,'monthly',100,5,'\"{\\\"reports\\\":false,\\\"loans\\\":true,\\\"savings\\\":true,\\\"shares\\\":false}\"','2026-02-22 18:53:58','2026-02-22 18:53:58'),
	(2,'Professional Plan','professional',99.99,'monthly',500,20,'\"{\\\"reports\\\":true,\\\"loans\\\":true,\\\"savings\\\":true,\\\"shares\\\":true}\"','2026-02-22 18:53:58','2026-02-22 18:53:58'),
	(3,'Enterprise Plan','enterprise',299.99,'monthly',-1,-1,'\"{\\\"reports\\\":true,\\\"loans\\\":true,\\\"savings\\\":true,\\\"shares\\\":true,\\\"api_access\\\":true}\"','2026-02-22 18:53:58','2026-02-22 18:53:58');

/*!40000 ALTER TABLE `plans` ENABLE KEYS */;
UNLOCK TABLES;


# Dump of table platform_users
# ------------------------------------------------------------

DROP TABLE IF EXISTS `platform_users`;

CREATE TABLE `platform_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `two_factor_secret` text COLLATE utf8mb4_unicode_ci,
  `two_factor_recovery_codes` text COLLATE utf8mb4_unicode_ci,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `platform_users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `platform_users` WRITE;
/*!40000 ALTER TABLE `platform_users` DISABLE KEYS */;

INSERT INTO `platform_users` (`id`, `name`, `email`, `email_verified_at`, `password`, `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`, `remember_token`, `created_at`, `updated_at`)
VALUES
	(1,'Test User','test@example.com',NULL,'$2y$12$9Y1cCu/bCig3DyUDGsIicu214FrQSSSoTXHmItkHHLiUqxHykxrNG',NULL,NULL,NULL,NULL,'2026-02-22 18:53:58','2026-02-22 18:53:58'),
	(2,'Super Admin','admin@mfukopro.com',NULL,'$2y$12$Rj/JWzzU/9uVeLVGa.K5ye21onakYMCCdVpXXv1ZJ1CmFJbEw0gB2',NULL,NULL,NULL,NULL,'2026-02-22 18:53:58','2026-02-22 18:53:58'),
	(3,'Newton Kimathi','newtonyamu22@gmail.com',NULL,'$2y$12$nvBG6f1/FXARe5HDSJLaC.YeQwSCLVuTDWQb94u/88PxCwMXK9suy',NULL,NULL,NULL,NULL,'2026-02-23 05:29:22','2026-02-23 05:29:22');

/*!40000 ALTER TABLE `platform_users` ENABLE KEYS */;
UNLOCK TABLES;


# Dump of table sessions
# ------------------------------------------------------------

DROP TABLE IF EXISTS `sessions`;

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

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;

INSERT INTO `sessions` (`id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`)
VALUES
	('3SmPfIfslOpau932d3LwWoNELHtC0hrThXQUrwtP',NULL,'127.0.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','YTo0OntzOjY6Il90b2tlbiI7czo0MDoidTR1bWlHZTF6RmkzZFFrOEo3cVdNTGoyVFJRRkxsZ2VFVDZ2a1BKRCI7czozOiJ1cmwiO2E6MTp7czo4OiJpbnRlbmRlZCI7czo1MzoiaHR0cDovL216YWxlbmRvLXNhY2NvLmxvY2FsaG9zdDo4MDAwL3NhdmluZ3MtZ3JvdXBzLzMiO31zOjk6Il9wcmV2aW91cyI7YToyOntzOjM6InVybCI7czo0MjoiaHR0cDovL216YWxlbmRvLXNhY2NvLmxvY2FsaG9zdDo4MDAwL2xvZ2luIjtzOjU6InJvdXRlIjtzOjU6ImxvZ2luIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==',1772379625),
	('E26wAZfdXziBW9pATDMmQK8NmFTMQxkDqE5vyKxR',1,'127.0.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','YTo1OntzOjY6Il90b2tlbiI7czo0MDoiWGJPRjk4QkduYk5kMDlaZU1URmdKdnlrczBDNDdXMU05Mmo3eXU1TiI7czozOiJ1cmwiO2E6MDp7fXM6OToiX3ByZXZpb3VzIjthOjI6e3M6MzoidXJsIjtzOjUxOiJodHRwOi8vbXphbGVuZG8tc2FjY28ubG9jYWxob3N0OjgwMDAvc2F2aW5ncy1ncm91cHMiO3M6NToicm91dGUiO3M6Mjc6InRlbmFudC5zYXZpbmdzLWdyb3Vwcy5pbmRleCI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fXM6NTM6ImxvZ2luX3RlbmFudF81OWJhMzZhZGRjMmIyZjk0MDE1ODBmMDE0YzdmNThlYTRlMzA5ODlkIjtpOjE7fQ==',1772372341),
	('HOeKKBLyrJ2wdkEtTMjp7mGwkgdttFW1nYhD1qzC',NULL,'127.0.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','YTo0OntzOjY6Il90b2tlbiI7czo0MDoiaVBrNFB4V29lbE5XYkwzWXNTbHJZY2JoZ3R4Q0VLZlNtTndBMnI5biI7czozOiJ1cmwiO2E6MTp7czo4OiJpbnRlbmRlZCI7czozODoiaHR0cDovL2xvY2FsaG9zdDo4MDAwL3NhdmluZ3MtZ3JvdXBzLzQiO31zOjk6Il9wcmV2aW91cyI7YToyOntzOjM6InVybCI7czoyNzoiaHR0cDovL2xvY2FsaG9zdDo4MDAwL2xvZ2luIjtzOjU6InJvdXRlIjtzOjU6ImxvZ2luIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==',1772432739),
	('I2BuUdnaOHkMO8vZAl2dRmbaeKyhPqYj3g5KaWY6',1,'127.0.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','YTo0OntzOjY6Il90b2tlbiI7czo0MDoiTnQ2MDV2Zlh6ZUZzcVk1WTRmelVkYWRmQXhKZzhFNTduOHhCWHZFWCI7czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6NTQ6Imh0dHA6Ly9temFsZW5kby1zYWNjby5sb2NhbGhvc3Q6ODAwMC9jaGFydC1vZi1hY2NvdW50cyI7czo1OiJyb3V0ZSI7czozMDoidGVuYW50LmNoYXJ0LW9mLWFjY291bnRzLmluZGV4Ijt9czo1MzoibG9naW5fdGVuYW50XzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiO2k6MTt9',1772437747);

/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;


# Dump of table tenants
# ------------------------------------------------------------

DROP TABLE IF EXISTS `tenants`;

CREATE TABLE `tenants` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subdomain` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `database_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `settings` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenants_subdomain_unique` (`subdomain`),
  UNIQUE KEY `tenants_domain_unique` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `tenants` WRITE;
/*!40000 ALTER TABLE `tenants` DISABLE KEYS */;

INSERT INTO `tenants` (`id`, `name`, `subdomain`, `domain`, `database_name`, `status`, `settings`, `created_at`, `updated_at`, `deleted_at`)
VALUES
	('0cb9af8b-b116-441e-b7c5-380e7f33ce62','Mfuko sacco','mfuko-sacco',NULL,'sacco_mfuko_sacco','active','{\"email\": \"swift@gmail.com\", \"phone\": \"+256789098909\", \"slogan\": \"Pride of Clients\", \"address\": \"Kisasi, Plot, 2345\", \"logo_url\": \"http://mfuko-sacco.localhost:8000/storage/logos/8Q8Rb3qqSBPMDTGjf6IO4mPo1ixz4NFkYit8lZzm.jpg\"}','2026-02-24 09:04:52','2026-02-26 05:57:29',NULL),
	('3f6c0eca-d715-47f7-8e23-41d697614138','Test Sacco','test-sacco',NULL,'sacco_test_sacco','active',NULL,'2026-02-23 08:11:22','2026-02-23 08:11:22',NULL),
	('934e1262-40f3-4f02-97cd-eb33d408eab7','Wazalendo Sacco','wazalendo-sacco',NULL,'sacco_wazalendo_sacco','active','{\"email\": \"Happyness@gmail.com\", \"phone\": \"+2567890989\", \"slogan\": \"The trusted friend\", \"address\": \"Kampala\", \"logo_url\": \"http://wazalendo-sacco.localhost:8000/storage/logos/HiR5UCWF7TU0GuQgmEwVJHtyhSyjisidT8xrrA9y.jpg\"}','2026-02-22 20:05:29','2026-02-23 13:07:41',NULL),
	('9b1ef55f-8ccf-44fb-9306-f4e9a5b8fa2a','Mzalendo Sacco','mzalendo-sacco',NULL,'sacco_mzalendo_sacco','active','{\"email\": null, \"phone\": null, \"slogan\": null, \"address\": null, \"logo_url\": \"http://mzalendo-sacco.localhost:8000/storage/logos/T0TFKQPh8o0Gwf9ssRX33nIvJz7mOJvXMLEN72AN.png\"}','2026-02-22 19:44:48','2026-02-28 15:46:06',NULL),
	('ad29672a-96de-4aa5-b0ee-9f4d5c157fd4','Ssekimuli Sacco','ssekimuli-sacco',NULL,'sacco_ssekimuli_sacco','active',NULL,'2026-02-24 08:13:11','2026-02-24 08:13:11',NULL);

/*!40000 ALTER TABLE `tenants` ENABLE KEYS */;
UNLOCK TABLES;



/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
