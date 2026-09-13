-- AiServe Shared WhatsApp Inbox - Phase 52 migration
-- Soft-delete for messages. The per-message ⋯ context menu on
-- /inbox/chat.php gains a Delete action that stamps deleted_at =
-- NOW() on the row; every message-list query filters WHERE
-- deleted_at IS NULL so the bubble disappears from the UI without
-- actually losing the row (audit trail preserved).
--
-- Idempotent — guarded on information_schema.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase52;
DELIMITER //
CREATE PROCEDURE aiserve_phase52()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages'
      AND COLUMN_NAME = 'deleted_at'
  ) THEN
    ALTER TABLE messages
      ADD COLUMN deleted_at DATETIME DEFAULT NULL,
      ADD KEY idx_messages_deleted (conversation_id, deleted_at);
  END IF;
END//
DELIMITER ;
CALL aiserve_phase52();
DROP PROCEDURE aiserve_phase52;
