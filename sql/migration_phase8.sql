-- AiServe Shared WhatsApp Inbox - Phase 8 migration
-- Platform-admin flag for SaaS operator impersonation.

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_platform_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`;

-- Promote the seeded admin (id=1) to platform admin so a fresh install
-- always has an operator who can run workspace setups.
UPDATE `users` SET `is_platform_admin` = 1 WHERE `id` = 1;
