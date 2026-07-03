-- AiServe Shared WhatsApp Inbox - Phase 17 migration
-- Race-condition fixes surfaced by pre-deploy review.
--
-- 1. auto_reply_fires gets a cooldown_bucket + UNIQUE key so two
--    concurrent webhook events for the same conversation cannot
--    both pass the cooldown check and double-send.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase17;
DELIMITER //
CREATE PROCEDURE aiserve_phase17()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'auto_reply_fires'
      AND COLUMN_NAME  = 'cooldown_bucket'
  ) THEN
    ALTER TABLE auto_reply_fires
      ADD COLUMN cooldown_bucket BIGINT UNSIGNED NOT NULL DEFAULT 0
        AFTER matched_text;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'auto_reply_fires'
      AND INDEX_NAME   = 'uk_arf_rule_conv_bucket'
  ) THEN
    ALTER TABLE auto_reply_fires
      ADD UNIQUE KEY uk_arf_rule_conv_bucket
        (auto_reply_id, conversation_id, cooldown_bucket);
  END IF;
END//
DELIMITER ;
CALL aiserve_phase17();
DROP PROCEDURE aiserve_phase17;
