-- AiServe Shared WhatsApp Inbox - Phase 63 migration
-- Structured product catalog per workspace.
--
-- The existing knowledge_base handles freeform prose (FAQs, policies,
-- product brochures as narrative text). This table stores products as
-- ROWS so the AI can:
--
--   - Answer "how much is the X?" with the actual price, currency, and
--     stock status — not a paraphrase from a 40-page PDF.
--   - Answer "do you have anything in category Y?" with a proper list.
--   - Cite a real product URL back to the customer.
--
-- Populated three ways:
--   1. Manual entry via /admin/products.php
--   2. CSV import (drag-and-drop upload) — see admin/products.php
--   3. Programmatic sync (future) — REST endpoint for e-commerce plugins
--
-- The AI grounding path in inc/ai_api.php (extended in this phase) does
-- a FULLTEXT match on (name, description, category) whenever a customer
-- message looks product-shaped, and injects the top matches into the
-- prompt as structured JSON — so the model can cite fields rather than
-- paraphrase them.
--
-- Idempotent — guarded via information_schema.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase63;
DELIMITER //
CREATE PROCEDURE aiserve_phase63()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
  ) THEN
    CREATE TABLE `products` (
      `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `company_id`  INT UNSIGNED NOT NULL,
      `sku`         VARCHAR(64)  DEFAULT NULL,
      `name`        VARCHAR(255) NOT NULL,
      `category`    VARCHAR(120) DEFAULT NULL,
      `price`       DECIMAL(12,2) DEFAULT NULL,
      `currency`    VARCHAR(8)   DEFAULT NULL,
      `description` TEXT,
      `image_url`   VARCHAR(500) DEFAULT NULL,
      `product_url` VARCHAR(500) DEFAULT NULL,
      `in_stock`    TINYINT(1)   NOT NULL DEFAULT 1,
      `attributes`  JSON         DEFAULT NULL,
      `status`      ENUM('active','archived') NOT NULL DEFAULT 'active',
      `created_by`  INT UNSIGNED DEFAULT NULL,
      `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_company_sku` (`company_id`, `sku`),
      KEY `idx_company_status` (`company_id`, `status`),
      -- FULLTEXT gives the AI a fast, no-embedding text search across
      -- name / description / category. Good enough for < ~10k products
      -- per workspace; add vector search later if a workspace outgrows it.
      FULLTEXT KEY `ft_search` (`name`, `description`, `category`),
      CONSTRAINT `fk_products_company` FOREIGN KEY (`company_id`)
        REFERENCES `companies` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase63();
DROP PROCEDURE aiserve_phase63;
