-- AiServe Shared WhatsApp Inbox - Phase 10 migration
-- First-touch AI auto-reply: per-workspace toggle, escalation phrases,
-- daily cap. Reuses the existing ai_suggest_reply + KB infrastructure.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase10;
DELIMITER //
CREATE PROCEDURE aiserve_phase10()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'ai_first_touch'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN ai_first_touch         TINYINT(1) NOT NULL DEFAULT 0 AFTER ai_auto_suggest,
      ADD COLUMN ai_escalation_phrases  VARCHAR(500) DEFAULT NULL AFTER ai_first_touch,
      ADD COLUMN ai_daily_cap           INT UNSIGNED NOT NULL DEFAULT 200 AFTER ai_escalation_phrases;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase10();
DROP PROCEDURE aiserve_phase10;
