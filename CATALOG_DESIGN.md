# Bouclay — Catalog Design Proposal (Phase 3)

**Status:** Proposal, not yet built. Standalone companion to [`IMPLEMENTATION.md`](IMPLEMENTATION.md) (Phase 3 section) and [`schema.md`](schema.md) (§3 Catalog & Pricing, §5 Trial offers). Does not change either document — open questions are called out in [§13](#13-schema-adjacent-notes--open-questions) for you to decide, not applied.

**Stack this proposal is grounded in:** Inertia + React 19 + TypeScript, shadcn/ui-on-Radix (`resources/js/components/ui/*`), Tailwind v4 (`@theme` tokens, no `tailwind.config.js`), `lucide-react` icons, `sonner` toasts, Laravel Wayfinder route helpers. Page shell, nav, and interaction patterns below reuse what Phase 2 already established in `resources/js/pages/developers/*` and `resources/js/components/app-sidebar.tsx` — new users should not be able to tell Catalog was designed by a different team than Developers.

---

## Contents

1. [Principles](#1-principles)
2. [Information architecture & navigation](#2-information-architecture--navigation)
3. [The mental model: Products, Prices, Trials](#3-the-mental-model-products-prices-trials)
4. [The onboarding journey](#4-the-onboarding-journey)
5. [Products](#5-products)
6. [Prices](#6-prices)
7. [Trials](#7-trials)
8. [Empty states](#8-empty-states)
9. [Success, loading & error states](#9-success-loading--error-states)
10. [Microinteractions](#10-microinteractions)
11. [Copy deck](#11-copy-deck)
12. [Future-proofing](#12-future-proofing)
13. [Schema-adjacent notes / open questions](#13-schema-adjacent-notes--open-questions)
14. [Build sequencing](#14-build-sequencing)

---

## 1. Principles

These are the load-bearing decisions everything else derives from.

1. **Products are containers, Prices are commitments.** A Product answers "what am I selling?" A Price answers "how much, how often?" Every screen keeps this distinction visually explicit — a Product never shows a dollar amount as if it were its own price; amounts only ever appear inside a Price row.
2. **The catalog is mode-agnostic — confirmed.** Unlike API keys and webhooks (which are explicitly test/live), Products, Prices, and Trials are **shared** between test and live. Only the *credentials used to charge* differ by mode (`team_processor_connections` / `api_keys`); the catalog itself does not. This removes an entire axis of confusion beginners hit in Stripe ("why do I have two versions of my product?"). Revisit only if a future need for sandboxed catalogs emerges — not a concern for this phase.
3. **Progressive disclosure over wizards, and over modals.** Catalog objects are consequential enough to deserve a real page, but a hard multi-step wizard (forced next/back, lost context) adds friction for an object people *edit* far more than they *create*. We use single-page, section-by-section disclosure — sections reveal as prior ones are satisfied, everything stays reachable by scrolling, and the same page structure is reused for editing.
4. **No dead-end empty states.** Every empty state teaches the concept before asking for the action. Nobody should hit "No products" and wonder what a product even is.
5. **No draft status — confirmed.** A product is either `active` or `archived`. Full stop. No derived "needs a price" pseudo-state, no separate draft concept. A product with zero prices is simply an Active product that shows "0 prices" — the Product Detail page's empty Pricing tab is where that gets addressed, not a special index-level status. Simpler than the original proposal, and matches how the team actually thinks about it.
6. **Prices lock only once they've actually been used.** `prices.version` exists in the schema to grandfather subscribers on change. The precise trigger: a price is freely editable in place — amount, currency, interval, anything — for as long as **no subscription has ever referenced it**. The moment a `subscription_item` exists against a price, it locks: further "edits" to amount/currency/interval must create a new Price (new `version`) and archive the old one. Cosmetic fields (name, metadata) stay editable in place always, regardless of usage. **Practical consequence for this phase:** since `subscriptions`/`subscription_items` don't exist until Phase 5, every price built in Phase 3 is unconditionally editable in place — there is nothing to lock yet. Phase 3 does not need to build the lock/version-bump UI at all; it only needs to leave the door open (i.e., don't hardcode assumptions that would make adding the Phase 5 check awkward). Document the rule now so Phase 5 doesn't have to invent it under time pressure.
7. **Trials are a side-effect of a Price, not a sibling top-level object.** `trial_offers.product_id` + `trial_price_id` + `transition_price_id` mean a trial is always scoped to one specific regular price. So in the UI, trials are created *from* a Price row ("+ Add a free trial"), never from a standalone "New Trial" entry point floating in the nav.

---

## 2. Information architecture & navigation

### Recommendation: `Catalog` as a single top-level nav item, `Products` as its only child for MVP.

```
Overview
Catalog                          ← new, positioned above Developers
  └ Products                     ← the only catalog nav entry for MVP
Developers
  ├ Nomba Integration
  ├ API Keys
  └ Webhooks
Settings
```

Followed in later phases by:

```
Catalog
  ├ Products
Customers                        ← Phase 4
Subscriptions                    ← Phase 5
Invoices                         ← Phase 6
Developers
Settings
```

**Why not `Catalog > Products` + `Catalog > Prices` as two siblings** (as sketched in the brief)? Because Prices don't exist independently of a Product in this schema (`prices.product_id` is required, not nullable) — a sibling "Prices" nav item would either duplicate the Products list with an extra click, or force users to pick a product from a dropdown before they can even see a price. Stripe's own dashboard learned this lesson: their old flat "Products" + "Pricing" nav was consolidated so pricing lives *inside* the product page. Bouclay should start there directly rather than re-discover it.

**Where do all prices go, then, once a team has many products?** Reachable, not primary: a `Products` page toolbar filter/search covers the common case ("find the product"); when a team's catalog grows large enough that they think in prices-first terms (e.g. "which of my prices are $29/mo"), add a lightweight **"All prices"** view as a *tab* inside the Products index (`Products | All prices`), not a new nav item — see [§5.1](#51-products-index). This is the extensibility seam: it costs one tab, not an IA rework, when the need shows up.

**Trials get no nav entry at all.** They're surfaced entirely inside the Product Detail page and inline on Price rows. A team member who wants to "manage trials" thinks in terms of "the trial on my Pro plan," not "my trials" as an independent collection.

**Test/Live**: Catalog pages carry **no** test/live tab toggle (contrast with `api-keys.tsx` / `webhooks.tsx`, which do). This is a deliberate, visible difference a first-time user will notice — worth a one-line hint the first time they land on Products (see empty state copy) so it reads as "intentional and simpler," not "missing."

### Sidebar implementation note

Mirrors the existing `Developers` entry in `resources/js/components/app-sidebar.tsx` exactly: a collapsible `NavItem` with an icon (`Package` from `lucide-react` fits the "container" metaphor better than `ShoppingCart` or `Tag`), gated by `teamPermissions.canViewProducts` (already seeded — `products.view` / `products.manage` exist in `app/Enums/PermissionName.php:19-26`). Route group lives in a new `routes/catalog.php`, required from `web.php` the same way `routes/developers.php` is, under `Route::prefix('{current_team}/catalog')->name('catalog.')`.

---

## 3. The mental model: Products, Prices, Trials

The single most important thing this phase has to teach. One diagram, reused verbatim (or near enough) in the empty state, the product creation intro, and the docs:

```
┌─────────────────────────────┐
│  PRODUCT                    │   "What you sell"
│  Pro Plan                   │
│                              │
│   ┌───────────────────────┐ │
│   │ PRICE                 │ │   "How a customer pays for it"
│   │ ₦15,000 / month       │ │
│   │  └─ Free trial: 14d   │ │   "A grace period before the price bites"
│   └───────────────────────┘ │
│   ┌───────────────────────┐ │
│   │ PRICE                 │ │
│   │ ₦150,000 / year       │ │
│   └───────────────────────┘ │
└─────────────────────────────┘
```

One product, multiple prices, each price optionally carrying its own trial. This directly explains why "Pro Plan" isn't itself billed monthly — the *price* is.

---

## 4. The onboarding journey

Picking up right where Phase 2 leaves off (Nomba connected, API keys generated). This is the arc the brief asked for, mapped to concrete screens:

```
 Dashboard (post Phase 2)
   │
   │  Onboarding checklist item: "Create your first product"  ⏵
   ▼
 Catalog → Products (empty state)
   │  "Create product" primary action
   ▼
 New Product page
   │  Section 1: Name & description           (always visible)
   │  Section 2: Add a price                   (reveals after name entered)
   │  Section 3: Free trial — optional          (reveals after price is valid, collapsed by default)
   │  Section 4: Review & sticky summary card   (always visible, fills in live)
   ▼
 [Create product] → toast: "Pro Plan is live"
   ▼
 Product Detail page (Pro Plan)
   │  Overview / API identifiers visible immediately
   │  "Copy Product ID" + "View in API docs" nudge
   ▼
 Dashboard checklist auto-ticks "Create your first product"
   │  Next suggested step surfaces: "Add a customer" (Phase 4, greyed/coming-soon until then)
```

Key journey decisions:

- **Product creation is reachable from two places**: the dashboard onboarding checklist (first-run) and `Catalog > Products` directly (every time after). Same destination, same page — no separate "quickstart" version that diverges from the real flow.
- **Trial is optional and collapsed by default**, never a forced step. A beginner who just wants "a product with a price" should be able to create one in under 30 seconds without ever seeing trial-duration fields.
- **The Product Detail page immediately answers "how do I use this via the API?"** — Overview tab shows the Product ID with a copy button and a link to API reference, closing the loop the brief calls out ("Integrate using API").
- No forced "Review" confirmation screen as a separate page — review happens in a persistent sticky summary card alongside the form (see [§5.2](#52-product-creation-flow)), so users see the shape of what they're creating as they build it, Stripe/Linear-style, rather than being surprised at the end.

---

## 5. Products

### 5.1 Products index

**Layout:** page shell matches `developers/api-keys.tsx` (`max-w` container, `<h1>` + description, primary action top-right) but widened, since this is a browsing/scanning page, not a settings page — recommend `max-w-5xl` or full-width with a constrained content column, not `max-w-2xl`.

**Cards, not a table.** Products carry a name, description, status, and a price summary — enough visual weight that a table row would either truncate everything or get very tall. A card grid (Shopify/Lemon Squeezy-style) reads faster and leaves room for the future product image. Recommend 2–3 columns responsive grid, each card clickable to the detail page.

```
┌──────────────────────────────────────────────────────────────────┐
│  Catalog                                                          │
│  Products                                    [+ Create product]  │
│  What you sell. Add pricing to a product to start billing for it.│
│                                                                    │
│  [ Products ]  [ All prices ]        🔍 Search products…   [Filter ▾] │
│  ──────────────────────────────────────────────────────────────  │
│                                                                    │
│  ┌───────────────────┐  ┌───────────────────┐  ┌───────────────┐ │
│  │ 🟪 Pr              │  │ 🟦 St              │  │ 🟩 En          │ │
│  │ Pro Plan           │  │ Starter            │  │ Enterprise     │ │
│  │ Active · 2 prices  │  │ Active · 1 price   │  │ Active · 0 prices│
│  │ ₦15,000/mo + 1 more│  │ ₦5,000/mo          │  │ —              │ │
│  └───────────────────┘  └───────────────────┘  └───────────────┘ │
└──────────────────────────────────────────────────────────────────┘
```

- **`🟪 Pr` monogram avatar**: auto-generated colored initials tile (deterministic hash of product id → hue), standing in for `image_url` until image upload ships (marked "future" in the brief). Zero-effort, never looks broken, and gives cards visual distinctiveness immediately.
- **Status pill logic** — deliberately simple, two values only (see [Principle 5](#1-principles)):
  - `Active` — `status = active`, regardless of price count. A freshly created product with zero prices still reads "Active" — the price count text ("0 prices") next to it is the only signal, not a separate lifecycle label.
  - `Archived` — `status = archived`, dimmed card, filtered out of the default view.
- **Filter chips**: Status (`All` / `Active` / `Archived`), and `category` as a free-text facet (schema keeps `category` as a plain string — surface it as filter chips generated from whatever values exist, not a managed taxonomy).
- **`All prices` tab**: a flat, searchable table (Name · Product · Amount · Interval · Status) across every product — the future-proofing seam from [§2](#2-information-architecture--navigation). Ships as a simple client-side sort/filter table; no separate page route needed, just a second tab state on the same Inertia page.

### 5.2 Product creation flow

**Decision: dedicated page, single scroll, progressive disclosure. Not a modal, not a multi-step wizard.**

Rationale against the alternatives:
- **Modal** — too small for "name + price + trial + review" without feeling cramped or requiring internal scrolling inside a scrolling page (the exact anti-pattern the brief calls out).
- **Hard multi-step wizard** (separate route per step) — adds forced back/forward navigation for an object users will *edit* constantly afterward; also means building two different layouts (creation wizard vs. edit page) that drift apart over time.
- **Single page, progressive disclosure** — one layout serves both creation and (mostly) editing, sections reveal as earlier ones are satisfied, and the sticky summary gives the "review" step for free, continuously, instead of as a final gate.

```
┌──────────────────────────────────────────────┬─────────────────────┐
│  ← Back to Products                            │  SUMMARY (sticky)  │
│                                                 │                     │
│  Create a product                              │  🟪 Pr              │
│  Products are what you sell — add pricing      │  Pro Plan           │
│  below to start billing for it.                │                     │
│                                                 │  Pricing            │
│  ── Product details ──────────────────────     │   ₦15,000 / month   │
│  Name *              [ Pro Plan            ]   │   Free trial: 14d   │
│  Description         [ For growing teams…  ]   │                     │
│  Category             [ SaaS ▾ ]               │  Not created yet    │
│                                                 │                     │
│  ── Pricing ───────────────────────────────     │                     │
│  How is this billed?                           │                     │
│   ( ) One-time      (•) Recurring              │                     │
│  Interval        [ Monthly ▾ ]                 │                     │
│  Amount          [ ₦ 15,000        ]           │                     │
│  Currency        [ NGN ]  (team default)       │                     │
│                                                 │                     │
│  ▸ Advanced pricing (tiered, custom interval)   │                     │
│                                                 │                     │
│  ── Free trial (optional) ──────────────────    │                     │
│  ☐ Give customers a free trial before billing  │                     │
│     ⤷ expands duration picker when checked      │                     │
│                                                 │                     │
│                              [Create product] →│                     │
└──────────────────────────────────────────────┴─────────────────────┘
```

- **Name is the only hard requirement** to unlock the rest — Pricing section is visually present but visually quieted (lower contrast) until a name exists, then activates. This is the "reveals as satisfied" behavior without literally hiding/showing DOM, which avoids layout jank.
- **A product *can* be created with zero prices** (user scrolls past Pricing without filling it) — it's still simply `Active` on the index, shown with "0 prices," and the Product Detail page's Pricing tab immediately prompts "Add your first price." Beginners are never blocked, but also never confused about why nothing bills yet.
- **"Advanced pricing" is a collapsed disclosure**, not a separate mode — reveals `pricing_model` choice (`Standard` selected by default; `Tiered` is the one alternate model MVP ships per `IMPLEMENTATION.md`), keeping the default path free of a concept most users don't need on day one.
- **Trial checkbox, not trial fields inline.** Keeping the checkbox as the only visible trial affordance during product creation avoids front-loading trial semantics (duration units, "what happens when it ends") into the *first* thing a new user does. The full trial experience lives properly in [§7](#7-trials), reachable here or later from the price row.

### 5.3 Product detail page — the hub

This is the page the brief specifically asks to make rich rather than "just an edit form." Tabs, not one long page — a product accumulates real content over time (subscribers, activity) and tabs keep that scalable.

```
┌────────────────────────────────────────────────────────────────┐
│  ← Products                                                      │
│  🟪  Pro Plan                              [Active ▾] [Edit] [⋯] │
│      For growing teams that need more headroom                  │
│                                                                    │
│  [ Overview ] [ Pricing ] [ Trials ] [ Activity ] [ Metadata ]   │
│  ────────────────────────────────────────────────────────────   │
│                                                                    │
│  OVERVIEW TAB                                                     │
│  ┌─────────────────────┐  ┌─────────────────────────────────┐   │
│  │ API identifiers      │  │ At a glance                     │   │
│  │ Product ID           │  │ 2 active prices                 │   │
│  │ 01HZY3...  [Copy]     │  │ 1 free trial offer              │   │
│  │                       │  │ 0 active subscribers  (Phase 5) │   │
│  │ [View API reference]  │  │                                  │   │
│  └─────────────────────┘  └─────────────────────────────────┘   │
│                                                                    │
│  Default price                                                   │
│  ₦15,000 / month · used when no price is specified at checkout   │
└────────────────────────────────────────────────────────────────┘
```

Tab-by-tab:

| Tab | Contents | Notes |
|---|---|---|
| **Overview** | Description, status control, Product ID + copy, link to API reference, at-a-glance counts, default price | The landing tab; answers "what is this and how do I use it" in one glance |
| **Pricing** | Full list of Price rows (see [§6.3](#63-price-list-inside-product-detail)), "+ Add price" | Primary working tab after creation |
| **Trials** | Trial offers on this product's prices, editable inline | Empty until a price has a trial attached |
| **Activity** | Recent catalog changes: "Price archived," "Trial duration changed 7→14 days" | Audit trail — cheap to add now (append-only log of catalog mutations), pays off enormously once support/finance roles need to answer "why did this change" |
| **Metadata** | Raw `custom_data` key/value editor | Power-user / integration escape hatch, kept out of the way |

Future tabs slot in without restructuring: **Subscribers** (Phase 5) and **Analytics** (later) simply become two more tab triggers in the same `Tabs` component.

**Status control**: a dropdown next to the title (`Active` / `Archive product`), not a separate settings page. Archiving requires a confirmation dialog (matches the existing `revoke-api-key-modal.tsx` / `disconnect-nomba-modal.tsx` pattern) since it hides the product from checkout flows — the dialog explains: *"Archived products won't appear when creating new subscriptions. Existing subscribers are unaffected."*

---

## 6. Prices

### 6.1 Making the pricing model legible

Schema exposes five `pricing_model` values; MVP ships two (`standard`, one tiered model — `IMPLEMENTATION.md` says "ship one tiered model only," `schema.md` build-order notes don't pick which, so this proposal recommends **`graduated`** over `volume`: graduated matches the far more common "first 100 free, next 900 at $0.01, rest at $0.005" mental model users already have from AWS/Twilio-style pricing pages, whereas volume pricing (whole quantity retroactively priced at one tier) is a less intuitive default to explain cold).

The UI never shows the raw enum. It asks one question in plain language:

```
How is this priced?
 (•) A flat amount per period           → pricing_model: standard
 ( ) Different rates by volume          → pricing_model: graduated
       (behind "Advanced pricing" disclosure, see §5.2)
```

### 6.2 Price creation drawer

**Decision: drawer, not a page.** Consistent with the existing `create-api-key-drawer.tsx` pattern, and appropriate here because a Price is a smaller, more frequent creation than a Product — users will add a second, third, fourth price to an existing product routinely (monthly + annual + a regional currency variant), and a full page navigation for that is disproportionate friction.

Progressive disclosure inside the drawer, following the brief's suggested order exactly:

```
┌───────────────────────────────────┐
│  Add a price                    ✕ │
│  Prices define how customers pay  │
│  for Pro Plan.                    │
│  ───────────────────────────────  │
│  Billing interval                 │
│   ( ) One time                    │
│   (•) Recurring                   │
│         [ Monthly ▾ ]  every [1]  │
│                                    │
│  Amount                           │
│   [ ₦  15,000            ]        │
│                                    │
│  Currency                         │
│   [ NGN — team default    ]       │
│   ⓘ To sell in another currency,  │
│     add a separate price.         │
│                                    │
│  ▸ Free trial (optional)          │
│                                    │
│  ── Preview ─────────────────────  │
│  "₦15,000 billed monthly"         │
│                                    │
│              [Cancel] [Add price] │
└───────────────────────────────────┘
```

- **Live preview line** ("₦15,000 billed monthly") updates as fields fill in — this is the single highest-leverage microcopy device for pricing UIs, because it's the sentence a customer will actually see, expressed back to the merchant in their own words instead of raw form fields.
- **Currency is a fixed pill defaulting to team currency**, not an open dropdown, with an inline explainer rather than a disabled control — multi-currency-per-price (`price_currency_options`) is explicitly deferred, so the UI should teach the current mental model ("one price row per currency") rather than hint at a feature that doesn't exist yet.
- **Name field** (`prices.name`, optional per schema) is deliberately *not* in this primary flow — auto-generate a sensible default ("Monthly — ₦15,000") and let it be renamed later from the price row's edit affordance. Forcing users to name a price before they've seen what it looks like is busywork.

### 6.3 Price list inside Product Detail

```
  Pricing                                          [+ Add price]

  ┌────────────────────────────────────────────────────────┐
  │ Monthly                                    [Active ▾]  │
  │ ₦15,000 / month                                        │
  │  └ 🎁 Free trial: 14 days                    [Edit trial]│
  ├────────────────────────────────────────────────────────┤
  │ Annual                                     [Active ▾]  │
  │ ₦150,000 / year   (≈ ₦12,500/mo)                       │
  │  └ No trial                          [+ Add trial]     │
  └────────────────────────────────────────────────────────┘
```

- **`(≈ ₦12,500/mo)` normalized comparison** on any non-monthly recurring price — small addition, large clarity gain, avoids users mentally doing the division themselves to compare plans.
- **Editing amount/interval/currency** is a plain in-place edit for Phase 3 — no lock, no dialog, no version bump. The immutability policy from [Principle 6](#1-principles) only activates once a price has been referenced by a `subscription_item`, which can't happen until Phase 5. Phase 3's edit affordance should still route through a single "update price" action (not scatter direct-mutation calls across the UI) so that Phase 5 can drop the usage-check in front of that one action later — *"Pro Plan Monthly has active subscribers. Changing the amount creates a new price and archives this one — existing subscribers keep their current rate."* — without a rewrite. Non-financial fields (display name) always edit in place regardless of usage, both now and later.
- **Archiving a price** (via the status dropdown) requires confirmation only if it's the product's sole active price ("This is Pro Plan's only price — archiving it will mark the product as needing a price again").

---

## 7. Trials

### 7.1 Simplifying `trial_offers` for the person filling out the form

The schema models a trial offer as its own catalog row referencing **two** prices (`trial_price_id` — a zero-amount recurring price — and `transition_price_id` — the regular price it becomes) plus duration fields. None of that should ever appear as raw fields to a user. The entire experience is one question:

```
▾ Free trial (optional)

  Give customers this much time before you start billing:
   [ 14 ] [ days ▾ ]

  Preview
  ┌─────────────────────────────────────────────┐
  │  Day 0            Day 14           Ongoing   │
  │  Trial starts  →  First charge  →  ₦15,000/mo│
  │  No card required to start the trial.        │  ← if trial_end_behavior allows it (Phase 5)
  └─────────────────────────────────────────────┘

  Customers redeem this trial once — reusing an account
  won't grant a second free period.
```

**Mapping to schema, handled entirely server-side:**

| What the user sees | What gets created |
|---|---|
| Duration `14` `days` | A hidden `trial_price` row: `unit_amount = 0`, `currency` = matching price's currency, `billing_interval = day`, `billing_frequency = 14` |
| (implicit) | `trial_offers.duration_type = relative`, `duration_iterations = 1` — MVP always trials for exactly one period of the chosen length, never "repeat the trial N times" (that concept stays dormant in the schema for a future power-user surface) |
| (implicit) | `transition_price_id` = the price this trial was added from; `transition_to_different_product = false`; `product_id` = that price's product |
| `once_per_customer` | Always `true` for MVP — not exposed as a toggle; it's the safe default and the schema field exists for a future "allow repeat trials" power setting |

This keeps the form to a single duration input while using the schema exactly as designed — nothing here requires a schema change, it's purely an input simplification with sensible defaults filled in server-side.

**Duration unit choices**: `days` / `weeks` / `months` — mapped directly to `billing_interval`. Defaulting the picker to `days` (not `month`) matches how most SaaS trials are actually communicated ("14-day free trial," rarely "1-month free trial") and avoids ambiguity around month-length.

### 7.2 Editing and removing a trial

- **Editing duration** on a trial with no redemptions yet: free edit, updates in place.
- **Removing a trial**: confirmation dialog — *"New subscriptions to Pro Plan Monthly will no longer include a free trial. Customers currently mid-trial are unaffected."* (relies on `subscription_item_trials` snapshotting the offer at redemption time, per schema — safe to state confidently once Phase 5 exists, but worth designing the copy now so it doesn't need reworking later.)
- **Once a trial has live redemptions** (Phase 5+), duration becomes locked for the same reason prices lock — edits would retroactively change a promise already made to a customer. UI disables the field with a tooltip: *"This trial has active customers — create a new trial offer instead of editing this one."* Not enforceable until subscriptions exist, but worth stating the rule now so Phase 5 doesn't have to invent it under time pressure.

---

## 8. Empty states

Every empty state follows the same beat: **what is this → why create one → what happens next → the action.**

### Products index (first-ever visit, zero products)

```
              📦

     Your catalog is empty

  A Product is something you sell — a plan,
  a tier, an add-on. Every Product can carry
  one or more Prices, which is what actually
  decides how a customer gets billed.

  Once you add a Price, your product is
  ready to attach to a subscription — no
  separate "publish" step.

           [ Create your first product ]

  Not sure where to start? A single "Pro"
  product with one monthly price covers
  most launches.
```

### Product Detail → Pricing tab (product created, no price yet)

```
        This product doesn't have a price yet

   Pro Plan can't be subscribed to until it has
   at least one Price — the amount and interval
   a customer is billed.

              [ + Add a price ]
```

### Product Detail → Trials tab (has prices, no trial)

```
        No free trial on this product yet

   A free trial lets new customers use Pro Plan
   before their card is charged. Add one from
   any price below, or skip it — trials are
   entirely optional.

   [ Monthly — ₦15,000/mo    + Add trial ]
   [ Annual  — ₦150,000/yr   + Add trial ]
```

### "All prices" tab, filtered to zero results (search/filter, not true-empty)

```
   No prices match "enterprise"

   [ Clear filters ]
```

(Distinguishing true-empty from filtered-empty matters — a filtered-empty state should never repeat the full educational copy, just offer a way back.)

---

## 9. Success, loading & error states

**Loading**: skeleton cards on Products index (monogram-shaped placeholder + two text bars), skeleton rows in the Pricing tab — never a spinner for list content. A spinner is reserved for in-flight button states only (`Create product` → spinner + "Creating…" while the request is out).

**Success**:
- Product created → toast: *"Pro Plan created"* with a "View product" action button in the toast itself (`sonner` supports action buttons), landing straight on the new Product Detail page anyway, so the toast action is a convenience if they navigated away.
- Price added → toast: *"Monthly price added to Pro Plan"* + the new row animates in with a brief highlight fade (background flashes a soft accent color for ~600ms then settles) rather than just appearing — confirms *which* row is new without a jarring layout jump.
- Trial enabled → inline confirmation, not a toast: the price row's "No trial" label morphs directly into "🎁 Free trial: 14 days" with a quick scale-in on the gift icon. Reserve toasts for actions whose result isn't already visible on screen; this one is.
- Copy Product ID → button icon swaps to a checkmark for ~1.5s (standard copy-affordance pattern), no toast needed for something this low-stakes.

**Errors**:
- Inline field-level validation (amount must be positive, name required) — never a toast for form validation; toasts are for things that happened, not things to fix.
- Server/network failure on save → toast with explicit retry: *"Couldn't save Pro Plan — check your connection and try again"* + a "Retry" action button that resubmits the same payload.
- Archiving a product's only price → blocking confirmation dialog (not a toast), since this changes catalog state other people rely on: *"Pro Plan has no other active price. Archiving this one will mark the product as needing a price. Continue?"*

---

## 10. Microinteractions — consolidated list

| Interaction | Treatment |
|---|---|
| Product created | Toast w/ "View product" action; redirect to detail page |
| Price added | Toast; new row highlight-fade animation |
| Trial enabled | Inline icon/label morph, no toast |
| Copy Product ID / Price ID | Icon → checkmark, 1.5s, no toast |
| Skeleton loading | Shape-matched skeletons for cards/rows, never bare spinners for lists |
| Inline validation | Red helper text under field, shake animation on submit attempt with invalid required field |
| Archiving (destructive-ish) | Confirmation dialog, consistent with `revoke-api-key-modal.tsx` styling |
| Editing a live price's amount | Dialog explaining version-bump behavior before proceeding |
| Live pricing preview | Text updates on every keystroke/selection change in the price drawer, no debounce needed (it's local computation, not a network call) |

---

## 11. Copy deck

**Buttons**
- Primary creation: `Create product` / `Add price` / `Add a free trial`
- Secondary: `Cancel`, `Save changes`, `View product`, `Copy ID`
- Destructive: `Archive product`, `Archive price`, `Remove trial` (never bare "Delete" — archiving is the real operation, and "remove" for trials since it detaches rather than destroys history)

**Tooltips**
- Price count on a product with zero prices: *"This product has no price yet — customers can't subscribe to it until you add one."*
- Currency field: *"Prices are billed in one currency. To offer another currency, create a separate price."*
- Locked price amount: *"This price is live. Change the amount to create a new price and keep this one for existing customers."*
- `once_per_customer` (if ever surfaced): *"A customer can only redeem this trial once, even across multiple subscriptions."*

**Descriptions (page subtitles)**
- Products index: *"What you sell. Add pricing to a product to start billing for it."*
- Product creation: *"Products are what you sell — add pricing below to start billing for it."*
- Price drawer: *"Prices define how customers pay for {product name}."*
- Trials section: *"Give new customers time to try {product name} before they're charged."*

**Validation**
- Empty product name: *"Give your product a name — customers will see this on their invoice."*
- Amount ≤ 0: *"Enter an amount greater than zero."*
- No interval selected on recurring: *"Choose how often this price bills."*

**Success toasts**
- *"{Product} created"*
- *"{Interval} price added to {Product}"*
- *"{Product} archived"*

**Trial explainer (used in both creation and detail views)**
> *"During the trial, {Product} is free. On day {N}, the customer is automatically charged {price} and billing continues on the normal schedule. No separate action needed from you."*

---

## 12. Future-proofing

- **Product images**: `image_url` column already exists; monogram avatars are a full substitute today. Adding upload later is additive — a click on the monogram opens an upload affordance, no layout change required.
- **Analytics / Active Subscribers**: Overview tab's "At a glance" card and a future "Subscribers" / "Analytics" tab are the seams — both are pure additions to the existing `Tabs` component, no restructuring.
- **Multi-currency**: keep teaching "one price row per currency" now (§6.2) so the future `price_currency_options` model, if ever adopted, is additive rather than a re-education. Alternatively, if multi-currency becomes common, the price drawer's currency picker becomes a multi-add ("+ Add another currency" repeating group inside the same drawer) without changing the surrounding page.
- **Tiered/graduated pricing UI**: ships behind "Advanced pricing" now so the visual seam for a tier-editor (add row per tier, `up_to` / `unit_amount` / `flat_amount`) already has a designated home — a table-style tier editor slots into that same disclosure panel.
- **API-identifier prefixing** (`prod_xxx` / `price_xxx` style instead of raw ULIDs): out of scope for this phase (affects IDs platform-wide, not just Catalog), but flagged here since Product Detail's "Copy Product ID" affordance is the first place a raw ULID becomes developer-visible — worth a platform-level decision before Phase 10 (public API surface) if Stripe-grade developer polish matters.
- **Discounts** (deferred per `IMPLEMENTATION.md`): when built, they attach at the subscription/price level per schema — Catalog's job is done once Prices exist; no Catalog IA changes anticipated.

---

## 13. Schema-adjacent notes — resolved

Settled during review. None require edits to `schema.md`/`IMPLEMENTATION.md` (kept standalone in this doc), but recorded here so these questions don't get re-litigated later.

1. **Catalog has no `mode` (test/live) column, and that's intentional — confirmed.** Mode only ever changes which Nomba/API credentials are used to charge; the catalog itself (Products/Prices/Trials) is shared across test and live. No sandboxed-catalog concept planned. Revisit only if that changes. See [Principle 2](#1-principles).
2. **No draft status — confirmed.** A product is `active` or `archived`, nothing else. The original proposal's derived "Needs a price" pseudo-state is dropped entirely — simpler, and matches how the team thinks about it. See [Principle 5](#1-principles) and [§5.1](#51-products-index).
3. **Price immutability trigger, clarified — confirmed.** The lock isn't "once active," it's "once used" — specifically, once a `subscription_item` references the price. Until Phase 5 ships subscriptions, every price stays freely editable in place; Phase 3 doesn't build any lock/version-bump UI, it just funnels edits through a single update path so Phase 5 can add the usage-check there later. See [Principle 6](#1-principles) and [§6.3](#63-price-list-inside-product-detail).
4. **Graduated over volume — confirmed** as the one MVP tiered model, for the legibility reasons in [§6.1](#61-making-the-pricing-model-legible). `IMPLEMENTATION.md`'s Phase 3 line ("ship one tiered model only") can be read as graduated going forward, even though that file itself isn't being edited here.

---

## 14. Build sequencing

Mapped to `IMPLEMENTATION.md`'s Phase 3 exit criteria ("Team creates 'Pro' product with monthly price and optional free trial offer"), in the order that produces a demoable increment fastest:

1. **Migrations/models** — `products`, `prices`, `price_tiers` (standard only functioning first; graduated tiers can land as a fast-follow within the phase), `trial_offers`.
2. **Products index + creation page** ([§5.1](#51-products-index), [§5.2](#52-product-creation-flow)) — without pricing/trial sections wired yet, just Name/Description/Category → gets a Product row persisting end-to-end first.
3. **Product Detail (Overview + Pricing tabs)** ([§5.3](#53-product-detail-page--the-hub)) — hub page navigable, even with an empty Pricing tab.
4. **Price creation drawer** ([§6.2](#62-price-creation-drawer)) — standard pricing model only first; wire into both the Product creation page's Pricing section and the Product Detail Pricing tab (same underlying component).
5. **Trial creation** ([§7.1](#71-simplifying-trial_offers-for-the-person-filling-out-the-form)) — the duration-only form + server-side mapping to `trial_offers`/hidden trial price.
6. **Empty states across all three** ([§8](#8-empty-states)) — cheap once the pages exist, disproportionately important for the demo narrative.
7. **Graduated pricing model + "Advanced pricing" disclosure** — last, since it's explicitly the harder/optional half of the tiered requirement.
8. **Activity tab, "All prices" tab** — nice-to-haves that don't block the Phase 3 exit criteria; sequence after if time allows, otherwise carry into Phase 13 polish.
