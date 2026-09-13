-- AiServe Shared WhatsApp Inbox - Phase 26 migration
-- Per-agent channel access control.
--
-- Backwards-compatible policy: if a user has ZERO rows in user_channels
-- they see all channels (matches existing behavior). If they have one
-- or more rows, the inbox and every conversation-touching endpoint
-- restrict them to conversations on those channel_ids.
--
-- Super admin + Manager bypass the restriction entirely — this is an
-- agent-level control, not a permission the operator can apply to
-- themselves.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user_channels` (
  `user_id`    INT UNSIGNED NOT NULL,
  `channel_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `channel_id`),
  KEY `idx_user_channels_channel` (`channel_id`),
  CONSTRAINT `fk_user_channels_user`
    FOREIGN KEY (`user_id`)    REFERENCES `users`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_channels_channel`
    FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
