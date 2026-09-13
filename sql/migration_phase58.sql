-- AiServe Shared WhatsApp Inbox - Phase 58 migration
-- Alerts table + per-message consecutive-MISS counter for the media sweeper.
--
-- Adds:
--   1. `alerts` table — one row per open/resolved incident. Detectors
--      (Evolution disconnect, silent inbound, stuck media) call
--      alert_open() from inc/alerts.php which UPSERTs on
--      (company_id, kind, subject_ref) so a chatter detector doesn't
--      spam the table. alert_close() stamps resolved_at when the
--      condition clears. The in-app bell, the toast, the browser
--      desktop notification, and the email fanout all consume rows
--      of this table — one source of truth, many delivery legs.
--
--   2. `messages.media_sync_attempts` — tiny counter the sweeper bumps
--      on every MISS for a given row. When it hits 15 the sweeper
--      calls alert_open() so agents see a "voice note stuck, ask
--      the customer to re-send" pill in the bell, then stops re-trying
--      that specific row (spares Evolution the calls). Reset to 0 the
--      moment media_local_path is populated so a happy fetch clears
--      any prior counter state.
--
-- Idempotent — guarded via information_schema.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase58;
DELIMITER //
CREATE PROCEDURE aiserve_phase58()
BEGIN
  -- ---- alerts table ----
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alerts'
  ) THEN
    CREATE TABLE `alerts` (
      `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `company_id`     INT UNSIGNED NOT NULL,
      `channel_id`     INT UNSIGNED DEFAULT NULL,
      `kind`           VARCHAR(64)  NOT NULL,      -- 'evolution_disconnected' | 'silent_inbound' | 'media_stuck'
      `severity`       ENUM('info','warn','error') NOT NULL DEFAULT 'warn',
      `subject_ref`    VARCHAR(128) NOT NULL DEFAULT '', -- dedupe key inside (company_id, kind): channel_id or message_id
      `title`          VARCHAR(255) NOT NULL,
      `body`           TEXT NULL,
      `href`           VARCHAR(500) DEFAULT NULL,  -- deep-link the agent can click to fix
      `dispatched_via` VARCHAR(120) NOT NULL DEFAULT '', -- CSV of legs already delivered: 'inapp,email,browser'
      `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `resolved_at`    DATETIME DEFAULT NULL,
      PRIMARY KEY (`id`),
      -- Dedup at the helper layer (inc/alerts.php :: alert_open() does a
      -- SELECT-then-INSERT with resolved_at IS NULL). A MySQL UNIQUE key
      -- can't enforce it because NULLs in a unique key are considered
      -- distinct, so multiple open rows would all pass the constraint.
      -- The rare race that slips through creates a harmless duplicate
      -- bell row — no real harm.
      KEY `idx_alerts_lookup`       (`company_id`, `kind`, `subject_ref`, `resolved_at`),
      KEY `idx_alerts_company_open` (`company_id`, `resolved_at`),
      KEY `idx_alerts_channel`      (`channel_id`),
      CONSTRAINT `fk_alerts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  END IF;

  -- ---- messages.media_sync_attempts ----
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'messages'
      AND COLUMN_NAME  = 'media_sync_attempts'
  ) THEN
    ALTER TABLE `messages`
      ADD COLUMN `media_sync_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0
        AFTER `media_local_path`;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase58();
DROP PROCEDURE aiserve_phase58;
