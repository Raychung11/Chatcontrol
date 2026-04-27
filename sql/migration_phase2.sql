-- AiServe Shared WhatsApp Inbox - Phase 2 migration
-- Adds: default department, routing rules, conversation resolution timestamps,
--       inbound media download status, and an outgoing-template message_type.
--
-- Safe to re-run: every change uses IF NOT EXISTS / IF EXISTS guards where
-- supported, otherwise wrapped in a stored procedure.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------
-- companies: default_department_id (auto-routing fallback)
-- ----------------------------------------------------------------
DROP PROCEDURE IF EXISTS aiserve_phase2;
DELIMITER //
CREATE PROCEDURE aiserve_phase2()
BEGIN
  -- companies.default_department_id
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'default_department_id'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN default_department_id INT UNSIGNED NULL AFTER timezone,
      ADD CONSTRAINT fk_companies_default_dept FOREIGN KEY (default_department_id)
        REFERENCES departments(id) ON DELETE SET NULL;
  END IF;

  -- conversations.resolved_at  (when first set to closed)
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND COLUMN_NAME = 'resolved_at'
  ) THEN
    ALTER TABLE conversations
      ADD COLUMN resolved_at DATETIME NULL AFTER first_response_at;
  END IF;

  -- messages.media_local_path  (downloaded inbound media)
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'media_local_path'
  ) THEN
    ALTER TABLE messages
      ADD COLUMN media_local_path VARCHAR(500) NULL AFTER media_filename;
  END IF;

  -- messages.media_id  (Meta media id, used for async download)
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'media_id'
  ) THEN
    ALTER TABLE messages
      ADD COLUMN media_id VARCHAR(120) NULL AFTER media_local_path;
  END IF;

  -- messages.template_name  (for outgoing template sends)
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'template_name'
  ) THEN
    ALTER TABLE messages
      ADD COLUMN template_name VARCHAR(120) NULL AFTER message_type;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase2();
DROP PROCEDURE aiserve_phase2;

-- ----------------------------------------------------------------
-- webhook_events - diagnostic log of every Meta webhook POST
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `webhook_events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `method` VARCHAR(8) DEFAULT NULL,
  `http_status` INT DEFAULT NULL,
  `message_count` INT NOT NULL DEFAULT 0,
  `status_count` INT NOT NULL DEFAULT 0,
  `error_text` VARCHAR(500) DEFAULT NULL,
  `raw_body` MEDIUMTEXT,
  `ip_address` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_webhook_events_company_time` (`company_id`,`created_at`),
  CONSTRAINT `fk_webhook_events_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- routing_rules - keyword-based department routing for new conversations
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

SET FOREIGN_KEY_CHECKS = 1;
