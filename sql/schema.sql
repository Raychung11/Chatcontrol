-- AiServe Shared WhatsApp Inbox Portal
-- MySQL / MariaDB schema
-- Charset: utf8mb4 to support emoji and full WhatsApp text

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------
-- companies
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `companies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `whatsapp_number` VARCHAR(32) DEFAULT NULL,
  `phone_number_id` VARCHAR(64) DEFAULT NULL,
  `business_account_id` VARCHAR(64) DEFAULT NULL,
  `api_version` VARCHAR(16) NOT NULL DEFAULT 'v21.0',
  `access_token` TEXT DEFAULT NULL,
  `webhook_verify_token` VARCHAR(128) DEFAULT NULL,
  `logo` VARCHAR(255) DEFAULT NULL,
  `brand_color` VARCHAR(16) DEFAULT '#25D366',
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Kuala_Lumpur',
  `default_department_id` INT UNSIGNED DEFAULT NULL,
  `plan` ENUM('starter','growth','enterprise') NOT NULL DEFAULT 'starter',
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- departments
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `departments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dept_company` (`company_id`),
  CONSTRAINT `fk_dept_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- users
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(32) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('super_admin','manager','agent') NOT NULL DEFAULT 'agent',
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_company_email` (`company_id`,`email`),
  KEY `idx_users_dept` (`department_id`),
  CONSTRAINT `fk_users_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_users_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- contacts (WhatsApp customers)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contacts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `wa_id` VARCHAR(64) NOT NULL,
  `phone` VARCHAR(32) DEFAULT NULL,
  `display_name` VARCHAR(190) DEFAULT NULL,
  `profile_name` VARCHAR(190) DEFAULT NULL,
  `tags` VARCHAR(500) DEFAULT NULL,
  `last_message_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_contacts_company_wa` (`company_id`,`wa_id`),
  KEY `idx_contacts_phone` (`phone`),
  CONSTRAINT `fk_contacts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- conversations
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `conversations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `contact_id` INT UNSIGNED NOT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `assigned_user_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('open','pending','closed','escalated') NOT NULL DEFAULT 'open',
  `priority` ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `last_message_text` VARCHAR(500) DEFAULT NULL,
  `last_message_at` DATETIME DEFAULT NULL,
  `last_customer_message_at` DATETIME DEFAULT NULL,
  `service_window_expires_at` DATETIME DEFAULT NULL,
  `unread_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `first_response_at` DATETIME DEFAULT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conv_company_status` (`company_id`,`status`),
  KEY `idx_conv_contact` (`contact_id`),
  KEY `idx_conv_assigned` (`assigned_user_id`),
  KEY `idx_conv_dept` (`department_id`),
  KEY `idx_conv_last_msg` (`last_message_at`),
  CONSTRAINT `fk_conv_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conv_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conv_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_conv_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- messages
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `conversation_id` INT UNSIGNED NOT NULL,
  `contact_id` INT UNSIGNED NOT NULL,
  `sender_type` ENUM('customer','agent','system','ai') NOT NULL,
  `sender_user_id` INT UNSIGNED DEFAULT NULL,
  `wa_message_id` VARCHAR(128) DEFAULT NULL,
  `direction` ENUM('incoming','outgoing') NOT NULL,
  `message_type` VARCHAR(32) NOT NULL DEFAULT 'text',
  `template_name` VARCHAR(120) DEFAULT NULL,
  `message_text` TEXT DEFAULT NULL,
  `media_url` VARCHAR(500) DEFAULT NULL,
  `media_mime_type` VARCHAR(120) DEFAULT NULL,
  `media_filename` VARCHAR(255) DEFAULT NULL,
  `media_local_path` VARCHAR(500) DEFAULT NULL,
  `media_id` VARCHAR(120) DEFAULT NULL,
  `raw_payload` MEDIUMTEXT DEFAULT NULL,
  `status` ENUM('received','pending','sent','delivered','read','failed') NOT NULL DEFAULT 'pending',
  `error_message` VARCHAR(500) DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `delivered_at` DATETIME DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_messages_wa_id` (`wa_message_id`),
  KEY `idx_messages_conv_created` (`conversation_id`,`created_at`),
  KEY `idx_messages_company` (`company_id`),
  KEY `idx_messages_status` (`status`),
  CONSTRAINT `fk_messages_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_user` FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- internal_notes
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `internal_notes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `conversation_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `note_text` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notes_conv` (`conversation_id`),
  CONSTRAINT `fk_notes_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notes_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- conversation_tags
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `conversation_tags` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(64) NOT NULL,
  `color` VARCHAR(16) NOT NULL DEFAULT '#999999',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tags_company_name` (`company_id`,`name`),
  CONSTRAINT `fk_tags_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- conversation_tag_map
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `conversation_tag_map` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` INT UNSIGNED NOT NULL,
  `tag_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_conv_tag` (`conversation_id`,`tag_id`),
  CONSTRAINT `fk_ctm_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ctm_tag` FOREIGN KEY (`tag_id`) REFERENCES `conversation_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- message_templates
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `message_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `template_name` VARCHAR(120) NOT NULL,
  `category` VARCHAR(64) DEFAULT NULL,
  `language` VARCHAR(16) NOT NULL DEFAULT 'en',
  `body_text` TEXT NOT NULL,
  `variables_json` TEXT DEFAULT NULL,
  `status` ENUM('draft','approved','rejected','paused') NOT NULL DEFAULT 'draft',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_templates_company_name_lang` (`company_id`,`template_name`,`language`),
  CONSTRAINT `fk_templates_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- routing_rules - keyword-based department routing
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `routing_rules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `priority` INT UNSIGNED NOT NULL DEFAULT 100,
  `match_type` ENUM('contains','starts_with','equals','regex') NOT NULL DEFAULT 'contains',
  `match_value` VARCHAR(255) NOT NULL,
  `department_id` INT UNSIGNED NOT NULL,
  `assigned_user_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_routing_company` (`company_id`,`status`,`priority`),
  CONSTRAINT `fk_routing_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_routing_dept`    FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_routing_user`    FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- companies.default_department_id FK (added after departments exists)
-- ----------------------------------------------------------------
ALTER TABLE `companies`
  ADD CONSTRAINT `fk_companies_default_dept`
  FOREIGN KEY (`default_department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;

-- ----------------------------------------------------------------
-- activity_logs
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action_type` VARCHAR(64) NOT NULL,
  `target_type` VARCHAR(64) DEFAULT NULL,
  `target_id` INT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_company` (`company_id`),
  KEY `idx_activity_user` (`user_id`),
  KEY `idx_activity_action` (`action_type`),
  CONSTRAINT `fk_activity_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- login_attempts (basic rate limiting)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(190) DEFAULT NULL,
  `ip_address` VARCHAR(64) DEFAULT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_email_time` (`email`,`attempted_at`),
  KEY `idx_attempts_ip_time` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
