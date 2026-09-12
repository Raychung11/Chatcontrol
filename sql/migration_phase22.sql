-- AiServe Shared WhatsApp Inbox - Phase 22 migration
-- Store WhatsApp LID identifier separately so we can create phantom
-- contacts for LID-only outgoing echoes, then MERGE them into the
-- real-phone contact when it appears via the incoming path.
--
-- Previously the webhook skipped LID-only outgoing AI echoes entirely
-- (return true, no DB write). That kept data clean but agents lost
-- visibility of AI replies until the customer messaged back through
-- the incoming path. New flow: keep a wa_lid column, seed the phantom
-- on outgoing, merge on incoming.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase22;
DELIMITER //
CREATE PROCEDURE aiserve_phase22()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts'
      AND COLUMN_NAME = 'wa_lid'
  ) THEN
    ALTER TABLE contacts
      ADD COLUMN wa_lid VARCHAR(64) DEFAULT NULL AFTER wa_id,
      ADD KEY idx_contact_company_lid (company_id, wa_lid);
  END IF;
END//
DELIMITER ;
CALL aiserve_phase22();
DROP PROCEDURE aiserve_phase22;
