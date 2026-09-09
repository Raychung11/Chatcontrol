-- AiServe Shared WhatsApp Inbox - Phase 60 migration
-- NFC card taps → chat widget.
--
-- Adds:
--   1. `nfc_cards`    — one row per physical card. Carries an opaque
--                       token that lives on the NFC tag's URL, plus
--                       the metadata needed to attribute the tap
--                       (label, table_number, branch, campaign).
--
--   2. `nfc_tap_events` — one row per real tap. Powers a per-card
--                         "how many times has this been tapped this
--                         month" chip on the admin page and the
--                         campaign / branch tap-analytics view.
--
--   3. `web_chat_sessions.nfc_card_id` — links a widget session back
--      to the card that spawned it, so the widget send handler can
--      pull the card's metadata (table, branch) at conversation-create
--      time and stamp them on the new conversation.
--
-- Idempotent — guarded via information_schema.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase60;
DELIMITER //
CREATE PROCEDURE aiserve_phase60()
BEGIN
  -- ---- nfc_cards ----
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nfc_cards'
  ) THEN
    CREATE TABLE `nfc_cards` (
      `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `company_id`         INT UNSIGNED NOT NULL,
      `channel_id`         INT UNSIGNED NOT NULL,   -- web_chat channel to land on
      `token`              CHAR(16)     NOT NULL,   -- opaque short token on the NFC URL
      `label`              VARCHAR(120) DEFAULT NULL,
      `table_number`       INT UNSIGNED DEFAULT NULL,
      `branch_id`          INT UNSIGNED DEFAULT NULL,
      `campaign`           VARCHAR(120) DEFAULT NULL,
      `enabled`            TINYINT(1)   NOT NULL DEFAULT 1,
      `created_by_user_id` INT UNSIGNED DEFAULT NULL,
      `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_nfc_cards_token` (`token`),
      KEY `idx_nfc_cards_company`  (`company_id`, `enabled`),
      KEY `idx_nfc_cards_campaign` (`company_id`, `campaign`),
      CONSTRAINT `fk_nfc_cards_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_nfc_cards_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels`  (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  END IF;

  -- ---- nfc_tap_events ----
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nfc_tap_events'
  ) THEN
    CREATE TABLE `nfc_tap_events` (
      `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `card_id`        INT UNSIGNED NOT NULL,
      `session_token`  VARCHAR(64)  DEFAULT NULL,  -- populated when the tap lands + creates a widget session
      `ip`             VARCHAR(45)  DEFAULT NULL,
      `user_agent`     VARCHAR(255) DEFAULT NULL,
      `tapped_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_nfc_taps_card_time` (`card_id`, `tapped_at`),
      CONSTRAINT `fk_nfc_taps_card` FOREIGN KEY (`card_id`) REFERENCES `nfc_cards` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  END IF;

  -- ---- web_chat_sessions.nfc_card_id ----
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'web_chat_sessions'
      AND COLUMN_NAME  = 'nfc_card_id'
  ) THEN
    ALTER TABLE `web_chat_sessions`
      ADD COLUMN `nfc_card_id` INT UNSIGNED DEFAULT NULL AFTER `context`,
      ADD KEY `idx_wcs_nfc_card` (`nfc_card_id`);
  END IF;
END//
DELIMITER ;
CALL aiserve_phase60();
DROP PROCEDURE aiserve_phase60;
