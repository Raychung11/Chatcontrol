-- AiServe Shared WhatsApp Inbox - Phase 3 migration
-- Multi-provider support: Meta Cloud API + Evolution API.
-- Idempotent: safe to run on a Phase 1/2 database.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase3;
DELIMITER //
CREATE PROCEDURE aiserve_phase3()
BEGIN
  -- companies.provider
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'provider'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN provider ENUM('cloud_api','evolution') NOT NULL DEFAULT 'cloud_api' AFTER plan;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'evolution_base_url'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN evolution_base_url  VARCHAR(255) DEFAULT NULL AFTER provider,
      ADD COLUMN evolution_api_key   VARCHAR(255) DEFAULT NULL AFTER evolution_base_url,
      ADD COLUMN evolution_instance  VARCHAR(120) DEFAULT NULL AFTER evolution_api_key,
      ADD COLUMN evolution_status    ENUM('disconnected','connecting','connected') NOT NULL DEFAULT 'disconnected' AFTER evolution_instance;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase3();
DROP PROCEDURE aiserve_phase3;
