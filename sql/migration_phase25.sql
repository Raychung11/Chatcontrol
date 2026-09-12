-- AiServe Shared WhatsApp Inbox - Phase 25 migration
-- Broadcast: allow up to N media attachments per blast.
--
-- Phase 24 added a single media_path column to broadcasts. Real-world
-- ask: send multiple images in one blast (a promo poster + gallery,
-- a step-by-step, etc). WhatsApp cannot bundle images into one message
-- — each attachment goes as its own message. We queue up to 4 per
-- broadcast and the cron worker sends them in order per recipient,
-- with the message_text carried on the LAST item as the caption (feels
-- natural at the bottom of the WhatsApp thread, near the reply box).
--
-- We keep the old broadcasts.media_* columns for now — the migration
-- backfills them into broadcast_media_items so existing broadcasts
-- keep sending, then new code paths use the new table exclusively.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `broadcast_media_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `broadcast_id` INT UNSIGNED NOT NULL,
  `sequence` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `media_path` VARCHAR(500) NOT NULL,
  `media_kind` ENUM('image','video','document','audio') NOT NULL,
  `media_mime_type` VARCHAR(120) NOT NULL,
  `media_filename` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bmi_broadcast` (`broadcast_id`, `sequence`),
  CONSTRAINT `fk_bmi_broadcast`
    FOREIGN KEY (`broadcast_id`) REFERENCES `broadcasts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: any existing broadcast that already carries a single media
-- attachment (phase 24 columns) becomes a sequence=1 row in the new
-- items table. Idempotent — the NOT EXISTS clause skips rows we've
-- already migrated on a previous run.
INSERT INTO broadcast_media_items
    (broadcast_id, sequence, media_path, media_kind, media_mime_type, media_filename)
SELECT b.id, 1, b.media_path, b.media_kind, b.media_mime_type, b.media_filename
FROM broadcasts b
WHERE b.media_path IS NOT NULL AND b.media_path <> ''
  AND b.media_kind IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM broadcast_media_items i WHERE i.broadcast_id = b.id
  );
