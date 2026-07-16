# Bouclay — Catalog Design Proposal (Phase 3)

> ## ⚠ Historical — two generations out of date (noted 2026-07-16)
>
> This was written as a **proposal** before the catalog was built. It was then
> built (V1 Phase 3) and **reshaped again by V2-1**, so the model below is not
> what the code does:
>
> | This doc says | Reality now |
> |---|---|
> | `products → prices` (flat) | `products → plans → prices` — a **plan** is the tier ("Premium"), a price its billable variant |
> | `trial_offers` as a catalog object | **Deleted.** Simple trials live on `prices.trial_length`/`trial_unit`; ramps use `price_phases`; anti-abuse via `price_trial_redemptions` |
> | prices are mutable, with a `version` counter | **Immutable once used.** An edit creates a new row (`replaces_price_id`) and archives the old one — a subscriber's price never changes under them |
> | — | `entitlements` grant capabilities from plans/products (V2-5) |
>
> **`schema.md` is the authority.** Read this only for the UX reasoning and the
> "why", which mostly still holds — not for the data model.


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
7. **Trials are scoped to a product, not a sibling top-level object — but the trial price is a real price.** `trial_offers.product_id` means a trial is always created *from* a specific product's page — never from a standalone "New Trial" entry point floating in the nav, and never with a Product picker in the form (see [§7](#7-trials)). But `trial_price_id` and `transition_price_id` are both ordinary, independently-visible catalog prices — nothing about a price is hidden because a trial happens to reference it.

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
   │  Section 3: Review & sticky summary card   (always visible, fills in live)
   ▼
 [Create product] → toast: "Pro Plan is live"
   ▼
 Product Detail page (Pro Plan)
   │  Overview / API identifiers visible immediately
   │  "Copy Product ID" + "View in API docs" nudge
   │  Trials section: "+ Create trial" once at least one price exists
   ▼
 Dashboard checklist auto-ticks "Create your first product"
   │  Next suggested step surfaces: "Add a customer" (Phase 4, greyed/coming-soon until then)
```

Key journey decisions:

- **Product creation is reachable from two places**: the dashboard onboarding checklist (first-run) and `Catalog > Products` directly (every time after). Same destination, same page — no separate "quickstart" version that diverges from the real flow.
- **Trials are never part of product or price creation** — a trial needs a real trial price to reference (see [§7](#7-trials)), which doesn't exist until at least one price has been created. Trials are created afterward, from the product page's own Trials section, keeping the creation flow focused on "what am I selling" without front-loading a second concept.
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
- **Modal** — too small for "name + price + review" without feeling cramped or requiring internal scrolling inside a scrolling page (the exact anti-pattern the brief calls out).
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
│  Name *              [ Pro Plan            ]   │                     │
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
│                              [Create product] →│                     │
└──────────────────────────────────────────────┴─────────────────────┘
```

