# AiServe Shared WhatsApp Inbox - Installation Guide

This MVP runs on plain LAMP (Linux + Apache + MySQL/MariaDB + PHP 8.2+).
Tested deployment target: Hostinger VPS / shared hosting with Apache + MySQL.

---

## 1. Requirements

- PHP **8.2** or later
- MySQL **5.7+** or MariaDB **10.4+**
- Apache **2.4+** with `mod_rewrite` (or Nginx with equivalent rules)
- TLS certificate (Meta requires HTTPS for the webhook URL)
- A configured **WhatsApp Business** account in Meta Business Manager
  with a phone number registered in the Cloud API

---

## 2. Database setup

```bash
mysql -u root -p
```

```sql
CREATE DATABASE aiserve_inbox CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aiserve'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON aiserve_inbox.* TO 'aiserve'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Import the schema and seed data:

```bash
mysql -u aiserve -p aiserve_inbox < sql/schema.sql
mysql -u aiserve -p aiserve_inbox < sql/seed.sql
```

**Upgrading?** Run the Phase 2 + Phase 3 migrations (idempotent):

```bash
mysql -u aiserve -p aiserve_inbox < sql/migration_phase2.sql
mysql -u aiserve -p aiserve_inbox < sql/migration_phase3.sql
```

Phase 2 adds: `companies.default_department_id`,
`conversations.resolved_at`, `messages.template_name`,
`messages.media_local_path`, `messages.media_id`, plus the
`routing_rules` table.

Phase 3 adds the messaging provider columns:
`companies.provider`, `evolution_base_url`, `evolution_api_key`,
`evolution_instance`, `evolution_status`. See
[`docs/EVOLUTION.md`](EVOLUTION.md) to self-host the Evolution server.

The seed creates:

- One `companies` row (id 1)
- Three default departments (General / Sales / Support)
- One **Super Admin** user:
  - email: `admin@aiserve.local`
  - password: `ChangeMe@123`

**Change this password immediately after first login** (Admin → Users).

---

## 3. Application configuration

Edit `config/db_config.php` (or set environment variables in your VHost / panel):

```php
$DB_HOST = '127.0.0.1';
$DB_NAME = 'aiserve_inbox';
$DB_USER = 'aiserve';
$DB_PASS = 'STRONG_PASSWORD_HERE';
```

Optional environment variables:

| Variable             | Purpose                            |
|----------------------|------------------------------------|
| `AISERVE_DB_HOST`    | DB host                            |
| `AISERVE_DB_NAME`    | DB name                            |
| `AISERVE_DB_USER`    | DB user                            |
| `AISERVE_DB_PASS`    | DB password                        |
| `AISERVE_BASE_URL`   | Public URL, e.g. `https://inbox.example.com` |
| `AISERVE_TZ`         | Timezone, default `Asia/Kuala_Lumpur` |
| `AISERVE_COMPANY_ID` | Tenant id, default `1`             |

---

## 4. Web server

Point the document root at the project directory. Apache + `.htaccess` is
included; `mod_headers` and `mod_rewrite` should be enabled. The
`/config`, `/inc`, and `/sql` folders are blocked from web access.

For Nginx, use the equivalent (deny `/config`, `/inc`, `/sql`, send all
requests to the matching `*.php`).

Make sure HTTPS is enabled - Meta will reject any non-HTTPS webhook URL.

---

## 5. WhatsApp Cloud API setup

1. Sign in to **Meta Business Manager** → create a WhatsApp Business app.
2. Add a phone number under **WhatsApp → API Setup** and copy:
   - Phone Number ID
   - WhatsApp Business Account ID
   - System User access token (long-lived recommended)
3. In the portal, log in as Super Admin and go to **Settings**.
   Fill in the values above, save.
4. Set a **Webhook Verify Token** (any random string, e.g. 32 hex chars).
5. In Meta App Dashboard → **WhatsApp → Configuration → Webhook**:
   - Callback URL: `https://YOUR-DOMAIN/webhook/whatsapp.php`
   - Verify Token: same value as in step 4
   - Subscribe to fields: `messages`, `message_status`
6. Send a test WhatsApp message to the number → it should appear in the
   shared inbox within seconds.

---

## 6. First login

- Visit `https://YOUR-DOMAIN/login.php`
- Sign in with the seed account
- Create departments + agents at **Admin → Departments / Users**
- Optional: pre-load **approved templates** at **Admin → Templates**
  (required for replies after the 24-hour service window)

---

## 7. Hardening checklist

- Change the seeded super admin password
- Generate a strong random `webhook_verify_token`
- Restrict DB user to the inbox database only
- Keep `config/db_config.php` outside the web root if possible
- Enable HTTPS (Let's Encrypt or your panel's TLS)
- Configure server backups for `aiserve_inbox` daily
- Review `activity_logs` regularly for unusual actions

---

## 8. Folder structure

```
/config       - DB / runtime config
/inc          - Shared PHP includes (auth, helpers, layout, sidebar, whatsapp_api)
/admin        - Admin & manager pages (users, settings, templates, reports...)
/inbox        - Shared inbox + chat detail
/api          - JSON endpoints (send_message, conversation_action)
/webhook      - Meta WhatsApp webhook receiver
/assets       - CSS, JS, images
/uploads      - Reserved for future media downloads
/sql          - Schema + seed
/docs         - Documentation
```
