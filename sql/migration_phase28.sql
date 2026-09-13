-- AiServe Shared WhatsApp Inbox - Phase 28 migration
-- Per-branch agent rotation.
--
-- Building on phase 27 (branches on contacts) and phase 26 (per-agent
-- channel access), this adds a many-to-many mapping between users and
-- branches. Two purposes rolled into one table:
--
--   1. Rotation pool: when a new conversation opens for a contact in
--      branch X, we round-robin among the users mapped to branch X.
--   2. (Later phase) Visibility: if we want branch-restricted agents in
--      the future, the same table can gate inbox_query the way
--      user_channels does. NOT wired to visibility today.
--
-- branches.last_assigned_user_id is the round-robin cursor - stores the
-- user_id we most recently assigned to for this branch, and the next
-- assignment picks the next user id after it (wrapping to the start).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user_branches` (
  `user_id`    INT UNSIGNED NOT NULL,
  `branch_id`  INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `branch_id`),
  KEY `idx_user_branches_branch` (`branch_id`),
  CONSTRAINT `fk_user_branches_user`
    FOREIGN KEY (`user_id`)   REFERENCES `users`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_branches_branch`
    FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS aiserve_phase28;
DELIMITER //
CREATE PROCEDURE aiserve_phase28()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branches'
      AND COLUMN_NAME = 'last_assigned_user_id'
  ) THEN
    ALTER TABLE branches
      ADD COLUMN last_assigned_user_id INT UNSIGNED DEFAULT NULL,
      ADD KEY idx_branches_last_assigned (last_assigned_user_id);
  END IF;
END//
DELIMITER ;
CALL aiserve_phase28();
DROP PROCEDURE aiserve_phase28;
