-- AiServe Shared WhatsApp Inbox - Phase 37 migration
-- AI chatbot metered billing.
--
-- Model: capture raw Anthropic cost per call, apply per-workspace
-- multiplier (default 5×) + USD→MYR at DISPLAY time. Multiplier changes
-- retroactively update revenue; raw snapshot stays stable.
--
-- Three workspace tiers on companies.ai_chatbot_plan:
--   none  - AI chatbot module disabled; existing AI features still
--           work (they're operator tools) but no client billing
--   payg  - pay-as-you-go, no cap; monthly invoice = sum(charge_myr)
--   paid  - has a monthly cap (ai_chatbot_monthly_cap in USD raw cost);
--           ai_can_spend() blocks further calls when hit
--
-- Every AI call that counts against a client (first_touch, always_on,
-- fnb_cart_parse, suggest_reply) inserts one ai_usage_events row with
-- prompt_tokens + completion_tokens + raw_cost_usd (frozen at call time).

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase37;
DELIMITER //
CREATE PROCEDURE aiserve_phase37()
BEGIN
  -- 1. Per-workspace billing config on companies
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
      AND COLUMN_NAME = 'ai_chatbot_plan'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN ai_chatbot_plan        ENUM('none','payg','paid') NOT NULL DEFAULT 'none',
      ADD COLUMN ai_chatbot_multiplier  DECIMAL(5,2) NOT NULL DEFAULT 5.00,
      ADD COLUMN ai_chatbot_monthly_cap DECIMAL(10,2) DEFAULT NULL;
  END IF;

  -- 2. Per-call usage log
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_usage_events'
  ) THEN
    CREATE TABLE ai_usage_events (
      id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      company_id        INT UNSIGNED NOT NULL,
      conversation_id   INT UNSIGNED NULL,
      feature           VARCHAR(40) NOT NULL,
      model             VARCHAR(80) NOT NULL,
      prompt_tokens     INT UNSIGNED NOT NULL DEFAULT 0,
      completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
      raw_cost_usd      DECIMAL(10,6) NOT NULL DEFAULT 0,
      created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_ai_usage_company_time    (company_id, created_at),
      KEY idx_ai_usage_company_feature (company_id, feature, created_at),
      CONSTRAINT fk_ai_usage_company
        FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  END IF;

  -- 3. Anthropic price defaults + platform-wide FX + default multiplier
  --    (USD per 1M tokens for input / output). Platform admin can edit
  --    these via /admin/pricing.php once we wire an editor there.
  INSERT IGNORE INTO platform_settings (`key`, `value`) VALUES
    ('ai_price_opus_input',     '15.00'),
    ('ai_price_opus_output',    '75.00'),
    ('ai_price_sonnet_input',   '3.00'),
    ('ai_price_sonnet_output',  '15.00'),
    ('ai_price_haiku_input',    '1.00'),
    ('ai_price_haiku_output',   '5.00'),
    ('ai_price_default_input',  '3.00'),
    ('ai_price_default_output', '15.00'),
    ('ai_usd_to_myr',           '4.70'),
    ('ai_default_multiplier',   '5.00');
END//
DELIMITER ;
CALL aiserve_phase37();
DROP PROCEDURE aiserve_phase37;
