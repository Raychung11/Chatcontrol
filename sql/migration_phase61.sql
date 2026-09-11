-- AiServe Shared WhatsApp Inbox - Phase 61 migration
-- Web Push subscriptions for per-agent PWA notifications.
--
-- Each agent's browser (Chrome / Edge / Safari / Firefox — installed as
-- a PWA or not) can register one row here per device. When a new
-- inbound customer message lands on a conversation assigned to that
-- agent, inc/push.php POSTs an aes128gcm-encrypted payload to
-- `endpoint` and the OS renders it on the phone/desktop lock screen
-- (or notification tray) even if the browser tab is closed.
--
-- Design:
--   - user_id is the FK. One agent may have several subscriptions
--     (phone + laptop + tablet). All get the same push.
--   - endpoint is UNIQUE — the browser reissues the same URL if
--     `subscribe` is called twice for the same combo, so we upsert.
--   - p256dh + auth are the subscription's own cryptographic material,
--     stored raw base64url as returned by PushSubscription.getKey().
--   - last_seen_at bumps on every successful push. push_cleanup()
--     deletes rows we haven't touched in 60 days (dead endpoints).
--
-- Idempotent — guarded via information_schema.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase61;
DELIMITER //
CREATE PROCEDURE aiserve_phase61()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_subscriptions'
  ) THEN
    CREATE TABLE `push_subscriptions` (
      `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id`      INT UNSIGNED NOT NULL,
      `endpoint`     VARCHAR(600) NOT NULL,
      `p256dh`       VARCHAR(140) NOT NULL,
      `auth`         VARCHAR(64)  NOT NULL,
      `user_agent`   VARCHAR(255) DEFAULT NULL,
      `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `last_seen_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_push_endpoint` (`endpoint`),
      KEY `idx_push_user` (`user_id`),
      CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase61();
DROP PROCEDURE aiserve_phase61;
