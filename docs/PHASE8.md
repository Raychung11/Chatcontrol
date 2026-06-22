# Phase 8 — Platform admin & workspace impersonation

Lets a SaaS operator (you) sign in as the super admin of any
workspace on the platform, configure it on the customer's behalf,
then return to their own account. The customer doesn't have to share
their password.

## What to upload

- `sql/migration_phase8.sql` — adds `users.is_platform_admin`
- `inc/auth.php` — impersonation helpers
- `admin/workspaces.php` — workspace list with "Sign in as" button
- `api/impersonate.php` — start/stop impersonation endpoint
- `inc/sidebar.php` — adds Platform link
- `inc/layout.php` — yellow banner across the top while impersonating
- `assets/css/app.css` — banner styling

## Run the migration

In phpMyAdmin SQL tab:

```sql
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS is_platform_admin TINYINT(1) NOT NULL DEFAULT 0;

-- Promote yourself
UPDATE users SET is_platform_admin = 1
  WHERE email = 'YOUR_EMAIL@example.com';
```

Replace the email with the email you log into the portal with.

## How to use

1. Log in normally
2. Sidebar → **Platform → Workspaces**
3. Find your customer's workspace
4. Click **Sign in as super admin**
5. Top of every page now shows a yellow banner: *"Impersonating Acme Trading — Return to your account"*
6. Configure Settings, add users, upload knowledge base, etc.
7. Click **Return to your account** in the banner when done

Every impersonation start/stop is logged in `activity_logs`.

## Security

- Only users with `is_platform_admin = 1` can see Platform → Workspaces or hit the impersonation API
- The customer still owns their data — you can't delete the workspace or its users from the impersonated session
- The banner is non-dismissible so it's always clear you're operating someone else's account
