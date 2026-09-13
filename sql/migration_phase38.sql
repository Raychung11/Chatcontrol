-- AiServe Shared WhatsApp Inbox - Phase 38 migration
-- AI persona field on companies.
--
-- ai_system_prompt already exists as the big power-user override for
-- the whole system prompt. Most operators don't need that — they just
-- want to say "the AI is Ali, casual Malaysian voice with a bit of BM
-- flavour". Give them a dedicated ai_persona field for that, injected
-- into the default prompt right after the framing sentence so it
-- flavours EVERY AI call (first-touch, always-on, suggest, F&B parse).
--
-- ai_model already exists — no new column, we just surface the model
-- picker on /admin/knowledge.php as part of the same "AI voice" card.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase38;
DELIMITER //
CREATE PROCEDURE aiserve_phase38()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
      AND COLUMN_NAME = 'ai_persona'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN ai_persona VARCHAR(1000) DEFAULT NULL;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase38();
DROP PROCEDURE aiserve_phase38;
