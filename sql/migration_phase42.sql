-- AiServe Shared WhatsApp Inbox - Phase 42 migration
-- Remember-me tokens for WhatsApp-style stay-signed-in-on-device.
--
-- Row per (user, device). Cookie contains the raw token; DB stores a
-- SHA-256 hash so a DB leak can't grant login. Token is rotated on
-- every successful auto-login so a stolen cookie only works once.
--
-- Cleanup: rows with expires_at <= NOW() are deleted by the nightly
-- aiserve-db-cleanup.sh cron (added in later commit if not there yet).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS user_remember_tokens (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  token_hash     CHAR(64)     NOT NULL,       -- sha256(hex) of the raw cookie value
  user_agent     VARCHAR(255) DEFAULT NULL,
  ip_address     VARCHAR(64)  DEFAULT NULL,
  last_seen_at   DATETIME     DEFAULT NULL,
  expires_at     DATETIME     NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_urt_user       (user_id),
  KEY idx_urt_expires    (expires_at),
  UNIQUE KEY uk_urt_hash (token_hash),
  CONSTRAINT fk_urt_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
