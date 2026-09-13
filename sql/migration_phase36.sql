-- AiServe Shared WhatsApp Inbox - Phase 36 migration
-- Widget → branch tagging.
--
-- fnb_orders.branch_id already exists (phase 33), but channels don't
-- carry a branch yet. Adding it here lets the operator bind a widget /
-- QR code to a physical branch, so the F&B AI ordering flow can stamp
-- the branch on every order it materializes (see fnb_create_order_from
-- _flow_state, which now COALESCEs channel.branch_id → contact.branch_id).
--
-- The stamping pattern matches fnb_order_items: denormalize at order
-- time so historical orders keep their branch even if the channel is
-- later rebound or the branch itself is deleted (ON DELETE SET NULL
-- protects the history rows).

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase36;
DELIMITER //
CREATE PROCEDURE aiserve_phase36()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'channels'
      AND COLUMN_NAME = 'branch_id'
  ) THEN
    ALTER TABLE channels
      ADD COLUMN branch_id INT UNSIGNED DEFAULT NULL,
      ADD KEY idx_channels_branch (company_id, branch_id),
      ADD CONSTRAINT fk_channels_branch
        FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase36();
DROP PROCEDURE aiserve_phase36;
