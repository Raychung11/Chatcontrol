-- AiServe Shared WhatsApp Inbox - Phase 51 migration
-- Enrich contacts with an email + external CRM id so the enhanced
-- xlsx importer can preserve MemberReport / CRM export fields
-- (CustomerNo, Membership No, Email) instead of dropping them.
--
-- Each column guarded INDEPENDENTLY so a partial prior run can be
-- reconciled by re-running the migration.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase51;
DELIMITER //
CREATE PROCEDURE aiserve_phase51()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts'
      AND COLUMN_NAME = 'email'
  ) THEN
    ALTER TABLE contacts
      ADD COLUMN email VARCHAR(255) DEFAULT NULL,
      ADD KEY idx_contacts_email (company_id, email(191));
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts'
      AND COLUMN_NAME = 'external_id'
  ) THEN
    ALTER TABLE contacts
      ADD COLUMN external_id VARCHAR(120) DEFAULT NULL,
      ADD KEY idx_contacts_extid (company_id, external_id);
  END IF;
END//
DELIMITER ;
CALL aiserve_phase51();
DROP PROCEDURE aiserve_phase51;
