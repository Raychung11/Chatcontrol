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
  ('pricing_footer_note',      'You can change plans any time. We prorate the difference for the current month.'),
  -- Operator legal details. Render in Terms / Privacy / Refund footers and the
  -- governing-law clause. Fill these in via /admin/pricing.php.
  ('operator_legal_name',      ''),
  ('operator_registration_no', ''),
  ('operator_address',         ''),
  ('operator_email',           ''),
  ('operator_jurisdiction',    'Malaysia'),
  ('operator_courts',          'the courts of Kuala Lumpur, Malaysia'),
  -- Editable "Last updated" dates so the legal page stops claiming today.
  ('legal_terms_updated',      '27 June 2026'),
  ('legal_privacy_updated',    '27 June 2026'),
  ('legal_disclaimer_updated', '27 June 2026'),
  ('legal_refund_updated',     '27 June 2026'),
  -- Refund policy details. 7-day window is the default for new subscriptions.
  ('refund_window_days',       '7'),
  ('refund_policy_extra',      '');
