-- AiServe Shared WhatsApp Inbox - Phase 23 migration
-- Facebook Pages + Instagram Business comment inbox.
--
-- Extends the existing multi-channel model (Phase 11) with two new
-- provider types:
--   - facebook_page       : Page comments on posts
--   - instagram_business  : IG comments on media (business/creator accounts)
--
-- Comments reuse conversations + messages so the shared inbox, AI drafts,
-- notes, tags, assignment, and reports all Just Work. One conversation
-- per top-level comment thread (NOT per post) so a viral post with 500
-- comments doesn't become one unusable thread.
--
-- Data model additions:
--   channels        : Meta OAuth tokens + Page + IG account ids
--   contacts        : platform column so FB/IG users don't collide with WA
--   conversations   : platform + parent post preview + external thread id
--   messages        : reply_mode (public reply vs private DM fallback)
--
-- wa_id / wa_message_id are kept as the generic external identifier
-- columns to avoid a mass rename touching every WhatsApp code path.
-- New FB/IG rows store their FB PSID / IG user id / comment id in those
-- same columns; the `platform` column disambiguates.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase23;
DELIMITER //
CREATE PROCEDURE aiserve_phase23()
BEGIN
  -- ------------------------------------------------------------------
  -- channels: extend provider enum + add Meta OAuth / Page / IG columns
  -- ------------------------------------------------------------------
  ALTER TABLE channels
    MODIFY COLUMN provider
      ENUM('cloud_api','evolution','aiserve_chatbot','facebook_page','instagram_business')
      NOT NULL DEFAULT 'cloud_api';

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'channels'
      AND COLUMN_NAME = 'meta_user_id'
  ) THEN
    ALTER TABLE channels
      ADD COLUMN meta_user_id           VARCHAR(64)  DEFAULT NULL,
      ADD COLUMN meta_user_access_token TEXT         DEFAULT NULL,
      ADD COLUMN meta_page_id           VARCHAR(64)  DEFAULT NULL,
      ADD COLUMN meta_page_access_token TEXT         DEFAULT NULL,
      ADD COLUMN meta_ig_business_id    VARCHAR(64)  DEFAULT NULL,
      ADD COLUMN meta_ig_username       VARCHAR(120) DEFAULT NULL,
      ADD COLUMN meta_subscribed_fields VARCHAR(255) DEFAULT NULL,
      ADD COLUMN meta_connected_at      DATETIME     DEFAULT NULL,
      ADD COLUMN meta_token_expires_at  DATETIME     DEFAULT NULL,
      ADD KEY idx_channels_meta_page (meta_page_id),
      ADD KEY idx_channels_meta_ig   (meta_ig_business_id);
  END IF;

  -- ------------------------------------------------------------------
  -- contacts: platform column (whatsapp default so existing rows stay
  -- correct without a backfill).
  -- ------------------------------------------------------------------
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts'
      AND COLUMN_NAME = 'platform'
  ) THEN
    ALTER TABLE contacts
      ADD COLUMN platform ENUM('whatsapp','facebook','instagram')
        NOT NULL DEFAULT 'whatsapp' AFTER wa_id,
      ADD KEY idx_contacts_platform (company_id, platform);
  END IF;

  -- ------------------------------------------------------------------
  -- conversations: platform, external thread id, parent-post preview
  -- ------------------------------------------------------------------
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations'
      AND COLUMN_NAME = 'platform'
  ) THEN
    ALTER TABLE conversations
      ADD COLUMN platform ENUM('whatsapp','fb_comment','ig_comment')
        NOT NULL DEFAULT 'whatsapp' AFTER channel_id,
      ADD COLUMN external_thread_id  VARCHAR(128) DEFAULT NULL AFTER platform,
      ADD COLUMN parent_post_id      VARCHAR(128) DEFAULT NULL,
      ADD COLUMN parent_post_url     VARCHAR(500) DEFAULT NULL,
      ADD COLUMN parent_post_preview TEXT         DEFAULT NULL,
      ADD KEY idx_conversations_platform (company_id, platform, status),
      ADD KEY idx_conversations_external (external_thread_id);
  END IF;

  -- ------------------------------------------------------------------
  -- messages: reply_mode (public comment reply vs private DM fallback)
  -- Only meaningful for FB/IG. WhatsApp rows keep default 'public' and
  -- ignore it. This lets an agent choose "Reply publicly" or "Reply
  -- privately via DM" from the composer.
  -- ------------------------------------------------------------------
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages'
      AND COLUMN_NAME = 'reply_mode'
  ) THEN
    ALTER TABLE messages
      ADD COLUMN reply_mode ENUM('public','private_dm')
        NOT NULL DEFAULT 'public' AFTER direction;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase23();
DROP PROCEDURE aiserve_phase23;
