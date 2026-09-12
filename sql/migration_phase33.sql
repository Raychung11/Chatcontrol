-- AiServe Shared WhatsApp Inbox - Phase 33 migration
-- F&B module Layer 2: orders + order items.
--
-- Snapshot pattern — every fnb_order_items row carries product_name,
-- unit_price, variants_json, addons_json copied at order time so a
-- later rename / price change / product delete doesn't retroactively
-- rewrite historical orders. The product_id FK is SET NULL on delete
-- for the same reason: you can still look at an old order for a
-- product that's since been removed from the menu.
--
-- Order number is generated post-INSERT as CONCAT('A', LPAD(id + 10000, 5, '0'))
-- so the first order in the workspace becomes A10001, the 500th A10500,
-- etc. Deliberately obfuscates the total order count without needing
-- a per-workspace counter column.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `fnb_orders` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id`       INT UNSIGNED NOT NULL,
  `branch_id`        INT UNSIGNED DEFAULT NULL,
  `conversation_id`  INT UNSIGNED DEFAULT NULL,
  `contact_id`       INT UNSIGNED DEFAULT NULL,
  `order_number`     VARCHAR(20)  NOT NULL DEFAULT '',
  `order_type`       ENUM('delivery','pickup') NOT NULL DEFAULT 'delivery',
  `customer_name`    VARCHAR(150) NOT NULL,
  `customer_phone`   VARCHAR(40)  DEFAULT NULL,
  `delivery_address` TEXT DEFAULT NULL,
  `delivery_notes`   VARCHAR(500) DEFAULT NULL,
  `pickup_time`      DATETIME DEFAULT NULL,
  `subtotal`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `delivery_fee`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status`           ENUM('new','confirmed','processing','completed','cancelled')
                     NOT NULL DEFAULT 'new',
  `notes`            TEXT DEFAULT NULL,   -- internal staff notes
  `created_by_user_id` INT UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fnb_orders_company_status` (`company_id`, `status`, `created_at`),
  KEY `idx_fnb_orders_number`         (`order_number`),
  KEY `idx_fnb_orders_contact`        (`contact_id`),
  KEY `idx_fnb_orders_conversation`   (`conversation_id`),
  CONSTRAINT `fk_fnb_orders_company`
    FOREIGN KEY (`company_id`)      REFERENCES `companies`     (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fnb_orders_branch`
    FOREIGN KEY (`branch_id`)       REFERENCES `branches`      (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fnb_orders_conv`
    FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fnb_orders_contact`
    FOREIGN KEY (`contact_id`)      REFERENCES `contacts`      (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fnb_orders_user`
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users`      (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fnb_order_items` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      INT UNSIGNED NOT NULL,
  `product_id`    INT UNSIGNED DEFAULT NULL,
    -- SET NULL when the source product is later deleted so orders still
    -- render. product_name is snapshotted below for the same reason.
  `product_name`  VARCHAR(150) NOT NULL,
  `quantity`      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `unit_price`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- Base product price at order time. Excludes variant / addon deltas.
  `variants_json` TEXT DEFAULT NULL,
    -- JSON array of picked variants:
    -- [{"group":"Size","name":"Large","price_delta":2.00}, ...]
  `addons_json`   TEXT DEFAULT NULL,
    -- JSON array of picked add-ons:
    -- [{"name":"Extra egg","price_delta":2.50}, ...]
  `instructions`  VARCHAR(500) DEFAULT NULL,
    -- Free-text per-item note like "Less spicy" or "No onion"
  `line_total`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- Computed: (unit_price + sum(variants) + sum(addons)) * quantity
  `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fnb_order_items_order` (`order_id`, `sort_order`),
  CONSTRAINT `fk_fnb_order_items_order`
    FOREIGN KEY (`order_id`)   REFERENCES `fnb_orders`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fnb_order_items_product`
    FOREIGN KEY (`product_id`) REFERENCES `fnb_products`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
