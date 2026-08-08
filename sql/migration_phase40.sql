-- AiServe Shared WhatsApp Inbox - Phase 40 migration
-- Primary branch on users.
--
-- user_branches (phase 28) is a many-to-many for rotation + visibility
-- scoping. It answers "which branches is this person part of?".
--
-- users.primary_branch_id answers "which branch is this person's home?"
-- — the single default branch shown in the user edit dropdown, used
-- when the operator just wants to say "she works at Branch A" without
-- opening the rotation-pool checkboxes.
--
-- Nullable. ON DELETE SET NULL so deleting a branch doesn't wipe the
-- user row.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase40;
DELIMITER //
CREATE PROCEDURE aiserve_phase40()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'primary_branch_id'
  ) THEN
    ALTER TABLE users
      ADD COLUMN primary_branch_id INT UNSIGNED DEFAULT NULL,
      ADD KEY idx_users_primary_branch (company_id, primary_branch_id),
      ADD CONSTRAINT fk_users_primary_branch
        FOREIGN KEY (primary_branch_id) REFERENCES branches (id) ON DELETE SET NULL;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase40();
DROP PROCEDURE aiserve_phase40;
