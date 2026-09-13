-- AiServe Shared WhatsApp Inbox - Phase 47 migration
-- KB learning enhancements: URL source, Q&A pairs, agent-edit
-- capture, coverage gaps.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS aiserve_phase47;
DELIMITER //
CREATE PROCEDURE aiserve_phase47()
BEGIN
  -- 1. knowledge_base gains source URL + last-fetched for A ("refresh")
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_base'
      AND COLUMN_NAME = 'source_url'
  ) THEN
    ALTER TABLE knowledge_base
      ADD COLUMN source_url             VARCHAR(500) DEFAULT NULL,
      ADD COLUMN source_last_fetched_at DATETIME    DEFAULT NULL;
  END IF;

  -- 2. Q&A pairs — high-signal short-form entries, higher priority in
  --    the AI prompt than long articles.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kb_qa_pairs'
  ) THEN
    CREATE TABLE kb_qa_pairs (
      id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      company_id  INT UNSIGNED NOT NULL,
      question    VARCHAR(500) NOT NULL,
      answer      TEXT         NOT NULL,
      tag         VARCHAR(60)  DEFAULT NULL,
      status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
      hit_count   INT UNSIGNED NOT NULL DEFAULT 0,
      created_by  INT UNSIGNED DEFAULT NULL,
      created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_kbqa_company_status (company_id, status),
      CONSTRAINT fk_kbqa_company  FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  END IF;

  -- 3. Agent-edit examples — captured on send when the agent used an
  --    AI draft. Weekly cron distills into a "Team style rules"
  --    auto-KB article.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_edit_examples'
  ) THEN
    CREATE TABLE ai_edit_examples (
      id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      company_id          INT UNSIGNED NOT NULL,
      conversation_id     INT UNSIGNED DEFAULT NULL,
      customer_message    TEXT         NOT NULL,
      ai_draft            TEXT         NOT NULL,
      agent_sent          TEXT         NOT NULL,
      edit_distance       INT UNSIGNED DEFAULT NULL,
      agent_user_id       INT UNSIGNED DEFAULT NULL,
      processed_at        DATETIME     DEFAULT NULL,
      created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_aie_company_processed (company_id, processed_at, edit_distance),
      CONSTRAINT fk_aie_company  FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  END IF;

  -- 4. Coverage gaps — questions the AI struggled with (no-answer
  --    reply, or human escalation within 30s of AI send).
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_coverage_gaps'
  ) THEN
    CREATE TABLE ai_coverage_gaps (
      id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      company_id          INT UNSIGNED NOT NULL,
      conversation_id     INT UNSIGNED DEFAULT NULL,
      customer_message    TEXT         NOT NULL,
      reason              ENUM('no_answer','fast_escalation','low_confidence','manual') NOT NULL DEFAULT 'no_answer',
      resolved_kb_id      INT UNSIGNED DEFAULT NULL,   -- set when operator drafts KB from this
      dismissed_at        DATETIME     DEFAULT NULL,
      created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_acg_company_open (company_id, resolved_kb_id, dismissed_at, created_at),
      CONSTRAINT fk_acg_company  FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  END IF;
END//
DELIMITER ;
CALL aiserve_phase47();
DROP PROCEDURE aiserve_phase47;
