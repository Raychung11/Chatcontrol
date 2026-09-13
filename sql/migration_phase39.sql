-- AiServe Shared WhatsApp Inbox - Phase 39 migration
-- Per-feature AI model overrides.
--
-- companies.ai_model already sets a workspace-wide default model.
-- ai_model_by_feature (JSON) lets each feature use a different tier —
-- typical pattern: Haiku for cheap mechanical calls (fnb_cart_parse,
-- kb_distill) and Sonnet for customer-facing quality (always_on,
-- first_touch, suggest_reply).
--
-- Shape: {"first_touch":"claude-sonnet-5","always_on":"claude-sonnet-5",
--         "suggest_reply":"claude-sonnet-5","fnb_cart_parse":"claude-haiku-4-5",
--         "kb_distill":"claude-opus-5"}
--
-- NULL / missing key falls back to companies.ai_model.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase39;
DELIMITER //
CREATE PROCEDURE aiserve_phase39()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
      AND COLUMN_NAME = 'ai_model_by_feature'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN ai_model_by_feature TEXT DEFAULT NULL;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase39();
DROP PROCEDURE aiserve_phase39;
