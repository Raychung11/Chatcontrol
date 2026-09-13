-- AiServe Shared WhatsApp Inbox - Phase 48 migration
-- Google Sheets sync for Q&A pairs. Operators publish a Google Sheet as
-- CSV ("File → Share → Publish to web → CSV") and paste the URL here.
-- Rows are wiped-and-reinserted every sync, tagged by source_sheet_url
-- so hand-added Q&As are never touched.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase48;
DELIMITER //
CREATE PROCEDURE aiserve_phase48()
BEGIN
  -- 1. kb_qa_pairs gains a source tag so we know which rows came from
  --    which sheet (NULL = added by hand from the UI, never wiped).
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kb_qa_pairs'
      AND COLUMN_NAME = 'source_sheet_url'
  ) THEN
    ALTER TABLE kb_qa_pairs
      ADD COLUMN source_sheet_url VARCHAR(500) DEFAULT NULL,
      ADD KEY idx_kbqa_source_sheet (company_id, source_sheet_url(191));
  END IF;

  -- 2. kb_qa_sheets — one row per configured sheet.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kb_qa_sheets'
  ) THEN
    CREATE TABLE kb_qa_sheets (
      id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      company_id          INT UNSIGNED NOT NULL,
      name                VARCHAR(120) NOT NULL,
      sheet_url           VARCHAR(500) NOT NULL,
      active              TINYINT(1)   NOT NULL DEFAULT 1,
      last_synced_at      DATETIME     DEFAULT NULL,
      last_synced_count   INT UNSIGNED DEFAULT NULL,
      last_error          VARCHAR(500) DEFAULT NULL,
      created_by          INT UNSIGNED DEFAULT NULL,
      created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_kbqs_company_active (company_id, active),
      CONSTRAINT fk_kbqs_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase48();
DROP PROCEDURE aiserve_phase48;
