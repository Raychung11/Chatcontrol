-- AiServe Shared WhatsApp Inbox - Phase 43 migration
-- 6-digit PIN quick-unlock (WhatsApp-style screen lock).
--
-- Users can optionally set a PIN in /admin/set_pin.php. When set,
-- remember-me auto-login pauses at /pin.php until the correct PIN is
-- entered — proves the device is in the right person's hands even if
-- their phone was picked up by someone else.
--
-- Password login skips the PIN entirely (they proved identity).
-- 5 wrong PIN attempts within a rolling 10-minute window locks the
-- account out of PIN unlock and forces a full password re-login.
--
-- pin_hash stores bcrypt(6-digit-string). Rainbow tables aren't a real
-- concern at 6 digits + bcrypt's salt + workfactor, but per-user
-- rate limiting (pin_failed_attempts + pin_locked_until) closes the
-- online brute-force door.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase43;
DELIMITER //
CREATE PROCEDURE aiserve_phase43()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'pin_hash'
  ) THEN
    ALTER TABLE users
      ADD COLUMN pin_hash             VARCHAR(255) DEFAULT NULL,
      ADD COLUMN pin_set_at           DATETIME     DEFAULT NULL,
      ADD COLUMN pin_failed_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 0,
      ADD COLUMN pin_last_failed_at   DATETIME     DEFAULT NULL,
      ADD COLUMN pin_locked_until     DATETIME     DEFAULT NULL;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase43();
DROP PROCEDURE aiserve_phase43;
