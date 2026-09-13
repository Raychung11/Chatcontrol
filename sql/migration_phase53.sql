-- AiServe Shared WhatsApp Inbox - Phase 53 migration
-- Backfill wa_lid on legacy contacts that were ingested BEFORE phase 22
-- (which added the LID handling) but whose raw_payload proves they're
-- actually a LID contact — e.g. Kun (wa_id=39749989458036, wa_lid=NULL,
-- raw_payload contains "@lid" + addressingMode":"lid").
--
-- The current webhook/evolution.php stamps wa_lid on any new LID inbound.
-- This migration retroactively stamps historical rows so the UI's LID
-- awareness (🔒 badge, broadcast exclusion, merge detection) works on
-- older contacts too.
--
-- Idempotent — only touches rows where wa_lid IS NULL.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase53;
DELIMITER //
CREATE PROCEDURE aiserve_phase53()
BEGIN
  -- For each contact with wa_lid NULL, look at their earliest message's
  -- raw_payload. If it contains "@lid" or addressingMode":"lid", the
  -- contact IS a LID contact — stamp wa_lid = the numeric wa_id since
  -- that's the LID's identifier the codebase uses.
  UPDATE contacts c
  INNER JOIN (
    SELECT DISTINCT ct.id AS contact_id
    FROM contacts ct
    INNER JOIN conversations cv ON cv.contact_id = ct.id
    INNER JOIN messages m ON m.conversation_id = cv.id
    WHERE ct.wa_lid IS NULL OR ct.wa_lid = ''
    AND (m.raw_payload LIKE '%@lid%'
      OR m.raw_payload LIKE '%"addressingMode":"lid"%')
  ) x ON x.contact_id = c.id
  SET c.wa_lid = c.wa_id
  WHERE c.wa_lid IS NULL OR c.wa_lid = '';
END//
DELIMITER ;
CALL aiserve_phase53();
DROP PROCEDURE aiserve_phase53;
