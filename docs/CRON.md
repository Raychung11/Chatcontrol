# Cron jobs for AiServe Inbox

Two automation features run on cron rather than inline so the request/response
loop stays fast:

| Script                              | What it does                                                       | Recommended frequency |
| ----------------------------------- | ------------------------------------------------------------------ | --------------------- |
| `cron/check_failed_sends.php`       | Emails workspace admins when bulk outbound send failures hit       | Every 5 minutes       |

All scripts are CLI-only — running them over HTTP returns a 403.

## Setting up on Hostinger

1. Hostinger control panel → **Advanced → Cron Jobs**
2. Click **Create cron job**
3. Pick frequency: **Custom → Every 5 minutes** (`*/5 * * * *`)
4. Command: paste the path to the PHP binary plus the absolute path to the
   script. For inbox.aiserve.my that's typically:

   ```
   /usr/bin/php /home/u822252863/domains/aiserve.my/public_html/inbox/cron/check_failed_sends.php >> ~/cron_check_failed_sends.log 2>&1
   ```

5. Save.

## Verify it's working

After 5–10 minutes:

```bash
tail -20 ~/cron_check_failed_sends.log
```

You should see entries like:

```
[2026-06-26 14:05:00] checking 3 workspace(s)
  1  default              - 0 failures (under threshold 5)
  2  slv-group-sdn-bhd    - 1 failures (under threshold 5)
  3  barat-tioman         - in cooldown, skipping
[done]
```

If the cron runs but a workspace with real failures isn't being alerted, check:

- `companies.alert_failed_sends_enabled = 1` for that workspace
- A user with `role = 'super_admin' AND status = 'active'` exists with a valid email
- Or `companies.alert_email` is set to a valid email

## Trigger a test alert by hand (CLI)

```bash
ssh into your Hostinger host
cd /home/u822252863/domains/aiserve.my/public_html/inbox
/usr/bin/php cron/check_failed_sends.php
```

The script prints exactly which workspaces it considered and which recipients
were emailed.

## Why mail might land in spam

`inc/email.php` uses Hostinger's built-in `mail()` and sets the From: header
to `noreply@<your domain>`. To get higher deliverability:

- Add an SPF record to your DNS letting Hostinger send on your behalf
  (Hostinger panel → DNS Zone → already configured by default for *.hostingersite.com)
- Add a DKIM record if Hostinger's mail panel exposes one
- Once volume picks up, swap `inc/email.php` to use SMTP via PHPMailer or
  Symfony Mailer pointed at SendGrid / Mailgun / Resend

## Stopping alerts for a workspace

Two ways:

1. **Workspace admin**: Admin → Settings → uncheck *"Email me when outbound
   sends fail in bulk"*, Save.
2. **Platform admin** (via SQL):
   ```sql
   UPDATE companies SET alert_failed_sends_enabled = 0 WHERE id = X;
   ```

Cooldown is enforced via `activity_logs` (`action_type = 'alert_failed_sends'`).
You can clear stuck cooldowns with:

```sql
DELETE FROM activity_logs
WHERE action_type = 'alert_failed_sends'
  AND created_at < NOW() - INTERVAL 1 HOUR;
```
