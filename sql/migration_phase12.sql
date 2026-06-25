-- AiServe Shared WhatsApp Inbox - Phase 12 migration
-- Always-on AI auto-reply + Business hours mode.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase12;
DELIMITER //
CREATE PROCEDURE aiserve_phase12()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'ai_always_on'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN ai_always_on             TINYINT(1)  NOT NULL DEFAULT 0      AFTER ai_daily_cap,
      ADD COLUMN business_hours_enabled   TINYINT(1)  NOT NULL DEFAULT 0      AFTER ai_always_on,
      ADD COLUMN business_hours_timezone  VARCHAR(64) DEFAULT NULL            AFTER business_hours_enabled,
      ADD COLUMN business_hours_schedule  TEXT        DEFAULT NULL            AFTER business_hours_timezone,
      ADD COLUMN off_hours_message        TEXT        DEFAULT NULL            AFTER business_hours_schedule;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase12();
DROP PROCEDURE aiserve_phase12;
