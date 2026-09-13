-- AiServe Shared WhatsApp Inbox - Phase 27 migration
-- Branch dimension on contacts.
--
-- Departments already exist for conversation-level routing (Sales,
-- Support, Billing). Branches sit at the CONTACT level and represent
-- a physical location or business unit that owns the customer
-- (e.g. "KL Office", "Penang Office", "JB Office"). One contact
-- belongs to at most one branch. Branch is optional — null is fine.
--
-- Not tied to per-user visibility in this phase. If the operator
-- wants agents restricted to a branch later, we can add a
-- user_branches many-to-many the same way we did for channels (phase 26).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `branches` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `name`       VARCHAR(120) NOT NULL,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_branches_company_name` (`company_id`, `name`),
  KEY `idx_branches_company_status` (`company_id`, `status`),
  CONSTRAINT `fk_branches_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS aiserve_phase27;
DELIMITER //
CREATE PROCEDURE aiserve_phase27()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts'
      AND COLUMN_NAME = 'branch_id'
  ) THEN
    ALTER TABLE contacts
      ADD COLUMN branch_id INT UNSIGNED DEFAULT NULL AFTER platform,
      ADD KEY idx_contacts_branch (company_id, branch_id),
      ADD CONSTRAINT fk_contacts_branch
        FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase27();
DROP PROCEDURE aiserve_phase27;
