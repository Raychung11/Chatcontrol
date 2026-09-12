-- AiServe Shared WhatsApp Inbox - Phase 32 migration
-- F&B module Layer 1: menu (categories + products + variants + add-ons)
-- plus a subscription flag so the module only appears for workspaces the
-- platform admin has opted in.
--
-- Layer 1 scope: menu management only. Orders + AI ordering ship in
-- Layers 2 and 3.
--
-- Data model:
--   fnb_categories   : top-level menu groupings (Rice, Drinks, Sides)
--   fnb_products     : the sellable item (name, price, image, category)
--   fnb_variants     : mutually exclusive product option (Size: S/M/L,
--                      Spice: mild/medium/hot). Customer picks one per
--                      group. Grouped by group_name so a product can
--                      have multiple radio groups.
--   fnb_addons       : optional multi-select extras (Extra egg, Extra
--                      cheese). Customer picks any number.
--
-- Both variant and add-on carry a price_delta added on top of the
-- base product price at checkout.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase32;
DELIMITER //
CREATE PROCEDURE aiserve_phase32()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
      AND COLUMN_NAME = 'fnb_plan'
  ) THEN
    -- 'none'   : F&B module not available for this workspace (sidebar link hidden)
    -- 'active' : F&B module available (menu editing, later: order dashboard)
    -- 'paid'   : Reserved for the paid tier when we ship Layer 3 pricing.
    -- No AFTER clause: column order is cosmetic in MySQL, and depending
    -- on a specific prior column here made this migration fail whenever
    -- a workspace hadn't run phase 31 yet. The column lands at the end
    -- of the row layout — same behavior in every other regard.
    ALTER TABLE companies
      ADD COLUMN fnb_plan ENUM('none','active','paid') NOT NULL DEFAULT 'none';
  END IF;
END//
DELIMITER ;
CALL aiserve_phase32();
DROP PROCEDURE aiserve_phase32;

CREATE TABLE IF NOT EXISTS `fnb_categories` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `name`       VARCHAR(120) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fnb_categories_company` (`company_id`, `status`, `sort_order`),
  CONSTRAINT `fk_fnb_categories_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fnb_products` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id`  INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `name`        VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `price`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `image_ext`   VARCHAR(8) DEFAULT NULL,
    -- File extension of the uploaded product image (jpg/png/webp).
    -- Actual file: uploads/fnb/<company_id>/<product_id>.<ext>
    -- Deleting a product cascades the row; we sweep the file async.
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fnb_products_company_status` (`company_id`, `status`),
  KEY `idx_fnb_products_category`       (`category_id`, `sort_order`),
  CONSTRAINT `fk_fnb_products_company`
    FOREIGN KEY (`company_id`)  REFERENCES `companies`     (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fnb_products_category`
    FOREIGN KEY (`category_id`) REFERENCES `fnb_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fnb_variants` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`  INT UNSIGNED NOT NULL,
  `group_name`  VARCHAR(80)  NOT NULL,
    -- Radio group label shown to the customer: "Size", "Spice level"
  `name`        VARCHAR(120) NOT NULL,
    -- One choice within the group: "Small", "Medium", "Large"
  `price_delta` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `is_default`  TINYINT(1) NOT NULL DEFAULT 0,
    -- One per group_name should be the default preselection.
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fnb_variants_product` (`product_id`, `group_name`, `sort_order`),
  CONSTRAINT `fk_fnb_variants_product`
    FOREIGN KEY (`product_id`) REFERENCES `fnb_products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fnb_addons` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`  INT UNSIGNED NOT NULL,
  `name`        VARCHAR(120) NOT NULL,
    -- "Extra egg", "Extra cheese", "Extra sauce"
  `price_delta` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fnb_addons_product` (`product_id`, `sort_order`),
  CONSTRAINT `fk_fnb_addons_product`
    FOREIGN KEY (`product_id`) REFERENCES `fnb_products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
