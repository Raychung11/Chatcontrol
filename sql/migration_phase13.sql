-- AiServe Shared WhatsApp Inbox - Phase 13 migration
-- Per-workspace failed-send email alerts.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase13;
DELIMITER //
CREATE PROCEDURE aiserve_phase13()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'alert_failed_sends_enabled'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN alert_failed_sends_enabled  TINYINT(1)   NOT NULL DEFAULT 1   AFTER off_hours_message,
      ADD COLUMN alert_failed_sends_threshold INT UNSIGNED NOT NULL DEFAULT 5  AFTER alert_failed_sends_enabled,
      ADD COLUMN alert_email                 VARCHAR(190) DEFAULT NULL         AFTER alert_failed_sends_threshold;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase13();
DROP PROCEDURE aiserve_phase13;
