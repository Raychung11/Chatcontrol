-- AiServe Shared WhatsApp Inbox - Phase 59 migration
-- Per-workspace admin phone for WhatsApp DM alert escalation.
--
-- Adds companies.admin_alert_phone. When populated, the alerts
-- dispatcher (inc/alerts.php :: alert_dispatch_whatsapp) sends a
-- one-line WhatsApp DM to this number for every new open incident
-- via any of the workspace's own connected Evolution channels.
--
-- Format: E.164 without a plus sign (e.g. '60123456789'). Kept
-- flexible so operators can enter it with or without a plus — the
-- helper strips non-digits before use.
--
-- Idempotent — guarded via information_schema.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase59;
DELIMITER //
CREATE PROCEDURE aiserve_phase59()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'companies'
      AND COLUMN_NAME  = 'admin_alert_phone'
  ) THEN
    ALTER TABLE `companies`
      ADD COLUMN `admin_alert_phone` VARCHAR(32) DEFAULT NULL
        AFTER `alert_email`;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase59();
DROP PROCEDURE aiserve_phase59;
