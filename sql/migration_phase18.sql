-- AiServe Shared WhatsApp Inbox - Phase 18 migration
-- Media retention + sticker skipping to control disk usage.
--
-- Every customer's WhatsApp sticker was being base64-decoded to disk as
-- a .webp file. Combined with the fact that no cron ever cleaned old
-- media, this drove up storage cost fast. This migration adds:
--   companies.skip_stickers        (default 1 - stickers no longer stored)
--   companies.media_retention_days (default 90 - files older than this
--                                   get swept by cron/cleanup_media.php)
--   companies.media_max_kb         (default 10240 = 10 MB - webhook
--                                   drops media larger than this)

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase18;
DELIMITER //
CREATE PROCEDURE aiserve_phase18()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
      AND COLUMN_NAME  = 'skip_stickers'
  ) THEN
    ALTER TABLE companies
      ADD COLUMN skip_stickers        TINYINT(1)   NOT NULL DEFAULT 1
        AFTER alert_email,
      ADD COLUMN media_retention_days INT UNSIGNED NOT NULL DEFAULT 90
        AFTER skip_stickers,
      ADD COLUMN media_max_kb         INT UNSIGNED NOT NULL DEFAULT 10240
        AFTER media_retention_days;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase18();
DROP PROCEDURE aiserve_phase18;
