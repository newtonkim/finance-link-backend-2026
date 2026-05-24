/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `accounting_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_periods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `period_code` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Format: YYYY-MM',
  `is_locked` tinyint(1) NOT NULL DEFAULT '0',
  `locked_by` bigint unsigned DEFAULT NULL,
  `locked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accounting_periods_period_code_unique` (`period_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manager_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'staff id from staff table -> id  to show the manager',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branches_code_unique` (`code`),
  KEY `branches_name_index` (`name`),
  KEY `branches_phone_index` (`phone`),
  KEY `branches_email_index` (`email`),
  KEY `branches_address_index` (`address`),
  KEY `branches_manager_id_index` (`manager_id`),
  KEY `branches_is_active_index` (`is_active`),
  KEY `branches_created_by_index` (`created_by`),
  KEY `branches_updated_by_index` (`updated_by`),
  KEY `branches_deleted_by_index` (`deleted_by`),
  KEY `branches_system_type_index` (`system_type`),
  KEY `branches_created_at_index` (`created_at`),
  KEY `branches_updated_at_index` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chart_of_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chart_of_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gl_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_subtype` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_type` enum('ASSET','LIABILITY','EQUITY','INCOME','EXPENSE') COLLATE utf8mb4_unicode_ci NOT NULL,
  `normal_balance` enum('DR','CR') COLLATE utf8mb4_unicode_ci NOT NULL,
  `level` smallint NOT NULL DEFAULT '1',
  `account_type_legacy` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `is_control` tinyint(1) NOT NULL DEFAULT '0',
  `is_postable` tinyint(1) NOT NULL DEFAULT '1',
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `allow_manual` tinyint(1) NOT NULL DEFAULT '1',
  `ifrs_category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  UNIQUE KEY `chart_of_accounts_code_unique` (`gl_code`),
  KEY `chart_of_accounts_parent_id_foreign` (`parent_id`),
  KEY `chart_of_accounts_type_index` (`account_type_legacy`),
  KEY `chart_of_accounts_created_by_index` (`created_by`),
  KEY `chart_of_accounts_updated_by_index` (`updated_by`),
  KEY `chart_of_accounts_deleted_by_index` (`deleted_by`),
  KEY `chart_of_accounts_system_type_index` (`system_type`),
  KEY `chart_of_accounts_code_index` (`code`),
  KEY `chart_of_accounts_branch_id_index` (`branch_id`),
  CONSTRAINT `chart_of_accounts_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `currency_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `currency_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `default_currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UGX',
  `enabled_currencies` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `currency_settings_default_currency_index` (`default_currency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_types_code_unique` (`code`),
  KEY `document_types_name_index` (`name`),
  KEY `document_types_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expense_approval_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_approval_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `expense_id` bigint unsigned NOT NULL,
  `approver_id` bigint unsigned NOT NULL,
  `level` int NOT NULL,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comments` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `expense_approval_history_expense_id_index` (`expense_id`),
  KEY `expense_approval_history_approver_id_index` (`approver_id`),
  CONSTRAINT `expense_approval_history_approver_id_foreign` FOREIGN KEY (`approver_id`) REFERENCES `staff` (`id`),
  CONSTRAINT `expense_approval_history_expense_id_foreign` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expense_approval_thresholds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_approval_thresholds` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `min_amount` decimal(15,2) NOT NULL,
  `max_amount` decimal(15,2) DEFAULT NULL,
  `required_role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The Spatie role name required',
  `level` int NOT NULL COMMENT '1, 2, 3, etc.',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expense_approval_thresholds_required_role_index` (`required_role`),
  KEY `expense_approval_thresholds_level_index` (`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expense_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `expense_id` bigint unsigned NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint unsigned DEFAULT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expense_attachments_expense_id_index` (`expense_id`),
  KEY `expense_attachments_created_by_index` (`created_by`),
  KEY `expense_attachments_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `expense_attachments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`),
  CONSTRAINT `expense_attachments_expense_id_foreign` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `expense_attachments_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `staff` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expense_budgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_budgets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `expense_category_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `fiscal_year` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_code` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'e.g. 2026-05',
  `allocated_amount` decimal(15,2) NOT NULL,
  `spent_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_cat_branch_period` (`expense_category_id`,`branch_id`,`period_code`),
  KEY `expense_budgets_expense_category_id_index` (`expense_category_id`),
  KEY `expense_budgets_branch_id_index` (`branch_id`),
  KEY `expense_budgets_fiscal_year_index` (`fiscal_year`),
  KEY `expense_budgets_period_code_index` (`period_code`),
  CONSTRAINT `expense_budgets_expense_category_id_foreign` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expense_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `chart_of_account_id` bigint unsigned NOT NULL COMMENT 'The GL account this category maps to',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `branch_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expense_categories_chart_of_account_id_index` (`chart_of_account_id`),
  KEY `expense_categories_branch_id_index` (`branch_id`),
  KEY `expense_categories_created_by_index` (`created_by`),
  CONSTRAINT `expense_categories_chart_of_account_id_foreign` FOREIGN KEY (`chart_of_account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `expense_categories_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint unsigned DEFAULT NULL COMMENT 'The recurring template ID this expense was generated from',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expense_category_id` bigint unsigned NOT NULL,
  `chart_of_account_id` bigint unsigned DEFAULT NULL COMMENT 'The Bank/Cash account paid from',
  `amount` decimal(15,2) NOT NULL,
  `vendor_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `reference_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `is_over_budget` tinyint(1) NOT NULL DEFAULT '0',
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash' COMMENT 'cash or accrual',
  `current_approval_level` int NOT NULL DEFAULT '0',
  `is_recurring` tinyint(1) NOT NULL DEFAULT '0',
  `recurring_frequency` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `next_due_date` date DEFAULT NULL,
  `created_by` bigint unsigned NOT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `paid_by` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expenses_expense_category_id_index` (`expense_category_id`),
  KEY `expenses_chart_of_account_id_index` (`chart_of_account_id`),
  KEY `expenses_status_index` (`status`),
  KEY `expenses_created_by_index` (`created_by`),
  KEY `expenses_approved_by_index` (`approved_by`),
  KEY `expenses_paid_by_index` (`paid_by`),
  KEY `expenses_branch_id_index` (`branch_id`),
  KEY `expenses_parent_id_index` (`parent_id`),
  KEY `expenses_type_index` (`type`),
  CONSTRAINT `expenses_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `staff` (`id`),
  CONSTRAINT `expenses_chart_of_account_id_foreign` FOREIGN KEY (`chart_of_account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `expenses_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`),
  CONSTRAINT `expenses_expense_category_id_foreign` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`),
  CONSTRAINT `expenses_paid_by_foreign` FOREIGN KEY (`paid_by`) REFERENCES `staff` (`id`),
  CONSTRAINT `expenses_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `expenses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `financial_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `financial_years` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  KEY `financial_years_name_index` (`name`),
  KEY `financial_years_start_date_index` (`start_date`),
  KEY `financial_years_end_date_index` (`end_date`),
  KEY `financial_years_created_at_index` (`created_at`),
  KEY `financial_years_created_by_index` (`created_by`),
  KEY `financial_years_updated_by_index` (`updated_by`),
  KEY `financial_years_deleted_by_index` (`deleted_by`),
  KEY `financial_years_system_type_index` (`system_type`),
  KEY `financial_years_code_index` (`code`),
  KEY `financial_years_branch_id_index` (`branch_id`),
  KEY `financial_years_deleted_at_index` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `general_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `general_charges` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_revenue` tinyint(1) NOT NULL DEFAULT '1',
  `application` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `where_to_apply` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_fine` tinyint(1) NOT NULL DEFAULT '0',
  `charge_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `interval_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `interval` int DEFAULT NULL,
  `credit_account_id` bigint unsigned DEFAULT NULL,
  `saving_product_ids` json DEFAULT NULL,
  `loan_product_ids` json DEFAULT NULL,
  `is_reversible` tinyint(1) NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  KEY `general_charges_name_index` (`name`),
  KEY `general_charges_is_revenue_index` (`is_revenue`),
  KEY `general_charges_application_index` (`application`),
  KEY `general_charges_where_to_apply_index` (`where_to_apply`),
  KEY `general_charges_is_fine_index` (`is_fine`),
  KEY `general_charges_charge_type_index` (`charge_type`),
  KEY `general_charges_interval_type_index` (`interval_type`),
  KEY `general_charges_interval_index` (`interval`),
  KEY `general_charges_credit_account_id_index` (`credit_account_id`),
  KEY `general_charges_is_reversible_index` (`is_reversible`),
  KEY `general_charges_created_at_index` (`created_at`),
  KEY `general_charges_code_index` (`code`),
  KEY `general_charges_created_by_index` (`created_by`),
  KEY `general_charges_deleted_at_index` (`deleted_at`),
  KEY `general_charges_branch_id_foreign` (`branch_id`),
  CONSTRAINT `general_charges_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `general_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `general_ledger` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_id` bigint unsigned NOT NULL,
  `journal_entry_id` bigint unsigned NOT NULL,
  `date` date NOT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `credit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `narration` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  KEY `general_ledger_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `general_ledger_account_id_date_index` (`account_id`,`date`),
  KEY `general_ledger_created_by_index` (`created_by`),
  KEY `general_ledger_updated_by_index` (`updated_by`),
  KEY `general_ledger_deleted_by_index` (`deleted_by`),
  KEY `general_ledger_system_type_index` (`system_type`),
  KEY `general_ledger_code_index` (`code`),
  KEY `general_ledger_branch_id_index` (`branch_id`),
  KEY `general_ledger_deleted_at_index` (`deleted_at`),
  CONSTRAINT `general_ledger_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `general_ledger_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `group_savings_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `group_savings_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `savings_group_id` bigint unsigned NOT NULL,
  `savings_product_id` bigint unsigned NOT NULL,
  `payment_mod_account_id` bigint unsigned DEFAULT NULL COMMENT 'The payment method use_for the transaction (debit account) the id comes from  chart of account',
  `is_new_account` tinyint(1) NOT NULL DEFAULT '1',
  `opening_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `initial_deposit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_created',
  `branch_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_savings_accounts_code_unique` (`code`),
  KEY `group_savings_accounts_savings_group_id_index` (`savings_group_id`),
  KEY `group_savings_accounts_savings_product_id_index` (`savings_product_id`),
  KEY `group_savings_accounts_is_new_account_index` (`is_new_account`),
  KEY `group_savings_accounts_status_index` (`status`),
  KEY `group_savings_accounts_branch_id_index` (`branch_id`),
  KEY `group_savings_accounts_created_by_index` (`created_by`),
  KEY `group_savings_accounts_updated_by_index` (`updated_by`),
  KEY `group_savings_accounts_created_at_index` (`created_at`),
  KEY `group_savings_accounts_payment_mod_account_id_index` (`payment_mod_account_id`),
  CONSTRAINT `gsa_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gsa_group_fk` FOREIGN KEY (`savings_group_id`) REFERENCES `savings_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gsa_product_fk` FOREIGN KEY (`savings_product_id`) REFERENCES `savings_products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `gsa_updated_by_fk` FOREIGN KEY (`updated_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
DROP TABLE IF EXISTS `journal_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entry_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `journal_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency_code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UGX',
  `reference_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date NOT NULL,
  `period_date` date DEFAULT NULL,
  `fiscal_period` char(7) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `narration` text COLLATE utf8mb4_unicode_ci,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `posted_by` bigint unsigned DEFAULT NULL,
  `reversed_by` bigint unsigned DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `reversed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  UNIQUE KEY `journal_entries_entry_no_unique` (`entry_no`),
  KEY `journal_entries_posted_by_foreign` (`posted_by`),
  KEY `journal_entries_reversed_by_foreign` (`reversed_by`),
  KEY `journal_entries_date_index` (`date`),
  KEY `journal_entries_status_index` (`status`),
  KEY `journal_entries_created_by_index` (`created_by`),
  KEY `journal_entries_updated_by_index` (`updated_by`),
  KEY `journal_entries_deleted_by_index` (`deleted_by`),
  KEY `journal_entries_system_type_index` (`system_type`),
  KEY `journal_entries_code_index` (`code`),
  KEY `journal_entries_branch_id_index` (`branch_id`),
  KEY `journal_entries_branch_created_idx` (`branch_id`,`created_at`),
  CONSTRAINT `journal_entries_posted_by_foreign` FOREIGN KEY (`posted_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `journal_entries_reversed_by_foreign` FOREIGN KEY (`reversed_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER `trg_prevent_journal_header_update` BEFORE UPDATE ON `journal_entries` FOR EACH ROW BEGIN
                IF OLD.status = 'posted' AND NEW.status != 'reversed' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Posted journals are immutable. Use reversal journals instead.';
                END IF;
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `journal_entry_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_entry_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `journal_entry_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `credit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `narration` text COLLATE utf8mb4_unicode_ci,
  `member_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loan_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `savings_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cost_centre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `line_no` smallint NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  KEY `journal_entry_lines_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `journal_entry_lines_account_id_index` (`account_id`),
  KEY `journal_entry_lines_created_by_index` (`created_by`),
  KEY `journal_entry_lines_updated_by_index` (`updated_by`),
  KEY `journal_entry_lines_deleted_by_index` (`deleted_by`),
  KEY `journal_entry_lines_system_type_index` (`system_type`),
  KEY `journal_entry_lines_code_index` (`code`),
  KEY `journal_entry_lines_branch_id_index` (`branch_id`),
  KEY `journal_entry_lines_deleted_at_index` (`deleted_at`),
  KEY `journal_entry_lines_branch_created_idx` (`branch_id`,`created_at`),
  CONSTRAINT `journal_entry_lines_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `journal_entry_lines_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER `trg_prevent_control_account_posting` BEFORE INSERT ON `journal_entry_lines` FOR EACH ROW BEGIN
                DECLARE v_is_control BOOLEAN;
                SELECT is_control INTO v_is_control 
                FROM chart_of_accounts 
                WHERE id = NEW.account_id;

                IF v_is_control = 1 THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Cannot post to a control account.';
                END IF;
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER `trg_prevent_journal_line_update` BEFORE UPDATE ON `journal_entry_lines` FOR EACH ROW BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Journal lines are immutable. Use reversal journals instead.';
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER `trg_prevent_journal_line_delete` BEFORE DELETE ON `journal_entry_lines` FOR EACH ROW BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Journal lines are immutable. Use reversal journals instead.';
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `journal_entry_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_entry_sequences` (
  `date_prefix` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `seq` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`date_prefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_application_approvals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_application_approvals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `loan_application_id` bigint unsigned NOT NULL,
  `approver_id` bigint unsigned NOT NULL,
  `level` int NOT NULL DEFAULT '1',
  `decision` enum('approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'approved',
  `comments` text COLLATE utf8mb4_unicode_ci,
  `decided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_laa_application_approver` (`loan_application_id`,`approver_id`),
  KEY `loan_application_approvals_loan_application_id_index` (`loan_application_id`),
  KEY `loan_application_approvals_approver_id_index` (`approver_id`),
  KEY `loan_application_approvals_level_index` (`level`),
  KEY `loan_application_approvals_decision_index` (`decision`),
  KEY `loan_application_approvals_decided_at_index` (`decided_at`),
  CONSTRAINT `loan_application_approvals_approver_id_foreign` FOREIGN KEY (`approver_id`) REFERENCES `staff` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `loan_application_approvals_loan_application_id_foreign` FOREIGN KEY (`loan_application_id`) REFERENCES `loan_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_application_collaterals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_application_collaterals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `loan_application_id` bigint unsigned NOT NULL,
  `asset_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estimated_value` decimal(18,2) NOT NULL DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `proof_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_application_collaterals_loan_application_id_foreign` (`loan_application_id`),
  CONSTRAINT `loan_application_collaterals_loan_application_id_foreign` FOREIGN KEY (`loan_application_id`) REFERENCES `loan_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_application_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_application_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `loan_application_id` bigint unsigned NOT NULL,
  `document_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint unsigned DEFAULT NULL COMMENT 'Bytes',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending | verified | rejected',
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_application_documents_verified_by_foreign` (`verified_by`),
  KEY `loan_application_documents_loan_application_id_index` (`loan_application_id`),
  KEY `loan_application_documents_document_type_index` (`document_type`),
  KEY `loan_application_documents_file_path_index` (`file_path`),
  KEY `loan_application_documents_status_index` (`status`),
  CONSTRAINT `loan_application_documents_loan_application_id_foreign` FOREIGN KEY (`loan_application_id`) REFERENCES `loan_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_application_documents_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_application_guarantors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_application_guarantors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guarantor_id` bigint unsigned NOT NULL COMMENT 'ID of guarantor',
  `guarantor_account_id` bigint unsigned DEFAULT NULL,
  `guarantor_type` enum('staff','individual','group') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Type of the guarantor',
  `guarantee_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `max_guarantee_used` decimal(15,2) NOT NULL DEFAULT '0.00',
  `loan_application_id` bigint unsigned NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `accepted_date` date DEFAULT NULL,
  `released_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_loan_guarantor` (`loan_application_id`,`guarantor_id`,`guarantor_type`,`guarantor_account_id`),
  KEY `loan_application_guarantors_code_index` (`code`),
  KEY `loan_application_guarantors_guarantor_id_index` (`guarantor_id`),
  KEY `loan_application_guarantors_guarantor_type_index` (`guarantor_type`),
  KEY `loan_application_guarantors_loan_application_id_index` (`loan_application_id`),
  KEY `loan_application_guarantors_system_type_index` (`system_type`),
  KEY `loan_application_guarantors_created_by_index` (`created_by`),
  KEY `loan_application_guarantors_updated_by_index` (`updated_by`),
  KEY `loan_application_guarantors_approved_by_index` (`approved_by`),
  KEY `loan_application_guarantors_accepted_date_index` (`accepted_date`),
  KEY `loan_application_guarantors_released_date_index` (`released_date`),
  KEY `loan_application_guarantors_guarantor_account_id_index` (`guarantor_account_id`),
  CONSTRAINT `loan_application_guarantors_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_application_guarantors_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_application_guarantors_loan_application_id_foreign` FOREIGN KEY (`loan_application_id`) REFERENCES `loan_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_application_guarantors_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_application_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_application_status_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `loan_application_id` bigint unsigned NOT NULL,
  `from_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `changed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_application_status_history_loan_application_id_index` (`loan_application_id`),
  KEY `loan_application_status_history_from_status_index` (`from_status`),
  KEY `loan_application_status_history_to_status_index` (`to_status`),
  KEY `loan_application_status_history_changed_by_index` (`changed_by`),
  KEY `loan_application_status_history_ip_address_index` (`ip_address`),
  KEY `loan_application_status_history_changed_at_index` (`changed_at`),
  CONSTRAINT `loan_application_status_history_changed_by_foreign` FOREIGN KEY (`changed_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_application_status_history_loan_application_id_foreign` FOREIGN KEY (`loan_application_id`) REFERENCES `loan_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_no` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `member_id` bigint unsigned NOT NULL,
  `loan_product_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `loan_officer_id` bigint unsigned DEFAULT NULL,
  `requested_amount` decimal(15,2) NOT NULL,
  `requested_term` smallint unsigned NOT NULL,
  `purpose` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `repayment_source` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `recommended_amount` decimal(15,2) DEFAULT NULL,
  `recommended_term` smallint unsigned DEFAULT NULL,
  `recommended_interest_rate` decimal(5,2) DEFAULT NULL,
  `approved_amount` decimal(15,2) DEFAULT NULL,
  `final_approved_amount` decimal(15,2) DEFAULT NULL,
  `final_approved_term` smallint unsigned DEFAULT NULL,
  `proposed_start_date` date DEFAULT NULL,
  `schedule_locked_at` timestamp NULL DEFAULT NULL,
  `approved_term` smallint unsigned DEFAULT NULL,
  `approved_interest_rate` decimal(5,2) DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `cancellation_reason` text COLLATE utf8mb4_unicode_ci,
  `appraisal_notes` text COLLATE utf8mb4_unicode_ci,
  `officer_notes` text COLLATE utf8mb4_unicode_ci,
  `bm_notes` text COLLATE utf8mb4_unicode_ci,
  `correction_reason` text COLLATE utf8mb4_unicode_ci,
  `quorum_required` int unsigned DEFAULT NULL,
  `approval_threshold` int unsigned DEFAULT NULL,
  `unanimity_required` tinyint(1) NOT NULL DEFAULT '0',
  `return_reason` text COLLATE utf8mb4_unicode_ci,
  `risk_rating` enum('low','medium','high','critical') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approval_notes` text COLLATE utf8mb4_unicode_ci,
  `appraised_by` bigint unsigned DEFAULT NULL,
  `recommended_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `disbursed_loan_id` bigint unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `returned_at` timestamp NULL DEFAULT NULL,
  `returned_by` bigint unsigned DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `recommended_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `disbursed_at` timestamp NULL DEFAULT NULL,
  `schedule_date` date DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loan_applications_application_no_unique` (`application_no`),
  KEY `loan_applications_member_id_index` (`member_id`),
  KEY `loan_applications_loan_product_id_index` (`loan_product_id`),
  KEY `loan_applications_branch_id_index` (`branch_id`),
  KEY `loan_applications_loan_officer_id_index` (`loan_officer_id`),
  KEY `loan_applications_status_index` (`status`),
  KEY `loan_applications_appraised_by_index` (`appraised_by`),
  KEY `loan_applications_recommended_by_index` (`recommended_by`),
  KEY `loan_applications_approved_by_index` (`approved_by`),
  KEY `loan_applications_rejected_by_index` (`rejected_by`),
  KEY `loan_applications_disbursed_loan_id_index` (`disbursed_loan_id`),
  KEY `loan_applications_submitted_at_index` (`submitted_at`),
  KEY `loan_applications_created_at_index` (`created_at`),
  KEY `loan_applications_updated_at_index` (`updated_at`),
  KEY `loan_applications_cancelled_by_foreign` (`cancelled_by`),
  KEY `loan_applications_risk_rating_index` (`risk_rating`),
  KEY `loan_applications_reviewed_at_index` (`reviewed_at`),
  KEY `loan_applications_reviewed_by_index` (`reviewed_by`),
  KEY `loan_applications_returned_at_index` (`returned_at`),
  KEY `loan_applications_returned_by_index` (`returned_by`),
  KEY `loan_applications_quorum_required_index` (`quorum_required`),
  KEY `loan_applications_approval_threshold_index` (`approval_threshold`),
  KEY `loan_applications_unanimity_required_index` (`unanimity_required`),
  KEY `loan_applications_final_approved_term_index` (`final_approved_term`),
  KEY `loan_applications_proposed_start_date_index` (`proposed_start_date`),
  KEY `loan_applications_schedule_locked_at_index` (`schedule_locked_at`),
  CONSTRAINT `loan_applications_appraised_by_foreign` FOREIGN KEY (`appraised_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_applications_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_applications_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_applications_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_applications_disbursed_loan_id_foreign` FOREIGN KEY (`disbursed_loan_id`) REFERENCES `loans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_applications_loan_officer_id_foreign` FOREIGN KEY (`loan_officer_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_applications_loan_product_id_foreign` FOREIGN KEY (`loan_product_id`) REFERENCES `loan_products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `loan_applications_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_applications_recommended_by_foreign` FOREIGN KEY (`recommended_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_applications_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_applied_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_applied_charges` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_created',
  `loan_id` bigint unsigned NOT NULL,
  `charge_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `charge_type` enum('flat','percentage') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'flat',
  `application_timing` enum('on_disbursement','on_repayment','monthly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'on_disbursement',
  `charge_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `default_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `used_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `is_waived` tinyint(1) NOT NULL DEFAULT '0',
  `waived_by` bigint unsigned DEFAULT NULL,
  `waived_date` datetime DEFAULT NULL,
  `waiver_reason` text COLLATE utf8mb4_unicode_ci,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_charges_loan_id_foreign` (`loan_id`),
  KEY `loan_charges_branch_id_index` (`branch_id`),
  KEY `loan_charges_waived_by_foreign` (`waived_by`),
  KEY `lac_charge_id_foreign` (`charge_id`),
  CONSTRAINT `lac_charge_id_foreign` FOREIGN KEY (`charge_id`) REFERENCES `loan_charges` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_charges_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_charges_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_charges_waived_by_foreign` FOREIGN KEY (`waived_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_approval_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_approval_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `loan_product_id` bigint unsigned NOT NULL,
  `quorum_size` int unsigned NOT NULL DEFAULT '3',
  `approval_threshold` int unsigned NOT NULL DEFAULT '2',
  `amount_tiers` json DEFAULT NULL,
  `abstention_timeout_hours` int unsigned NOT NULL DEFAULT '48',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loan_approval_settings_loan_product_id_unique` (`loan_product_id`),
  CONSTRAINT `loan_approval_settings_loan_product_id_foreign` FOREIGN KEY (`loan_product_id`) REFERENCES `loan_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_approval_votes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_approval_votes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `loan_application_id` bigint unsigned NOT NULL,
  `staff_id` bigint unsigned NOT NULL,
  `decision` enum('approve','decline') COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` text COLLATE utf8mb4_unicode_ci,
  `abstained` tinyint(1) NOT NULL DEFAULT '0',
  `abstained_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loan_approval_votes_loan_application_id_staff_id_unique` (`loan_application_id`,`staff_id`),
  KEY `loan_approval_votes_staff_id_foreign` (`staff_id`),
  KEY `loan_approval_votes_abstained_by_foreign` (`abstained_by`),
  CONSTRAINT `loan_approval_votes_abstained_by_foreign` FOREIGN KEY (`abstained_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_approval_votes_loan_application_id_foreign` FOREIGN KEY (`loan_application_id`) REFERENCES `loan_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_approval_votes_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_arrears_tiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_arrears_tiers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `from_day` int unsigned NOT NULL,
  `to_day` int unsigned DEFAULT NULL,
  `charge_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percentage',
  `charge_value` decimal(15,2) NOT NULL DEFAULT '0.00',
  `applies_to` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'outstanding_balance',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_tier_unique` (`tenant_id`,`from_day`,`to_day`),
  KEY `loan_arrears_tiers_tenant_id_index` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_charges` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category` enum('processing_fee','disbursement_fee','penalty','late_fee','appraisal_fee','insurance','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `charge_type` enum('flat','percentage') COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` decimal(15,2) NOT NULL DEFAULT '0.00',
  `frequency` enum('one_time','daily','weekly','monthly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'one_time',
  `grace_days` int unsigned NOT NULL DEFAULT '0',
  `max_value` decimal(15,2) DEFAULT NULL,
  `max_value_type` enum('none','flat_cap','percentage_of_outstanding') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `income_account_id` bigint unsigned DEFAULT NULL,
  `receivable_account_id` bigint unsigned DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_charges_tenant_id_index` (`tenant_id`),
  KEY `loan_charges_category_index` (`category`),
  KEY `loan_charges_is_active_index` (`is_active`),
  KEY `loan_charges_code_index` (`code`),
  KEY `loan_charges_income_account_id_foreign` (`income_account_id`),
  KEY `loan_charges_receivable_account_id_foreign` (`receivable_account_id`),
  CONSTRAINT `loan_charges_income_account_id_foreign` FOREIGN KEY (`income_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_charges_receivable_account_id_foreign` FOREIGN KEY (`receivable_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_collateral`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_collateral` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_created',
  `loan_id` bigint unsigned NOT NULL,
  `member_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `collateral_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `estimated_value` decimal(15,2) NOT NULL DEFAULT '0.00',
  `forced_sale_value` decimal(15,2) NOT NULL DEFAULT '0.00',
  `serial_or_title_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verification_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verified_by` bigint unsigned DEFAULT NULL,
  `verification_date` date DEFAULT NULL,
  `release_date` date DEFAULT NULL,
  `document_ref` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_collateral_system_type_index` (`system_type`),
  KEY `loan_collateral_loan_id_index` (`loan_id`),
  KEY `loan_collateral_member_id_index` (`member_id`),
  KEY `loan_collateral_branch_id_index` (`branch_id`),
  KEY `loan_collateral_collateral_type_index` (`collateral_type`),
  KEY `loan_collateral_serial_or_title_no_index` (`serial_or_title_no`),
  KEY `loan_collateral_verification_status_index` (`verification_status`),
  KEY `loan_collateral_verified_by_index` (`verified_by`),
  KEY `loan_collateral_verification_date_index` (`verification_date`),
  KEY `loan_collateral_release_date_index` (`release_date`),
  KEY `loan_collateral_document_ref_index` (`document_ref`),
  CONSTRAINT `loan_collateral_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_collateral_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_collateral_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_collateral_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_guarantors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_guarantors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_created',
  `loan_id` bigint unsigned DEFAULT NULL,
  `loan_application_id` bigint unsigned DEFAULT NULL,
  `member_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `guarantee_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `guarantee_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `max_guarantee_used` decimal(15,2) NOT NULL DEFAULT '0.00',
  `accepted_date` date DEFAULT NULL,
  `released_date` date DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_guarantors_loan_id_index` (`loan_id`),
  KEY `loan_guarantors_member_id_index` (`member_id`),
  KEY `loan_guarantors_branch_id_index` (`branch_id`),
  KEY `loan_guarantors_guarantee_type_index` (`guarantee_type`),
  KEY `loan_guarantors_accepted_date_index` (`accepted_date`),
  KEY `loan_guarantors_released_date_index` (`released_date`),
  KEY `loan_guarantors_status_index` (`status`),
  KEY `loan_guarantors_approved_by_index` (`approved_by`),
  KEY `loan_guarantors_loan_application_id_index` (`loan_application_id`),
  CONSTRAINT `loan_guarantors_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_guarantors_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_guarantors_loan_application_id_foreign` FOREIGN KEY (`loan_application_id`) REFERENCES `loan_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_guarantors_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_guarantors_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `loan_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general' COMMENT 'general, approval, rejection, disbursement, rescheduling, write_off, top_up',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_notes_loan_id_foreign` (`loan_id`),
  KEY `loan_notes_created_by_foreign` (`created_by`),
  CONSTRAINT `loan_notes_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_notes_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_created',
  `loan_id` bigint unsigned NOT NULL,
  `member_id` bigint unsigned NOT NULL,
  `channel` enum('SMS','EMAIL','WHATSAPP') COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  `delivery_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `failure_reason` text COLLATE utf8mb4_unicode_ci,
  `sent_by` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_notifications_system_type_index` (`system_type`),
  KEY `loan_notifications_loan_id_index` (`loan_id`),
  KEY `loan_notifications_member_id_index` (`member_id`),
  KEY `loan_notifications_channel_index` (`channel`),
  KEY `loan_notifications_template_id_index` (`template_id`),
  KEY `loan_notifications_sent_at_index` (`sent_at`),
  KEY `loan_notifications_delivery_status_index` (`delivery_status`),
  KEY `loan_notifications_sent_by_index` (`sent_by`),
  KEY `loan_notifications_created_by_index` (`created_by`),
  KEY `loan_notifications_updated_by_index` (`updated_by`),
  CONSTRAINT `loan_notifications_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_notifications_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_notifications_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_notifications_sent_by_foreign` FOREIGN KEY (`sent_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_notifications_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_penalty_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_penalty_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_created',
  `loan_product_id` bigint unsigned NOT NULL,
  `penalty_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `penalty_rate` decimal(15,2) NOT NULL DEFAULT '0.00',
  `grace_days` int NOT NULL DEFAULT '0',
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `applies_to` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_penalty_rules_system_type_index` (`system_type`),
  KEY `loan_penalty_rules_loan_product_id_index` (`loan_product_id`),
  KEY `loan_penalty_rules_penalty_type_index` (`penalty_type`),
  KEY `loan_penalty_rules_applies_to_index` (`applies_to`),
  KEY `loan_penalty_rules_branch_id_index` (`branch_id`),
  KEY `loan_penalty_rules_created_by_index` (`created_by`),
  KEY `loan_penalty_rules_updated_by_index` (`updated_by`),
  CONSTRAINT `loan_penalty_rules_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_penalty_rules_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_penalty_rules_loan_product_id_foreign` FOREIGN KEY (`loan_product_id`) REFERENCES `loan_products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_penalty_rules_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_product_charge`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_product_charge` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `loan_product_id` bigint unsigned NOT NULL,
  `loan_charge_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loan_product_charge_unique` (`loan_product_id`,`loan_charge_id`),
  KEY `loan_product_charge_loan_charge_id_foreign` (`loan_charge_id`),
  CONSTRAINT `loan_product_charge_loan_charge_id_foreign` FOREIGN KEY (`loan_charge_id`) REFERENCES `loan_charges` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_product_charge_loan_product_id_foreign` FOREIGN KEY (`loan_product_id`) REFERENCES `loan_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_product_required_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_product_required_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `loan_product_id` bigint unsigned NOT NULL,
  `document_type_id` bigint unsigned NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT '1',
  `required_stage` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'submission',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loan_product_required_docs_unique` (`loan_product_id`,`document_type_id`,`required_stage`),
  KEY `loan_product_required_documents_loan_product_id_index` (`loan_product_id`),
  KEY `loan_product_required_documents_document_type_id_index` (`document_type_id`),
  KEY `loan_product_required_documents_is_required_index` (`is_required`),
  KEY `loan_product_required_documents_required_stage_index` (`required_stage`),
  KEY `loan_product_required_documents_is_active_index` (`is_active`),
  CONSTRAINT `loan_product_required_documents_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `loan_product_required_documents_loan_product_id_foreign` FOREIGN KEY (`loan_product_id`) REFERENCES `loan_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_created',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `min_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `max_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `exposure_limit` decimal(15,2) DEFAULT NULL COMMENT 'Maximum total outstanding loan exposure per member for this product',
  `arrears_action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'warn' COMMENT 'block = hard-fail on arrears, warn = flag only',
  `interest_rate` decimal(15,2) NOT NULL DEFAULT '0.00',
  `interest_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `repayment_structure` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `interest_period` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loan_duration` int DEFAULT NULL,
  `duration_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `repayment_cycle` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `max_guarantors` int NOT NULL DEFAULT '0',
  `min_guarantors` int NOT NULL DEFAULT '0',
  `min_membership_months` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'Minimum months of membership before eligibility',
  `grace_period` int NOT NULL DEFAULT '0',
  `penalty_grace_days` int unsigned NOT NULL DEFAULT '0',
  `savings_appraisal_threshold` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT '% of applicant savings that does not require appraisal',
  `warning_days` smallint unsigned DEFAULT NULL COMMENT 'Days before due date to start sending repayment reminders',
  `max_securities` tinyint unsigned NOT NULL DEFAULT '3' COMMENT 'Maximum number of securities allowed for a loan',
  `security_value_percentage` decimal(5,2) NOT NULL DEFAULT '150.00' COMMENT 'Required security value as % of loan amount',
  `allow_sub_schedule` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Allow reducing balance interest generation on a yearly basis',
  `penalty_rate` decimal(15,2) NOT NULL DEFAULT '0.00',
  `penalty_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requires_approval` tinyint(1) NOT NULL DEFAULT '0',
  `allow_top_up` tinyint(1) NOT NULL DEFAULT '1',
  `topup_repayment_basis` enum('principal','principal_interest','outstanding_balance') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `topup_min_percentage` decimal(5,2) DEFAULT NULL,
  `topup_auto_disbursement` tinyint(1) DEFAULT NULL,
  `allow_reschedule` tinyint(1) NOT NULL DEFAULT '1',
  `processing_fee_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `processing_fee_value` decimal(15,2) NOT NULL DEFAULT '0.00',
  `loan_portfolio_account_id` bigint unsigned DEFAULT NULL,
  `interest_income_account_id` bigint unsigned DEFAULT NULL,
  `interest_receivable_account_id` bigint unsigned DEFAULT NULL,
  `penalty_income_account_id` bigint unsigned DEFAULT NULL,
  `penalty_receivable_account_id` bigint unsigned DEFAULT NULL,
  `disbursement_account_id` bigint unsigned DEFAULT NULL,
  `charges_income_account_id` bigint unsigned DEFAULT NULL,
  `charges_receivable_account_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loan_products_code_unique` (`code`),
  KEY `loan_products_name_index` (`name`),
  KEY `loan_products_is_active_index` (`is_active`),
  KEY `loan_products_branch_id_index` (`branch_id`),
  KEY `loan_products_loan_portfolio_account_id_foreign` (`loan_portfolio_account_id`),
  KEY `loan_products_interest_income_account_id_foreign` (`interest_income_account_id`),
  KEY `loan_products_interest_receivable_account_id_foreign` (`interest_receivable_account_id`),
  KEY `loan_products_penalty_income_account_id_foreign` (`penalty_income_account_id`),
  KEY `loan_products_penalty_receivable_account_id_foreign` (`penalty_receivable_account_id`),
  KEY `loan_products_disbursement_account_id_foreign` (`disbursement_account_id`),
  KEY `loan_products_created_by_foreign` (`created_by`),
  KEY `loan_products_updated_by_foreign` (`updated_by`),
  KEY `loan_products_interest_method_index` (`interest_method`),
  KEY `loan_products_repayment_structure_index` (`repayment_structure`),
  KEY `loan_products_charges_income_account_id_foreign` (`charges_income_account_id`),
  KEY `loan_products_charges_receivable_account_id_foreign` (`charges_receivable_account_id`),
  CONSTRAINT `loan_products_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_products_charges_income_account_id_foreign` FOREIGN KEY (`charges_income_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_products_charges_receivable_account_id_foreign` FOREIGN KEY (`charges_receivable_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_products_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_products_disbursement_account_id_foreign` FOREIGN KEY (`disbursement_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_products_interest_income_account_id_foreign` FOREIGN KEY (`interest_income_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_products_interest_receivable_account_id_foreign` FOREIGN KEY (`interest_receivable_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_products_loan_portfolio_account_id_foreign` FOREIGN KEY (`loan_portfolio_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_products_penalty_income_account_id_foreign` FOREIGN KEY (`penalty_income_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_products_penalty_receivable_account_id_foreign` FOREIGN KEY (`penalty_receivable_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_products_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_repayment_schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_repayment_schedule` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loan_id` bigint unsigned NOT NULL,
  `reschedule_id` bigint unsigned DEFAULT NULL,
  `due_date` date NOT NULL,
  `installment_no` int NOT NULL,
  `principal_due` decimal(15,2) NOT NULL,
  `interest_due` decimal(15,2) NOT NULL,
  `charges_due` decimal(15,2) NOT NULL DEFAULT '0.00',
  `penalty_due` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_due` decimal(15,2) NOT NULL,
  `principal_paid` decimal(15,2) NOT NULL DEFAULT '0.00',
  `interest_paid` decimal(15,2) NOT NULL DEFAULT '0.00',
  `charges_paid` decimal(15,2) NOT NULL DEFAULT '0.00',
  `penalty_paid` decimal(15,2) NOT NULL DEFAULT '0.00',
  `outstanding_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `paid_at` date DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `last_arrears_tier_id` bigint unsigned DEFAULT NULL,
  `paid_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  KEY `loan_schedules_loan_id_foreign` (`loan_id`),
  KEY `loan_schedules_created_by_index` (`created_by`),
  KEY `loan_schedules_updated_by_index` (`updated_by`),
  KEY `loan_schedules_deleted_by_index` (`deleted_by`),
  KEY `loan_schedules_system_type_index` (`system_type`),
  KEY `loan_schedules_code_index` (`code`),
  KEY `loan_schedules_branch_id_index` (`branch_id`),
  KEY `loan_schedules_deleted_at_index` (`deleted_at`),
  KEY `loan_repayment_schedule_reschedule_id_foreign` (`reschedule_id`),
  CONSTRAINT `loan_repayment_schedule_reschedule_id_foreign` FOREIGN KEY (`reschedule_id`) REFERENCES `loan_rescheduling` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_schedules_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_rescheduling`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_rescheduling` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_created',
  `reschedule_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reschedule_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'tenor_extension, rate_change, capitalization',
  `old_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Loan status at the time of rescheduling (disbursed, active, arrears)',
  `original_loan_id` bigint unsigned NOT NULL,
  `new_loan_id` bigint unsigned DEFAULT NULL,
  `reschedule_date` date DEFAULT NULL,
  `old_outstanding` decimal(15,2) NOT NULL DEFAULT '0.00',
  `old_interest_rate` decimal(5,2) DEFAULT NULL,
  `old_remaining_periods` int DEFAULT NULL,
  `old_maturity_date` date DEFAULT NULL,
  `new_principal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `new_rate` decimal(15,2) NOT NULL DEFAULT '0.00',
  `new_duration` int DEFAULT NULL,
  `new_maturity_date` date DEFAULT NULL,
  `capitalized_arrears` decimal(15,2) NOT NULL DEFAULT '0.00',
  `capitalized_interest` decimal(15,2) NOT NULL DEFAULT '0.00',
  `penalties_waived` decimal(15,2) NOT NULL DEFAULT '0.00',
  `interest_waived` decimal(15,2) NOT NULL DEFAULT '0.00',
  `fees_applied` json DEFAULT NULL,
  `old_product_id` bigint unsigned DEFAULT NULL,
  `new_product_id` bigint unsigned DEFAULT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `approved_by` bigint unsigned DEFAULT NULL,
  `performed_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_rescheduling_reschedule_id_index` (`reschedule_id`),
  KEY `loan_rescheduling_original_loan_id_index` (`original_loan_id`),
  KEY `loan_rescheduling_new_loan_id_index` (`new_loan_id`),
  KEY `loan_rescheduling_reschedule_date_index` (`reschedule_date`),
  KEY `loan_rescheduling_approved_by_index` (`approved_by`),
  KEY `loan_rescheduling_branch_id_index` (`branch_id`),
  KEY `loan_rescheduling_performed_by_foreign` (`performed_by`),
  CONSTRAINT `loan_rescheduling_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_rescheduling_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_rescheduling_new_loan_id_foreign` FOREIGN KEY (`new_loan_id`) REFERENCES `loans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_rescheduling_original_loan_id_foreign` FOREIGN KEY (`original_loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_rescheduling_performed_by_foreign` FOREIGN KEY (`performed_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_created',
  `branch_id` bigint unsigned NOT NULL,
  `min_approvers` int NOT NULL DEFAULT '1',
  `max_approvers` int NOT NULL DEFAULT '3',
  `member_id` bigint unsigned DEFAULT NULL,
  `guarantor_mode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loan_id` bigint unsigned DEFAULT NULL,
  `holiday_skip_mode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guarantor_id` bigint unsigned DEFAULT NULL,
  `public_holiday_id` bigint unsigned DEFAULT NULL,
  `allow_top_up` tinyint(1) NOT NULL DEFAULT '1',
  `topup_repayment_basis` enum('principal','principal_interest','outstanding_balance') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'principal_interest',
  `topup_min_percentage` decimal(5,2) NOT NULL DEFAULT '40.00',
  `topup_auto_disbursement` tinyint(1) NOT NULL DEFAULT '0',
  `reschedule_fee_income_account_id` bigint unsigned DEFAULT NULL,
  `allow_reschedule` tinyint(1) NOT NULL DEFAULT '1',
  `max_reschedule_count` tinyint NOT NULL DEFAULT '3',
  `loan_reschedule_id` bigint unsigned DEFAULT NULL,
  `auto_penalty` tinyint(1) NOT NULL DEFAULT '1',
  `charge_distribution_mode` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'evenly' COMMENT 'How on_repayment charges spread: evenly | first_installment | last_installment',
  `push_installments_on_holidays` tinyint(1) NOT NULL DEFAULT '0',
  `push_installments_on_holidays_weekdays_only` tinyint(1) NOT NULL DEFAULT '0',
  `relative_scheduling` tinyint(1) NOT NULL DEFAULT '0',
  `charge_deduction_mode` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'deduct_from_principal' COMMENT 'How on_disbursement charges are collected: deduct_from_principal | capitalize | debit_savings | pay_cash',
  `repayment_allocation_order` enum('principal_interest_penalties_charges','interest_principal_penalties_charges','penalties_charges_interest_principal','penalties_charges_principal_interest') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'penalties_charges_interest_principal',
  `penalty_grace_days` int NOT NULL DEFAULT '0',
  `notification_channels` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loan_cycle_limit` int NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `reschedule_fee_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `reschedule_fee_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'flat',
  `reschedule_fee_amount` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `reschedule_fee_basis` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reschedule_fee_collection` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `reschedule_product_change_fee_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `reschedule_product_change_fee_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'flat',
  `reschedule_product_change_fee_amount` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `reschedule_product_change_fee_basis` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reschedule_product_change_fee_collection` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `reschedule_same_product_fee_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `reschedule_same_product_fee_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'flat',
  `reschedule_same_product_fee_amount` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `reschedule_same_product_fee_basis` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reschedule_same_product_fee_collection` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `reschedule_other_charges_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `reschedule_other_charges_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'flat',
  `reschedule_other_charges_amount` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `reschedule_other_charges_basis` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reschedule_other_charges_collection` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  PRIMARY KEY (`id`),
  KEY `loan_settings_system_type_index` (`system_type`),
  KEY `loan_settings_branch_id_index` (`branch_id`),
  KEY `loan_settings_member_id_index` (`member_id`),
  KEY `loan_settings_loan_id_index` (`loan_id`),
  KEY `loan_settings_guarantor_id_index` (`guarantor_id`),
  KEY `loan_settings_public_holiday_id_index` (`public_holiday_id`),
  KEY `loan_settings_loan_reschedule_id_index` (`loan_reschedule_id`),
  KEY `loan_settings_created_by_index` (`created_by`),
  KEY `loan_settings_updated_by_index` (`updated_by`),
  CONSTRAINT `loan_settings_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_settings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_settings_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_settings_loan_reschedule_id_foreign` FOREIGN KEY (`loan_reschedule_id`) REFERENCES `loan_rescheduling` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_settings_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_settings_public_holiday_id_foreign` FOREIGN KEY (`public_holiday_id`) REFERENCES `public_holidays` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_status_histories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_status_histories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `loan_id` bigint unsigned NOT NULL,
  `from_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `changed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_status_histories_loan_id_foreign` (`loan_id`),
  KEY `loan_status_histories_changed_by_foreign` (`changed_by`),
  CONSTRAINT `loan_status_histories_changed_by_foreign` FOREIGN KEY (`changed_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_status_histories_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_status_history_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_status_history_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_created',
  `branch_id` bigint unsigned DEFAULT NULL,
  `loan_id` bigint unsigned NOT NULL,
  `changed_by` bigint unsigned DEFAULT NULL,
  `old_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `change_reason` text COLLATE utf8mb4_unicode_ci,
  `changed_at` datetime NOT NULL,
  `ip_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_status_history_audit_loan_id_index` (`loan_id`),
  KEY `loan_status_history_audit_changed_by_index` (`changed_by`),
  KEY `loan_status_history_audit_old_status_index` (`old_status`),
  KEY `loan_status_history_audit_new_status_index` (`new_status`),
  KEY `loan_status_history_audit_changed_at_index` (`changed_at`),
  KEY `loan_status_history_audit_branch_id_index` (`branch_id`),
  CONSTRAINT `loan_status_history_audit_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_status_history_audit_changed_by_foreign` FOREIGN KEY (`changed_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_status_history_audit_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_topup_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_topup_applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `member_id` bigint unsigned NOT NULL,
  `reference_loan_id` bigint unsigned NOT NULL,
  `new_loan_id` bigint unsigned DEFAULT NULL,
  `loan_application_id` bigint unsigned DEFAULT NULL,
  `topup_type` enum('consolidated','parallel') COLLATE utf8mb4_unicode_ci NOT NULL,
  `topup_amount` decimal(15,2) NOT NULL,
  `new_loan_total` decimal(15,2) NOT NULL,
  `requested_term` int NOT NULL,
  `new_monthly_installment` decimal(15,2) NOT NULL,
  `dsr_before` decimal(5,4) DEFAULT NULL,
  `dsr_after` decimal(5,4) DEFAULT NULL,
  `eligibility_passed` tinyint(1) NOT NULL DEFAULT '0',
  `eligibility_checks` json DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `rejected_reason` text COLLATE utf8mb4_unicode_ci,
  `branch_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned NOT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `disbursed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loan_topup_applications_application_number_unique` (`application_number`),
  KEY `loan_topup_applications_member_id_foreign` (`member_id`),
  KEY `loan_topup_applications_reference_loan_id_foreign` (`reference_loan_id`),
  KEY `loan_topup_applications_new_loan_id_foreign` (`new_loan_id`),
  KEY `loan_topup_applications_created_by_foreign` (`created_by`),
  KEY `loan_topup_applications_approved_by_foreign` (`approved_by`),
  KEY `loan_topup_applications_branch_id_index` (`branch_id`),
  CONSTRAINT `loan_topup_applications_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_topup_applications_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_topup_applications_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_topup_applications_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_topup_applications_new_loan_id_foreign` FOREIGN KEY (`new_loan_id`) REFERENCES `loans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_topup_applications_reference_loan_id_foreign` FOREIGN KEY (`reference_loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_created',
  `payment_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loan_id` bigint unsigned NOT NULL,
  `reschedule_id` bigint unsigned DEFAULT NULL COMMENT 'References loan_rescheduling.id; set when payment is made on a rescheduled loan',
  `member_id` bigint unsigned NOT NULL,
  `amount_paid` decimal(15,2) NOT NULL DEFAULT '0.00',
  `principal_portion` decimal(15,2) NOT NULL DEFAULT '0.00',
  `interest_portion` decimal(15,2) NOT NULL DEFAULT '0.00',
  `penalty_portion` decimal(15,2) NOT NULL DEFAULT '0.00',
  `charges_portion` decimal(15,2) NOT NULL DEFAULT '0.00',
  `payment_date` date DEFAULT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipt_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `collected_by` bigint unsigned DEFAULT NULL,
  `reversal_flag` tinyint(1) NOT NULL DEFAULT '0',
  `reversed_by` bigint unsigned DEFAULT NULL,
  `reversed_date` datetime DEFAULT NULL,
  `transaction_ref` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_transactions_payment_id_index` (`payment_id`),
  KEY `loan_transactions_loan_id_index` (`loan_id`),
  KEY `loan_transactions_member_id_index` (`member_id`),
  KEY `loan_transactions_payment_date_index` (`payment_date`),
  KEY `loan_transactions_payment_method_index` (`payment_method`),
  KEY `loan_transactions_receipt_no_index` (`receipt_no`),
  KEY `loan_transactions_collected_by_index` (`collected_by`),
  KEY `loan_transactions_reversal_flag_index` (`reversal_flag`),
  KEY `loan_transactions_reversed_by_index` (`reversed_by`),
  KEY `loan_transactions_transaction_ref_index` (`transaction_ref`),
  KEY `loan_transactions_branch_id_index` (`branch_id`),
  KEY `loan_transactions_reschedule_id_foreign` (`reschedule_id`),
  CONSTRAINT `loan_transactions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_transactions_collected_by_foreign` FOREIGN KEY (`collected_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_transactions_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_transactions_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_transactions_reschedule_id_foreign` FOREIGN KEY (`reschedule_id`) REFERENCES `loan_rescheduling` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_transactions_reversed_by_foreign` FOREIGN KEY (`reversed_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_write_offs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_write_offs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_created',
  `writeoff_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loan_id` bigint unsigned NOT NULL,
  `writeoff_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `writeoff_date` date DEFAULT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `approved_by` bigint unsigned DEFAULT NULL,
  `recovery_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount_recovered` decimal(15,2) NOT NULL DEFAULT '0.00',
  `recovery_date` date DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_write_offs_writeoff_id_index` (`writeoff_id`),
  KEY `loan_write_offs_loan_id_index` (`loan_id`),
  KEY `loan_write_offs_writeoff_date_index` (`writeoff_date`),
  KEY `loan_write_offs_approved_by_index` (`approved_by`),
  KEY `loan_write_offs_recovery_status_index` (`recovery_status`),
  KEY `loan_write_offs_recovery_date_index` (`recovery_date`),
  KEY `loan_write_offs_branch_id_index` (`branch_id`),
  CONSTRAINT `loan_write_offs_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_write_offs_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_write_offs_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `parent_loan_id` bigint unsigned DEFAULT NULL,
  `topup_type` enum('consolidated','parallel','none') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loan_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `loan_application_id` bigint unsigned DEFAULT NULL,
  `member_id` bigint unsigned NOT NULL,
  `loan_product_id` bigint unsigned DEFAULT NULL,
  `principal` decimal(15,2) NOT NULL,
  `processing_fee` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_charges_deducted` decimal(15,2) NOT NULL DEFAULT '0.00',
  `net_disbursed_amount` decimal(15,2) DEFAULT NULL,
  `applied_amount` decimal(15,2) DEFAULT '0.00',
  `approved_amount` decimal(15,2) DEFAULT '0.00',
  `disbursement_amount` decimal(15,2) DEFAULT NULL,
  `interest_rate` decimal(5,2) NOT NULL,
  `term_months` int NOT NULL,
  `disbursed_at` date DEFAULT NULL,
  `schedule_date` date DEFAULT NULL,
  `disbursement_method` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disbursement_reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `charge_deduction_mode` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `charge_receipt_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `savings_account_id` bigint unsigned DEFAULT NULL,
  `mobile_money_provider` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile_money_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `is_rescheduled` tinyint(1) NOT NULL DEFAULT '0',
  `reschedule_count` tinyint NOT NULL DEFAULT '0',
  `original_term_months` int DEFAULT NULL,
  `original_interest_rate` decimal(5,2) DEFAULT NULL,
  `outstanding_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `approved_by` bigint unsigned DEFAULT NULL,
  `disbursed_by` bigint unsigned DEFAULT NULL,
  `loan_created_by` bigint unsigned DEFAULT NULL,
  `loan_disbursed_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  `reschedule_loan_parent_id` bigint unsigned DEFAULT NULL,
  `closed_by_id` bigint unsigned DEFAULT NULL,
  `sacco_branch_id` bigint unsigned DEFAULT NULL,
  `loan_officer_id` bigint unsigned DEFAULT NULL,
  `assigned_to_approve` bigint unsigned DEFAULT NULL,
  `assigned_by_approve` bigint unsigned DEFAULT NULL,
  `approved_by_id` bigint unsigned DEFAULT NULL,
  `withdrawn_by` bigint unsigned DEFAULT NULL,
  `written_off_by_id` bigint unsigned DEFAULT NULL,
  `assigned_after_decline_to_review` bigint unsigned DEFAULT NULL,
  `assigned_to_disburse` bigint unsigned DEFAULT NULL,
  `comment_on_disburse` text COLLATE utf8mb4_unicode_ci,
  `interest_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `interest_period` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `override_interest` tinyint(1) NOT NULL DEFAULT '0',
  `overrride_interest_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `loan_duration` int DEFAULT NULL,
  `loan_duration_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `repayment_cycle` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loan_purpose` text COLLATE utf8mb4_unicode_ci,
  `description` text COLLATE utf8mb4_unicode_ci,
  `balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loans_loan_no_unique` (`loan_no`),
  KEY `loans_member_id_foreign` (`member_id`),
  KEY `loans_approved_by_foreign` (`approved_by`),
  KEY `loans_created_by_index` (`created_by`),
  KEY `loans_updated_by_index` (`updated_by`),
  KEY `loans_deleted_by_index` (`deleted_by`),
  KEY `loans_system_type_index` (`system_type`),
  KEY `loans_code_index` (`code`),
  KEY `loans_branch_id_index` (`branch_id`),
  KEY `loans_branch_status_idx` (`branch_id`,`status`),
  KEY `loans_branch_member_idx` (`branch_id`,`member_id`),
  KEY `loans_loan_created_by_foreign` (`loan_created_by`),
  KEY `loans_loan_disbursed_by_foreign` (`loan_disbursed_by`),
  KEY `loans_reschedule_loan_parent_id_foreign` (`reschedule_loan_parent_id`),
  KEY `loans_closed_by_id_foreign` (`closed_by_id`),
  KEY `loans_sacco_branch_id_foreign` (`sacco_branch_id`),
  KEY `loans_loan_officer_id_foreign` (`loan_officer_id`),
  KEY `loans_assigned_to_approve_foreign` (`assigned_to_approve`),
  KEY `loans_assigned_by_approve_foreign` (`assigned_by_approve`),
  KEY `loans_assigned_after_decline_fk` (`assigned_after_decline_to_review`),
  KEY `loans_assigned_to_disburse_fk` (`assigned_to_disburse`),
  KEY `loans_loan_application_id_foreign` (`loan_application_id`),
  KEY `loans_parent_loan_id_foreign` (`parent_loan_id`),
  CONSTRAINT `loans_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_assigned_after_decline_fk` FOREIGN KEY (`assigned_after_decline_to_review`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_assigned_by_approve_foreign` FOREIGN KEY (`assigned_by_approve`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_assigned_to_approve_foreign` FOREIGN KEY (`assigned_to_approve`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_assigned_to_disburse_fk` FOREIGN KEY (`assigned_to_disburse`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_closed_by_id_foreign` FOREIGN KEY (`closed_by_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_loan_application_id_foreign` FOREIGN KEY (`loan_application_id`) REFERENCES `loan_applications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_loan_created_by_foreign` FOREIGN KEY (`loan_created_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_loan_disbursed_by_foreign` FOREIGN KEY (`loan_disbursed_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_loan_officer_id_foreign` FOREIGN KEY (`loan_officer_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loans_parent_loan_id_foreign` FOREIGN KEY (`parent_loan_id`) REFERENCES `loans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_reschedule_loan_parent_id_foreign` FOREIGN KEY (`reschedule_loan_parent_id`) REFERENCES `loans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_sacco_branch_id_foreign` FOREIGN KEY (`sacco_branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `member_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `member_charges` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `member_id` bigint unsigned NOT NULL,
  `general_charge_id` bigint unsigned DEFAULT NULL,
  `savings_account_id` bigint unsigned DEFAULT NULL,
  `charge_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` enum('pending','paid','waived','applied') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `due_date` date DEFAULT NULL,
  `applied_at` timestamp NOT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `transaction_id` bigint unsigned DEFAULT NULL,
  `narration` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_member_charge_gc_txn` (`general_charge_id`,`transaction_id`),
  KEY `member_charges_member_id_index` (`member_id`),
  KEY `member_charges_general_charge_id_index` (`general_charge_id`),
  KEY `member_charges_savings_account_id_index` (`savings_account_id`),
  KEY `member_charges_charge_name_index` (`charge_name`),
  KEY `member_charges_status_index` (`status`),
  KEY `member_charges_due_date_index` (`due_date`),
  KEY `member_charges_applied_at_index` (`applied_at`),
  KEY `member_charges_paid_at_index` (`paid_at`),
  KEY `member_charges_transaction_id_index` (`transaction_id`),
  KEY `member_charges_created_by_index` (`created_by`),
  KEY `member_charges_code_index` (`code`),
  KEY `member_charges_branch_id_index` (`branch_id`),
  KEY `member_charges_deleted_at_index` (`deleted_at`),
  CONSTRAINT `member_charges_general_charge_id_foreign` FOREIGN KEY (`general_charge_id`) REFERENCES `general_charges` (`id`) ON DELETE SET NULL,
  CONSTRAINT `member_charges_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `member_charges_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `is_external_member` tinyint(1) NOT NULL DEFAULT '0',
  `created_from` varchar(70) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `branch_id` bigint DEFAULT NULL,
  `member_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employee_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `member_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `profile_picture` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `salutation` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gender` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `national_id_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profile_path` text COLLATE utf8mb4_unicode_ci,
  `id_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_country` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UG',
  `other_contact` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_contact_country` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UG',
  `mobile_money_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile_money_country` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UG',
  `dob` date DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `next_of_kin` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `next_of_kin_contact` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `next_of_kin_contact_country` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `initial_deposit` decimal(15,2) DEFAULT NULL,
  `is_shareholder` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `opening_balance` decimal(15,2) DEFAULT NULL,
  `shares_quantity` int unsigned DEFAULT NULL,
  `status` enum('active','pending','suspended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `joined_at` date DEFAULT NULL,
  `registered_by` bigint unsigned DEFAULT NULL,
  `code` varchar(70) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marital_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nationality` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Uganda',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `two_factor_secret` text COLLATE utf8mb4_unicode_ci,
  `two_factor_recovery_codes` text COLLATE utf8mb4_unicode_ci,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `referred_by` bigint unsigned DEFAULT NULL,
  `joined_date` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  UNIQUE KEY `members_email_unique` (`email`),
  UNIQUE KEY `members_member_number_unique` (`member_number`),
  KEY `members_name_index` (`name`),
  KEY `members_code_index` (`code`),
  KEY `members_email_verified_at_index` (`email_verified_at`),
  KEY `members_password_index` (`password`),
  KEY `members_id_number_index` (`id_number`),
  KEY `members_national_id_number_index` (`national_id_number`),
  KEY `members_phone_index` (`phone`),
  KEY `members_dob_index` (`dob`),
  KEY `members_status_index` (`status`),
  KEY `members_joined_at_index` (`joined_at`),
  KEY `members_created_by_index` (`registered_by`),
  KEY `members_two_factor_confirmed_at_index` (`two_factor_confirmed_at`),
  KEY `members_member_type_index` (`member_type`),
  KEY `members_salutation_index` (`salutation`),
  KEY `members_gender_index` (`gender`),
  KEY `members_other_contact_index` (`other_contact`),
  KEY `members_mobile_money_number_index` (`mobile_money_number`),
  KEY `members_marital_status_index` (`marital_status`),
  KEY `members_nationality_index` (`nationality`),
  KEY `members_next_of_kin_index` (`next_of_kin`),
  KEY `members_next_of_kin_contact_index` (`next_of_kin_contact`),
  KEY `members_initial_deposit_index` (`initial_deposit`),
  KEY `members_email_index` (`email`),
  KEY `members_phone_country_index` (`phone_country`),
  KEY `members_other_contact_country_index` (`other_contact_country`),
  KEY `members_mobile_money_country_index` (`mobile_money_country`),
  KEY `members_next_of_kin_contact_country_index` (`next_of_kin_contact_country`),
  KEY `members_is_shareholder_index` (`is_shareholder`),
  KEY `members_opening_balance_index` (`opening_balance`),
  KEY `members_approved_by_index` (`approved_by`),
  KEY `members_approved_at_index` (`approved_at`),
  KEY `members_rejected_by_index` (`rejected_by`),
  KEY `members_rejected_at_index` (`rejected_at`),
  KEY `members_rejection_reason_index` (`rejection_reason`),
  KEY `members_referred_by_foreign` (`referred_by`),
  KEY `members_updated_by_index` (`updated_by`),
  KEY `members_deleted_by_index` (`deleted_by`),
  KEY `members_system_type_index` (`system_type`),
  KEY `members_account_number_index` (`account_number`),
  KEY `members_employee_number_index` (`employee_number`),
  KEY `members_shares_quantity_index` (`shares_quantity`),
  KEY `members_branch_id_index` (`branch_id`),
  KEY `members_branch_status_idx` (`branch_id`,`status`),
  KEY `members_branch_created_idx` (`branch_id`,`created_at`),
  KEY `members_is_external_member_index` (`is_external_member`),
  KEY `members_created_from_index` (`created_from`),
  CONSTRAINT `members_created_by_foreign` FOREIGN KEY (`registered_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `members_referred_by_foreign` FOREIGN KEY (`referred_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `message_notification_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_notification_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cost` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'cost per unit',
  `platform_cost` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'cost per platform',
  `channel` enum('in_app','email','sms','push','whatsapp','mms') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sms',
  `length_min` int DEFAULT NULL,
  `length_max` int DEFAULT NULL,
  `status` enum('active','deactivated') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_created',
  `created_by` bigint DEFAULT NULL,
  `updated_by` bigint DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `message_notification_settings_code_unique` (`code`),
  KEY `message_notification_settings_length_min_index` (`length_min`),
  KEY `message_notification_settings_length_max_index` (`length_max`),
  KEY `message_notification_settings_status_index` (`status`),
  KEY `message_notification_settings_enabled_index` (`enabled`),
  KEY `message_notification_settings_system_type_index` (`system_type`),
  KEY `message_notification_settings_created_by_index` (`created_by`),
  KEY `message_notification_settings_updated_by_index` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `onboarding_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `onboarding_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shares_compulsory` tinyint(1) NOT NULL DEFAULT '0',
  `min_shares_on_onboarding` int unsigned NOT NULL DEFAULT '1',
  `share_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `share_payment_account_id` bigint unsigned DEFAULT NULL,
  `shares_compulsory_applies_to_existing` tinyint(1) NOT NULL DEFAULT '0',
  `auto_create_savings_account` tinyint(1) NOT NULL DEFAULT '1',
  `require_member_approval` tinyint(1) NOT NULL DEFAULT '0',
  `loyal_member_min_tenure_months` smallint unsigned NOT NULL DEFAULT '12',
  `hide_initial_deposit_field` tinyint(1) NOT NULL DEFAULT '0',
  `hide_opening_balance_field` tinyint(1) NOT NULL DEFAULT '0',
  `hide_is_shareholder_field` tinyint(1) NOT NULL DEFAULT '0',
  `reversal_requires_approval` tinyint(1) NOT NULL DEFAULT '0',
  `reversal_approver_roles` json DEFAULT NULL,
  `reversal_max_days` smallint unsigned NOT NULL DEFAULT '0' COMMENT '0 means no limit on how many days after a transaction a reversal can be requested',
  `regular_savings_interest_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  KEY `onboarding_settings_shares_compulsory_index` (`shares_compulsory`),
  KEY `onboarding_settings_min_shares_on_onboarding_index` (`min_shares_on_onboarding`),
  KEY `onboarding_settings_share_price_index` (`share_price`),
  KEY `onboarding_settings_shares_compulsory_applies_to_existing_index` (`shares_compulsory_applies_to_existing`),
  KEY `onboarding_settings_created_by_index` (`created_by`),
  KEY `onboarding_settings_updated_by_index` (`updated_by`),
  KEY `onboarding_settings_deleted_by_index` (`deleted_by`),
  KEY `onboarding_settings_system_type_index` (`system_type`),
  KEY `onboarding_settings_code_index` (`code`),
  KEY `onboarding_settings_branch_id_index` (`branch_id`),
  KEY `onboarding_settings_deleted_at_index` (`deleted_at`),
  KEY `onboarding_settings_share_payment_account_id_foreign` (`share_payment_account_id`),
  CONSTRAINT `onboarding_settings_share_payment_account_id_foreign` FOREIGN KEY (`share_payment_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'permissions name e.g., ''edit articles'' name must be unique in the system (create-tenants)',
  `parent_module` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'central-tenants',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_action_unique` (`action`),
  KEY `permissions_parent_module_action_index` (`parent_module`,`action`),
  KEY `permissions_parent_module_index` (`parent_module`),
  KEY `permissions_description_index` (`description`),
  KEY `permissions_created_at_index` (`created_at`),
  KEY `permissions_created_by_index` (`created_by`),
  KEY `permissions_updated_by_index` (`updated_by`),
  KEY `permissions_deleted_by_index` (`deleted_by`),
  KEY `permissions_system_type_index` (`system_type`),
  KEY `permissions_code_index` (`code`),
  KEY `permissions_branch_id_index` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `permission_ids` json DEFAULT NULL COMMENT 'json array of permission ids looks like [1,2,3]',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  KEY `permissions_users_id_user_id_created_at_index` (`id`,`user_id`,`created_at`),
  KEY `permissions_users_user_id_index` (`user_id`),
  KEY `permissions_users_created_at_index` (`created_at`),
  KEY `permissions_users_updated_at_index` (`updated_at`),
  KEY `permissions_users_created_by_index` (`created_by`),
  KEY `permissions_users_updated_by_index` (`updated_by`),
  KEY `permissions_users_deleted_by_index` (`deleted_by`),
  KEY `permissions_users_system_type_index` (`system_type`),
  KEY `permissions_users_code_index` (`code`),
  KEY `permissions_users_branch_id_index` (`branch_id`),
  KEY `permissions_users_deleted_at_index` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `public_holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `public_holidays` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_created',
  `holiday_date` date NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loan_repayment_schedule_id` bigint unsigned DEFAULT NULL,
  `loan_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `recurrence_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `public_holidays_holiday_date_index` (`holiday_date`),
  KEY `public_holidays_loan_repayment_schedule_id_index` (`loan_repayment_schedule_id`),
  KEY `public_holidays_loan_id_index` (`loan_id`),
  KEY `public_holidays_branch_id_index` (`branch_id`),
  KEY `public_holidays_recurrence_type_index` (`recurrence_type`),
  KEY `public_holidays_created_by_index` (`created_by`),
  CONSTRAINT `public_holidays_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `public_holidays_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `public_holidays_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `public_holidays_loan_repayment_schedule_id_foreign` FOREIGN KEY (`loan_repayment_schedule_id`) REFERENCES `loan_repayment_schedule` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'roles name e.g., ''admin'', ''editor'',''super-admin'', ''tenant-admin'' ',
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'code ',
  `system_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `default_permissions` json DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_scope` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'self',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_unique` (`name`),
  KEY `roles_name_index` (`name`),
  KEY `roles_code_index` (`code`),
  KEY `roles_system_type_index` (`system_type`),
  KEY `roles_description_index` (`description`),
  KEY `roles_branch_scope_index` (`branch_scope`),
  KEY `roles_created_at_index` (`created_at`),
  KEY `roles_created_by_index` (`created_by`),
  KEY `roles_updated_by_index` (`updated_by`),
  KEY `roles_deleted_by_index` (`deleted_by`),
  KEY `roles_branch_id_index` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sacco_branding`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sacco_branding` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sacco_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tagline` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  KEY `sacco_branding_sacco_name_index` (`sacco_name`),
  KEY `sacco_branding_tagline_index` (`tagline`),
  KEY `sacco_branding_logo_path_index` (`logo_path`),
  KEY `sacco_branding_created_at_index` (`created_at`),
  KEY `sacco_branding_code_index` (`code`),
  KEY `sacco_branding_created_by_index` (`created_by`),
  KEY `sacco_branding_deleted_at_index` (`deleted_at`),
  KEY `sacco_branding_branch_id_foreign` (`branch_id`),
  CONSTRAINT `sacco_branding_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `savings_account_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `savings_account_transfers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `from_account_id` bigint unsigned NOT NULL,
  `to_account_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('rejected','pending','approved','cancelled','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `transaction_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `savings_account_transfers_code_index` (`code`),
  KEY `savings_account_transfers_amount_index` (`amount`),
  KEY `savings_account_transfers_from_account_id_index` (`from_account_id`),
  KEY `savings_account_transfers_to_account_id_index` (`to_account_id`),
  KEY `savings_account_transfers_created_by_index` (`created_by`),
  KEY `savings_account_transfers_updated_by_index` (`updated_by`),
  KEY `savings_account_transfers_deleted_by_index` (`deleted_by`),
  KEY `savings_account_transfers_system_type_index` (`system_type`),
  KEY `savings_account_transfers_status_index` (`status`),
  KEY `savings_account_transfers_transaction_date_index` (`transaction_date`),
  KEY `savings_account_transfers_created_at_index` (`created_at`),
  KEY `savings_account_transfers_updated_at_index` (`updated_at`),
  KEY `savings_account_transfers_deleted_at_index` (`deleted_at`),
  KEY `savings_account_transfers_branch_id_index` (`branch_id`),
  CONSTRAINT `savings_account_transfers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`),
  CONSTRAINT `savings_account_transfers_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `staff` (`id`),
  CONSTRAINT `savings_account_transfers_from_account_id_foreign` FOREIGN KEY (`from_account_id`) REFERENCES `savings_accounts` (`id`),
  CONSTRAINT `savings_account_transfers_to_account_id_foreign` FOREIGN KEY (`to_account_id`) REFERENCES `savings_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `savings_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `savings_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `member_id` bigint unsigned DEFAULT NULL,
  `savings_product_id` bigint unsigned DEFAULT NULL,
  `payment_mod_account_id` bigint unsigned DEFAULT NULL COMMENT 'The payment method use_for the transaction (debit account) the id comes from  chart of account',
  `account_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'voluntary',
  `is_new_account` tinyint(1) NOT NULL DEFAULT '1',
  `account_opening_balance` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Opening balance for the savings account NOTE NEVER CHANGE THIS VALUE in any way, this is a read-only field',
  `balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `initial_deposit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `consider_min_balance` tinyint(1) NOT NULL DEFAULT '1',
  `selected_charges` json DEFAULT NULL,
  `interest_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `tenor_months` int unsigned DEFAULT NULL,
  `maturity_date` date DEFAULT NULL,
  `next_interest_date` date DEFAULT NULL,
  `maturity_action` enum('auto_rollover','manual','convert_to_savings') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payout_savings_account_id` bigint unsigned DEFAULT NULL,
  `last_interest_posted_at` timestamp NULL DEFAULT NULL,
  `custom_monthly_fee_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `custom_monthly_fee_type` enum('percentage','amount') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `custom_monthly_fee_amount` decimal(15,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  UNIQUE KEY `savings_accounts_code_unique` (`code`),
  UNIQUE KEY `savings_accounts_account_no_unique` (`account_no`),
  KEY `savings_accounts_member_id_index` (`member_id`),
  KEY `savings_accounts_account_type_index` (`account_type`),
  KEY `savings_accounts_balance_index` (`balance`),
  KEY `savings_accounts_interest_rate_index` (`interest_rate`),
  KEY `savings_accounts_status_index` (`status`),
  KEY `savings_accounts_created_at_index` (`created_at`),
  KEY `savings_accounts_updated_at_index` (`updated_at`),
  KEY `savings_accounts_savings_product_id_foreign` (`savings_product_id`),
  KEY `savings_accounts_is_new_account_index` (`is_new_account`),
  KEY `savings_accounts_initial_deposit_index` (`initial_deposit`),
  KEY `savings_accounts_consider_min_balance_index` (`consider_min_balance`),
  KEY `savings_accounts_account_opening_balance_index` (`account_opening_balance`),
  KEY `savings_accounts_custom_monthly_fee_enabled_index` (`custom_monthly_fee_enabled`),
  KEY `savings_accounts_custom_monthly_fee_type_index` (`custom_monthly_fee_type`),
  KEY `savings_accounts_custom_monthly_fee_amount_index` (`custom_monthly_fee_amount`),
  KEY `savings_accounts_created_by_index` (`created_by`),
  KEY `savings_accounts_updated_by_index` (`updated_by`),
  KEY `savings_accounts_deleted_by_index` (`deleted_by`),
  KEY `savings_accounts_system_type_index` (`system_type`),
  KEY `savings_accounts_branch_id_index` (`branch_id`),
  KEY `savings_accounts_branch_status_idx` (`branch_id`,`status`),
  KEY `savings_accounts_branch_member_idx` (`branch_id`,`member_id`),
  KEY `savings_accounts_payout_savings_account_id_foreign` (`payout_savings_account_id`),
  KEY `savings_accounts_maturity_date_index` (`maturity_date`),
  KEY `savings_accounts_next_interest_date_index` (`next_interest_date`),
  KEY `savings_accounts_payment_mod_account_id_index` (`payment_mod_account_id`),
  CONSTRAINT `savings_accounts_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `savings_accounts_payout_savings_account_id_foreign` FOREIGN KEY (`payout_savings_account_id`) REFERENCES `savings_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `savings_accounts_savings_product_id_foreign` FOREIGN KEY (`savings_product_id`) REFERENCES `savings_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `savings_group_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `savings_group_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `savings_group_id` bigint unsigned NOT NULL,
  `group_account_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `member_id` bigint unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('member','secretary','chairman','treasurer','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member',
  `account_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  UNIQUE KEY `savings_group_members_savings_group_id_member_id_unique` (`savings_group_id`,`member_id`),
  KEY `savings_group_members_savings_group_id_index` (`savings_group_id`),
  KEY `savings_group_members_member_id_index` (`member_id`),
  KEY `savings_group_members_code_index` (`code`),
  KEY `savings_group_members_role_index` (`role`),
  KEY `savings_group_members_account_number_index` (`account_number`),
  KEY `savings_group_members_created_at_index` (`created_at`),
  KEY `savings_group_members_group_account_id_index` (`group_account_id`),
  KEY `savings_group_members_created_by_index` (`created_by`),
  KEY `savings_group_members_updated_by_index` (`updated_by`),
  KEY `savings_group_members_deleted_by_index` (`deleted_by`),
  KEY `savings_group_members_system_type_index` (`system_type`),
  KEY `savings_group_members_branch_id_index` (`branch_id`),
  KEY `savings_group_members_deleted_at_index` (`deleted_at`),
  CONSTRAINT `sgm_group_fk` FOREIGN KEY (`savings_group_id`) REFERENCES `savings_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sgm_member_fk` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `savings_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `savings_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `group_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `group_category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_balance` decimal(15,2) DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `primary_contact_country_code` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UG',
  `primary_contact_phone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `other_contact_country_code` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UG',
  `other_contact_phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_created` date NOT NULL,
  `location` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  KEY `savings_groups_name_index` (`name`),
  KEY `savings_groups_group_type_index` (`group_type`),
  KEY `savings_groups_group_category_index` (`group_category`),
  KEY `savings_groups_code_index` (`code`),
  KEY `savings_groups_primary_contact_country_code_index` (`primary_contact_country_code`),
  KEY `savings_groups_other_contact_country_code_index` (`other_contact_country_code`),
  KEY `savings_groups_other_contact_phone_index` (`other_contact_phone`),
  KEY `savings_groups_date_created_index` (`date_created`),
  KEY `savings_groups_status_index` (`status`),
  KEY `savings_groups_created_by_index` (`created_by`),
  KEY `savings_groups_created_at_index` (`created_at`),
  KEY `savings_groups_updated_at_index` (`updated_at`),
  KEY `savings_groups_image_path_index` (`image_path`),
  KEY `savings_groups_updated_by_index` (`updated_by`),
  KEY `savings_groups_deleted_by_index` (`deleted_by`),
  KEY `savings_groups_system_type_index` (`system_type`),
  KEY `savings_groups_branch_id_index` (`branch_id`),
  CONSTRAINT `sg_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `savings_interest_postings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `savings_interest_postings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `savings_account_id` bigint unsigned NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `principal` decimal(15,2) NOT NULL,
  `rate` decimal(5,4) NOT NULL,
  `interest_amount` decimal(15,2) NOT NULL,
  `payout_type` enum('at_maturity','periodic_payout','compound') COLLATE utf8mb4_unicode_ci NOT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `posted_by` int unsigned DEFAULT NULL COMMENT 'null = system auto-post',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_posting_period` (`savings_account_id`,`period_start`,`period_end`),
  CONSTRAINT `savings_interest_postings_savings_account_id_foreign` FOREIGN KEY (`savings_account_id`) REFERENCES `savings_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `savings_product_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `savings_product_charges` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `savings_product_id` bigint unsigned NOT NULL,
  `general_charge_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('deposit','withdraw','transfer') COLLATE utf8mb4_unicode_ci NOT NULL,
  `minimum_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `maximum_amount` decimal(15,2) DEFAULT NULL,
  `charge_type` enum('percentage','amount') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `is_reversible` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  KEY `1` (`savings_product_id`),
  KEY `savings_product_charges_type_index` (`type`),
  KEY `savings_product_charges_minimum_amount_index` (`minimum_amount`),
  KEY `savings_product_charges_maximum_amount_index` (`maximum_amount`),
  KEY `savings_product_charges_charge_type_index` (`charge_type`),
  KEY `savings_product_charges_created_by_index` (`created_by`),
  KEY `savings_product_charges_updated_by_index` (`updated_by`),
  KEY `savings_product_charges_deleted_by_index` (`deleted_by`),
  KEY `savings_product_charges_system_type_index` (`system_type`),
  KEY `savings_product_charges_name_index` (`name`),
  KEY `savings_product_charges_is_reversible_index` (`is_reversible`),
  KEY `savings_product_charges_code_index` (`code`),
  KEY `savings_product_charges_deleted_at_index` (`deleted_at`),
  KEY `savings_product_charges_branch_id_foreign` (`branch_id`),
  KEY `savings_product_charges_general_charge_id_foreign` (`general_charge_id`),
  CONSTRAINT `1` FOREIGN KEY (`savings_product_id`) REFERENCES `savings_products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `savings_product_charges_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `savings_product_charges_general_charge_id_foreign` FOREIGN KEY (`general_charge_id`) REFERENCES `general_charges` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `savings_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `savings_products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('fixed','standard') COLLATE utf8mb4_unicode_ci NOT NULL,
  `minimum_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `minimum_maturity_months` int NOT NULL DEFAULT '0',
  `dormancy_period_months` int NOT NULL DEFAULT '0',
  `charge_on_deposit` tinyint(1) NOT NULL DEFAULT '0',
  `charge_on_withdraw` tinyint(1) NOT NULL DEFAULT '0',
  `charge_on_transfer` tinyint(1) NOT NULL DEFAULT '0',
  `monthly_fee_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `monthly_fee_type` enum('percentage','amount') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `monthly_fee_amount` decimal(15,2) DEFAULT NULL,
  `monthly_fee_deduction_day` int DEFAULT NULL,
  `loyalty_fee_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `loyalty_adjustment_type` enum('discount_percentage','fixed_discount','custom_fee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'discount_percentage',
  `loyalty_adjustment_value` decimal(15,2) DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `interest_rate` decimal(5,4) NOT NULL DEFAULT '0.0000' COMMENT 'Annual rate e.g. 0.1200 = 12%',
  `interest_payout_type` enum('at_maturity','periodic_payout','compound') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'at_maturity',
  `interest_posting_frequency` enum('monthly','quarterly','semi_annually','annually') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `default_tenor_months` int unsigned NOT NULL DEFAULT '6',
  `maturity_action` enum('auto_rollover','manual','convert_to_savings') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `convert_to_product_id` bigint unsigned DEFAULT NULL,
  `interest_expense_account_id` bigint unsigned DEFAULT NULL,
  `interest_payable_account_id` bigint unsigned DEFAULT NULL,
  `interest_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  KEY `savings_products_name_index` (`name`),
  KEY `savings_products_code_index` (`code`),
  KEY `savings_products_type_index` (`type`),
  KEY `savings_products_minimum_balance_index` (`minimum_balance`),
  KEY `savings_products_minimum_maturity_months_index` (`minimum_maturity_months`),
  KEY `savings_products_dormancy_period_months_index` (`dormancy_period_months`),
  KEY `savings_products_charge_on_deposit_index` (`charge_on_deposit`),
  KEY `savings_products_charge_on_withdraw_index` (`charge_on_withdraw`),
  KEY `savings_products_charge_on_transfer_index` (`charge_on_transfer`),
  KEY `savings_products_status_index` (`status`),
  KEY `savings_products_created_at_index` (`created_at`),
  KEY `savings_products_monthly_fee_enabled_index` (`monthly_fee_enabled`),
  KEY `savings_products_monthly_fee_type_index` (`monthly_fee_type`),
  KEY `savings_products_monthly_fee_amount_index` (`monthly_fee_amount`),
  KEY `savings_products_monthly_fee_deduction_day_index` (`monthly_fee_deduction_day`),
  KEY `savings_products_created_by_index` (`created_by`),
  KEY `savings_products_updated_by_index` (`updated_by`),
  KEY `savings_products_deleted_by_index` (`deleted_by`),
  KEY `savings_products_system_type_index` (`system_type`),
  KEY `savings_products_convert_to_product_id_foreign` (`convert_to_product_id`),
  KEY `savings_products_branch_id_foreign` (`branch_id`),
  CONSTRAINT `savings_products_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `savings_products_convert_to_product_id_foreign` FOREIGN KEY (`convert_to_product_id`) REFERENCES `savings_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sent_message_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sent_message_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned DEFAULT NULL,
  `code` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text COLLATE utf8mb4_unicode_ci,
  `from_module` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sender_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `receiver_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `str_length` int DEFAULT NULL COMMENT 'length of message',
  `channel` enum('in_app','email','sms','push','whatsapp','mms') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_app',
  `status` enum('sent','failed','no-funds','in_progress','in_queue','cancelled','delivered','pending') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `cost` decimal(10,2) DEFAULT NULL COMMENT 'cost of message',
  `reason_for_failure` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `other_data` json DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `sent_time_at` timestamp NULL DEFAULT NULL,
  `notification_setting_id` bigint unsigned DEFAULT NULL,
  `sender_type` enum('staff','member','system') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `created_by` bigint DEFAULT NULL,
  `updated_by` bigint DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sent_message_notifications_code_unique` (`code`),
  KEY `sent_message_notifications_status_channel_code_index` (`status`,`channel`,`code`),
  KEY `sent_message_notifications_from_module_status_index` (`from_module`,`status`),
  KEY `sent_message_notifications_branch_id_created_at_index` (`branch_id`,`created_at`),
  KEY `sent_message_notifications_from_module_index` (`from_module`),
  KEY `sent_message_notifications_sender_id_index` (`sender_id`),
  KEY `sent_message_notifications_receiver_id_index` (`receiver_id`),
  KEY `sent_message_notifications_channel_index` (`channel`),
  KEY `sent_message_notifications_status_index` (`status`),
  KEY `sent_message_notifications_reason_for_failure_index` (`reason_for_failure`),
  KEY `sent_message_notifications_notification_setting_id_index` (`notification_setting_id`),
  KEY `sent_message_notifications_sender_type_index` (`sender_type`),
  KEY `sent_message_notifications_system_type_index` (`system_type`),
  KEY `sent_message_notifications_created_by_index` (`created_by`),
  KEY `sent_message_notifications_updated_by_index` (`updated_by`),
  CONSTRAINT `sent_message_notifications_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sent_message_notifications_notification_setting_id_foreign` FOREIGN KEY (`notification_setting_id`) REFERENCES `message_notification_settings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `share_capitalization`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `share_capitalization` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `open_capital` bigint unsigned NOT NULL COMMENT 'Opening capital are shared no /points',
  `open_capital_points_walth_amount` bigint unsigned NOT NULL COMMENT ' calculated amount of money of points open to be bought',
  `opening_balance` bigint unsigned NOT NULL COMMENT 'Opening capital are shared no /points remaining after bought',
  `share_price` decimal(8,2) DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive','suspended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `branch_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_created',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `share_capitalization_code_unique` (`code`),
  KEY `share_capitalization_open_capital_index` (`open_capital`),
  KEY `share_capitalization_open_capital_points_walth_amount_index` (`open_capital_points_walth_amount`),
  KEY `share_capitalization_opening_balance_index` (`opening_balance`),
  KEY `share_capitalization_share_price_index` (`share_price`),
  KEY `share_capitalization_status_index` (`status`),
  KEY `share_capitalization_branch_id_index` (`branch_id`),
  KEY `share_capitalization_created_by_index` (`created_by`),
  KEY `share_capitalization_updated_by_index` (`updated_by`),
  KEY `share_capitalization_created_at_index` (`created_at`),
  KEY `share_capitalization_system_type_index` (`system_type`),
  CONSTRAINT `share_capitalization_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER `prevent_delete_system_share_capitalization` BEFORE DELETE ON `share_capitalization` FOR EACH ROW BEGIN
                IF OLD.system_type = "system" THEN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "System records cannot be deleted";
                END IF;
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `share_capitalization_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `share_capitalization_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive','suspended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `share_capitalization_id` bigint unsigned DEFAULT NULL COMMENT 'share_capitalization table id ',
  `open_capital` bigint unsigned NOT NULL COMMENT 'Opening capital are shared no /points',
  `open_capital_points_walth_amount` bigint unsigned NOT NULL COMMENT ' calculated amount of money of points open to be bought',
  `opening_balance` bigint unsigned NOT NULL COMMENT 'Opening capital are shared no /points remaining after bought',
  `share_price` decimal(8,2) DEFAULT NULL,
  `new_open_capital` bigint unsigned NOT NULL,
  `new_open_capital_points_walth_amount` bigint unsigned NOT NULL COMMENT ' ',
  `new_opening_balance` bigint unsigned NOT NULL,
  `new_share_price` decimal(8,2) DEFAULT NULL,
  `other_data` json DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `share_capitalization_history_code_unique` (`code`),
  KEY `share_capitalization_history_status_index` (`status`),
  KEY `share_capitalization_history_share_capitalization_id_index` (`share_capitalization_id`),
  KEY `share_capitalization_history_open_capital_index` (`open_capital`),
  KEY `sch_ocpwa_idx` (`open_capital_points_walth_amount`),
  KEY `share_capitalization_history_opening_balance_index` (`opening_balance`),
  KEY `share_capitalization_history_share_price_index` (`share_price`),
  KEY `share_capitalization_history_new_open_capital_index` (`new_open_capital`),
  KEY `sch_nocpwa_idx` (`new_open_capital_points_walth_amount`),
  KEY `share_capitalization_history_new_opening_balance_index` (`new_opening_balance`),
  KEY `share_capitalization_history_new_share_price_index` (`new_share_price`),
  KEY `share_capitalization_history_branch_id_index` (`branch_id`),
  KEY `share_capitalization_history_created_by_index` (`created_by`),
  KEY `share_capitalization_history_updated_by_index` (`updated_by`),
  KEY `share_capitalization_history_created_at_index` (`created_at`),
  KEY `share_capitalization_history_system_type_index` (`system_type`),
  CONSTRAINT `share_capitalization_history_share_capitalization_id_foreign` FOREIGN KEY (`share_capitalization_id`) REFERENCES `share_capitalization` (`id`) ON DELETE SET NULL,
  CONSTRAINT `share_capitalization_history_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `share_transaction_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `share_transaction_charges` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `share_capitalization_id` bigint unsigned DEFAULT NULL,
  `transaction_type` enum('buying','selling','withdraw','transfer') COLLATE utf8mb4_unicode_ci NOT NULL,
  `minimum_shares` decimal(15,2) NOT NULL DEFAULT '0.00',
  `maximum_shares` decimal(15,2) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `charge_type` enum('percentage','fixed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `share_transaction_charges_code_unique` (`code`),
  KEY `share_transaction_charges_branch_id_index` (`branch_id`),
  KEY `share_transaction_charges_share_capitalization_id_index` (`share_capitalization_id`),
  KEY `share_transaction_charges_transaction_type_index` (`transaction_type`),
  KEY `share_transaction_charges_minimum_shares_index` (`minimum_shares`),
  KEY `share_transaction_charges_maximum_shares_index` (`maximum_shares`),
  KEY `share_transaction_charges_status_index` (`status`),
  KEY `share_transaction_charges_charge_type_index` (`charge_type`),
  KEY `share_transaction_charges_system_type_index` (`system_type`),
  KEY `share_transaction_charges_created_by_index` (`created_by`),
  KEY `share_transaction_charges_updated_by_index` (`updated_by`),
  KEY `share_transaction_charges_created_at_index` (`created_at`),
  CONSTRAINT `share_transaction_charges_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER `prevent_delete_system_share_transaction_charges` BEFORE DELETE ON `share_transaction_charges` FOR EACH ROW BEGIN
                IF OLD.system_type = "system" THEN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "System records cannot be deleted";
                END IF;
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shares` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `member_id` bigint unsigned NOT NULL,
  `transferring_member_id` bigint unsigned DEFAULT NULL COMMENT 'Shares transferred from which member',
  `share_no` int DEFAULT NULL,
  `buyer_payment_mode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Account, Cash',
  `share_value` decimal(10,2) NOT NULL,
  `total_value` decimal(15,2) NOT NULL,
  `purchased_at` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  KEY `shares_member_id_index` (`member_id`),
  KEY `shares_created_by_index` (`created_by`),
  KEY `shares_updated_by_index` (`updated_by`),
  KEY `shares_deleted_by_index` (`deleted_by`),
  KEY `shares_system_type_index` (`system_type`),
  KEY `shares_code_index` (`code`),
  KEY `shares_branch_id_index` (`branch_id`),
  KEY `shares_buyer_payment_mode_index` (`buyer_payment_mode`),
  KEY `shares_transferring_member_id_index` (`transferring_member_id`),
  CONSTRAINT `shares_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shares_transferring_member_id_fk` FOREIGN KEY (`transferring_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `staff` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Staff',
  `is_tenant_admin` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `can_vote_on_loans` tinyint(1) NOT NULL DEFAULT '0',
  `can_manage_branch` tinyint(1) NOT NULL DEFAULT '0',
  `can_finalise_loan` tinyint(1) NOT NULL DEFAULT '0',
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `two_factor_secret` text COLLATE utf8mb4_unicode_ci,
  `two_factor_recovery_codes` text COLLATE utf8mb4_unicode_ci,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  `branch_can_be_accessed` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_email_unique` (`email`),
  KEY `staff_role_id_index` (`role_id`),
  KEY `staff_name_index` (`name`),
  KEY `staff_email_verified_at_index` (`email_verified_at`),
  KEY `staff_password_index` (`password`),
  KEY `staff_role_index` (`role`),
  KEY `staff_remember_token_index` (`remember_token`),
  KEY `staff_created_at_index` (`created_at`),
  KEY `staff_updated_at_index` (`updated_at`),
  KEY `staff_is_tenant_admin_index` (`is_tenant_admin`),
  KEY `staff_status_index` (`status`),
  KEY `staff_two_factor_confirmed_at_index` (`two_factor_confirmed_at`),
  KEY `staff_created_by_index` (`created_by`),
  KEY `staff_updated_by_index` (`updated_by`),
  KEY `staff_deleted_by_index` (`deleted_by`),
  KEY `staff_system_type_index` (`system_type`),
  KEY `staff_code_index` (`code`),
  KEY `staff_branch_id_index` (`branch_id`),
  KEY `staff_branch_status_idx` (`branch_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sub_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sub_ledger` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_id` bigint unsigned NOT NULL,
  `entity_id` bigint unsigned NOT NULL,
  `entity_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `journal_entry_id` bigint unsigned NOT NULL,
  `date` date NOT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `credit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `narration` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  KEY `sub_ledger_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `sub_ledger_account_id_entity_type_entity_id_index` (`account_id`,`entity_type`,`entity_id`),
  KEY `sub_ledger_entity_type_entity_id_index` (`entity_type`,`entity_id`),
  KEY `sub_ledger_created_by_index` (`created_by`),
  KEY `sub_ledger_updated_by_index` (`updated_by`),
  KEY `sub_ledger_deleted_by_index` (`deleted_by`),
  KEY `sub_ledger_system_type_index` (`system_type`),
  KEY `sub_ledger_code_index` (`code`),
  KEY `sub_ledger_branch_id_index` (`branch_id`),
  KEY `sub_ledger_deleted_at_index` (`deleted_at`),
  CONSTRAINT `sub_ledger_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `sub_ledger_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `settings_name` varchar(70) COLLATE utf8mb4_unicode_ci NOT NULL,
  `settings_title` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `settings_module` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `settings_status` enum('active','de-activated') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `settings_action` json NOT NULL,
  `settings_setting_description` text COLLATE utf8mb4_unicode_ci,
  `settings_action_description` text COLLATE utf8mb4_unicode_ci,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint NOT NULL,
  `updated_at` timestamp NOT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` bigint NOT NULL,
  `deteleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_settings_settings_name_unique` (`settings_name`),
  KEY `system_settings_id_index` (`id`),
  KEY `system_settings_settings_title_index` (`settings_title`),
  KEY `system_settings_settings_module_index` (`settings_module`),
  KEY `system_settings_settings_status_index` (`settings_status`),
  KEY `system_settings_system_type_index` (`system_type`),
  KEY `system_settings_created_at_index` (`created_at`),
  KEY `system_settings_created_by_index` (`created_by`),
  KEY `system_settings_updated_at_index` (`updated_at`),
  KEY `system_settings_updated_by_index` (`updated_by`),
  KEY `system_settings_deteleted_at_index` (`deteleted_at`),
  KEY `system_settings_code_index` (`code`),
  KEY `system_settings_deleted_at_index` (`deleted_at`),
  KEY `system_settings_branch_id_foreign` (`branch_id`),
  CONSTRAINT `system_settings_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_billing_account`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_billing_account` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. SMS, Email',
  `account_balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'cost per unit',
  `channel` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'e.g. sms, email, whatsapp, mms',
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `created_by` bigint DEFAULT NULL,
  `updated_by` bigint DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_billing_account_code_unique` (`code`),
  UNIQUE KEY `tenant_billing_account_name_unique` (`name`),
  KEY `tenant_billing_account_channel_index` (`channel`),
  KEY `tenant_billing_account_system_type_index` (`system_type`),
  KEY `tenant_billing_account_created_by_index` (`created_by`),
  KEY `tenant_billing_account_updated_by_index` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transaction_reversals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction_reversals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transaction_id` bigint unsigned NOT NULL,
  `reversal_transaction_id` bigint unsigned DEFAULT NULL,
  `status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `narration` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_by` bigint unsigned DEFAULT NULL,
  `assigned_approver_id` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  KEY `transaction_reversals_transaction_id_index` (`transaction_id`),
  KEY `transaction_reversals_reversal_transaction_id_index` (`reversal_transaction_id`),
  KEY `transaction_reversals_status_index` (`status`),
  KEY `transaction_reversals_requested_by_index` (`requested_by`),
  KEY `transaction_reversals_approved_by_index` (`approved_by`),
  KEY `transaction_reversals_approved_at_index` (`approved_at`),
  KEY `transaction_reversals_created_at_index` (`created_at`),
  KEY `transaction_reversals_assigned_approver_id_index` (`assigned_approver_id`),
  KEY `transaction_reversals_code_index` (`code`),
  KEY `transaction_reversals_branch_id_index` (`branch_id`),
  KEY `transaction_reversals_created_by_index` (`created_by`),
  KEY `transaction_reversals_deleted_at_index` (`deleted_at`),
  CONSTRAINT `transaction_reversals_reversal_transaction_id_foreign` FOREIGN KEY (`reversal_transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transaction_reversals_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint DEFAULT NULL,
  `code` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `umbrella_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Umbrella Code i case of multiple transactions they can be under one umbrella code for easy trcking ',
  `savings_account_transfers_id` bigint unsigned DEFAULT NULL,
  `reference` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `receipt_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `member_id_transferring_shares` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `member_id` bigint unsigned DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_before_transactions` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'this will be tell amount of monet account had before this transaction was made',
  `meta_details_before_transaction` json DEFAULT NULL COMMENT 'this will be tell amount of monet account had before this transaction was made',
  `deposited_amount_before_charge` decimal(8,2) DEFAULT NULL COMMENT 'this will be the amount that was actually deposited to the account before any charges were applied',
  `amount` decimal(15,2) NOT NULL COMMENT 'amount saved after charges which is money at hand/wallet',
  `payment_mode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_mod_account_id` bigint unsigned DEFAULT NULL COMMENT 'The payment method use_for the transaction (debit account) the id comes from  chart of account',
  `deposited_by` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `charge_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `account_id` bigint unsigned DEFAULT NULL,
  `account_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `narration` text COLLATE utf8mb4_unicode_ci,
  `charge_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gl_credit_account_id` bigint unsigned DEFAULT NULL,
  `is_reversible` tinyint(1) NOT NULL DEFAULT '1',
  `is_migrated` tinyint(1) NOT NULL DEFAULT '0',
  `is_reversed` tinyint(1) NOT NULL DEFAULT '0',
  `reversal_of` bigint unsigned DEFAULT NULL,
  `grouped_with` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `group_savings_account_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `system_type` enum('system','user_created') COLLATE utf8mb4_unicode_ci DEFAULT 'user_created',
  PRIMARY KEY (`id`),
  UNIQUE KEY `transactions_reference_unique` (`reference`),
  UNIQUE KEY `transactions_code_unique` (`code`),
  KEY `transactions_member_id_index` (`member_id`),
  KEY `transactions_type_index` (`type`),
  KEY `transactions_amount_index` (`amount`),
  KEY `transactions_charge_amount_index` (`charge_amount`),
  KEY `transactions_account_id_index` (`account_id`),
  KEY `transactions_account_type_index` (`account_type`),
  KEY `transactions_created_by_index` (`created_by`),
  KEY `transactions_created_at_index` (`created_at`),
  KEY `transactions_updated_at_index` (`updated_at`),
  KEY `transactions_payment_mode_index` (`payment_mode`),
  KEY `transactions_deposited_by_index` (`deposited_by`),
  KEY `transactions_transaction_date_index` (`transaction_date`),
  KEY `transactions_member_id_transferring_shares_index` (`member_id_transferring_shares`),
  KEY `transactions_group_savings_account_id_index` (`group_savings_account_id`),
  KEY `transactions_updated_by_index` (`updated_by`),
  KEY `transactions_deleted_by_index` (`deleted_by`),
  KEY `transactions_system_type_index` (`system_type`),
  KEY `transactions_reversal_of_foreign` (`reversal_of`),
  KEY `transactions_grouped_with_index` (`grouped_with`),
  KEY `1` (`receipt_number`),
  KEY `transactions_receipt_number_index` (`receipt_number`),
  KEY `transactions_branch_id_index` (`branch_id`),
  KEY `transactions_branch_type_idx` (`branch_id`,`type`),
  KEY `transactions_branch_created_idx` (`branch_id`,`created_at`),
  KEY `transactions_amount_before_transactions_index` (`amount_before_transactions`),
  KEY `transactions_charge_name_index` (`charge_name`),
  KEY `transactions_savings_account_transfers_id_index` (`savings_account_transfers_id`),
  KEY `transactions_is_reversible_index` (`is_reversible`),
  KEY `transactions_umbrella_code_index` (`umbrella_code`),
  KEY `transactions_payment_mod_account_id_index` (`payment_mod_account_id`),
  KEY `transactions_deposited_amount_before_charge_index` (`deposited_amount_before_charge`),
  CONSTRAINT `transactions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transactions_reversal_of_foreign` FOREIGN KEY (`reversal_of`) REFERENCES `transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2026_02_19_140100_create_staff_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2026_02_19_140200_create_members_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2026_02_19_140200_create_setting_v2_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_02_22_000001_enhance_staff_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_02_22_000002_enhance_members_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_02_22_000003_create_savings_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_02_22_000004_create_shares_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_02_22_000005_create_loans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_02_22_000006_create_loan_schedules_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_02_22_000007_create_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_02_22_000008_create_chart_of_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_02_22_000009_create_journal_entries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_02_22_000010_create_journal_entry_lines_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_02_22_000011_create_general_ledger_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_02_22_000012_create_sub_ledger_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_02_24_130600_add_member_form_columns_to_members_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_02_24_154042_update_members_table_for_countries_and_nullable_email',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_02_24_173753_add_avatar_path_to_members_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_02_26_103000_create_savings_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_02_26_103500_create_savings_product_charges_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_02_26_103500_create_shar_transaction_charges_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_02_26_114203_update_savings_accounts_table_add_product_and_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_02_26_114204_update_savings_accounts_table_add_account_opening_balance',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_02_27_000001_enhance_chart_of_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_02_27_000002_enhance_journal_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_02_27_000003_create_mysql_financial_triggers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_02_27_000004_fix_coa_legacy_column',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_02_28_064512_add_next_of_kin_contact_country_to_members_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_02_28_103000_add_existing_member_fields_to_members_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_02_28_114423_add_details_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_02_28_114423_add_details_to_transactions_table2',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_02_28_114423_add_group_savings_account_id_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_02_28_172723_add_selected_charges_to_savings_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_03_01_000000_create_savings_groups_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_03_01_000001_add_image_to_savings_groups_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_03_02_000000_create_savings_group_members_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_03_02_000001_attached_group_memebr_id_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_03_03_101927_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_03_03_104500_ensure_member_type_on_members_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_03_10_135000_create_roles___tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_03_10_135001_create_permissions__tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_03_10_155600_create_permissions_users__table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_03_10_184511_add_monthly_fee_fields_to_savings_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_03_10_184725_add_monthly_fee_fields_to_savings_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_03_11_025545_add_onboarded_by_to_members_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_03_11_100000_create_onboarding_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_03_11_120000_add_auto_create_savings_account_to_onboarding_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_03_11_130000_add_require_member_approval_to_onboarding_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_03_11_140000_add_approval_fields_to_members_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_03_11_200000_create_financial_years_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_03_12_000000_refactor_member_staff_tracking_fields',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_03_12_100000_add_audit_columns_to__all_tenant_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_03_14_000001_add_import_fields_to_members_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_03_14_130000_create_sacco_branding_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_03_15_000001_add_is_migrated_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_03_16_000001_add_loyal_member_min_tenure_months_to_onboarding_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_03_16_000002_add_reversal_fields_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_03_17_000001_add_grouped_with_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_03_17_000002_fix_journal_entries_immutability_trigger',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_03_18_000001_add_name_and_is_reversible_to_savings_product_charges_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_03_18_000003_create_general_charges_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_03_18_000004_add_is_active_to_general_charges_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_03_19_000001_create_member_charges_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_03_19_000003_add_receipt_number_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_03_19_100001_create_transaction_reversals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_03_19_100002_add_reversal_settings_to_onboarding_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_03_19_100003_add_assigned_approver_to_transaction_reversals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_03_23_000001_create_branches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_03_23_085946_create_savings_transfer_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_03_24_093255_add_missing_column_to_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_03_24_145951_add_branch_id_to_journal_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_03_25_000001_add_branch_scope_to_roles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_03_25_000002_drop_branch_id_from_non_business_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_03_25_000003_create_missing_columns_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_03_25_000004_add_branch_composite_indexes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_03_25_100000_add_soft_deletes_to_savings_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_03_25_120000_create_loan_notes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_03_25_172000_add_missing_columns_to_loans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_03_25_175200_add_additional_columns_to_loans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_03_25_175700_add_phase3_columns_to_loans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_03_25_180600_create_loan_charges_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_03_25_181400_add_phase5_columns_to_loans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_03_25_184100_add_phase6_columns_to_loans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_03_25_195100_rename_and_update_loan_schedules_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_03_25_200300_create_loan_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_03_25_200800_create_loan_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_03_25_201900_update_loan_approvals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_03_25_202300_create_loan_write_offs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_03_25_203000_create_loan_rescheduling_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_03_25_205400_create_public_holidays_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_03_25_210400_create_loan_guarantors_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_03_25_210500_add_global_columns_to_loan_module_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_03_25_213700_create_loan_collateral_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_03_25_214600_create_loan_penalty_rules_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_03_25_215300_add_loan_purpose_to_loans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2026_03_25_220200_add_tracking_columns_to_loan_charges_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2026_03_25_220700_create_loan_status_history_audit_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2026_03_25_221400_add_metadata_to_audit_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2026_03_25_222100_create_loan_notifications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'2026_03_25_223000_create_loan_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'2026_03_25_224200_create_loan_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'2026_03_26_103000_drop_loan_product_id_from_loan_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'2026_03_26_120000_add_visibility_fields_to_onboarding_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'2026_03_26_120100_create_currency_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'2026_03_26_130000_add_soft_deletes_to_loan_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'2026_03_26_150000_add_v1_fields_to_loan_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'2026_03_26_150100_backfill_loan_products_v1_defaults',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'2026_03_26_150200_add_loan_products_v1_foreign_keys_and_indexes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'2026_03_27_085640_add_security_and_schedule_fields_to_loan_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (110,'2026_03_27_100000_create_loan_applications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (111,'2026_03_27_100001_create_loan_application_status_history_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (112,'2026_03_28_100000_add_eligibility_fields_to_loan_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (113,'2026_03_28_120000_add_required_document_types_to_loan_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (114,'2026_03_28_120001_create_loan_application_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (115,'2026_03_28_130000_add_cancellation_fields_to_loan_applications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (116,'2026_03_28_130000_create_document_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (117,'2026_03_28_130100_create_loan_product_required_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (118,'2026_03_28_140000_drop_legacy_loan_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (119,'2026_03_28_150000_backfill_normalized_loan_product_required_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (120,'2026_03_28_160000_drop_required_document_types_from_loan_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (121,'2026_03_28_200000_add_appraisal_fields_to_loan_applications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (122,'2026_03_28_200000_add_joined_date_memebers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (123,'2026_03_28_200000_add_saving-group_id_fields_to_loan_applications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (124,'2026_03_28_210000_create_loan_application_approvals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (125,'2026_03_28_220000_add_disbursement_fields_to_loans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (126,'2026_03_29_080000_add_charge_accounts_to_loan_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (127,'2026_03_29_080100_add_charge_distribution_mode_to_loan_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (128,'2026_03_29_090000_add_charge_deduction_and_channel_fields_to_loans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (129,'2026_03_29_130000_add_application_to_loan_guarantors',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (130,'2026_03_29_200000_create_loan_application_collaterals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (131,'2026_03_31_175300_add_loan_application_id_to_loans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (132,'2026_04_01_100001_add_is_external_meber_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (133,'2026_04_01_100341_create_loan_status_histories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (134,'2026_04_02_120000_rename_loan_charges_to_loan_applied_charges_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (135,'2026_04_02_120100_create_loan_charges_definitions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (136,'2026_04_02_120200_create_loan_product_charge_pivot_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (137,'2026_04_02_120300_add_penalty_grace_days_to_loan_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (138,'2026_04_02_130000_repair_loan_charges_schema_for_definitions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (139,'2026_04_03_090000_add_repayment_allocation_order_to_loan_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (140,'2026_04_03_130000_rename_insurance_to_disbursement_fee_in_loan_charges',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (141,'2026_04_07_000001_add_charge_amount_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (142,'2026_04_07_000001_make_charge_id_nullable_in_loan_applied_charges',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (143,'2026_04_08_181000_add_holiday_setting_to_loan_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (144,'2026_04_10_000000_create_loan_arrears_tiers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (145,'2026_04_18_000001_create_journal_entry_sequences_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (146,'2026_04_18_100002_add_amount_before_transactions_fields_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (147,'2026_04_18_100002_add_branch_access_in_staff_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (148,'2026_04_18_100002_add_charge_fields_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (149,'2026_04_19_000001_add_fd_fields_to_savings_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (150,'2026_04_19_000002_add_fd_fields_to_savings_accounts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (151,'2026_04_19_000002_add_loyalty_fields_to_savings_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (152,'2026_04_19_000003_create_savings_interest_postings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (153,'2026_04_19_140100_create_loan_application_garantors_table3',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (154,'2026_04_21_000000_add_interest_rate_columns_to_loan_applications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (155,'2026_04_21_141600_add_schedule_date_to_loans_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (156,'2026_04_22_040857_create_notification_settings_table1',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (157,'2026_04_22_040857_create_tenant_billing_account_',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (158,'2026_04_22_040858_create_notifications_table1',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (159,'2026_04_22_113825_add_meta_details_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (160,'2026_04_22_113825_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (161,'2026_04_22_140000_add_acout_id_to_loan_application_guarantors',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (162,'2026_04_22_140000_add_topup_settings_to_loan_settings_and_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (163,'2026_04_22_140001_enhance_loans_and_create_topup_applications',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (164,'2026_04_23_193354_add_branch_id_to_key_activity_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (165,'2026_04_24_000001_add_proof_path_to_loan_application_collaterals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (166,'2026_04_25_000001_add_three_tier_approval_columns_to_loan_applications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (167,'2026_04_25_000002_create_loan_approval_votes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (168,'2026_04_25_000003_create_capitalize_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (169,'2026_04_25_000003_create_loan_approval_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (170,'2026_04_25_000004_add_columns_incapital_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (171,'2026_04_25_000004_add_voting_and_approval_flags_to_staff_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (172,'2026_04_25_000004_create_capitalize_history_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (173,'2026_04_26_000001_add_holiday_schedule_modes_to_loan_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (174,'2026_04_26_100001_add_gl_credit_account_id_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (175,'2026_04_27_000001_add_loan_application_id_to_topup_applications',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (176,'2026_04_27_000001_create_group_savings_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (177,'2026_04_27_000002_fix_loan_applied_charges_charge_id_foreign_key',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (178,'2026_04_27_000003_alter_group_savings_accounts_add_opening_balance_system_type',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (179,'2026_04_27_000004_add_disbursement_fee_to_loan_charges_category_enum',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (180,'2026_04_27_000005_patch_disbursement_fee_applied_charges_timing',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (181,'2026_04_27_000006_zero_out_schedule_charges_for_on_disbursement_applied_charges',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (182,'2026_04_27_000007_fix_duplicate_processing_fee_applied_charge',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (183,'2026_04_27_000008_fix_loan21_installment1_paid_status',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (184,'2026_04_28_000001_enhance_loan_rescheduling_schema',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (185,'2026_04_28_000002_add_old_status_to_loan_rescheduling_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (186,'2026_04_28_000003_add_method_shares_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (187,'2026_04_28_000003_add_reschedule_id_to_loan_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (188,'2026_04_28_000003_add_transferring_member_id_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (189,'2026_04_28_000004_fix_charge_amount_default_on_transactions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (190,'2026_04_28_000005_add_interest_enabled_to_savings_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (191,'2026_04_28_000006_add_regular_savings_interest_enabled_to_onboarding_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (192,'2026_04_29_000001_add_reschedule_charges_to_loan_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (193,'2026_04_29_000002_add_reschedule_audit_columns_to_loan_rescheduling',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (194,'2026_04_30_000001_add_share_payment_account_id_to_onboarding_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (195,'2026_05_02_160000_add_avatar_to_staff_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (196,'2026_05_02_170000_create_expense_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (197,'2026_05_02_170001_create_expenses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (198,'2026_05_02_170002_create_expense_attachments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (199,'2026_05_02_170003_add_parent_id_to_expenses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (200,'2026_05_02_170004_update_expense_attachments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (201,'2026_05_04_100001_create_expense_budgets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (202,'2026_05_04_100002_create_expense_approval_thresholds_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (203,'2026_05_04_100003_create_expense_approval_history_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (204,'2026_05_04_100004_update_expenses_table_structure',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (205,'2026_05_04_150000_create_accounting_periods_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (206,'2026_05_04_170000_add_is_over_budget_to_expenses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (207,'2026_05_06_135259_create_alter_savings_accounts_add_payment_mod_id_us_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (208,'2026_05_06_135259_create_alter_transactions_add_payment_mod_id_us_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (209,'2026_05_07_000001_add_general_charge_id_to_savings_product_charges',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (210,'2026_05_07_000002_add_unique_charge_transaction_to_member_charges',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (211,'2026_05_07_090026_add_document_label_to_loan_application_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (212,'2026_05_07_120000_add_applied_status_to_member_charges_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (213,'2026_05_09_000001_deduplicate_expense_categories',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (214,'2026_05_09_000002_add_medical_insurance_coa',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (215,'2026_05_10_220305_add_manual_journal_metadata',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (216,'2026_05_12_000001_remap_chart_of_accounts_gl_codes',1);
