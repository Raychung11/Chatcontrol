-- AiServe Shared WhatsApp Inbox - Phase 49 migration
-- Add 'assign_branch' to the flow_nodes.node_type ENUM. Phase 51 code
-- added the node type in the editor + engine (task #51), but the DB
-- ENUM was never widened — so saving a node as assign_branch fails
-- with 'Data truncated for column node_type at row 1' (strict mode) or
-- silently truncates to empty string (non-strict), which then makes
-- the flow un-editable.
--
-- Idempotent — checks the current ENUM definition before altering.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase49;
DELIMITER //
CREATE PROCEDURE aiserve_phase49()
BEGIN
  DECLARE cur_def TEXT;

  SELECT COLUMN_TYPE INTO cur_def
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'flow_nodes'
     AND COLUMN_NAME  = 'node_type'
   LIMIT 1;

  IF cur_def IS NOT NULL AND LOCATE('assign_branch', cur_def) = 0 THEN
    ALTER TABLE flow_nodes
      MODIFY COLUMN node_type ENUM(
        'send_message',
        'wait_reply',
        'branch',
        'assign_dept',
        'assign_branch',
        'save_note',
        'end',
        'fnb_send_menu',
        'fnb_cart_add',
        'fnb_cart_show',
        'fnb_create_order'
      ) NOT NULL;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase49();
DROP PROCEDURE aiserve_phase49;
