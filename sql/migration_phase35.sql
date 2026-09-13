-- AiServe Shared WhatsApp Inbox - Phase 35 migration
-- Web chat channel provider — customer scans a QR (or opens a URL),
-- lands in a browser-based chat that flows through the SAME inbox
-- pipeline as WhatsApp. Agents, AI reply drafts, message flows, F&B
-- ordering — everything downstream is identical.
--
-- Design: web_chat is just another channel provider next to cloud_api,
-- evolution, aiserve_chatbot, facebook_page. Provider send for web_chat
-- is a no-op (returns success + a fake wa_message_id) because the
-- message row is inserted by the caller in the normal flow — the
-- widget picks up new outgoing messages via a poll endpoint.

SET NAMES utf8mb4;

ALTER TABLE channels
  MODIFY COLUMN provider
    ENUM('cloud_api','evolution','aiserve_chatbot','facebook_page','instagram_business','web_chat')
    NOT NULL DEFAULT 'cloud_api';

DROP PROCEDURE IF EXISTS aiserve_phase35;
DELIMITER //
CREATE PROCEDURE aiserve_phase35()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'channels'
      AND COLUMN_NAME = 'web_chat_greeting'
  ) THEN
    ALTER TABLE channels
      ADD COLUMN web_chat_greeting VARCHAR(500) DEFAULT NULL,
      ADD COLUMN web_chat_title    VARCHAR(120) DEFAULT NULL;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase35();
DROP PROCEDURE aiserve_phase35;

-- Browser sessions. One row per (browser, channel). session_token is
-- the localStorage-stored opaque identifier the widget sends on every
-- request; server resolves it to contact_id + channel_id. On expiry
-- the customer's next visit starts a fresh session (= a new anonymous
-- contact) — that's acceptable; we can layer a "connect your phone"
-- merge on top later.
CREATE TABLE IF NOT EXISTS `web_chat_sessions` (
  `session_token`   CHAR(48)     NOT NULL,
  `channel_id`      INT UNSIGNED NOT NULL,
  `contact_id`      INT UNSIGNED NOT NULL,
  `conversation_id` INT UNSIGNED DEFAULT NULL,
    -- Set on first inbound message; reused for repeat opens within
    -- the session so the customer's history is one thread.
  `context`         VARCHAR(120) DEFAULT NULL,
    -- Optional URL-carried context: table number, branch code, promo
    -- source, etc. Displayed to agents; passed to F&B order.
  `ip`              VARCHAR(45)  DEFAULT NULL,
  `user_agent`      VARCHAR(255) DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`      DATETIME NOT NULL,
  PRIMARY KEY (`session_token`),
  KEY `idx_wc_sessions_contact` (`contact_id`),
  KEY `idx_wc_sessions_channel` (`channel_id`),
  CONSTRAINT `fk_wc_sessions_channel`
    FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wc_sessions_contact`
    FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wc_sessions_conv`
    FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- contacts.platform gets a new value for web-chat customers. Enum grow
-- keeps existing whatsapp/facebook/instagram rows unchanged.
ALTER TABLE contacts
  MODIFY COLUMN platform
    ENUM('whatsapp','facebook','instagram','web_chat')
    NOT NULL DEFAULT 'whatsapp';
