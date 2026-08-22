-- AiServe Shared WhatsApp Inbox - Phase 55 migration
-- In-store dine-in ordering + customer-facing order-status flow node.
--
-- Two ENUM widenings, both guarded via information_schema so this
-- migration is safely re-runnable:
--
--   1. fnb_orders.order_type gains 'dine_in' — a customer sitting at
--      a table scans a QR, orders, and the kitchen sees "Table 5,
--      2 nasi lemak" instead of an address or a pickup time. Dine-in
--      orders carry the table number in delivery_notes (repurposed —
--      cheapest way to avoid another schema field).
--
--   2. flow_nodes.node_type gains 'fnb_order_status' — a flow node
--      that, when the customer types "status", "ready?", "mana dah",
--      "order 1234", looks up their most recent open order and replies
--      with a friendly status line + ETA. See inc/flow_engine.php's
--      case 'fnb_order_status'.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase55;
DELIMITER //
CREATE PROCEDURE aiserve_phase55()
BEGIN
  DECLARE cur_enum TEXT;

  -- 1. fnb_orders.order_type += 'dine_in'
  SELECT COLUMN_TYPE INTO cur_enum
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'fnb_orders'
     AND COLUMN_NAME  = 'order_type'
   LIMIT 1;

  IF cur_enum IS NOT NULL AND LOCATE('dine_in', cur_enum) = 0 THEN
    ALTER TABLE fnb_orders
      MODIFY COLUMN order_type ENUM('delivery','pickup','dine_in')
                    NOT NULL DEFAULT 'delivery';
  END IF;

  -- 2. flow_nodes.node_type += 'fnb_order_status'
  SELECT COLUMN_TYPE INTO cur_enum
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'flow_nodes'
     AND COLUMN_NAME  = 'node_type'
   LIMIT 1;

  IF cur_enum IS NOT NULL AND LOCATE('fnb_order_status', cur_enum) = 0 THEN
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
        'fnb_create_order',
        'fnb_order_status'
      ) NOT NULL;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase55();
DROP PROCEDURE aiserve_phase55;
