-- AiServe Shared WhatsApp Inbox - Phase 24 migration
-- Broadcast media attachment (image / video / document / audio).
--
-- The broadcast row now optionally carries one media attachment.
-- Recipients receive the media with `message_text` used as the caption
-- (WhatsApp shows the caption under the image / video / document).
--
-- We store the local file path only. The cron worker uploads to Meta
-- once per batch when the channel is Cloud API (media_id is reusable
-- for ~30 days but per-batch upload is simpler and rate-safe).
-- Evolution and AiServe Chatbot both accept a local file path directly.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase24;
DELIMITER //
CREATE PROCEDURE aiserve_phase24()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'broadcasts'
      AND COLUMN_NAME = 'media_path'
  ) THEN
    ALTER TABLE broadcasts
      ADD COLUMN media_path      VARCHAR(500) DEFAULT NULL AFTER message_text,
      ADD COLUMN media_kind      ENUM('image','video','document','audio') DEFAULT NULL AFTER media_path,
      ADD COLUMN media_mime_type VARCHAR(120) DEFAULT NULL AFTER media_kind,
      ADD COLUMN media_filename  VARCHAR(255) DEFAULT NULL AFTER media_mime_type;
  END IF;

  -- message_text is currently NOT NULL. Making it NULLABLE so media-only
  -- broadcasts (no caption) are allowed. The form still lets users type
  -- a caption; it's just no longer required when a media file is attached.
  ALTER TABLE broadcasts
    MODIFY COLUMN message_text TEXT NULL;
END//
DELIMITER ;
CALL aiserve_phase24();
DROP PROCEDURE aiserve_phase24;
