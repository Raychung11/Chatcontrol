-- AiServe Shared WhatsApp Inbox - Phase 6 migration
-- AI reply suggestion (Claude-powered). Per-tenant config + auto-suggest toggle.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase6;
DELIMITER //
CREATE PROCEDURE aiserve_phase6()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'ai_enabled'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN ai_enabled       TINYINT(1)   NOT NULL DEFAULT 0      AFTER chatbot_bearer_token,
      ADD COLUMN ai_provider      ENUM('claude','openai') NOT NULL DEFAULT 'claude' AFTER ai_enabled,
      ADD COLUMN ai_model         VARCHAR(64)  NOT NULL DEFAULT 'claude-haiku-4-5' AFTER ai_provider,
      ADD COLUMN ai_api_key       VARCHAR(255) DEFAULT NULL AFTER ai_model,
      ADD COLUMN ai_system_prompt TEXT         DEFAULT NULL AFTER ai_api_key,
      ADD COLUMN ai_auto_suggest  TINYINT(1)   NOT NULL DEFAULT 0 AFTER ai_system_prompt;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase6();
DROP PROCEDURE aiserve_phase6;
