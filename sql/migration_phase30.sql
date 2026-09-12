-- AiServe Shared WhatsApp Inbox - Phase 30 migration
-- Broadcast metering: free tier + paid tier.
--
-- Every workspace gets a `broadcast_plan` (free or paid). Metering unit
-- is the RECIPIENT (one broadcast_recipients row = one billable send).
-- Limits + price are platform-wide settings so the operator can tune
-- them from /admin/pricing.php without touching code.
--
-- Enforcement is at broadcast-creation time in admin/broadcast_new.php:
-- count sent-this-billing-month + new recipients; block if that would
-- exceed the plan's quota.
--
-- Rollover: usage is counted from calendar-month boundaries in the
-- workspace's timezone. Simple. Fits the common "RM 480 / month for
-- 10k sends" mental model without needing a proper subscription clock.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase30;
DELIMITER //
CREATE PROCEDURE aiserve_phase30()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
      AND COLUMN_NAME = 'broadcast_plan'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN broadcast_plan ENUM('free','paid') NOT NULL DEFAULT 'free';
  END IF;
END//
DELIMITER ;
CALL aiserve_phase30();
DROP PROCEDURE aiserve_phase30;

INSERT IGNORE INTO `platform_settings` (`key`, `value`) VALUES
  -- Free tier: recipient sends per month included at no cost.
  ('broadcast_free_limit',   '1000'),
  -- Paid tier: monthly quota + flat monthly price.
  ('broadcast_paid_limit',   '10000'),
  ('broadcast_paid_price',   '480');
