-- AiServe Shared WhatsApp Inbox - Phase 16 migration
-- Keyword-triggered auto-reply / catalog send.
--
-- When a customer's incoming text matches a rule, the system sends the
-- configured reply (with an optional media file attached) via the same
-- channel the customer messaged into. Rate-limited per (rule,
-- conversation) so a repeated keyword does not spam the customer.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `auto_replies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `channel_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = applies to every channel in the workspace',
  `name` VARCHAR(150) NOT NULL,
  `match_type` ENUM('contains','starts_with','equals','regex') NOT NULL DEFAULT 'contains',
  `match_value` VARCHAR(500) NOT NULL,
  `reply_text` TEXT DEFAULT NULL,
  `media_kind` ENUM('none','image','video','document') NOT NULL DEFAULT 'none',
  `media_path` VARCHAR(500) DEFAULT NULL,
  `media_filename` VARCHAR(255) DEFAULT NULL,
  `media_mime` VARCHAR(120) DEFAULT NULL,
  `priority` INT UNSIGNED NOT NULL DEFAULT 100,
  `cooldown_min` INT UNSIGNED NOT NULL DEFAULT 60,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `trigger_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_triggered_at` DATETIME DEFAULT NULL,
  `created_by_user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ar_company_status_priority` (`company_id`, `status`, `priority`),
  KEY `idx_ar_channel` (`channel_id`),
  CONSTRAINT `fk_ar_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ar_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ar_user`    FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fire log so we can enforce the per-conversation cooldown and audit
-- what got auto-sent to whom.
CREATE TABLE IF NOT EXISTS `auto_reply_fires` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `auto_reply_id` INT UNSIGNED NOT NULL,
  `conversation_id` INT UNSIGNED NOT NULL,
  `message_id` INT UNSIGNED DEFAULT NULL,
  `matched_text` VARCHAR(500) DEFAULT NULL,
  `fired_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_arf_rule_conv_time` (`auto_reply_id`, `conversation_id`, `fired_at`),
  CONSTRAINT `fk_arf_rule` FOREIGN KEY (`auto_reply_id`)   REFERENCES `auto_replies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_arf_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
