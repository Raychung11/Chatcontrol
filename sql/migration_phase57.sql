-- AiServe Shared WhatsApp Inbox - Phase 57 migration
-- Add 'assign_next_agent' to flow_nodes.node_type ENUM.
--
-- The new node explicitly triggers branch-rotation from inside a
-- flow — same logic as the automatic post-webhook rotation in
-- inc/branch_rotation.php, but callable at any point in a flow.
--
-- Useful when you want to:
--   - Route to a different pool AFTER a customer picks a category
--     ("1. Sales, 2. Support" → rotate among Sales agents only)
--   - Re-rotate mid-conversation (customer escalated, hand to a
--     different agent)
--   - Assign a specific branch's pool regardless of contact branch
--     (via the node's optional branch_id config)
--
-- Idempotent — guarded via information_schema.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase57;
DELIMITER //
CREATE PROCEDURE aiserve_phase57()
BEGIN
  DECLARE cur_enum TEXT;

  SELECT COLUMN_TYPE INTO cur_enum
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'flow_nodes'
     AND COLUMN_NAME  = 'node_type'
   LIMIT 1;

  IF cur_enum IS NOT NULL AND LOCATE('assign_next_agent', cur_enum) = 0 THEN
    ALTER TABLE flow_nodes
      MODIFY COLUMN node_type ENUM(
        'send_message',
        'wait_reply',
        'branch',
        'assign_dept',
        'assign_branch',
        'assign_nearest_branch',
        'assign_next_agent',
        'save_note',
        'end',
        'fnb_send_menu',
        'fnb_cart_add',
        'fnb_cart_show',
        'fnb_create_order',
        'fnb_order_status'
      ) NOT NULL;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase57();
DROP PROCEDURE aiserve_phase57;
