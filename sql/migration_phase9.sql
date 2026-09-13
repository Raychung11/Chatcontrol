-- AiServe Shared WhatsApp Inbox - Phase 9 migration
-- Topic analysis cache. AI extracts top discussion topics from recent
-- conversations; we store the result for 24h to avoid re-running on every
-- page load.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `topic_analyses` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id`         INT UNSIGNED NOT NULL,
  `period_days`        INT UNSIGNED NOT NULL,
  `conversation_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `message_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `model`              VARCHAR(64) DEFAULT NULL,
  `topics_json`        MEDIUMTEXT NOT NULL,
  `input_tokens`       INT UNSIGNED DEFAULT NULL,
  `output_tokens`      INT UNSIGNED DEFAULT NULL,
  `created_by`         INT UNSIGNED DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_topics_company_period` (`company_id`, `period_days`, `created_at`),
  CONSTRAINT `fk_topics_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_topics_user`    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
