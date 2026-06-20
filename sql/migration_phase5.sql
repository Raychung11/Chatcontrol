-- AiServe Shared WhatsApp Inbox - Phase 5 migration
-- Multi-tenant SaaS:
--   1. Each company gets a URL-safe `slug` (unique) used in webhook URLs.
--   2. Existing rows keep working - ACTIVE_COMPANY_ID stays a fallback.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase5;
DELIMITER //
CREATE PROCEDURE aiserve_phase5()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'slug'
  ) THEN
    ALTER TABLE companies ADD COLUMN slug VARCHAR(64) NULL AFTER name;
  END IF;

  -- Backfill any null slugs with a deterministic placeholder
  UPDATE companies SET slug = CONCAT('company-', id) WHERE slug IS NULL OR slug = '';

  -- Required column
  ALTER TABLE companies MODIFY COLUMN slug VARCHAR(64) NOT NULL;

  -- Unique index (replace if pre-existing)
  IF EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND INDEX_NAME = 'uk_companies_slug'
  ) THEN
    ALTER TABLE companies DROP INDEX uk_companies_slug;
  END IF;
  ALTER TABLE companies ADD UNIQUE KEY uk_companies_slug (slug);
END//
DELIMITER ;
CALL aiserve_phase5();
DROP PROCEDURE aiserve_phase5;