- **Name is the only hard requirement** to unlock the rest — Pricing section is visually present but visually quieted (lower contrast) until a name exists, then activates. This is the "reveals as satisfied" behavior without literally hiding/showing DOM, which avoids layout jank.
- **A product *can* be created with zero prices** (user scrolls past Pricing without filling it) — it's still simply `Active` on the index, shown with "0 prices," and the Product Detail page's Pricing tab immediately prompts "Add your first price." Beginners are never blocked, but also never confused about why nothing bills yet.
- **"Advanced pricing" is a collapsed disclosure**, not a separate mode — reveals `pricing_model` choice (`Standard` selected by default; `Graduated` is the one alternate model MVP ships per `IMPLEMENTATION.md`), keeping the default path free of a concept most users don't need on day one.
- **No trial step here at all.** Trials reference a real trial price (see [§7](#7-trials)), which can't exist before at least one price does. Bundling trial creation into product/price creation would mean either creating a throwaway placeholder price or blocking on a half-finished trial form — both worse than just making trial creation its own short, focused step immediately available on the product page once a price exists.

### 5.3 Product detail page — the hub

This is the page the brief specifically asks to make rich rather than "just an edit form." Tabs, not one long page — a product accumulates real content over time (subscribers, activity) and tabs keep that scalable.

**Revision**: this originally shipped as a `Tabs` layout (Overview / Pricing / Trials / Metadata as separate panels). It was rebuilt as a **single scrollable page** — sections stacked with dividers instead of tabs — so a user never loses the rest of the product while looking at one part of it, and every section is a search-engine/scan-friendly part of the same view rather than hidden behind a click. The wireframe and section list below reflect the current, built version.

```
┌────────────────────────────────────────────────────────────────┐
│  ← Products                                                      │
│  🟪  Pro Plan                              [Active] [Edit] [⋯]   │
│      prod_xxx  [Copy]                                            │
│      For growing teams that need more headroom                  │
├────────────────────────────────────────────────────────────────┤
│  Product information                                             │
│  Category      Created        Active prices   Active subscribers│
│  SaaS          Jul 3, 2026     2                0 (coming soon)  │
├────────────────────────────────────────────────────────────────┤
│  Pricing                                          [+ Add price] │
│  Products are containers — prices define how customers bill.    │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ Monthly            Active      NGN 15,000/mo    Edit Archive│ │
│  │  + Add trial                                               │ │
│  ├──────────────────────────────────────────────────────────┤   │
│  │ Annual             Active      NGN 150,000/yr   Edit Archive│ │
│  │  Trial "Free trial offer" leads here                        │ │
│  └──────────────────────────────────────────────────────────┘   │
├────────────────────────────────────────────────────────────────┤
│  Trials                                          [+ Create trial]│
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ 🎁 Free trial offer   Active     Edit  Remove               │ │
│  │    Monthly → Annual                                         │ │
│  └──────────────────────────────────────────────────────────┘   │
├────────────────────────────────────────────────────────────────┤
│  Metadata                                              [Edit]   │
│  external_id                                        acme-987   │
└────────────────────────────────────────────────────────────────┘
```

Section-by-section:

| Section | Contents | Notes |
|---|---|---|
| **Header** | Name, status badge, `prod_xxx` public ID + copy, description, Edit / Archive actions | Answers "what is this" in the first glance |
| **Product information** | Category, created date, active-price count, subscriber count (Phase 5 placeholder) | Compact stats row, not a full tab of its own |
| **Pricing** | Full list of Price rows (see [§6.3](#63-price-list-inside-product-detail)), "+ Add price"; a price used as a trial's trial price shows a badge, a price a trial transitions into shows an inline note | The primary section — biggest visual weight, most-used |
| **Trials** | Every `trial_offers` row on this product — name, trial price, → transition target, repeat count, Edit / Remove; "+ Create trial" opens the same drawer used from a price row (see [§7](#7-trials)) | Independent of Pricing's per-row "+ Add trial" shortcuts — this is the full list |
| **Metadata** | `custom_data` key/value list, "Edit" opens a drawer with dynamic add/remove rows | Never hidden behind navigation — scrolls into view like everything else |

Future sections (Subscribers, Analytics, Audit history) slot in the same way: another `<section>` between dividers, no restructuring, no new nav.

**Status control**: `Active`/`Archived` badge in the header, with Archive/Reactivate in the `⋯` dropdown next to "Edit" — not a separate settings page. Archiving requires a confirmation dialog (matches the existing `revoke-api-key-modal.tsx` / `disconnect-nomba-modal.tsx` pattern) since it hides the product from checkout flows — the dialog explains: *"Archived products won't appear when creating new subscriptions. Existing subscribers are unaffected."*

**Every edit is a side drawer, never a navigation.** Edit Product, Edit Price, Create/Edit Trial, and Edit Metadata all open the same `Sheet`-based drawer used elsewhere in the app (matching `create-price-drawer.tsx`'s pattern) — the product page itself never navigates away or reloads. Only destructive confirmations (Archive, Remove) use a centered dialog, matching the existing `revoke-api-key-modal.tsx` convention for that category of action specifically.

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

**Revision history**: §7.1 originally shipped a deliberately narrowed trial model — free-only, hidden auto-generated trial price, no product transition, no repeat — matching the "Defer" list in `IMPLEMENTATION.md`'s Phase 3 section. On review, that list was never actually scheduled for a later phase (the only other place it's mentioned is Phase 13, "Polish & defer bucket," which just restates it as "logic later" with no phase attached — see the Phase 3 discussion this section now reflects). Given trials are meant to mirror Stripe's Trial Offer model, and the schema already supports all of it with zero migrations, the simplification was pulled back out. What follows is the **current, full model**; the section below preserves why each field maps the way it does.

### 7.1 The full `trial_offers` shape, exposed directly

A trial offer references **two real, independently-managed prices** — not a hidden one the system invents:

- **Trial price** (`trial_price_id`) — what the customer pays *during* the trial. A normal catalog price, visible in the Pricing tab like any other. It is **not necessarily free** — `unit_amount = 0` is just one possible price, same as Stripe's "$1 for 3 months" intro-rate pattern.
- **Transition price** (`transition_price_id`) — what the customer moves to once the trial period ends. Defaults to the price the trial was launched from (via a price row's "+ Add trial" shortcut), but can be any active price on the product.
- **Transition product** (`transition_product_id` / `transition_to_different_product`) — optionally, the trial can hand the customer off to a **different product** entirely (an upsell/downsell pattern), in which case the transition price is picked from that other product's price list instead.
- **Repeat** (`duration_iterations`) — the trial price's own billing interval defines the cadence (e.g. "$1/month"); `duration_iterations` says how many times that cadence repeats before transitioning (1 = a single period, 3 = "$1/month for 3 months"). `duration_type` stays `relative` for MVP — `timestamp` (an absolute end date instead of N periods) is the one piece still genuinely deferred, since it's a different UI shape entirely and wasn't part of this round's ask.
- **Name** (`trial_offers.name`) — a real, user-edited field ("This will appear on customers' receipts and invoices"), not auto-generated.

```
Create trial
────────────
Name
This will appear on customers' receipts and invoices.
[ Free trial offer                              ]

Trial price
[ Choose a price                              ▾ ]
What customers pay during the trial — a normal price, free or paid.

☐ Transition to a different product when trial ends

  Product when trial ends           (shown only if checked)
  [ Choose a product                            ▾ ]

Price when trial ends
[ Choose a price                               ▾ ]

☐ Repeat
  Repeat [ 1 ] times.                (shown only if checked)

                                    [ Cancel ]  [ Create ]
```

Product is never a field in this form — a trial is always created **from** a specific product's page and is implicitly scoped to it (`trial_offers.product_id`). This is a deliberate divergence from Stripe's own modal, which shows Product as a top-level dropdown because Stripe's trial offers aren't page-scoped the way ours are; ours don't need that dropdown because the context already answers it.

**Picker scope**: "Trial price" and the same-product "Price when trial ends" only list the current product's own prices. The "Product when trial ends" dropdown searches the team's *other* active products; once one is picked, "Price when trial ends" repopulates from that product's prices instead.

**A price is a price, full stop**: because the trial price is a normal row, the Pricing tab shows a small badge on any price currently serving as a trial price ("Trial price for {trial name}"), and a price a trial transitions *into* shows an inline note ("Trial '{trial name}' leads here"). Neither hides the price from the list or from being picked for anything else — a price can be a regular price, a trial price, and a transition target all at once if that's genuinely what the catalog looks like.

### 7.2 Editing and removing a trial

- **Editing** (name, trial price, transition target, repeat count) is a free, in-place edit for as long as the trial has no redemptions — same "no lock until used" posture as prices (Principle 6). No hidden price to reconcile: editing the trial price just repoints `trial_price_id` at a different existing price.
- **Removing a trial** deletes the `trial_offers` row only — it **never** deletes the trial price or transition price, since both are real, independently-owned catalog prices that may be referenced elsewhere. Confirmation copy: *"New subscriptions to {product} will no longer include this trial. Customers currently mid-trial are unaffected."* (relies on `subscription_item_trials` snapshotting the offer at redemption time, per schema — safe to state confidently once Phase 5 exists.)
- **Once a trial has live redemptions** (Phase 5+), the same fields lock for the same reason prices lock — edits would retroactively change a promise already made to a customer. Not enforceable until subscriptions exist, but worth stating the rule now so Phase 5 doesn't have to invent it under time pressure.

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

### Product Detail → Trials section (has prices, no trials)

```
        No trials on this product yet

   Trials are entirely optional — create one
   whenever you're ready.

                              [ + Create trial ]
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
- Trial created → toast: *"Trial created"*, and the Trials section (already on-screen, since the drawer never navigates away) gains the new row directly.
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
| Trial created | Toast; new row appears in the on-screen Trials section |
| Copy Product ID / Price ID | Icon → checkmark, 1.5s, no toast |
| Skeleton loading | Shape-matched skeletons for cards/rows, never bare spinners for lists |
| Inline validation | Red helper text under field, shake animation on submit attempt with invalid required field |
| Archiving (destructive-ish) | Confirmation dialog, consistent with `revoke-api-key-modal.tsx` styling |
| Editing a live price's amount | Dialog explaining version-bump behavior before proceeding |
| Live pricing preview | Text updates on every keystroke/selection change in the price drawer, no debounce needed (it's local computation, not a network call) |

---

## 11. Copy deck

**Buttons**
- Primary creation: `Create product` / `Add price` / `Create trial`
- Secondary: `Cancel`, `Save changes`, `View product`, `Copy ID`
- Destructive: `Archive product`, `Archive price`, `Remove` (trials — never bare "Delete"; archiving/removing is the real operation, and "remove" for trials since it detaches the offer rather than destroying the prices it references)

**Tooltips**
- Price count on a product with zero prices: *"This product has no price yet — customers can't subscribe to it until you add one."*
- Currency field: *"Prices are billed in one currency. To offer another currency, create a separate price."*
- Locked price amount: *"This price has active subscribers. Change the amount to create a new price and keep this one for existing customers."* (Phase 5+, once subscriptions exist — see Principle 6)
- Trial price picker: *"What customers pay during the trial — a normal price, free or paid."*

**Descriptions (page subtitles)**
- Products index: *"What you sell. Add pricing to a product to start billing for it."*
- Product creation: *"Products are what you sell — add pricing below to start billing for it."*
- Price drawer: *"Prices define how customers pay for {product name}."*
- Trial drawer: *"Automatically attached to {product name}."*

**Validation**
- Empty product name: *"Give your product a name — customers will see this on their invoice."*
- Amount ≤ 0: *"Enter an amount greater than zero."*
- No interval selected on recurring: *"Choose how often this price bills."*
- Trial and transition price the same: *"Choose a different price for when the trial ends."*

**Success toasts**
- *"{Product} created"*
- *"{Interval} price added to {Product}"*
- *"{Product} archived"*
- *"Trial created"* / *"Trial updated"* / *"Trial removed"*

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
2. **Products index + creation page** ([§5.1](#51-products-index), [§5.2](#52-product-creation-flow)) — without a pricing section wired yet, just Name/Description/Category → gets a Product row persisting end-to-end first.
3. **Product Detail page** ([§5.3](#53-product-detail-page--the-hub)) — single-scroll hub navigable, even with an empty Pricing section.
4. **Price creation drawer** ([§6.2](#62-price-creation-drawer)) — standard pricing model only first; wire into both the Product creation page's Pricing section and the Product Detail Pricing section (same underlying component).
5. **Trial creation** ([§7.1](#71-the-full-trial_offers-shape-exposed-directly)) — name, trial-price picker, transition-to-different-product, repeat; server-side mapping straight onto `trial_offers` (no hidden price to synthesize).
6. **Empty states across all three** ([§8](#8-empty-states)) — cheap once the pages exist, disproportionately important for the demo narrative.
7. **Graduated pricing model + "Advanced pricing" disclosure** — last, since it's explicitly the harder/optional half of the tiered requirement.
8. **Metadata editor, "All prices" tab** — nice-to-haves that don't block the Phase 3 exit criteria; sequence after if time allows, otherwise carry into Phase 13 polish.
