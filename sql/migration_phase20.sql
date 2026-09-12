-- AiServe Shared WhatsApp Inbox - Phase 20 migration
-- "Learn from history" auto-KB feature.
--
-- Weekly cron reads replied conversations, sends them to Claude for
-- distillation, saves the result as an auto-generated knowledge_base
-- article. The AI reply drafting path already reads from knowledge_base,
-- so once this article exists the bot will start using the team's own
-- historical answers to draft future replies.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase20;
DELIMITER //
CREATE PROCEDURE aiserve_phase20()
BEGIN
  -- Per-workspace opt-in.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
      AND COLUMN_NAME = 'learn_from_history_enabled'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN learn_from_history_enabled TINYINT(1) NOT NULL DEFAULT 0
        AFTER media_max_kb;
  END IF;

  -- Flag distinguishes hand-curated articles from cron-generated ones.
  -- Auto articles are read-only in the admin UI.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_base'
      AND COLUMN_NAME = 'auto_generated'
  ) THEN
    ALTER TABLE knowledge_base
      ADD COLUMN auto_generated TINYINT(1) NOT NULL DEFAULT 0
        AFTER status,
      ADD COLUMN last_auto_updated_at DATETIME DEFAULT NULL
        AFTER auto_generated;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase20();
DROP PROCEDURE aiserve_phase20;
