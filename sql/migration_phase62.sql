-- AiServe Shared WhatsApp Inbox - Phase 62 migration
--
-- Three notification-refinement additions on top of phase 61's Web Push:
--
--   1. users.viewing_conversation_id + users.viewing_at
--      The chat-page poller writes to these every 5s from api/poll.php
--      (scope=chat). notify_new_inbound() reads them to suppress a
--      push when the target agent is CURRENTLY looking at the
--      conversation — mirrors how WhatsApp Web behaves.
--
--   2. notification_dispatch_log
--      One row per delivered notification (push or email). Powers two
--      rate limits:
--        - push:  at most one per (user, conversation) per minute
--                 (protects a customer's burst of 10 messages/second
--                 from firing 10 lock-screen entries)
--        - email: at most one per (user, conversation) per 15 min
--                 (protects agent's inbox from becoming a play-by-play)
--
--   3. No new column on push_subscriptions — email fallback is inferred
--      by "user has zero rows in push_subscriptions" or "every push
--      attempt returned 4xx/5xx and got pruned".
--
-- Idempotent — guarded via information_schema.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase62;
DELIMITER //
CREATE PROCEDURE aiserve_phase62()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'viewing_conversation_id'
  ) THEN
    ALTER TABLE `users`
      ADD COLUMN `viewing_conversation_id` INT UNSIGNED DEFAULT NULL,
      ADD COLUMN `viewing_at`              DATETIME     DEFAULT NULL,
      ADD KEY `idx_users_viewing` (`viewing_conversation_id`, `viewing_at`);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notification_dispatch_log'
  ) THEN
    CREATE TABLE `notification_dispatch_log` (
      `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id`         INT UNSIGNED NOT NULL,
      `conversation_id` INT UNSIGNED NOT NULL,
      `kind`            VARCHAR(20)  NOT NULL,
      `sent_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_ndl_lookup` (`user_id`, `conversation_id`, `kind`, `sent_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase62();
DROP PROCEDURE aiserve_phase62;
