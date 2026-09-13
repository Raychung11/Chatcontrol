-- AiServe Shared WhatsApp Inbox - Phase 34 migration
-- F&B module Layer 3: AI order-taking via the message flow engine.
--
-- Extends flow_nodes.node_type with four F&B-specific node kinds:
--   fnb_send_menu     : sends the workspace's active menu as WhatsApp text
--   fnb_cart_add      : calls Claude to parse the customer's last reply
--                       into structured cart items, appends them to
--                       flow_instances.state.cart, and echoes a confirmation
--   fnb_cart_show     : sends the current cart contents as a message
--   fnb_create_order  : materializes state.cart + state.vars.* into
--                       fnb_orders + fnb_order_items, links the source
--                       conversation, and sends the final order number
--
-- Requires phases 29 (flow engine) and 32/33 (F&B menu + orders).

SET NAMES utf8mb4;

ALTER TABLE flow_nodes
  MODIFY COLUMN node_type
  ENUM(
    'send_message',
    'wait_reply',
    'branch',
    'assign_dept',
    'save_note',
    'end',
    'fnb_send_menu',
    'fnb_cart_add',
    'fnb_cart_show',
    'fnb_create_order'
  ) NOT NULL;
