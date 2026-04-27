# AiServe Shared WhatsApp Inbox Portal

A multi-agent WhatsApp customer service portal built for **AiServe / SLV Group**.

One official **WhatsApp Business** number connects to the portal via the
WhatsApp Cloud API. More than 10 staff can log in, view the same inbox,
take ownership of conversations, reply from the same number, and let
managers monitor performance.

## Stack

- PHP 8.2+ (no framework, easy for mid-level PHP devs to maintain)
- MySQL / MariaDB
- Vanilla JS + minimal CSS (single stylesheet)
- Apache or Nginx
- Hostinger VPS / shared hosting compatible
- No Docker, no Node build step

## Highlights

- Three-tier RBAC (Super Admin / Manager / Agent)
- Shared inbox with live status badges, filters, search, departments
- Conversation assignment, reassignment, escalation, close/reopen
- Internal notes (not shown to the customer)
- WhatsApp **24-hour service window** tracking - free-text replies
  blocked outside the window, with prompt to use a template
- Approved template management
- Activity log for every important action
- Reports: conversation totals, first-response time, per-agent stats
- Webhook receiver handles text + media + interactive replies
- AI-reply scaffolding ready for Phase 3

## Quick start

```bash
# 1. Set up DB
mysql -u root -p
> CREATE DATABASE aiserve_inbox CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
> CREATE USER 'aiserve'@'localhost' IDENTIFIED BY 'change_me';
> GRANT ALL ON aiserve_inbox.* TO 'aiserve'@'localhost';

# 2. Import schema + seed
mysql -u aiserve -p aiserve_inbox < sql/schema.sql
mysql -u aiserve -p aiserve_inbox < sql/seed.sql

# 3. Configure DB credentials
$EDITOR config/db_config.php

# 4. Point Apache/Nginx at the project root.
# 5. Visit https://YOUR-DOMAIN/login.php
#    Default: admin@aiserve.local / ChangeMe@123  (CHANGE THIS!)
```

See [`docs/INSTALL.md`](docs/INSTALL.md) for the full installation guide
including Meta WhatsApp Cloud API setup.

## Folder layout

```
config/    runtime + DB config
inc/       shared PHP includes (auth, helpers, layout, sidebar, whatsapp_api)
admin/     admin & manager pages
inbox/     shared inbox + chat detail
api/       JSON endpoints (send_message, conversation_action)
webhook/   Meta webhook receiver
assets/    CSS / JS
sql/       schema + seed
docs/      documentation
```

## Roadmap

**Phase 1 (this MVP)** - login, RBAC, webhook ingest, shared inbox, reply
sending, assignment, internal notes, basic reports, template registry.

**Phase 2** - template send, file/image upload, delivery/read receipts UI,
tags, department auto-routing, richer reports.

**Phase 3** - AI reply suggestion, auto summary, FAQ assistant, sentiment,
CRM/Odoo integration, broadcast campaigns.

## License

Proprietary - AiServe / SLV Group.
