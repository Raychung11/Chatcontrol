-- AiServe Shared WhatsApp Inbox - Phase 56 migration
-- Evolution health-probe fields on channels.
--
-- The cron cron/evolution_health_ping.php polls each active
-- Evolution channel every ~5 minutes for its connection state via
-- /instance/connectionState. Results are cached on the channel row
-- so /admin/channels_health.php can render the live state without
-- fanning out one HTTP request per row on page load.
--
-- Columns:
--   probe_state          — connected / connecting / disconnected / unknown
--   probe_state_since    — when we first observed the current state
--                          (so 'been disconnected for 2h' is trivial to render)
--   probe_last_at        — when the cron last polled this channel
--   probe_queue_size     — backpressure indicator: count of outgoing
--                          messages we tried to send but haven't yet
--                          seen 'sent' status confirmed
--   alert_last_sent_at   — throttle for the disconnect email so we
--                          don't spam the workspace's alert_email
--                          every 5 minutes while the QR is expired
--
-- Idempotent — each ADD COLUMN is guarded via information_schema.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase56;
DELIMITER //
CREATE PROCEDURE aiserve_phase56()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'channels'
      AND COLUMN_NAME = 'probe_state'
  ) THEN
    ALTER TABLE channels ADD COLUMN probe_state
      ENUM('connected','connecting','disconnected','unknown')
      NOT NULL DEFAULT 'unknown';
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'channels'
      AND COLUMN_NAME = 'probe_state_since'
  ) THEN
    ALTER TABLE channels ADD COLUMN probe_state_since DATETIME DEFAULT NULL;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'channels'
      AND COLUMN_NAME = 'probe_last_at'
  ) THEN
    ALTER TABLE channels ADD COLUMN probe_last_at DATETIME DEFAULT NULL;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'channels'
      AND COLUMN_NAME = 'probe_queue_size'
  ) THEN
    ALTER TABLE channels ADD COLUMN probe_queue_size INT NOT NULL DEFAULT 0;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'channels'
      AND COLUMN_NAME = 'alert_last_sent_at'
  ) THEN
    ALTER TABLE channels ADD COLUMN alert_last_sent_at DATETIME DEFAULT NULL;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase56();
DROP PROCEDURE aiserve_phase56;
