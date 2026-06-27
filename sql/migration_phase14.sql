-- AiServe Shared WhatsApp Inbox - Phase 14 migration
-- Platform-wide settings table (currently used for editable pricing).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `platform_settings` (
  `key`        VARCHAR(64)  NOT NULL PRIMARY KEY,
  `value`      TEXT         NOT NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default pricing the first time the migration runs. Keys we read on the
-- public pricing page, the signup form, and the landing page. Edit them from
-- /admin/pricing.php once the migration is in.
INSERT IGNORE INTO `platform_settings` (`key`, `value`) VALUES
  ('pricing_currency',         'RM'),
  ('pricing_period_label',     '/ month'),
  ('pricing_per_seat',         '12'),
  ('pricing_starter_seats',    '3'),
  ('pricing_bundle_seats',     '10'),
  ('pricing_bundle_price',     '60'),
  ('pricing_extra_seat_price', '12'),
  ('pricing_payment_methods',  'Bank transfer (Malaysia), DuitNow, or e-wallet. Talk to us if you need annual billing for a discount.'),
  ('pricing_footer_note',      'You can change plans any time. We prorate the difference for the current month.');
