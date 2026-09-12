# AiServe F&B Order Taking — Introducer Deck Copy

Copy-paste ready. Each `## Page` block is one slide. Trim to 3–5 pages
depending on the audience — restaurant owners want pages 1, 3, 5;
investors want 1, 2, 3, 4.

---

## Page 1 — The Problem

**Taking phone orders is broken.**

- Staff answer WhatsApp one-by-one; menu screenshots get lost in the thread
- No structured order — everything is copy-pasted onto notepads
- Missed items, wrong quantities, illegible handwriting
- Zero tracking: "Did we accept that order? Who's making it?"
- One busy weekend = one shift wasted on message triage

**The math:**
Even a small F&B outlet handling 50 WhatsApp orders a day loses ~90 minutes
of staff time to manual order taking, plus 5–8% of orders to accuracy errors.

---

## Page 2 — What AiServe F&B Does

**A WhatsApp AI that takes orders end-to-end, into a real kitchen dashboard.**

The customer's experience:

```
Customer: "Hi I want to order"
AiServe:  "Welcome! 🍽️ Would you like delivery or pickup?"
Customer: "Delivery"
AiServe:  [Sends numbered menu]
Customer: "2 chicken rice, 1 nasi lemak less spicy"
AiServe:  "✓ Added 2 items to your cart.
           🛒 Your order so far:
           *1.* 2x Chicken Rice — RM 19.80
           *2.* 1x Nasi Lemak — RM 12.90
                _📝 Less spicy_

           Subtotal: RM 32.70

           Anything else? Say 'done' when finished."
Customer: "actually remove #1"
AiServe:  "✓ Removed: Chicken Rice
           🛒 Your order so far:
           *1.* 1x Nasi Lemak — RM 12.90
           ..."
Customer: "done"
AiServe:  "Got it. What name should we put on the order?"
...
AiServe:  "🎉 Order confirmed! Your order number: A10042
           Total: RM 12.90
           Our team will process it and confirm shortly."
```

The staff's experience:

- A new card lands on the **Orders** kanban dashboard
- Email notification fires to the person in charge
- Staff moves the order across: New → Confirmed → Processing → Completed
- One-click print for the kitchen ticket / delivery slip
- Everything linked back to the original WhatsApp chat

---

## Page 3 — Modules Included

**1. Menu management (Layer 1)**
- Categories, products, prices, descriptions, product images
- Variants: size, spice level, hot/cold — one required pick per group
- Add-ons: extra egg, extra sauce, extra cheese
- Enable / disable individual items when out of stock

**2. Order dashboard (Layer 2)**
- Kanban view across 5 statuses: New, Confirmed, Processing, Completed, Cancelled
- Live KPIs: orders today, revenue today, count per status
- Search + branch + date filters
- Manual entry form for phone orders taken by staff
- Print-friendly order slip for the kitchen
- Previous-orders lookup per customer

**3. AI order taking through WhatsApp (Layer 3)**
- One-click seed a working ordering flow — no coding needed
- Customer messages "order" or "menu" → bot takes over
- Claude parses natural language into structured cart items
- Handles remove / clear intents in natural language
- Materialises into a real order on the dashboard
- Full conversation history preserved and linked to the order

**Included from the base platform, at no extra work**

- Multi-outlet support via existing **Branches** — orders tag which outlet
- Multi-channel — one restaurant, multiple WhatsApp numbers
- Human takeover — agent picks up mid-flow whenever they want
- Full activity log — every status change is auditable

---

## Page 4 — Why It Sells

**For the operator:**

- **Saves 60–90 minutes of staff time per day** on manual order taking
- **Structured data from day one** — every order has a number, timestamp,
  customer, phone, items, quantities, options, price. Ready for reports.
- **No missed items or misheard quantities** — the customer sees the cart
  before confirming
- **24/7 availability** — the AI takes orders when staff are asleep
- **Customer prefers WhatsApp** — no downloading a new app, no website form

**For the platform (you):**

- Bolts onto the same subscription workspace — no separate product
- **Sold as a module** — flip on for restaurants only, invisible for
  everyone else
- Every restaurant customer already uses WhatsApp Inbox — F&B is the
  natural upsell

**Positioning line:**

> *An AI-powered WhatsApp ordering system that answers menu enquiries,
> captures customer selections, and turns conversations into organised
> orders for staff processing.*

---

## Page 5 — Getting Started (4 steps, ~30 minutes)

**1. Enable the F&B module** — platform admin toggles "🍜 F&B on" for the
workspace on `/admin/workspaces.php`.

**2. Upload the menu** — `/admin/fnb_menu.php`
- Create categories (Rice, Drinks, Sides, …)
- Add products with images, prices, variants, and add-ons
- Or: paste your existing menu PDF into the AI import (Phase 2)

**3. Seed the ordering flow** — `/admin/flows.php`
- Click **🍜 Seed a starter F&B ordering flow**
- A 13-node ordering conversation is created and wired up automatically
- Review the wording, click **Save flow**, then flip **Status** to **Active**

**4. Point customers at your WhatsApp number** — they message "order" or
"menu", and the AI takes it from there. Orders land on
`/admin/fnb_orders.php` in real time.

**Prereq:** the workspace's Anthropic API key on `/admin/ai_settings.php`.
That's the only external dependency.

---

## Optional Page 6 — Pricing (adjust to your model)

**Free tier** — up to 30 orders per month · included in every workspace

**Paid tier** — RM 199 / month · 1,000 orders / month · yearly discount available

**Pay-as-you-go** — RM 0.30 per order · no monthly commitment · unlimited

*(Same billing pattern the broadcast module already uses — one-click switch
between tiers from the workspaces admin.)*
