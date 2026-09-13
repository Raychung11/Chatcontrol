-- AiServe Shared WhatsApp Inbox - Phase 41 migration
-- User availability + smarter rotation input.
--
-- users.availability lets each salesperson self-toggle their status:
--   available  - eligible for branch rotation, default
--   busy       - eligible but deprioritised (fills only if nobody's free)
--   away       - skipped by rotation entirely (lunch, meeting, sick day)
--
-- users.availability_updated_at powers the "away since X" tooltip so
-- managers can see who forgot to flip back to available at the end of
-- their break.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase41;
DELIMITER //
CREATE PROCEDURE aiserve_phase41()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'availability'
  ) THEN
    ALTER TABLE users
      ADD COLUMN availability            ENUM('available','busy','away')
                                         NOT NULL DEFAULT 'available',
      ADD COLUMN availability_updated_at DATETIME DEFAULT NULL,
      ADD KEY idx_users_availability     (company_id, availability, status);
  END IF;
END//
DELIMITER ;
CALL aiserve_phase41();
DROP PROCEDURE aiserve_phase41;
