-- AiServe Shared WhatsApp Inbox - Phase 11 migration
-- Multi-channel: one workspace can hold multiple WhatsApp numbers.
-- Provider config moves from companies.* to channels.*. A "Default"
-- channel is auto-created per existing workspace and all existing
-- conversations + messages get backfilled with that channel_id.
--
-- The legacy companies.provider/access_token/... columns are NOT dropped
-- so a partial deploy can't break send. The new channels table is the
-- canonical source going forward.

SET NAMES utf8mb4;

-- 1. Channels table
CREATE TABLE IF NOT EXISTS `channels` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id`           INT UNSIGNED NOT NULL,
  `name`                 VARCHAR(100) NOT NULL,
  `display_phone`        VARCHAR(32) DEFAULT NULL,
  `provider`             ENUM('cloud_api','evolution','aiserve_chatbot') NOT NULL DEFAULT 'cloud_api',
  `webhook_token`        VARCHAR(64) NOT NULL,
  -- Cloud API
  `phone_number_id`      VARCHAR(64) DEFAULT NULL,
  `business_account_id`  VARCHAR(64) DEFAULT NULL,
  `api_version`          VARCHAR(16) NOT NULL DEFAULT 'v21.0',
  `access_token`         TEXT DEFAULT NULL,
  -- Evolution
  `evolution_base_url`   VARCHAR(255) DEFAULT NULL,
  `evolution_api_key`    VARCHAR(255) DEFAULT NULL,
  `evolution_instance`   VARCHAR(120) DEFAULT NULL,
  `evolution_status`     ENUM('disconnected','connecting','connected') NOT NULL DEFAULT 'disconnected',
  -- Chatbot gateway
  `chatbot_base_url`     VARCHAR(255) DEFAULT NULL,
  `chatbot_bearer_token` VARCHAR(255) DEFAULT NULL,
  -- Lifecycle
  `is_default`           TINYINT(1) NOT NULL DEFAULT 0,
  `status`               ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_channels_webhook_token` (`webhook_token`),
  KEY `idx_channels_company` (`company_id`, `status`),
  CONSTRAINT `fk_channels_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add channel_id to conversations + messages (nullable so migration is two-step)
DROP PROCEDURE IF EXISTS aiserve_phase11_columns;
DELIMITER //
CREATE PROCEDURE aiserve_phase11_columns()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND COLUMN_NAME = 'channel_id'
  ) THEN
    ALTER TABLE conversations
      ADD COLUMN channel_id INT UNSIGNED DEFAULT NULL AFTER company_id,
      ADD KEY idx_conversations_channel (channel_id),
      ADD CONSTRAINT fk_conversations_channel FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE SET NULL;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'channel_id'
  ) THEN
    ALTER TABLE messages
      ADD COLUMN channel_id INT UNSIGNED DEFAULT NULL AFTER company_id,
      ADD KEY idx_messages_channel (channel_id),
      ADD CONSTRAINT fk_messages_channel FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE SET NULL;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase11_columns();
DROP PROCEDURE aiserve_phase11_columns;

-- 3. Seed one "Default" channel per existing company, cloning the
--    company's existing provider config so nothing stops working.
INSERT INTO channels
  (company_id, name, display_phone, provider, webhook_token,
   phone_number_id, business_account_id, api_version, access_token,
   evolution_base_url, evolution_api_key, evolution_instance, evolution_status,
   chatbot_base_url, chatbot_bearer_token,
   is_default, status)
SELECT
  c.id,
  CONCAT(COALESCE(c.name, 'Workspace'), ' default'),
  c.whatsapp_number,
  COALESCE(c.provider, 'cloud_api'),
  SUBSTRING(SHA2(CONCAT(c.id, '-', UUID()), 256), 1, 48),
  c.phone_number_id, c.business_account_id, COALESCE(c.api_version, 'v21.0'), c.access_token,
  c.evolution_base_url, c.evolution_api_key, c.evolution_instance, COALESCE(c.evolution_status, 'disconnected'),
  c.chatbot_base_url, c.chatbot_bearer_token,
  1, 'active'
FROM companies c
WHERE NOT EXISTS (SELECT 1 FROM channels ch WHERE ch.company_id = c.id);

-- 4. Backfill: every conversation gets its company's default channel_id.
UPDATE conversations conv
INNER JOIN channels ch ON ch.company_id = conv.company_id AND ch.is_default = 1
SET conv.channel_id = ch.id
WHERE conv.channel_id IS NULL;

-- 5. Backfill: every message gets its conversation's channel_id.
UPDATE messages m
INNER JOIN conversations c ON c.id = m.conversation_id
SET m.channel_id = c.channel_id
WHERE m.channel_id IS NULL AND c.channel_id IS NOT NULL;
