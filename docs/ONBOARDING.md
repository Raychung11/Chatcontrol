# AiServe Inbox — Client Onboarding Poster Brief

A ready-to-use content + design pack for generating a customer onboarding
poster. Hand the **Image-generator prompt** section to ChatGPT-Image /
Midjourney / Canva AI / any image tool, and use the **Email body** to
welcome new sign-ups.

---

## 📋 Poster content (ready to paste)

### Header

> **AiServe Inbox**
>
> Your team's WhatsApp HQ — one number, ten agents, with AI.

### Section A — "Before you start" callout

You'll need:

- Your business WhatsApp number
- A short name for your workspace (e.g. `acme`)
- Your team's email addresses
- A Bearer token from your provider (we'll send this)

### Section B — The 5 steps

| # | Icon | Step | Subtext |
|---|------|---------------------|------------------------------------------------------------------|
| 1 | 📝   | **Sign up**         | Create your workspace in 30 seconds at `inbox.aiserve.my/register` |
| 2 | 🔌   | **Connect WhatsApp**| Admin → Settings → paste your provider URL + Bearer token → save |
| 3 | 👥   | **Invite your team**| Admin → Users → add up to 10 staff (Growth plan)                 |
| 4 | 📚   | **Upload FAQs**     | Admin → Knowledge base → drop in PDFs / FAQs so AI grounds its replies in your docs |
| 5 | 💬   | **Start chatting**  | Open Inbox → customer messages auto-route → click 🤖 AI suggest → review → Send |

### Section C — Pro tips strip

- ✓ Test your connection in Settings before going live
- ✓ Set up routing rules so messages reach the right department
- ✓ Use 🤖 Handover summary when reassigning a conversation
- ✓ Internal notes are private — customers never see them
- ✓ Tap ⓘ on mobile to open the conversation side panel

### Section D — Need help? footer

- 🆘 **Admin → Webhook log** — diagnoses delivery issues
- 📊 **Admin → Reports** — see avg response time + agent performance
- 📧 Support: `support@aiserve.my`

---

## 🎨 Visual brief

**Format:** A4 portrait poster, 2480 × 3508 px at 300 dpi.

**Style:** Clean, modern SaaS dashboard aesthetic. Flat illustration,
generous white space. Think Stripe / Linear / Intercom marketing
collateral — **not** clipart.

### Color palette

| Role             | Hex       | Used for                           |
|------------------|-----------|------------------------------------|
| Primary green    | `#25D366` | Call-to-action, step number badges |
| AI accent purple | `#6f42c1` | AI features, tips strip            |
| Dark text        | `#1a2330` | Headings + body copy               |
| Background       | `#f4f6f8` | Poster background                  |
| Card white       | `#ffffff` | Step rows                          |
| Success green    | `#1f7a3f` | Checkmarks                         |

### Layout (top to bottom)

1. **Top 15%** — Header: AiServe logo (green dot + bold "AiServe
   Inbox"), tagline below
2. **Top-right corner** — Small "Quick Start" badge in green
3. **Below header** — Thin grey "Before you start" callout box
4. **Middle 55%** — Five horizontal step rows. Each row: green circle
   with the step number, flat-style icon, bold step name, one-line
   subtext beneath
5. **Below steps** — Purple-tinted "Pro tips" strip with checkmarks
6. **Bottom 10%** — Help footer with phone icon + email

### Icon suggestions (flat, single-color, line style)

| Step | Icon idea                            |
|------|--------------------------------------|
| 1    | ✏️ Document with cursor              |
| 2    | 🔌 Plug into socket                  |
| 3    | 👥 Three people silhouettes          |
| 4    | 📄 Document stack with magnifier     |
| 5    | 💬 Speech bubble with sparkle        |

### Typography

- Headings: **Inter Bold** or **SF Pro Display Bold**
- Body: **Inter Regular**, 14–16 pt
- All caps for "STEP 1", "STEP 2" row labels

---

## 🤖 Image-generator prompt (paste verbatim)

> A modern SaaS onboarding poster for "AiServe Inbox" — a shared
> WhatsApp inbox for customer service teams. A4 portrait, 2480 × 3508
> px. Top: bold "AiServe Inbox" header with a green WhatsApp dot logo,
> tagline "Your team's WhatsApp HQ — one number, ten agents, with AI."
> Middle: five horizontal step rows, each with a green circle number
> badge (1–5), a flat single-color line icon, a bold step title, and a
> one-line subtitle. Steps: 1. Sign up (document icon), 2. Connect
> WhatsApp (plug icon), 3. Invite your team (people icon), 4. Upload
> FAQs (document stack icon), 5. Start chatting (speech bubble icon).
> Below the steps: a purple-tinted "Pro tips" strip with four green
> checkmarks. Bottom: thin grey footer with support email. Colour
> palette: WhatsApp green #25D366 for primary accents, purple #6f42c1
> for AI / tips section, white card backgrounds on light grey #f4f6f8.
> Clean, modern Stripe / Linear-style typography (Inter Bold + Regular).
> Generous white space. No clipart, no stock photography, no
> overlapping elements. Print-ready quality.

---

## 📐 Recommended variants

| Variant                    | Use case                              | Format          |
|----------------------------|---------------------------------------|-----------------|
| **A4 portrait poster**     | Print, customer success kit           | 2480 × 3508 px  |
| **Horizontal social card** | LinkedIn, WhatsApp Status, email head | 1200 × 630 px   |
| **Square**                 | Instagram, partner deck slide         | 1080 × 1080 px  |

For the social card, shrink to three steps (Sign up → Connect → Chat),
drop the "Before you start" callout, keep the purple AI accent.

For the square, lead with the AI tag-line and put the steps in a
2-column grid (2 on top, 2 below, "Start chatting" centered as a CTA).

---

## ✉️ Welcome email body (companion to the poster)

> **Subject:** Welcome to AiServe Inbox — let's get you live
>
> Hi {{ admin_name }},
>
> Your **{{ workspace_name }}** workspace is ready. You're now the
> Super Admin — here's the 5-step path to going live.
>
> **1. Connect your WhatsApp number** — go to *Admin → Settings*,
> paste the provider URL and Bearer token we sent you in the previous
> email, then click *Save*.
>
> **2. Invite your team** — *Admin → Users* → *+ New user*. You can
> add up to 10 teammates on the Growth plan. Each one will get their
> own login.
>
> **3. Add your FAQs** — *Admin → Knowledge base* → upload your
> existing FAQs, policy docs, or pricing sheet. The AI will use them
> to draft replies in your voice.
>
> **4. Test the connection** — back in *Settings*, scroll to *Test
> connection* and send yourself a "hello" to confirm everything's
> wired up.
>
> **5. Start handling messages** — open *Inbox*. New customer messages
> appear within seconds. Click 🤖 *AI suggest* on any conversation and
> the system drafts a reply grounded in your knowledge base. Edit,
> hit *Send*.
>
> **One-page poster:** {{ link_to_poster }}
> **Full guide:** {{ link_to_docs }}
>
> Reply to this email anytime — we're here to help.
>
> — The AiServe team

---

## 🧾 Print + share checklist

- [ ] Final PDF exported at 300 dpi
- [ ] Workspace URL replaced with your actual production URL
- [ ] Support email replaced with your real support address
- [ ] Logo file at 1× and 2× added to the kit folder
- [ ] Cropped social variants generated (1200 × 630, 1080 × 1080)
- [ ] Poster + welcome email both linked from the signup confirmation
      page
- [ ] Reviewed by a non-technical reader for clarity

---

*Last updated as part of the AiServe Shared WhatsApp Inbox repo.*
