-- AiServe Shared WhatsApp Inbox - Phase 15 migration
-- WhatsApp broadcast / blast feature.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `broadcasts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `channel_id` INT UNSIGNED NOT NULL,
  `created_by_user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `message_text` TEXT NOT NULL,
  `status` ENUM('draft','running','paused','done','cancelled') NOT NULL DEFAULT 'draft',
  `batch_size` INT UNSIGNED NOT NULL DEFAULT 5,
  `batch_interval_min` INT UNSIGNED NOT NULL DEFAULT 3,
  `scheduled_at` DATETIME DEFAULT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `last_batch_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `total_recipients` INT UNSIGNED NOT NULL DEFAULT 0,
  `sent_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_broadcasts_company_status` (`company_id`,`status`),
  KEY `idx_broadcasts_status_lastbatch` (`status`,`last_batch_at`),
  CONSTRAINT `fk_broadcasts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_broadcasts_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_broadcasts_user`    FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `broadcast_recipients` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `broadcast_id` INT UNSIGNED NOT NULL,
  `contact_id` INT UNSIGNED DEFAULT NULL,
  `conversation_id` INT UNSIGNED DEFAULT NULL,
  `message_id` INT UNSIGNED DEFAULT NULL,
  `wa_id` VARCHAR(40) NOT NULL,
  `display_name` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  `wa_message_id` VARCHAR(128) DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recipients_broadcast_status` (`broadcast_id`,`status`),
  UNIQUE KEY `uk_recipients_broadcast_wa` (`broadcast_id`, `wa_id`),
  CONSTRAINT `fk_recipients_broadcast` FOREIGN KEY (`broadcast_id`) REFERENCES `broadcasts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
