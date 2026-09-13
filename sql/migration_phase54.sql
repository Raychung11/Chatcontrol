-- AiServe Shared WhatsApp Inbox - Phase 54 migration
-- Manual broadcast credits ledger.
--
-- Platform admin now grants extra broadcast recipients to a workspace
-- (a top-up bought offline, a goodwill refund, a promo) without touching
-- the plan tier. Each row is a signed grant (positive = add, negative =
-- revoke / correction) tied to a granting user + reason so /admin/
-- broadcast_credits.php can render a full audit trail.
--
-- broadcast_quota_for_workspace() sums this month's plan_limit + all
-- non-expired credit rows for the workspace and uses that as the
-- effective monthly limit. See inc/helpers.php.
--
-- Idempotent — guarded via information_schema.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase54;
DELIMITER //
CREATE PROCEDURE aiserve_phase54()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'broadcast_credits'
  ) THEN
    CREATE TABLE broadcast_credits (
      id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
      company_id          BIGINT       NOT NULL,
      amount              INT          NOT NULL,
      reason              VARCHAR(255) NOT NULL DEFAULT '',
      granted_by_user_id  BIGINT       DEFAULT NULL,
      granted_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      expires_at          DATETIME     DEFAULT NULL,
      revoked_at          DATETIME     DEFAULT NULL,
      revoked_by_user_id  BIGINT       DEFAULT NULL,
      KEY idx_bc_company     (company_id, revoked_at, expires_at),
      KEY idx_bc_granted_at  (granted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase54();
DROP PROCEDURE aiserve_phase54;
