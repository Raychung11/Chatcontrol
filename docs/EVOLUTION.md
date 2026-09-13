# Self-hosting Evolution for the AiServe portal

Evolution API is a self-hosted Node.js server that connects to WhatsApp via
the same protocol the WhatsApp Web app uses (Baileys). It is **unofficial** —
Meta's terms allow them to ban a number found using non-official APIs, so use
this provider for prototypes, internal tools, or where the cost of the
official Cloud API is the bigger problem than the ban risk.

This guide gets a single Evolution instance running on a Linux VPS and paired
with the AiServe portal. Plan ~20 minutes start to finish.

---

## 1. Pick a host

Anything that can run Docker and has a public DNS name with TLS:

- A 1 vCPU / 2 GB RAM VPS is plenty for a single number with low/medium volume
- Hostinger / DigitalOcean / Hetzner all work
- TLS is **mandatory** — Evolution doesn't enforce it, but the portal does
  via Apache/Nginx in front, and WhatsApp Web simply works better over HTTPS

Suggested DNS: `evo.your-domain.com` → A record → server IP.

## 2. Install Docker

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER  # log out / back in to apply
```

## 3. Start Evolution

Clone or copy the AiServe repo onto the host (we just need two files), then:

```bash
cp .env.evolution.example .env
$EDITOR .env       # set EVOLUTION_PUBLIC_URL + EVOLUTION_API_KEY

docker compose -f docker-compose.evolution.yml --env-file .env up -d
docker compose -f docker-compose.evolution.yml logs -f evolution-api  # watch boot
```

You should see `[Evolution API] Started successfully` within ~30 seconds.

## 4. Put TLS in front

Evolution listens on port `8080`. Put any reverse proxy in front. Caddy is
the fastest:

```caddy
evo.your-domain.com {
    reverse_proxy localhost:8080
}
```

…or Nginx + Certbot, or Cloudflare proxied DNS — all fine. The end result
is `https://evo.your-domain.com/` reaching Evolution.

## 5. Configure the portal

Sign in to the portal as super admin → **Admin → Settings**:

| Field                  | Value                                                           |
| ---------------------- | --------------------------------------------------------------- |
| Messaging provider     | **Evolution API**                                               |
| Evolution server URL   | `https://evo.your-domain.com`                                   |
| Evolution API key      | the `EVOLUTION_API_KEY` from `.env`                             |
| Instance name          | any short slug, e.g. `aiserve-prod`                             |
| Webhook verify token   | any random string (used to authenticate Evolution → portal)     |
| Main WhatsApp number   | the number you'll pair, e.g. `+60 12 345 6789`                  |

Save settings.

## 6. Pair the WhatsApp number

Go to **Admin → Pair WhatsApp** and click **Start pairing**.

The portal will:
1. Create the instance on Evolution (`POST /instance/create`)
2. Configure Evolution to push events back to
   `/webhook/evolution.php?token=<your verify token>`
3. Fetch and display a QR code

On the phone with the WhatsApp number you want to use:
**WhatsApp → Settings → Linked devices → Link a device** → scan the QR.

The pill at the top of the page changes to **connected** within a few seconds.
From that moment, customer messages flow into the inbox just like the Cloud
API path.

## 7. Verify end-to-end

1. From a different phone, send a WhatsApp message to your business number.
2. Refresh the portal inbox — the conversation should appear within 1–2 s.
3. Open it and reply. The customer receives the reply on WhatsApp.

If nothing arrives, check:

- **Admin → Webhook log** — every Evolution POST is recorded there with
  `method = POST-EVO`. If count = 0 the webhook URL or token is wrong.
- `docker compose logs evolution-api` — Evolution prints the events it
  attempted to deliver.

## 8. What's different vs the Cloud API path

| | Cloud API           | Evolution                        |
| -------------- | ------------------- | -------------------------------- |
| Templates      | Required outside 24h window | Not used. Free text any time. |
| Inbox composer | Shows "Send template" button when there are approved templates | Hidden |
| 24-hour banner | Shown on expired conversations | Hidden |
| Media          | Two-step: upload → send | One-step send via base64 |
| Pairing        | OTP from Meta       | QR code scan from the phone      |
| Status updates | Meta webhook        | Evolution `MESSAGES_UPDATE`      |

The portal handles all of these differences for you — you switch the
provider radio and the UI/server behavior follow.

## 9. Operational notes

- **Backups**: Postgres is the source of truth for Evolution state. Run a
  daily `pg_dump` of the `evolution_pg` volume.
- **Multiple numbers**: spin up additional instances with different
  instance names, then add them as new tenants in the portal once the
  multi-tenant SaaS layer ships.
- **Bans**: if Meta bans the number, the WhatsApp Web link breaks and
  Evolution will report `state=disconnected`. There is no recovery — you
  need a fresh number.
- **Updates**: pin the image tag (`atendai/evolution-api:v2.1.1`). Do not
  blindly pull `:latest` — breaking webhook payload changes have happened
  between minor releases.
