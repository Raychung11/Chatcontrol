# Setting up the Meta App for Facebook + Instagram comment inbox

This portal connects to Facebook Pages and Instagram Business accounts via a
single Meta App owned by the platform operator (you). Each customer connects
their own Page(s) against your App through OAuth — you never see their
password, and they can revoke access from Facebook at any time.

Plan ~30 minutes for the initial setup. App Review adds 3–14 days.

---

## Prerequisites

- A **Meta Developer account** — https://developers.facebook.com
- A **Meta Business account, verified** — https://business.facebook.com
  (Business Verification is the slow part; if you're already verified, you're
  ahead of schedule.)
- Your portal deployed at an HTTPS URL. Meta requires TLS on redirect + webhook.

---

## 1. Create the Meta App

1. https://developers.facebook.com/apps → **Create App**.
2. Use case: **Manage your business's integrations**.
3. Type: **Business**.
4. Business Portfolio: pick your verified Business.
5. Name the app (customer-facing, e.g. "AiServe Inbox").

## 2. Confirm ownership is the Business

App → **Settings → Basic** → App Owner. If it shows your personal name,
click **Transfer Ownership** and pick the Business. This is required for
advanced permissions.

## 3. Copy the App ID + App Secret

Same page. Set them as environment variables on your portal:

```bash
META_APP_ID=1234567890123
META_APP_SECRET=abcdef...
META_WEBHOOK_VERIFY_TOKEN=$(openssl rand -hex 24)
```

Or edit `config/meta_config.php` directly (not recommended in production —
the secret should not sit in git).

## 4. Add App Domains

Settings → Basic → **App Domains** → add your portal domain, e.g.
`inbox.aiserve.my`.

Also fill:
- Privacy Policy URL: `https://YOUR-DOMAIN/privacy.php`
- Data Deletion URL: `https://YOUR-DOMAIN/api/meta_deletion_callback.php`
  (this endpoint ships in a follow-up commit)
- Category: Business and Pages

## 5. Add Facebook Login for Business

Products → **Add product** → Facebook Login for Business → Set up.

- Client OAuth Login: **Yes**
- Web OAuth Login: **Yes**
- Valid OAuth Redirect URIs:
  `https://YOUR-DOMAIN/api/meta_oauth_callback.php`
- Allowed Domains for the JavaScript SDK: your portal domain

## 6. Add Webhooks

Products → **Add product** → Webhooks → Set up.

- Object: **Page**
- Callback URL: `https://YOUR-DOMAIN/webhook/meta.php`
- Verify Token: paste the same value you put in `META_WEBHOOK_VERIFY_TOKEN`
- Subscribe to fields: `feed` (comments on Page posts)

Repeat for object **Instagram** if you're doing IG in this phase:
- Object: **Instagram**
- Same callback URL, same verify token
- Subscribe to fields: `comments`

## 7. Add yourself + testers as App Roles

While in Development mode, only App Roles can connect. App → **App Roles →
Roles** → add:

- Yourself as **Administrator**
- Any pilot customer as **Tester** — they'll get a Facebook notification,
  they accept it, then they can connect their Pages against your App.

You can sell to those testers immediately with no App Review.

## 8. Request permissions (App Review)

When ready to open the door to any customer, submit for App Review under
App → **App Review → Permissions and Features**. Request each of these one
by one with a screencast:

- `pages_show_list`
- `pages_read_engagement`
- `pages_manage_engagement`
- `pages_manage_metadata`
- `instagram_basic`
- `instagram_manage_comments`

Timeline: usually 3–7 days per permission with a verified Business. Meta
will ping you if they need more info.

Later phase (private DM reply-to-comment):
- `pages_messaging`
- `instagram_manage_messages`

## 9. Switch to Live mode

App Dashboard → toggle **Live**. Now any Facebook user can connect their
Pages to your app.

---

## Verifying it works

1. As an App Role (yourself or a tester), sign in to the portal.
2. Admin → Channels → **Connect Facebook** (or **Connect Instagram**).
3. Facebook Login pops up. Approve the requested Pages.
4. You come back to Channels; the Pages appear as connected.
5. Have someone comment on a Page post. Within seconds you should see it
   appear in the shared inbox.

## Rotating the App Secret

If the secret ever leaks (`git blame` shows it, screenshared it, etc.):

1. Meta App → Settings → Basic → App Secret → **Reset**.
2. Update `META_APP_SECRET` env var on the server.
3. Restart PHP-FPM / Apache to reload env.

Existing Page tokens keep working — they are not derived from the App Secret.

## Rotating a Page's access token

Long-lived Page tokens don't expire, but if a customer revokes access from
their Facebook settings, our stored token becomes invalid. The webhook will
start returning 190 errors. Show them a "Reconnect" button that runs the
OAuth flow again.
