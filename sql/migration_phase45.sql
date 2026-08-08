-- AiServe Shared WhatsApp Inbox - Phase 45 migration
-- Broadcast plan invoicing.
--
-- One row per invoice. Created by cron/send_invoices.php scanning
-- activity_logs for broadcast_plan_selfserve_change entries. Sent to
-- the workspace super_admin's email as an HTML link (viewer at
-- /invoice.php?id=X&token=Y).
--
-- Idempotency: UNIQUE(source_activity_id) means re-running the cron
-- after a partial run doesn't re-invoice the same upgrade.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id          INT UNSIGNED NOT NULL,
  source_activity_id  INT UNSIGNED DEFAULT NULL,     -- activity_logs.id that triggered this
  invoice_number      VARCHAR(30)  NOT NULL,          -- INV-2026-00042
  plan                VARCHAR(30)  NOT NULL,          -- 'paid' | 'payg'
  billing_cycle       VARCHAR(20)  NOT NULL DEFAULT 'monthly',
  quantity            INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price          DECIMAL(10,2) NOT NULL DEFAULT 0,
  subtotal            DECIMAL(10,2) NOT NULL DEFAULT 0,
  tax                 DECIMAL(10,2) NOT NULL DEFAULT 0,
  total               DECIMAL(10,2) NOT NULL DEFAULT 0,
  currency            VARCHAR(10)  NOT NULL DEFAULT 'RM',
  status              ENUM('draft','sent','paid','void') NOT NULL DEFAULT 'draft',
  view_token          CHAR(48)     NOT NULL,          -- for /invoice.php URL
  notes               VARCHAR(500) DEFAULT NULL,
  email_sent_to       VARCHAR(255) DEFAULT NULL,
  email_sent_at       DATETIME     DEFAULT NULL,
  paid_at             DATETIME     DEFAULT NULL,
  paid_note           VARCHAR(255) DEFAULT NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_inv_number (invoice_number),
  UNIQUE KEY uk_inv_source (source_activity_id),
  UNIQUE KEY uk_inv_token  (view_token),
  KEY idx_inv_company (company_id, created_at),
  KEY idx_inv_status  (status),
  CONSTRAINT fk_inv_company FOREIGN KEY (company_id)
    REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
