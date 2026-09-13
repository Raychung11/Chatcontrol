-- AiServe Shared WhatsApp Inbox - Phase 7 migration
-- Knowledge base: uploaded text/PDF/DOCX articles per tenant. AI grounds
-- suggested replies in their content via prompt caching.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `knowledge_base` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id`      INT UNSIGNED NOT NULL,
  `title`           VARCHAR(200) NOT NULL,
  `source_filename` VARCHAR(255) DEFAULT NULL,
  `mime_type`       VARCHAR(120) DEFAULT NULL,
  `content_text`    MEDIUMTEXT NOT NULL,
  `content_chars`   INT UNSIGNED NOT NULL DEFAULT 0,
  `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`      INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kb_company_status` (`company_id`, `status`),
  CONSTRAINT `fk_kb_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_kb_user`    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
