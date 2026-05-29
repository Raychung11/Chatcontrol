-- AiServe Shared WhatsApp Inbox - Phase 4 migration
-- Adds the third messaging provider: aiserve_chatbot (custom Bearer-token
-- gateway in front of Evolution, at chatbot.aiserve.my).

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase4;
DELIMITER //
CREATE PROCEDURE aiserve_phase4()
BEGIN
  -- Extend provider enum to add 'aiserve_chatbot'
  ALTER TABLE companies
    MODIFY COLUMN provider ENUM('cloud_api','evolution','aiserve_chatbot')
           NOT NULL DEFAULT 'cloud_api';

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
      AND COLUMN_NAME = 'chatbot_base_url'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN chatbot_base_url VARCHAR(255) DEFAULT NULL AFTER evolution_status,
      ADD COLUMN chatbot_bearer_token VARCHAR(255) DEFAULT NULL AFTER chatbot_base_url;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase4();
DROP PROCEDURE aiserve_phase4;
