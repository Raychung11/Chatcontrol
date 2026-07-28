-- AiServe Shared WhatsApp Inbox - Phase 31 migration
-- Broadcast metering follow-ups:
--   - Pay-as-you-go tier ('payg'): no monthly quota, priced per recipient
--   - Yearly billing cycle: same price × 12 with a discount %
--   - 80% quota alert emails: stamp per month so we only email once
--
-- Schema: enum grows, new columns added on companies. platform_settings
-- gains two new rows for the PAYG per-recipient rate and the yearly
-- discount %.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase31;
DELIMITER //
CREATE PROCEDURE aiserve_phase31()
BEGIN
  -- Extend the broadcast_plan enum with 'payg'
  ALTER TABLE companies
    MODIFY COLUMN broadcast_plan ENUM('free','paid','payg') NOT NULL DEFAULT 'free';

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
      AND COLUMN_NAME = 'broadcast_billing_cycle'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN broadcast_billing_cycle ENUM('monthly','yearly')
        NOT NULL DEFAULT 'monthly' AFTER broadcast_plan;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
      AND COLUMN_NAME = 'broadcast_quota_alert_month'
  ) THEN
    -- YYYY-MM string of the most recent month we alerted this workspace
    -- about crossing 80% of their quota. Prevents re-emailing every day
    -- once the threshold has been crossed.
    ALTER TABLE companies
      ADD COLUMN broadcast_quota_alert_month VARCHAR(7) DEFAULT NULL
        AFTER broadcast_billing_cycle;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase31();
DROP PROCEDURE aiserve_phase31;

INSERT IGNORE INTO `platform_settings` (`key`, `value`) VALUES
  -- Pay-as-you-go rate per recipient send. Currency inherits from
  -- pricing_currency (RM by default).
  ('broadcast_payg_per_recipient', '0.05'),
  -- Percent discount when a workspace picks yearly billing on the paid
  -- broadcast plan. Displayed only — actual billing is out-of-band
  -- (Stripe / manual invoice), not enforced by the app.
  ('broadcast_yearly_discount_pct', '20');
