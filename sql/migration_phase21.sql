-- AiServe Shared WhatsApp Inbox - Phase 21 migration
-- Click-to-translate on chat messages.
--
-- Caches the translation on the messages row itself so a click never
-- re-hits the AI API for the same message. If the operator later wants
-- a different target language, we overwrite - the workspace picks one
-- target language at a time.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase21;
DELIMITER //
CREATE PROCEDURE aiserve_phase21()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages'
      AND COLUMN_NAME = 'translated_text'
  ) THEN
    ALTER TABLE messages
      ADD COLUMN translated_text TEXT DEFAULT NULL AFTER message_text,
      ADD COLUMN translated_to_lang VARCHAR(8) DEFAULT NULL AFTER translated_text,
      ADD COLUMN translated_at DATETIME DEFAULT NULL AFTER translated_to_lang;
  END IF;

  -- Workspace-level default target language for the translate button.
  -- 'en' by default because agents on this platform tend to work in
  -- English regardless of the customer's language.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
      AND COLUMN_NAME = 'translate_target_lang'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN translate_target_lang VARCHAR(8) NOT NULL DEFAULT 'en'
        AFTER learn_from_history_enabled;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase21();
DROP PROCEDURE aiserve_phase21;
