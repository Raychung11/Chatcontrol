-- AiServe Shared WhatsApp Inbox - Phase 46 migration
-- Password reset tokens.
--
-- One row per reset request. Raw token never stored — DB keeps
-- sha256(hex_token) so a DB leak can't be used to hijack accounts.
-- One-shot (used_at) + expiry (1h default) + rate-limited on the
-- application side.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  token_hash    CHAR(64)     NOT NULL,
  ip_address    VARCHAR(64)  DEFAULT NULL,
  user_agent    VARCHAR(255) DEFAULT NULL,
  expires_at    DATETIME     NOT NULL,
  used_at       DATETIME     DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_prt_hash    (token_hash),
  KEY idx_prt_user_active   (user_id, used_at, expires_at),
  CONSTRAINT fk_prt_user    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
