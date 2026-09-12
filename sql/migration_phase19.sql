-- AiServe Shared WhatsApp Inbox - Phase 19 migration
-- Composite indexes for the recent-activity panels on the channel edit page.
-- Without these, opening the page runs three filesort-heavy queries in
-- series on any busy workspace, adding seconds of TTFB.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase19;
DELIMITER //
CREATE PROCEDURE aiserve_phase19()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'webhook_events'
      AND INDEX_NAME = 'idx_wh_company_id'
  ) THEN
    ALTER TABLE webhook_events
      ADD KEY idx_wh_company_id (company_id, id);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'messages'
      AND INDEX_NAME = 'idx_msg_company_channel_id'
  ) THEN
    ALTER TABLE messages
      ADD KEY idx_msg_company_channel_id (company_id, channel_id, id);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'activity_logs'
      AND INDEX_NAME = 'idx_al_company_action_id'
  ) THEN
    ALTER TABLE activity_logs
      ADD KEY idx_al_company_action_id (company_id, action_type, id);
  END IF;
END//
DELIMITER ;
CALL aiserve_phase19();
DROP PROCEDURE aiserve_phase19;
