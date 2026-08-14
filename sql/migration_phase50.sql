-- AiServe Shared WhatsApp Inbox - Phase 50 migration
-- Assign to nearest branch by AI-mapped location.
--
-- Adds two columns to branches (address + area_keywords) so the flow
-- engine has enough context to hand to Claude when mapping a customer's
-- free-text location ('near KLCC', 'Bangsar', '46200') to the nearest
-- outlet. Also widens flow_nodes.node_type ENUM to accept the new node.
--
-- Idempotent — each ALTER/ENUM change is guarded.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase50;
DELIMITER //
CREATE PROCEDURE aiserve_phase50()
BEGIN
  DECLARE cur_enum TEXT;

  -- 1. branches gets a mailing address + a serves-these-areas list.
  --    Both optional — populated per-branch in /admin/branches.php.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branches'
      AND COLUMN_NAME = 'address'
  ) THEN
    ALTER TABLE branches
      ADD COLUMN address       VARCHAR(500) DEFAULT NULL,
      ADD COLUMN area_keywords TEXT         DEFAULT NULL;
  END IF;

  -- 2. flow_nodes.node_type ENUM gets the new type.
  SELECT COLUMN_TYPE INTO cur_enum
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'flow_nodes'
     AND COLUMN_NAME  = 'node_type'
   LIMIT 1;

  IF cur_enum IS NOT NULL AND LOCATE('assign_nearest_branch', cur_enum) = 0 THEN
    ALTER TABLE flow_nodes
      MODIFY COLUMN node_type ENUM(
        'send_message',
        'wait_reply',
        'branch',
        'assign_dept',
        'assign_branch',
        'assign_nearest_branch',
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
CALL aiserve_phase50();
DROP PROCEDURE aiserve_phase50;
