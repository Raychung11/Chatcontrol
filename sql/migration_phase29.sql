-- AiServe Shared WhatsApp Inbox - Phase 29 migration
-- Message flow engine (qualification / drip / menu bots).
--
-- Model:
--   flows           = the workflow definition (name, trigger, status).
--   flow_nodes      = a step in the workflow (send message, wait for
--                     reply, branch, assign department, etc).
--   flow_edges      = links from one node to the next. Non-branch
--                     nodes have exactly one outgoing edge. Branch nodes
--                     have many, each with a condition to match against
--                     the customer's last reply.
--   flow_instances  = one running copy of a flow for one specific
--                     conversation. Carries the state (JSON) — e.g. the
--                     answers the customer has given so far.
--
-- Execution:
--   Webhook detects a matching trigger -> flow_engine_start() creates an
--   instance in status='running'. Engine walks nodes until it hits a
--   wait_reply -> instance status becomes 'waiting'. When the customer
--   replies, webhook calls flow_engine_advance() which resumes from the
--   waiting node.
--
-- Node types shipped in this phase:
--   send_message     : send text to the customer, advance immediately.
--   wait_reply       : pause; the next customer message resumes here and
--                      is saved into state as { var_name: reply_text }.
--   branch           : evaluate outgoing edges against the last saved
--                      reply; take the first matching edge, or the
--                      default edge if none match.
--   assign_dept      : set conversations.department_id, advance.
--   save_note        : append the collected state as an internal_notes
--                      row so the agent picking up the conversation sees
--                      the answers.
--   end              : mark instance completed.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `flows` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id`         INT UNSIGNED NOT NULL,
  `name`               VARCHAR(150) NOT NULL,
  `trigger_type`       ENUM('new_conversation','keyword','manual')
                       NOT NULL DEFAULT 'new_conversation',
  `trigger_keywords`   VARCHAR(500) DEFAULT NULL,
    -- Comma-separated. Match is case-insensitive substring on the
    -- customer's message. Ignored for new_conversation triggers.
  `status`             ENUM('draft','active','paused') NOT NULL DEFAULT 'draft',
  `entry_node_id`      INT UNSIGNED DEFAULT NULL,
  `created_by_user_id` INT UNSIGNED DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_flows_company_status` (`company_id`, `status`),
  CONSTRAINT `fk_flows_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flow_nodes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `flow_id`    INT UNSIGNED NOT NULL,
  `node_type`  ENUM('send_message','wait_reply','branch','assign_dept','save_note','end')
               NOT NULL,
  `label`      VARCHAR(120) DEFAULT NULL,
  `config`     TEXT DEFAULT NULL,
    -- JSON. Shape depends on node_type:
    --   send_message  { text: "Hi..." }
    --   wait_reply    { var_name: "customer_name", timeout_min: null }
    --   branch        (no config; edges hold conditions)
    --   assign_dept   { department_id: 3 }
    --   save_note     { template: "Answers: name={{customer_name}}" }
    --   end           (no config)
  `next_node_id` INT UNSIGNED DEFAULT NULL,
    -- For everything EXCEPT branch: the single next node. Redundant
    -- with flow_edges but denormalized for O(1) engine walks.
  `position_x` SMALLINT NOT NULL DEFAULT 0,
  `position_y` SMALLINT NOT NULL DEFAULT 0,
    -- Reserved for the visual canvas in the next phase; ignored for now.
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_flow_nodes_flow` (`flow_id`),
  CONSTRAINT `fk_flow_nodes_flow`
    FOREIGN KEY (`flow_id`) REFERENCES `flows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flow_edges` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `flow_id`        INT UNSIGNED NOT NULL,
  `from_node_id`   INT UNSIGNED NOT NULL,
  `to_node_id`     INT UNSIGNED NOT NULL,
  `condition_type` ENUM('any','keyword','default') NOT NULL DEFAULT 'any',
  `condition_value` VARCHAR(255) DEFAULT NULL,
    -- keyword: matches if the customer's last reply contains this string
    --          (case-insensitive). Empty = never matches.
    -- default: fallback taken when no other edge matches.
    -- any:     always match. Used for non-branch nodes.
  `sort_order`     SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_flow_edges_from` (`from_node_id`),
  KEY `idx_flow_edges_flow` (`flow_id`),
  CONSTRAINT `fk_flow_edges_flow`
    FOREIGN KEY (`flow_id`) REFERENCES `flows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flow_instances` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `flow_id`          INT UNSIGNED NOT NULL,
  `conversation_id`  INT UNSIGNED NOT NULL,
  `current_node_id`  INT UNSIGNED DEFAULT NULL,
  `status`           ENUM('running','waiting','completed','failed','cancelled')
                     NOT NULL DEFAULT 'running',
  `state`            TEXT DEFAULT NULL,
    -- JSON: { last_reply: "...", vars: { customer_name: "...", ... } }
  `error_message`    VARCHAR(500) DEFAULT NULL,
  `waiting_since`    DATETIME DEFAULT NULL,
  `completed_at`     DATETIME DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_flow_instances_flow_conv` (`flow_id`, `conversation_id`),
    -- One active instance per (flow, conversation) — so a customer
    -- opening a new conversation while one is already running for
    -- the same flow doesn't double-start.
  KEY `idx_flow_instances_conv_status` (`conversation_id`, `status`),
  CONSTRAINT `fk_flow_instances_flow`
    FOREIGN KEY (`flow_id`) REFERENCES `flows` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_flow_instances_conv`
    FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
