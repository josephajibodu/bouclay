# Bouclay — Customers & Payment Methods Design Proposal (Phase 4)

**Status:** Phase 4 core is built; Phase 5–6 hub sections are wired. This doc remains the original design proposal — see [`IMPLEMENTATION.md`](IMPLEMENTATION.md) and [`schema.md`](schema.md) § Dashboard vocabulary for live behaviour. Standalone companion to [`IMPLEMENTATION.md`](IMPLEMENTATION.md) (Phase 4 section) and [`schema.md`](schema.md) (§2 Customers & Payment Methods). Does not change either document — schema-adjacent observations are called out in [§14](#14-schema-adjacent-notes--open-questions) for you to decide, not applied.

**Implemented (2026-07-06):** customer hub **Subscriptions** and **Invoices** sections are live (invoice rows from `Invoice::toDashboardArray()`, rows link to `/invoices/{id}`). **Total spend** is in the Overview grid. There is no "Transactions" section — Bouclay uses **Invoice** (billing record) and **Payment** (charge attempt) per `schema.md`.

**Stack this proposal is grounded in:** Inertia + React 19 + TypeScript, shadcn/ui-on-Radix (`resources/js/components/ui/*`), Tailwind v4 (`@theme` tokens, no `tailwind.config.js`), `lucide-react` icons, `sonner` toasts, Laravel Wayfinder route helpers. Everything below reuses the patterns Phase 3 (Catalog) and Phase 2 (Developers) already established — the Products index table (`resources/js/pages/catalog/products.tsx`), the single-scroll Product detail with side drawers (`resources/js/pages/catalog/show.tsx`), the `Actions` dropdown, and the copy-ID check-swap microinteraction. A first-time user should not be able to tell Customers was designed by a different team than Catalog.

**Design north star:** the depth and hub-thinking of **Stripe Customers**, the restraint and calm of **Paddle**. When the two conflict, Stripe wins on the *detail page*, Paddle wins on the *list and filters*.

---

## Contents

1. [Principles](#1-principles)
2. [Information architecture & navigation](#2-information-architecture--navigation)
3. [The mental model: Customer → Payment method → Subscription-ready](#3-the-mental-model-customer--payment-method--subscription-ready)
4. [The customer lifecycle journey](#4-the-customer-lifecycle-journey)
5. [Customers list](#5-customers-list)
6. [Customer creation](#6-customer-creation)
7. [Customer detail page — the hub](#7-customer-detail-page--the-hub)
8. [Edit customer](#8-edit-customer)
9. [Addresses](#9-addresses)
10. [Payment methods & the tokenization journey](#10-payment-methods--the-tokenization-journey)
11. [Placeholder sections (Subscriptions, Invoices)](#11-placeholder-sections)
12. [Activity timeline](#12-activity-timeline)
13. [Empty, loading, success & error states](#13-empty-loading-success--error-states)
14. [Schema-adjacent notes & open questions](#14-schema-adjacent-notes--open-questions)
15. [Microinteractions — consolidated list](#15-microinteractions--consolidated-list)
16. [Copy deck](#16-copy-deck)
17. [Future-proofing](#17-future-proofing)
18. [Build sequencing](#18-build-sequencing)

---

## 1. Principles

The load-bearing decisions everything else derives from.

1. **A customer is a billing subject, not a CRM contact.** Bouclay stores who you bill and how you bill them — nothing else. No notes, no tasks, no deal stages, no lifecycle-marketing fields. Every field on the page has to earn its place by being *required to send money*. When in doubt, cut it. This is the single decision that keeps Bouclay feeling like Stripe and not like HubSpot.

2. **The detail page is the product; the list is the index to it.** Most of the design budget goes into the Customer detail page, because that is where a team member actually *does* things — adds a card, checks why a payment failed, opens a subscription. The list exists only to find the right customer quickly. This is why the list stays deliberately Paddle-thin while the detail page grows Stripe-rich.

3. **Bouclay never touches raw card data — and says so, visibly.** The most important trust signal in this entire phase is that PANs are entered on Nomba's surface, tokenized by Nomba, and only a *token + safe metadata* (brand, last4, expiry) ever reach Bouclay. This is stated in plain language at the exact moment it matters (the "Add payment method" empty state and the checkout hand-off), not buried in a footer. Security messaging that appears only where it's relevant reads as confidence; security messaging everywhere reads as anxiety.

4. **Mode-agnostic, like the catalog — confirmed.** Customers, addresses, and payment methods are **shared** across test and live, exactly as Products/Prices are. There is no test/live toggle on any Customers page. What differs by mode is only the *credentials used to tokenize and charge* (`team_processor_connections`), never the customer record. A card tokenized with test keys carries a test token; the record it lives on is the same record. We surface this with a small **Test** tag on payment methods created against test keys (see [§10.7](#107-test-vs-live-payment-methods)) rather than forking the whole section. One fewer axis of confusion than Stripe.

5. **Progressive disclosure over paperwork.** Creating a customer asks for the *minimum* — name and email — and lets everything else (phone, address, currency, metadata) be added later from the detail page, where there's room to do it well. A customer created in five seconds and enriched later beats a customer that never gets created because the form looked like a tax return.

6. **No dead-end empty states.** Every empty region teaches its concept before asking for the action. "No payment methods" explains what a payment method *is*, that Nomba tokenizes it, that Bouclay never sees the card, and what becomes possible once one exists. Nobody should hit an empty section and wonder why it's there.

7. **Design the whole page now, wire the ready parts.** Subscriptions and Invoices did not exist until Phases 5–6, but their sections appeared on the detail page *today* — as intentional, well-copywritten placeholders with disabled primary actions and "Coming soon" affordances. This is the difference between a product that feels *staged* and one that feels *unfinished*. The page's skeleton is its final skeleton; later phases fill cells, they don't move walls. *(Both sections are now live — see §11.)*

8. **Archive, never delete (in the UI).** `customers` carries `deleted_at` (SoftDeletes). The destructive action a team member sees is **Archive** — reversible, non-scary, consistent with how Products already behave. Hard deletion is not a dashboard affordance. This matches the "Active / Archived" status filter in your screenshots.

---

## 2. Information architecture & navigation

### Recommendation: `Customers` as a single top-level nav item, no children.

```
Overview
Catalog                          ← existing (Phase 3)
  └ Products
Customers                        ← new (Phase 4), positioned below Catalog, above Developers
Developers
  ├ Nomba Integration
  ├ API Keys
  └ Webhooks
Settings
```

The full arc, so the IA visibly anticipates later phases:

```
Overview
Catalog        → Products
Customers      ← Phase 4  (the hub every later object hangs off)
Subscriptions  ← Phase 5
Invoices       ← Phase 6
Developers
Settings
```

**Why `Customers` is flat (no collapsible children), unlike Catalog and Developers.** Payment methods and addresses are *never* browsed as their own top-level collections — you always reach a card *through* the customer who owns it (`payment_methods.customer_id` is required). A "Payment methods" nav item would be a list nobody starts from. This mirrors the Catalog reasoning for not giving Prices their own nav entry: sub-objects that can't exist independently of a parent don't get independent navigation. So `Customers` is a plain `NavItem` with an icon and an `href`, not a collapsible group.

**Position: directly below `Catalog`.** The natural reading order of the sidebar becomes the natural order of the work: *define what you sell (Catalog) → define who you sell to (Customers) → connect them (Subscriptions)*. This is the arc the brief describes, made spatial.

**Icon:** `Users` from `lucide-react` (the plural — a collection of people), reserving `User` (singular) for the detail-page avatar/monogram context. Catalog uses `Package`; Developers uses `Code2`; `Users` sits cleanly beside them.

**Test/Live:** no toggle here (see [Principle 4](#1-principles)). Worth a one-line hint the first time a team lands on the empty Customers list, so the *absence* reads as intentional, matching how Catalog handles the same difference from Developers.

### Sidebar implementation note

Add to `resources/js/components/app-sidebar.tsx` as a sibling of the `Catalog` entry — but as a **flat** item (no `items` array), gated by `teamPermissions.canViewCustomers` (maps to the seeded `customers.view` permission). Route group lives in a new `routes/customers.php`, required from `web.php` the same way `routes/catalog.php` is, under `Route::prefix('{current_team}/customers')->name('customers.')`. Wayfinder will emit `resources/js/routes/customers/*` to match.

```
// pattern, mirroring the existing Catalog block
...(currentTeam && teamPermissions?.canViewCustomers
    ? [{ title: 'Customers', href: customersIndex(), icon: Users }]
    : []),
```

**Permission gating throughout this phase:**
- `customers.view` → can open the list and detail pages, read everything.
- `customers.manage` → can create, edit, archive, add/remove addresses and payment methods, set defaults.

These already exist in the RBAC seed (schema.md → `customers.view`, `customers.manage`, held by **Admin**, **Invoicing**; `customers.view` also by **Support**). No new permissions needed. Read-only viewers (Support) see a fully populated page with every mutating control absent or disabled — never a broken one.

---

## 3. The mental model: Customer → Payment method → Subscription-ready

The one thing this phase has to teach. Reused verbatim (or near) in the list empty state and the payment-methods empty state:

```
┌───────────────────────────────────────────────┐
│  CUSTOMER                          "Who you bill"
│  Joseph Ajibodu · jo.ajibodu@…                 │
│                                                │
│   ┌──────────────────────────────────────────┐ │
│   │ PAYMENT METHOD          "How you charge"  │ │
│   │ Visa ···· 4242 · exp 08/28 · Default      │ │
│   │  └─ tokenized by Nomba, not stored here   │ │
│   └──────────────────────────────────────────┘ │
│                                                │
│   ┌──────────────────────────────────────────┐ │
│   │ ADDRESS                 "Where they're    │ │
│   │ Akobo, Ibadan · Oyo · NG    billed"       │ │
│   └──────────────────────────────────────────┘ │
│                                                │
│   Once a customer has a payment method,        │
│   they're ready to subscribe.        ← Phase 5 │
└───────────────────────────────────────────────┘
```

Three sentences the whole phase is built to make obvious:

- A **customer** is who you bill.
- A **payment method** is how you charge them — a Nomba *token*, never a card number Bouclay holds.
- A customer with a payment method is **subscription-ready** — which is the entire point of creating them.

The last line is load-bearing. Customers in Bouclay are not an address book; they exist so subscriptions and charges can happen. Every empty state points forward to that.

---

## 4. The customer lifecycle journey

Picking up where Catalog leaves off (products + prices exist, Nomba connected). The arc the brief asked for, mapped to concrete screens:

```
 Overview / Onboarding checklist
   │   "Add your first customer"  ⏵
   ▼
 Customers list (empty state — teaches the concept)
   │   "Create customer" primary action
   ▼
 Create customer  (side drawer: name + email, that's it)
   │   success toast → lands on the new Customer detail page
   ▼
 Customer detail  (the hub — mostly empty, clearly signposted)
   │   Payment methods section: "Add payment method"
   ▼
 Add payment method → launch Nomba secure checkout
   │   card entered on Nomba's surface → Nomba tokenizes
   ▼
 Success: card appears under the customer, auto-set as default
   │
   ▼
 Customer is subscription-ready
   │
   ▼   ┄┄┄┄┄┄┄┄┄┄┄┄ Phase 5 ┄┄┄┄┄┄┄┄┄┄┄┄
 "Create subscription" (today: visible, disabled, "Coming soon")
```

The friction budget is spent almost entirely on **step 4 (tokenization)** because that's the only genuinely hard part — everything else is a name, an email, and a page that reads well. Creating the customer itself must feel like nothing.

---

## 5. Customers list

Deliberately the *thinnest* page in the phase. Paddle, not Stripe: search, one status filter, bulk select, done. No saved segments, no "High refunds / High disputes / Remaining balances" preset tabs (those are Stripe's, and they need data this phase doesn't produce yet). We can add segment tabs later without an IA change — see [§17](#17-future-proofing).

### 5.1 Layout

Reuses the Products index shell exactly (`max-w-5xl`, header with title + description + primary action, filter row, bordered table, count footer).

```
┌────────────────────────────────────────────────────────────────────┐
│  Customers                                          [ + Create ]     │
│  The people and businesses you bill.                                 │
│                                                                      │
│  ┌──────────────────────────────┐  [ Status: Active ▾ ]             │
│  │ 🔍  Search name or email…    │                                    │
│  └──────────────────────────────┘                                    │
│                                                                      │
│  ┌──┬──────────────────────┬─────────────────────┬──────────┬─────┐ │
│  │▢ │ Customer             │ Email               │ Created  │  ⋯  │ │
│  ├──┼──────────────────────┼─────────────────────┼──────────┼─────┤ │
│  │▢ │ ◐ Joseph Ajibodu     │ jo.ajibodu@gmail.com│ Jul 4    │  ⋯  │ │
│  │▢ │ ◐ Amelia Karteh      │ kartehameli@gmail.… │ Jul 4    │  ⋯  │ │
│  │▢ │ ◐ Oluwabusayo Ogun…  │ ogunsijibusayo@gm…  │ Jul 3    │  ⋯  │ │
│  └──┴──────────────────────┴─────────────────────┴──────────┴─────┘ │
│  3 customers                                                         │
└────────────────────────────────────────────────────────────────────┘
```

**Columns (kept to five, Paddle-minimal):**

| Column | Content | Notes |
|---|---|---|
| ▢ | Row checkbox | Header checkbox = select-all-on-page |
| Customer | Monogram + name | Falls back to email if `name` is null (schema allows null name); monogram derives from name-or-email initial, reusing `ProductMonogram`'s deterministic color hash as a `CustomerMonogram` |
| Email | `email` | The one field that is never null — the reliable identity anchor |
| Created | `created_at` | Relative-ish short date (`Jul 4`), full timestamp on hover title |
| ⋯ | Row action menu | Copy ID, Edit, Archive |

**Explicitly *not* columns:** Total spend, Payment method count, Subscription count. Your Stripe screenshot shows "Total spend" — but that requires `payments` (Phase 6). Adding a column that reads `—` or `$0.00` for every row at this stage is noise that teaches users to ignore the column. We add **Total spend** and **Subscriptions** columns in Phase 6/5 respectively, when they carry signal. Designing the table to accept a 6th/7th column later is trivial; showing empty columns now is a cost with no benefit.

### 5.2 Search

Single input, client-side filter for the MVP dataset (same approach as `products.tsx`), matching on `name` **and** `email` (case-insensitive `includes`). Placeholder: `Search name or email…`. When search yields zero rows against a non-empty list, show the filtered-empty state (not the true-empty state) — see [§13](#13-empty-loading-success--error-states).

> **Scale note.** Client-side filtering matches the current Products implementation and is fine for hundreds of rows. Customers is the first table that will plausibly reach thousands. [§14](#14-schema-adjacent-notes--open-questions) flags the seam for server-side search + cursor pagination; the *UI* here (one search box, one status filter) is identical either way, so the visual design doesn't change when the backend does.

### 5.3 Status filter

Exactly the Paddle control in your third screenshot — a single **Status** dropdown, default **Active**, options **Active** / **Archived**. Not "All statuses" as the default: a team overwhelmingly wants to see live customers, and archived ones are the exception they occasionally go looking for. (This is a deliberate divergence from the Products filter, which defaults to "All" — Products are rarely archived; customers churn and get archived routinely, so a live-by-default view is the calmer daily state.)

Implemented as a shadcn `Select` (matching `products.tsx`), or, to match the Paddle checkbox-popover in your screenshot precisely, a `DropdownMenu` with checkbox items. Recommend the `Select` for MVP — one active status at a time is the real need; multi-select archived+active is a non-goal.

### 5.4 Bulk selection & actions

This is the one place the list earns a Stripe-grade interaction, because your screenshots show it and it's genuinely useful. Row checkboxes + a header select-all. When ≥1 row is selected, a **floating action bar** docks at the bottom-center of the viewport (exactly your second screenshot):

```
        ┌──────────────────────────────────────────┐
        │  3 selected           [ 🗑 Archive ]   ✕  │
        └──────────────────────────────────────────┘
```

- **Copy:** the destructive verb is **Archive**, not **Delete** (your screenshot says "Delete" in red — I'd change that to **Archive** to match the soft-delete reality and the single-customer action menu; a user shouldn't learn two different words for the same operation).
- **Confirmation:** bulk archive opens a confirm dialog (`Dialog`) — *"Archive 3 customers? They'll stop appearing in your active list and can't be subscribed to new plans. You can restore them anytime."* Single-customer archive from the row menu confirms inline the same way.
- **✕** clears the selection (also `Esc`).
- The bar animates in from below (subtle translate-y + fade), matching the premium feel of the onboarding widget transitions already in the app.
- Gated on `customers.manage`; a viewer sees checkboxes disabled (or absent) so they never select-then-discover they can't act.

**Selection scope caveat.** "Select all" selects the current page/filtered set, and the bar labels it precisely — *"3 selected"*, never an ambiguous "All". If pagination lands, add the Stripe-style *"Select all N matching"* affordance inside the bar; until then, page-scoped selection with an honest count is correct and unsurprising.

### 5.5 Sorting

Minimal: default sort is **Created, newest first** (what your screenshot shows). Clickable sort on **Created** and **Customer (name)** headers is a nice-to-have; if cut for MVP, newest-first is the right and only default. Do not build a sort menu — column-header sort or nothing.

### 5.6 Row interaction

Whole row is clickable → navigates to the customer detail page (`router.visit(show(customer.id))`), matching the Products row behavior. The trailing `⋯` menu and the leading checkbox `stopPropagation` so they don't trigger navigation. Cursor is `pointer` on the row.

---

## 6. Customer creation

### Recommendation: a **side drawer**, not a page, not a wizard.

The brief asks which of modal / side panel / dedicated page / wizard. The answer is **side drawer** (the `Sheet`/`drawer` component the catalog already uses for every create/edit action), for three reasons:

1. **Consistency.** Catalog established that *creation and editing happen in drawers, navigation is reserved for viewing*. A customer is a smaller object than a product; giving it a whole page (the way your current screenshots imply) is heavier than it needs to be and breaks the pattern users just learned.
2. **Context preservation.** Creating a customer is often something you do *from* somewhere — the list, or later a "New subscription" flow that needs a customer first. A drawer keeps the underlying context alive; a page navigation destroys it.
3. **Speed.** The whole point (Principle 5) is that this feels like nothing. A drawer that slides in, takes a name and email, and slides out is the fastest possible path.

**Why not a wizard:** there's nothing to sequence. Two required fields don't need steps.

**Why not a centered modal:** the side drawer is already the app's create idiom; a modal would be a third, competing pattern.

### 6.1 The drawer

Progressive disclosure — the whole thing is *two fields and a button*, with optional fields tucked behind a disclosure so the default view stays calm.

```
┌───────────────────────────────────────────┐
│  Create customer                        ✕  │
│  Add someone you want to bill. You can     │
│  add payment details and more after.       │
│  ─────────────────────────────────────────│
│                                            │
│  Name                                      │
│  ┌───────────────────────────────────────┐│
│  │ Joseph Ajibodu                        ││
│  └───────────────────────────────────────┘│
│  Optional — helps you recognise them.      │
│                                            │
│  Email  *                                  │
│  ┌───────────────────────────────────────┐│
│  │ jo.ajibodu@gmail.com                  ││
│  └───────────────────────────────────────┘│
│  Where receipts and billing emails go.     │
│                                            │
│  ▸ More details (optional)                 │
│    ┄ Phone                                 │
│    ┄ Currency        [ Team default: NGN ] │
│    ┄ Your reference (external ID)          │
│                                            │
│  ─────────────────────────────────────────│
│              [ Cancel ]   [ Create customer ]│
└───────────────────────────────────────────┘
```

**Field decisions (grounded in the `customers` table):**

| Field | Required? | Why / copy |
|---|---|---|
| `email` | **Required** | The only non-null column; the identity anchor and where receipts go. Inline-validated as an email. |
| `name` | Optional | Schema allows null. Labeled "Optional — helps you recognise them." A business billing another business may only have an email. |
| `phone` | Optional, collapsed | Under "More details." |
| `currency` | Optional, collapsed | Defaults to team currency (shown as placeholder "Team default: NGN"). Only surface if they want to override — most won't. |
| `external_ref` | Optional, collapsed | Labeled **"Your reference"** with helper *"Your own ID for this customer, if you have one. Must be unique."* This is the integrator's own customer id — a developer-experience nicety that lets them reconcile Bouclay customers against their system. |
| `country`, `locale` | **Not in the create form** | Country is captured properly with the *address* (§9), where it's meaningful; locale defaults and is an edit-page nicety. Putting them here is paperwork. |
| `custom_data` (metadata) | **Not in create** | Added from the detail page's Metadata section where there's room. |

**Address is deliberately *not* in the create flow.** A customer can be created with zero addresses and enriched later. Forcing an address at creation is exactly the "government form" the brief warns against. Address lives on the detail page (§9).

### 6.2 Validation & submission

- **Inline validation:** email format checked on blur; duplicate-email is *allowed* (Stripe allows duplicate emails — same person, two records is a real case) but surfaces a soft, non-blocking hint if an existing customer shares the email: *"You already have a customer with this email. Create another?"* with the existing one linked. This prevents accidental dupes without forbidding intentional ones.
- **`external_ref` uniqueness** (unique per team when set) is the one hard validation — a collision returns an inline field error: *"You already use this reference for another customer."*
- **Submit:** primary button shows a spinner + "Creating…", disabled during flight. On success the drawer closes, a toast fires (§15), and the app **navigates to the new customer's detail page** — because the natural next action (add a payment method) lives there. Creating a customer and staying on the list would strand the user one click from where they need to be.
- **Keyboard:** `⌘/Ctrl+Enter` submits; `Esc` cancels (with a dirty-check confirm only if fields were touched).

---

## 7. Customer detail page — the hub

The centerpiece of the phase. A **single scrollable page** (not tabs), `max-w-4xl`, matching the Product detail page's structure exactly — so the two feel like one product. It reads top-to-bottom as: *who is this → how do we bill them → what have they bought (future) → what happened (activity) → developer info*.

The brief's current screenshots show a thin version of this (header, customer details, empty Subscriptions, one invoice row). The redesign below is the full hub.

### 7.1 Page skeleton

```
┌─────────────────────────────────────────────────────────────────────┐
│  ‹ Customers                                                         │
│                                                                     │
│  ◐  Joseph Ajibodu   [● Active]                    [ Actions ▾ ]     │
│      jo.ajibodu@gmail.com  ·  copy                                  │
│      Customer since Jun 13, 2026 · ctm_01kv14az… ⧉                  │
│  ───────────────────────────────────────────────────────────────── │
│                                                                     │
│  OVERVIEW  (compact facts grid)                                     │
│  ┌───────────────┬───────────────┬───────────────┐                 │
│  │ Email         │ Default method│ Billing address│                │
│  │ jo.ajibodu@…  │ Visa ···4242  │ Ibadan, NG     │                │
│  ├───────────────┼───────────────┼───────────────┤                 │
│  │ Currency      │ Customer since│ Status         │                 │
│  │ NGN           │ Jun 13, 2026  │ Active         │                 │
│  └───────────────┴───────────────┴───────────────┘                 │
│                                                                     │
│  PAYMENT METHODS                          (read-only — no "Add")    │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ 💳 Visa ···· 4242   exp 08/28   [Default]                ⋯  │   │
│  │ 💳 Mastercard ···· 4444   exp 01/27                      ⋯  │   │
│  └─────────────────────────────────────────────────────────────┘   │
│  Cards are saved automatically when the customer pays a checkout.  │
│                                                                     │
│  SUBSCRIPTIONS                          [ + New subscription ]⌦     │
│  ┌─── staged placeholder — see §11 ────────────────────────────┐   │
│  │  Recurring plans this customer is on will appear here.       │   │
│  │  Available in the next release.               (disabled CTA) │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  INVOICES                                                           │
│  ┌─── real table (Phase 6) — see §11 ──────────────────────────┐   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  ADDRESSES                                     [ + Add address ]    │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ 🏠 Billing · Default    Akobo, Ibadan · Oyo · 200132 · NG ⋯ │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  ACTIVITY                                                          │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ ● Payment method added        · Jul 4, 9:24 PM               │   │
│  │ ● Customer created            · Jun 13, 6:42 PM              │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  METADATA                                          [ Edit ]        │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ plan_source   =  landing_page                                │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  DEVELOPER                                                         │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ Customer ID   ctm_01kv14azjychg5c4vymsk5km50            ⧉    │   │
│  │ Your reference  cus_4812  (external_ref)               ⧉    │   │
│  │ Created  Jun 13, 2026 18:42:07 WAT                           │   │
│  │ ▸ View as API object  { … }                                  │   │
│  └─────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────┘
```

**Section order rationale.** Payment Methods sits *above* the future placeholders because it's the customer's billing spine — a customer is subscription-ready once a card is on file. **The section is read-only** (see [§10](#10-payment-methods--the-tokenization-journey)): unlike Stripe, Bouclay does not let a team *type in* or *add* a card here — following Paddle's verified model, a card is saved as a **byproduct of the customer paying a checkout**, never entered by the team. So the section lists cards, sets a default, and removes them, but has no "Add" button. Subscriptions and Invoices come next (they're what *drives* the checkout that stores the card). Addresses follow (supporting billing data). Activity, Metadata, Developer close the page as reference material.

### 7.2 The header

The brief asks for a richer header than the current screenshot. Full spec:

```
◐  Joseph Ajibodu   [● Active]                          [ Actions ▾ ]
    jo.ajibodu@gmail.com   ⧉
    Customer since Jun 13, 2026   ·   ctm_01kv14az…5km50   ⧉
```

- **Monogram** (`CustomerMonogram`): deterministic color from the id (reuse `ProductMonogram`'s hash), initial from name-or-email. Gives every customer an instant visual identity.
- **Name** (or email if name is null), large/semibold — matches the Product detail `h1`.
- **Status badge:** `[● Active]` green `secondary` badge / `[Archived]` muted `outline` badge — same `Badge` variants Products use, so status reads identically across the app. Archived customers also get a full-width muted banner atop the page: *"This customer is archived. Restore them to add payment methods or subscribe them to plans."* with a **Restore** button.
- **Email**, muted, with a hover-reveal **copy** icon → toast *"Email copied."*
- **Meta line:** "Customer since {created_at}" · truncated **Customer ID** with a copy icon that does the check-swap microinteraction (the `Copy → Check` swap already in `show.tsx`, 2s revert).
- **Actions ▾** (top-right) — see §7.4.

### 7.3 Overview facts grid

A compact 3-column grid of read-only facts — the "answer the top questions without scrolling" band. Each cell is a label + value; values that are copyable (email) or navigable (default method → scrolls to Payment Methods) afford it on hover. Cells:

| Cell | Value / empty behavior |
|---|---|
| Email | Always present |
| Default payment method | `Visa ···4242` linking to the PM row, or **"None yet"** with a subtle "Add" link if manageable |
| Billing address | `City, Country` of the default billing address, or **"No address"** |
| Currency | `customers.currency` or "Team default (NGN)" |
| Customer since | `created_at`, friendly date |
| Status | Active / Archived |

Deliberately **not** in the grid: total spend, subscription count, MRR — all Phase 6 data. The grid is sized so those cells *drop in later* (it becomes a 3×3) without reflowing the page.

### 7.4 The Actions menu

The brief asks for careful ordering and for *future* actions to appear now, disabled with "Coming soon." Recommendation — grouped, most-common first, destructive last, future actions present-but-disabled:

```
Actions ▾
┌────────────────────────────┐
│  ✎  Edit customer          │   ← primary edit, opens drawer (§8)
│  ⧉  Copy customer ID       │
│ ───────────────────────────│
│  ＋ Add address            │
│ ───────────────────────────│
│  💳 Charge customer        │   ← creates a checkout; a card is stored
│                            │      as a byproduct (§10.3, §10.8)
│  ↻  Create subscription    │   ⌦ disabled · "Available next release"
│ ───────────────────────────│
│  🗄 Archive customer       │   ← destructive, red, confirm dialog
└────────────────────────────┘
```

**No "Add payment method" item** — verified against Paddle (which has none) and confirmed with the team: a team never types or adds a card. The way a card ends up on file is by the customer **paying a checkout** — so the relevant action is **Charge customer** (a one-time checkout, Paddle's "New one-time transaction"), which tokenizes the card as a side-effect ([§10.3](#103-the-add-payment-method--tokenization-flow)). Whether "Charge customer" ships *in* Phase 4 or lands with subscriptions/invoicing is the scoping decision recorded in [`IMPLEMENTATION.md`](IMPLEMENTATION.md) Phase 4; until it ships, the item is present-but-disabled with a "Coming soon" tooltip, like Create subscription.

**Ordering logic:**
1. **Edit / Copy ID** — the two things you do most often, at the top where the cursor lands.
2. **Add address** — the one enrichment action a team genuinely performs by hand (a card is not — it's collected via checkout, not the menu).
3. **Charge customer / Create subscription** — the money actions, **present but disabled** until their phase lands, each with a tooltip explaining *when* it arrives. Both are what actually trigger a checkout and therefore store a card. Ordering them *above* Archive isolates the destructive action.
4. **Archive** — red, separated, opens a confirm dialog (never fires on the click itself). For an already-archived customer this item becomes **Restore** (not red).

**"New business" is dropped.** The current screenshot's Actions menu has "New business" — that's a B2B/organization concept the schema doesn't model (there's no `businesses` table under customers). Including a menu item that can't do anything erodes trust more than omitting it. If B2B org grouping is wanted later, it's a schema change ([§14](#14-schema-adjacent-notes--open-questions)), not a menu item to stub now.

Disabled items use a real disabled `DropdownMenuItem` wrapped so the tooltip still fires on hover (Radix disabled items swallow pointer events — wrap in a `span` with `tabIndex` or use `data-disabled` styling + `onSelect` prevented, so the "Coming soon" tooltip is reachable).

---

## 8. Edit customer

The brief wants the edit drawer to feel polished, not generic CRUD. It's the same `Sheet` as create, but pre-filled and organized into labeled groups with descriptions — not a flat stack of inputs.

```
┌───────────────────────────────────────────┐
│  Edit customer                          ✕  │
│  Update billing details for this customer. │
│  ─────────────────────────────────────────│
│  IDENTITY                                  │
│    Name        [ Joseph Ajibodu         ]  │
│    Email  *    [ jo.ajibodu@gmail.com   ]  │
│                Receipts and billing emails │
│                go here.                    │
│                                            │
│  LOCALE & BILLING                          │
│    Phone       [ +234 …                  ] │
│    Currency    [ NGN ▾ ]                    │
│                Used for this customer's    │
│                invoices and subscriptions. │
│                                            │
│  DEVELOPER                                 │
│    Your reference (external ID)            │
│                [ cus_4812               ]  │
│                Your own ID for reconciling.│
│  ─────────────────────────────────────────│
│         [ Cancel ]        [ Save changes ] │
└───────────────────────────────────────────┘
```

**Polish decisions:**
- **Grouped sections** (Identity / Locale & billing / Developer) with quiet uppercase labels — reads as considered, not a form dump. Same visual language as the Metadata/Developer groupings on the detail page.
- **Descriptions under consequential fields only** (email, currency, external_ref) — not every field, which would be noise. Currency's description warns it affects invoices, because changing a customer's currency after they have subscriptions is consequential (and in Phase 5 will be locked once a subscription exists, mirroring the price-lock rule in Catalog).
- **Address and payment methods are NOT here.** They have their own richer surfaces (§9, §10). The edit drawer edits the `customers` row only. This keeps the drawer short and prevents the "edit everything" mega-form the brief explicitly rejects.
- **Metadata is NOT here** either — it's edited from the detail page's Metadata section (reusing the existing `edit-metadata-drawer.tsx` from Catalog, which is already generic over `custom_data`). One metadata editor, reused.
- **Primary / secondary actions:** filled **Save changes** (right), ghost **Cancel** (left) — matches every other drawer. Save is disabled until the form is dirty *and* valid; shows spinner + "Saving…" in flight. Dirty-close confirm on `Esc`/`✕`.
- **Validation copy** is human: not "The email field is required" but *"Add an email — it's where receipts go."*; not "The external_ref has already been taken" but *"You already use this reference for another customer."*

---

## 9. Addresses

Addresses support billing (and later shipping). They must not feel like a government form — progressive disclosure is the whole game (the app already does this on the *signup* address step, per the git log "progressively reveal address sub-fields"; reuse that exact pattern for consistency).

### 9.1 In the detail page

A section listing the customer's addresses as compact rows:

```
ADDRESSES                                        [ + Add address ]
┌──────────────────────────────────────────────────────────────┐
│ 🏠 Billing · Default   Akobo, Ibadan · Oyo · 200132 · NG   ⋯ │
│ 📦 Shipping            12 Marina · Lagos · NG              ⋯ │
└──────────────────────────────────────────────────────────────┘
```

- Each row: a **type chip** (`Billing` / `Shipping`, from `addresses.type`), a **Default** badge if `is_default` (default is *per type* per schema), the address collapsed to one human line, and a `⋯` menu (Edit · Set as default · Copy · Remove).
- **Copy address** copies a formatted multi-line block → toast *"Address copied."*

### 9.2 Add / edit address drawer — progressive reveal

```
┌───────────────────────────────────────────┐
│  Add address                            ✕  │
│  ─────────────────────────────────────────│
│  Type      ( ● Billing   ○ Shipping )      │  ← toggle-group
│                                            │
│  Country   [ Nigeria ▾ ]                    │  ← FIRST field; drives the rest
│                                            │
│  ── reveals after country is chosen ──     │
│  Address   [ Akobo, Ibadan              ]  │  line1
│            [ Apartment, suite (optional) ] │  line2 (revealed via "+ Add line 2")
│  City      [ Ibadan          ]             │
│  Region    [ Oyo             ]  Postal [ 200132 ] │
│                                            │
│  ☑ Set as default billing address          │
│  ─────────────────────────────────────────│
│         [ Cancel ]        [ Save address ] │
└───────────────────────────────────────────┘
```

**Progressive-disclosure decisions:**
- **Country first.** It's the field that determines what the rest of the form should even say (region vs state vs province, postal-code presence/format). Asking it first and revealing the remaining fields after is the single move that makes address entry feel light. Field labels adapt to country where cheap (e.g. "Postal code" vs "ZIP"), but don't over-engineer per-country forms for MVP — a sensible generic set is enough.
- **Line 2 is hidden behind "+ Add apartment, suite, etc."** — most addresses don't need it; revealing it on demand removes a field from the default view.
- **`is_default`** as a checkbox, scoped by the type toggle ("Set as default *billing* address"). First address of a type is auto-default (checkbox pre-checked and disabled with helper *"Your first billing address is the default."*).
- **`name` and `phone`** on the address (schema allows them — e.g. "Attn: Accounts Payable") live under a "+ Add recipient details" disclosure, never in the default view.

### 9.3 Address ↔ payment method link

`payment_methods.billing_address_id` lets a card point at a billing address. Surface this *softly*: when adding a payment method and the customer has ≥1 billing address, offer "Use {default billing address} for this card" as a pre-checked option rather than making it a required step. Don't block tokenization on an address.

---

## 10. Payment methods & the tokenization journey

The heart of the phase, and the place the design must most clearly communicate *Bouclay never holds card data*.

### 10.1 The security model, stated in the UI

The mental model users must leave with:

```
  Customer          You (Bouclay dashboard)
     │                     │
     │  "Add payment method"
     ▼                     ▼
  ┌─────────────────────────────────────┐
  │   Nomba secure checkout (their UI)  │  ← card number entered HERE
  │   PCI surface — not Bouclay         │     never touches Bouclay
  └─────────────────────────────────────┘
     │  Nomba tokenizes
     ▼
  ┌─────────────────────────────────────┐
  │  Bouclay stores only:               │
  │   token · brand · last4 · expiry    │  ← safe metadata, no PAN
  └─────────────────────────────────────┘
```

This diagram (or its prose form) appears in the **empty payment-methods state** and again, condensed, at the **hand-off to Nomba**. Copy: *"Card details are entered securely on Nomba and never touch Bouclay. We only store a secure token, the card brand, and the last four digits — enough to charge it, nothing more."*

### 10.2 Empty state (no payment methods)

```
PAYMENT METHODS
┌──────────────────────────────────────────────────────────────┐
│                         💳                                    │
│              No card on file yet                              │
│                                                              │
│  A card is saved automatically the first time this customer   │
│  pays a checkout — you never enter card details here. When    │
│  they pay, Nomba securely tokenizes the card and the saved    │
│  card shows up on this profile, ready to charge again.        │
│                                                              │
│  Bouclay only ever stores a secure token, the card brand,     │
│  and the last four digits — never the full card number.       │
│                                                              │
│  🔒 Secured & tokenized by Nomba                              │
└──────────────────────────────────────────────────────────────┘
```

**No CTA in this empty state — on purpose.** Unlike Stripe, there is no "Add payment method" button (see [§10.8](#108-how-a-card-actually-gets-saved-resolved)): a card is a *byproduct of payment*, not a thing the team adds. So the empty state *educates* (why cards exist, that Nomba tokenizes them, that Bouclay never sees the PAN, what happens next) but points the user at the real trigger — charging the customer or subscribing them — rather than a dead "Add" button. The three-questions rule still holds; the "what happens next" answer is "they pay a checkout," not "click here."

**Precondition, surfaced where the trigger lives.** Collecting a card requires the team's Nomba connection for the current mode. That gate belongs on the **Charge customer / Create subscription** action (§7.4), not here — if Nomba isn't connected, *those* actions carry the inline notice + link: *"Connect your Nomba account to start accepting cards."* → Developers → Nomba Integration. The read-only PM section never needs the gate because it never initiates a checkout.

### 10.3 How a card gets tokenized (the checkout flow)

> **Verified against Nomba's docs (2026-07-04).** Nomba Checkout is a **hosted, full-redirect payment page** — there is no embedded card field and no zero-amount "setup intent." A card is tokenized only as a **side-effect of a real checkout order**: you `POST /v1/checkout/order` with `tokenizeCard: true` and a **required `amount`**, receive a `checkoutLink` (`https://checkout.nomba.com/pay/…`) + `orderReference`, and redirect the customer there. The card (number, expiry, CVV, 3DS/OTP) is entered entirely on Nomba's surface. On completion Nomba redirects to your `callbackUrl?orderReference=…`. The token + safe card metadata come back two ways: the `payment_success` **webhook** (`tokenizedCardData { tokenKey, cardType, tokenExpiryMonth/Year, cardPan }` + `order.cardLast4Digits`) **and** a synchronous **`GET /v1/checkout/tokenized-card-data?customerEmail=…`** list.

> **Verified against Paddle (2026-07-04) + confirmed with the team.** There is **no standalone "add a card" action**. A card is saved as the **byproduct of the customer paying a checkout** the team triggers via **Charge customer** (a one-time transaction) or **Create subscription**. Paddle's "Create transaction" makes the two collection modes explicit — *Manually, via invoice* (send a checkout/invoice link the customer pays) vs. *Automatically, using a stored payment method* (charge a card already on file). Bouclay adopts this exactly; those two modes are already the `collection_mode: manual | automatic` enum in the schema. This **retires the earlier "Add & verify a card" / ₦50 verify-charge idea** — the charge that mints the token is a *real payment the customer wanted*, not an artificial setup fee.

So the flow below is not launched from a PM "Add" button — it's the tail of a **Charge customer / Create subscription** action. The card appears on the customer afterward.

```
Charge customer  /  Create subscription   (§7.4 — a real amount, tokenizeCard:true)
        │
        ▼
┌─ Confirm & pay for:  Joseph Ajibodu ──────────┐
│  Amount:   ₦6,000.00  (the actual thing being │
│            charged — not a setup fee)          │
│  Collection: ● Manual (send checkout link)     │
│              ○ Automatic (needs a stored card) │
│           [ Cancel ]   [ Continue to Nomba → ] │
└────────────────────────────────────────────────┘
        │  Bouclay: POST /v1/checkout/order
        │  { amount, currency, customerId, customerEmail,
        │    callbackUrl, tokenizeCard: true }   (team's Nomba keys)
        │  → { checkoutLink, orderReference }
        ▼
┌─ REDIRECT to checkout.nomba.com/pay/… ─────────┐   ← card entered HERE,
│   Card · Transfer · USSD · …                   │     on Nomba's domain,
│   [ Pay ₦6,000.00 ]   🔒 powered by Nomba       │     never in Bouclay's DOM
└────────────────────────────────────────────────┘
        │  Nomba tokenizes → redirect to
        │  callbackUrl?orderReference=…
        ▼
┌─ Reconciling (Bouclay callback route) ─────────┐
│   1. Verify: GET /v1/transactions/accounts/     │
│      single?orderReference=…  → status SUCCESS  │
│   2. Capture token: from the payment_success    │
│      webhook (exact), or GET /v1/checkout/      │
│      tokenized-card-data?customerEmail=… (sync)  │
│   3. Persist payment_methods row                │
│      (processor_token, brand, last4, exp)       │
│   ⟳  Saving your card…                          │
└────────────────────────────────────────────────┘
        │
        ▼
   ✓ Payment succeeded · card now on file · toast
```

**Endpoint → column mapping** (so backend and UI agree on what's storable):

| `payment_methods` column | Source from Nomba |
|---|---|
| `processor` | `nomba` (constant) |
| `processor_token` | `tokenizedCardData.tokenKey` |
| `brand` | `tokenizedCardData.cardType` / `order.cardType` (e.g. Visa, Verve) |
| `last4` | `order.cardLast4Digits` (or last 4 of masked `cardPan`) |
| `exp_month` / `exp_year` | `tokenExpiryMonth` / `tokenExpiryYear` — **may be `N/A`**, so both stay nullable and the UI shows "Expiry unknown" gracefully |
| `type` | `card` (Nomba tokenization is card-only today) |
| `fingerprint` | **Not provided by Nomba** — see [§14.7](#14-schema-adjacent-notes--open-questions); the dedupe-by-fingerprint idea does not hold with Nomba |
| `issuer` | Not reliably available (production `/checkout/transaction` returns `cardBank` code; sandbox does not) — leave null |
| `status` | `active` on success |

**Token correlation — the one subtlety.** `list-tokenized-cards` filters by **`customerEmail`**, not by `orderReference`. The only place `orderReference` and `tokenKey` are tied together is the **`payment_success` webhook**. So the *exact-correlation* path is the webhook; the *synchronous* path (list by email) is good enough when a customer's email is unique. This is why Phase 4 should extend the existing `POST /webhooks/nomba/{token}` receiver (built in Phase 2, currently just marks `webhook_verified_at`) to capture `tokenizedCardData` keyed by `orderReference` — a *small* addition now, not the full Phase 7 mapping. See [§18](#18-build-sequencing) step 6 and [§14.8](#14-schema-adjacent-notes--open-questions).

### 10.4 State-by-state (loading / validation / failure / retry / cancel / success)

The brief asks for the full journey. Each state and its treatment:

| State | Trigger | Treatment |
|---|---|---|
| **Loading (launch)** | "Continue to Nomba" clicked | Button → spinner "Opening secure form…"; the modal stays, dimmed, so cancel is still possible if Nomba is slow |
| **In-progress** | User is on Nomba's surface | Bouclay shows a quiet "Waiting for Nomba…" state with a **Cancel** escape hatch (closes the popup/iframe, no record created) |
| **Reconciling** | Nomba returns a token | Brief "Saving your card…" spinner while Bouclay persists the `payment_methods` row |
| **Success** | Row persisted | Modal closes → new card animates into the list (subtle highlight-fade) → toast *"Card added — Visa ···· 4242."* → if it's the first/only card or "set default" was checked, it shows the **Default** badge |
| **Validation error** | Nomba rejects the card (bad number, expired) | Handled *on Nomba's surface* (their validation); Bouclay never sees it. If Nomba returns a declined/failed result, Bouclay shows a retryable error state |
| **Tokenization failure** | Nomba returns a failed/declined checkout | Non-destructive error card: *"We couldn't save that card — it was declined or the details didn't check out. Any verify charge is automatically reversed."* + **[ Try again ]** (creates a fresh order → reopens Nomba) + **[ Cancel ]**. (Because saving a card *is* a charge, this copy must not promise "no charge was made" — see [§10.8](#108-the-verification-charge-decision).) |
| **Cancellation** | User closes Nomba or hits Cancel | Silent — no toast, no orphan record, modal returns to its pre-launch state or closes cleanly |
| **Nomba unreachable** | Network/timeout | *"Nomba isn't responding right now. Your card wasn't saved — please try again in a moment."* + retry |

**No partial records, ever.** A `payment_methods` row is written *only* on a confirmed token from Nomba. A cancelled or failed flow leaves zero trace. This is both correct and a trust property users feel ("it didn't create a broken card").

### 10.5 The payment-methods list (populated)

```
PAYMENT METHODS                              (no "Add" — read-only)
┌──────────────────────────────────────────────────────────────┐
│ 💳 Visa ···· 4242    Expires 08/28    [Default]           ⋯  │
│ 💳 Mastercard ···· 4444   Expires 01/27                   ⋯  │
│ ⚠ Visa ···· 1881    Expired 03/25                         ⋯  │
└──────────────────────────────────────────────────────────────┘
Saved when the customer paid a checkout. To add another, charge them again.
```

The section **lists** cards and manages them (`⋯` → set default / remove / view details) but has **no add control** — a new card only ever arrives via a fresh checkout (§10.3, §10.8).

- **Brand glyph** (Visa / Mastercard mark, or a generic card icon for `bank`/`wallet` types). Brand + `···· last4` is the human name of the instrument.
- **Expiry**: "Expires 08/28"; when past-due, a warning-tinted **"Expired 03/25"** and a muted row — with `status = expired` from the schema surfaced honestly. Expired cards can't be set default; the menu says why.
- **Default badge** on the one card that is `customers.default_payment_method_id`. Exactly one card is default.
- **`⋯` menu:** *Set as default* · *Copy payment method ID* · *View details* · —— · *Remove* (red). Removing the default prompts the user to pick a new default if others exist; removing the last card is allowed but warns *"This is the only way to charge this customer. Remove it anyway?"*.
- **Types beyond card** (`bank`, `wallet` per schema) render with the same row shape, swapping the glyph and the "···· last4" for the appropriate identifier. Designing the row generic over `type` now means Nomba bank/wallet instruments drop in without a redesign.

### 10.6 Payment method detail

A **drawer** (not a page — it's small, and matches the price-detail-drawer pattern already in Catalog), showing everything safe Bouclay holds:

```
┌───────────────────────────────────────────┐
│  Visa ···· 4242                         ✕  │
│  [Default]                                 │
│  ─────────────────────────────────────────│
│  Brand           Visa                      │
│  Last 4          4242                      │
│  Expiry          08 / 2028                 │
│  Type            Card                      │
│  Status          Active                    │
│  Issuer          —                         │
│  Billing address Akobo, Ibadan · NG        │
│  Added           Jul 4, 2026               │
│  ─────────────────────────────────────────│
│  DEVELOPER                                 │
│  Payment method ID  pm_01k…   ⧉            │
│  Processor          Nomba                  │
│  Token key          tok_••••••  (masked)   │
│  ─────────────────────────────────────────│
│  [ Set as default ]            [ Remove ]  │
└───────────────────────────────────────────┘
```

The **token key** is masked, never fully shown — it's a credential, treated like the API-key secrets in Developers. Issuer/billing-address show `—` gracefully when null. **Fingerprint is dropped from the UI** — verification showed Nomba does not return a card fingerprint, so the schema's `fingerprint` column stays null and the "same card across customers" signal isn't available with Nomba ([§14.7](#14-schema-adjacent-notes--open-questions)). **Remove** here calls Nomba's `DELETE /v1/checkout/tokenized-card-data` (by `tokenKey`) *and* soft-removes the local row, so the token dies on both sides.

### 10.7 Test vs live payment methods

Per Principle 4, a card tokenized against test keys is real data on the same record. Surface it with a small muted **Test** tag on the row (and detail), so a team member testing in sandbox doesn't later try to charge a test token in production and get confused. This is the *one* place test/live leaks into Customers — as a tag, not a page fork. (Nomba's sandbox uses its own base URLs — `sandbox.nomba.com` — and test cards; the tag reflects which mode's keys tokenized the card.)

### 10.8 How a card actually gets saved (resolved)

**Resolved** — verifying both Nomba *and* Paddle collapsed the earlier "verify-charge" debate. Two facts settle it:

1. **Nomba** has no zero-amount setup intent — a token only exists after a *paid* checkout with `tokenizeCard: true`.
2. **Paddle** (the model we're following) never exposes an "add card" action at all — a card is saved purely as the **byproduct of the customer paying** something they were actually billed for.

So the answer is **tokenize-on-payment** (what I'd called Option B), and it applies in *both* modes — there is no separate live-mode policy to decide, and **no verify-charge anywhere**:

- **The card-minting event is always a real charge the customer wanted** — a **Charge customer** one-time transaction, or the **first subscription payment**. Never an artificial ₦50.
- **Test mode** uses Nomba sandbox cards → the exact same flow, fake money. This is the Phase 4 demo path.
- **Live mode** needs no special handling — the first genuine payment mints the token, exactly as Stripe/Paddle behave ("you attach a card *by paying/subscribing*").
- **Consequence:** there is no "card on file before the customer has ever paid" state. That's correct for a billing engine — a card with nothing to charge isn't a real use case.

**The only open item is *sequencing*, not policy:** since a card requires a checkout, and checkouts are driven by Charge-customer (transaction) / Create-subscription, **something that triggers a checkout has to exist in Phase 4** for its exit criteria ("complete a test checkout, payment method stored") to be met. That decision — how thin a transaction slice to pull forward — is recorded in [`IMPLEMENTATION.md`](IMPLEMENTATION.md) Phase 4, not here.

---

## 11. Placeholder sections

> **Implemented (2026-07-06):** **Subscriptions** and **Invoices** are live on the customer hub. Invoice rows use `Invoice::toDashboardArray()` and link to `/invoices/{id}`. There is no "Transactions" section — see `schema.md` § Dashboard vocabulary.

The original Phase 4 brief staged Subscriptions and Invoices as intentional placeholders. Phase 5 activated Subscriptions; Phase 6 activated Invoices in the same slot.

### 11.1 The staged-placeholder pattern

A reusable component (`<StagedSection>`) used for every not-yet-built section, so they all feel deliberately consistent:

```
SUBSCRIPTIONS                          [ + New subscription ]⌦
┌──────────────────────────────────────────────────────────────┐
│   ↻                                                          │
│   Subscriptions will live here                               │
│                                                              │
│   When you subscribe this customer to a plan, their active   │
│   and past subscriptions — status, renewal date, and plan —  │
│   will show up here.                                         │
│                                                              │
│   Available in the next release.                             │
└──────────────────────────────────────────────────────────────┘
```

- **A real section header with a real (disabled) primary action.** The `+ New subscription` button is present but disabled, with a tooltip *"Subscriptions arrive in the next release."* — the same treatment as the Actions-menu future items. The button being *there* (just off) is what makes the page feel staged rather than missing a feature.
- **Forward-looking body copy** that describes what the section *will* hold and what the user will be able to *do* — answering the brief's three placeholder questions (what it'll contain / why it exists / what you'll do here).
- **A muted glyph** matching the eventual section (↻ subscriptions, 🧾 invoices).
- **No fake data, no `$0.00` rows.** Emptiness that's clearly "coming" beats emptiness that looks broken.

### 11.2 Invoices *(was "Transactions" in early drafts)*

The original screenshot showed a billing table row. Bouclay models that as an **`Invoice`** (numbered billing record), not a "Transaction" entity. Charge attempts against an invoice appear on the **subscription hub** as **Payments**, not on the customer hub.

**Built (Phase 6):** the customer hub **Invoices** section lists invoice rows for this customer (`Invoice::toDashboardArray()`), each linking to the invoice detail page. Empty state when none exist; **+ New invoice** opens `CreateInvoiceDrawer` when `canManageInvoices`.

Original Phase 4 placeholder copy (for reference):

```
INVOICES
┌──────────────────────────────────────────────────────────────┐
│   🧾  Invoices will appear here                               │
│   Every bill Bouclay raises against this customer — open,     │
│   paid, or void — will be listed here once billing is on.    │
│   Available with invoicing.                                   │
└──────────────────────────────────────────────────────────────┘
```

### 11.3 Which sections, in which order

On the detail page, sections appear in their permanent positions (§7.1): **Subscriptions** and **Invoices** directly under Payment Methods (because they're what a payment method is *for*). We use separate sections (Stripe-shaped), not a single combined "Transactions" box.

---

## 12. Activity timeline

A right-sized event log — enough to feel like a source of truth, not so much it becomes an audit product.

### 12.1 What it shows now vs later

The schema has no dedicated per-customer activity table; the `events` table is the *outbound integrator* event log (Phase 9) and isn't the right source for a human-readable customer timeline. Two honest options:

- **(A) Derive it** from timestamps Bouclay already stores — `customer.created_at` (→ "Customer created"), each `payment_method.created_at` (→ "Payment method added · Visa ···4242"), `address.created_at`, `customer.updated_at` (→ "Customer updated"). Zero new tables, and it scales as later phases add timestamped rows (subscription created, invoice paid). **Recommended for MVP** — it's real, it's accurate, it needs no schema change.
- **(B) A dedicated `customer_events` / activity table.** Cleaner for rich events with actor/diff detail, but it's net-new schema the brief says not to add. Defer unless (A) proves too thin.

### 12.2 Component design (scales without redesign)

```
ACTIVITY
┌──────────────────────────────────────────────────────────────┐
│  ●  Payment method added · Visa ···· 4242    Jul 4, 9:24 PM   │
│  │                                                            │
│  ●  Address added · Billing, Ibadan          Jul 4, 9:20 PM   │
│  │                                                            │
│  ●  Customer created                          Jun 13, 6:42 PM  │
└──────────────────────────────────────────────────────────────┘
```

- **Vertical connected dots**, newest first, each event = icon-dot + human sentence + timestamp (relative on the surface, absolute on hover).
- **Event vocabulary is an enum-like union in the frontend** (`customer.created`, `customer.updated`, `payment_method.added`, `payment_method.removed`, `address.added`, `subscription.created`…) with a renderer per type. Only types with data *today* render; adding Phase-5/6 types is adding a case to the renderer, not touching layout. This is the "design it to scale" requirement met concretely.
- Truncates to ~5 with a **"Show all activity"** expander once there's history.

---

## 13. Empty, loading, success & error states

### 13.1 Empty states (the three questions, everywhere)

Every empty region answers *why it exists / why create one / what happens next*.

**Customers list — true empty (zero customers ever):**
```
                    👥
          No customers yet
Customers are the people and businesses you bill. Add one,
give them a payment method, and you can put them on a
subscription. Everything about a customer — cards, addresses,
payments — lives on their profile.
              [ + Create your first customer ]
Tip: You only need a name and email to start. Add the rest anytime.
```
Also carries the mode-agnostic one-liner the first time: *"Customers are shared across test and live — only the keys used to charge differ."*

**Customers list — filtered empty (has customers, filter matched none):**
```
No customers match your search.        [ Clear ]
```
Distinct from true-empty — never show the teaching empty state when the list is merely filtered; that's disorienting. (Matches how `products.tsx` splits these two.)

**Payment methods empty:** §10.2. **Addresses empty:**
```
🏠  No address on file
Add a billing address to appear on this customer's invoices and
receipts. It's optional — you can subscribe them without one.
              [ + Add address ]
```

### 13.2 Loading states

- **List:** skeleton rows (the `Skeleton` component) matching the real table's column widths — 6–8 shimmer rows, not a spinner. The page chrome (header, filter row) renders immediately; only the table body shimmers.
- **Detail:** skeleton header (monogram circle + two text bars) + skeleton facts grid + skeleton section headers. The page's *shape* is present instantly; content fills in. This makes navigation feel fast even when data isn't ready.
- **In-drawer submit / tokenization:** button-level spinners with verb labels ("Creating…", "Saving…", "Opening secure form…") — never a full-page block.
- **Optimistic where safe:** setting a default payment method flips the badge immediately and reconciles; on failure it reverts with a toast. Creating a customer is *not* optimistic (it navigates), so it uses a real in-flight state.

### 13.3 Success states

- **Toasts** (`sonner`) for every mutation, specific not generic: *"Customer created."* / *"Card added — Visa ···· 4242."* / *"Default payment method updated."* / *"Address saved."* / *"Customer archived."* Each with an **Undo** where reversible (archive especially — a 5s undo toast is far kinder than a confirm dialog for a soft-delete).
- **In-context confirmation** beyond the toast: the new card animates into the list; the default badge moves; the archived customer's header flips to the muted banner. The toast tells you it happened; the UI *shows* it happened.

### 13.4 Error states

- **Field-level** (inline, red, human): duplicate `external_ref`, malformed email.
- **Action-level** (toast): *"Couldn't archive this customer. Try again."* with a retry affordance where the action is idempotent.
- **Tokenization** (§10.4): its own dedicated retryable card, because it's the highest-stakes, most-likely-to-fail action in the phase and deserves more than a toast.
- **Permission**: a viewer who somehow reaches a mutating action sees it *disabled with a reason* ("You need the Manage customers permission"), never an action that fails after the click.
- **Nomba-not-connected**: pre-empted (§10.2), not error-recovered — the button is disabled with a fix-it link before the user can trip it.

---

## 14. Schema-adjacent notes & open questions

These are *not* applied — flagged for you to decide, matching how CATALOG_DESIGN.md handled its open questions. **The schema stands; these are UX-driven observations.**

1. **Tokenization model — FULLY RESOLVED (verified against Nomba + Paddle, 2026-07-04).** Nomba is a **hosted full-redirect checkout**; tokenization is a side-effect of a **paid** order (`tokenizeCard: true`, required `amount`) — no embedded field, no $0 setup intent (§10.3). Paddle confirms the product model: **no "add card" action anywhere** — a card is the byproduct of the customer paying. So Bouclay is **tokenize-on-payment in both modes, no verify-charge, no live-mode policy to decide** (§10.8). `payment_methods.processor_token` = Nomba's `tokenKey`. **The only thing left is sequencing** — Phase 4 needs *some* checkout trigger to store its first card (recorded in `IMPLEMENTATION.md` Phase 4), not a design decision here.

2. **List scale → server-side search & pagination.** Customers is the first table likely to exceed the client-filter comfort zone. The *UI* (one search box, one status filter, one bulk bar) is identical whether filtering is client- or server-side, so nothing in this doc changes — but the controller should return paginated + server-searchable data from the start rather than dumping the full set like `products.tsx` does. Flagging as an implementation choice, not a design change.

3. **"Total spend" / subscription-count columns and Overview cells** need `payments`/`subscriptions` (Phases 5–6). The table and facts grid are designed with the empty slots reserved; wire them when the data exists, don't stub them now.

4. **B2B / organization grouping ("New business" in the current screenshot)** has no schema support (no `businesses` table under customers). Dropped from the Actions menu (§7.4). If multi-business customers are a real need, that's a schema addition to raise separately — not something to stub.

5. **Activity timeline source** (§12): recommend deriving from existing timestamps (option A) rather than adding a `customer_events` table. If richer activity (actor, field-level diffs) is wanted, that's a new table — deferred.

6. **Duplicate emails — reconsider given Nomba keys tokens by email.** My first instinct (Stripe-style: allow dupes, soft-warn) now has a wrinkle: Nomba's synchronous `list-tokenized-cards` filters by **`customerEmail`**, so two Bouclay customers sharing an email can't be told apart when pulling tokens back that way. The **webhook path (orderReference ↔ tokenKey)** doesn't have this problem, so it's not fatal — but it's a reason to lean toward **discouraging duplicate emails more firmly** (or relying on the webhook, not the email-list, for correlation). Recommend: keep dupes *allowed* but make the webhook the authoritative token source (§14.8), and keep the soft-warn. Confirm.

7. **`fingerprint` is unpopulatable with Nomba.** Verification showed Nomba returns no card fingerprint. The schema column stays (harmless, nullable), but the "is this the same card across customers?" dedupe feature I'd sketched in the PM detail is **dropped** (§10.6). If cross-customer card dedupe is ever needed, it'd require a different processor signal — not available today.

8. **Phase-4 token capture needs a *minimal* webhook extension, not full Phase 7.** The reliable `orderReference → tokenKey` tie lives only in the `payment_success` webhook. The Phase 2 receiver (`POST /webhooks/nomba/{token}`, currently just marks `webhook_verified_at`) should be extended *just enough* to stash `tokenizedCardData` against the order so the callback-return reconcile can persist the `payment_methods` row deterministically. This is a small, contained addition — full signature-verified event mapping still lands in Phase 7. Alternatively, rely on the synchronous verify + list-by-email path and accept the duplicate-email caveat (item 6). **Recommend the minimal webhook extension.**

9. **`customers.default_payment_method_id`** is the single source of truth for "default." The UI never lets two cards be default simultaneously; setting a new default updates this FK. (The `payment_methods.is_default` boolean in the schema is redundant with this FK — worth reconciling which one the app treats as authoritative to avoid drift. Recommend the FK on `customers` as canonical, `is_default` as a derived mirror.)

---

## 15. Microinteractions — consolidated list

Every one subtle, premium, confidence-inspiring. Reuse the primitives Catalog already ships.

| Interaction | Behavior |
|---|---|
| **Copy customer ID** | `Copy → Check` icon swap, 2s revert (the exact `show.tsx` pattern), toast *"Customer ID copied."* |
| **Copy email / address / payment-method ID** | Hover-reveal copy icon; toast per item |
| **Customer created** | Drawer slides out, toast *"Customer created."*, navigate to detail |
| **Payment method added** | Card animates into the list (highlight-fade), toast with brand+last4, default badge appears if applicable |
| **Card tokenized** | "Saving your card…" spinner → checkmark micro-animation before the row settles |
| **Set default** | Optimistic badge move with a soft transition; reverts on failure |
| **Status badge** | Green `secondary` (Active) / muted `outline` (Archived), identical to Products |
| **Archive** | Confirm dialog → success toast with **Undo** (5s); header flips to muted archived banner |
| **Restore** | One-click from the archived banner; toast *"Customer restored."* |
| **Disabled future actions** | Real disabled state + `Tooltip` explaining *when* it arrives ("Available in the next release") |
| **Skeletons** | Column-matched shimmer on list; shape-matched shimmer on detail |
| **Bulk bar** | Slides up from bottom-center (translate-y + fade), honest count, `Esc` to clear |
| **Dirty-close guard** | Drawers confirm on close only if fields were touched |
| **Nomba hand-off** | Quiet "Waiting for Nomba…" with a visible Cancel — never a dead spinner |

---

## 16. Copy deck

Consolidated, so tone stays consistent. Modern, trustworthy, developer-first — plain sentences, no exclamation-mark enthusiasm.

**Nav / titles**
- Nav: `Customers`
- List title: `Customers` — sub: *"The people and businesses you bill."*

**Buttons**
- `Create customer` · `Create your first customer` · `Save changes` · `Add payment method` · `Add address` · `Continue to Nomba →` · `Set as default` · `Remove` · `Archive customer` · `Restore`

**Create drawer**
- Header: `Create customer` — *"Add someone you want to bill. You can add payment details and more after."*
- Email helper: *"Where receipts and billing emails go."*
- Name helper: *"Optional — helps you recognise them."*
- external_ref: **"Your reference"** — *"Your own ID for this customer, if you have one. Must be unique."*

**Security / tokenization**
- Empty PM state: *"A payment method is how you'll charge this customer. Card details are entered securely on Nomba — Bouclay never sees or stores the full card number, only a secure token, the brand, and the last four digits."*
- Hand-off: *"We'll open Nomba's secure form to collect the card. You won't leave this page."*
- Trust line: `🔒 Secured & tokenized by Nomba`
- Token masked note: *"For security, the full token is never shown."*

**Empty states**
- Customers true-empty: *"Customers are the people and businesses you bill. Add one, give them a payment method, and you can put them on a subscription."*
- Filtered-empty: *"No customers match your search."*
- Addresses: *"Add a billing address to appear on this customer's invoices and receipts. It's optional."*

**Placeholders**
- Subscriptions: *"When you subscribe this customer to a plan, their active and past subscriptions will show up here. Available in the next release."* *(section is live — copy retained for empty state only)*
- Invoices: *"Every bill Bouclay raises against this customer will be listed here once billing is on. Available with invoicing."* *(section is live — copy retained for empty state only)*

**Validation (human, not framework)**
- Missing email: *"Add an email — it's where receipts go."*
- Bad email: *"That doesn't look like an email address."*
- Dupe external_ref: *"You already use this reference for another customer."*
- Soft dupe email: *"You already have a customer with this email. Create another?"*

**Success toasts**
- *"Customer created."* · *"Card added — Visa ···· 4242."* · *"Default payment method updated."* · *"Address saved."* · *"Customer archived."* (+ Undo) · *"Customer restored."*

**Confirmations**
- Archive one: *"Archive this customer? They'll stop appearing in your active list and can't be subscribed to new plans. You can restore them anytime."*
- Archive bulk: *"Archive {n} customers? …"*
- Remove last card: *"This is the only way to charge this customer. Remove it anyway?"*

**Tooltips (disabled future actions)**
- Create subscription: *"Subscriptions arrive in the next release."*
- Record transaction: *"Available once invoicing is on."*
- Nomba not connected: *"Connect your Nomba account to start accepting cards."*

---

## 17. Future-proofing

How this page absorbs Phases 5–13 without a redesign:

- **Subscriptions (P5):** ✅ the staged Subscriptions section (§11) became a real list in the *same slot*; the `+ New subscription` button opens `CreateSubscriptionDrawer`; the `Create subscription` Actions item is live.
- **Invoices (P6):** ✅ the staged Invoices placeholder was replaced by a real invoice table in the *same slot*; rows link to `/invoices/{id}`. **Total spend** is in the Overview facts grid via `Customer::totalSpend()`. Charge attempts are *not* listed here — they appear on the subscription hub as **Payments**.
- **Activity (P5–9):** the timeline's per-type renderer (§12) gains cases (`subscription.created`, `invoice.paid`, `payment.failed`) — additive, no structural change.
- **Segments (later):** if the team eventually wants Stripe-style preset tabs ("Top customers", "High refunds"), they slot in *above* the search as a `Tabs` row — the exact seam Catalog reserved for "All prices." One tab strip, not an IA rework. We intentionally *don't* build these now (no data, and Paddle-simplicity is the current goal).
- **Customer portal (P11):** the detail page's "Actions" gains a "Copy portal link" / "Send portal invite" item — a menu addition, not a page.
- **Multi-currency, bank/wallet methods:** the PM row is already generic over `type`; the facts grid already shows per-customer currency. Both absorb Nomba's non-card instruments and multi-currency customers without new components.

The governing promise: **the page's skeleton is its final skeleton.** Every later phase fills a reserved cell or adds a renderer case; none moves a wall.

---

## 18. Build sequencing

Suggested order within Phase 4, each step demoable:

1. **Nav + routes + list shell.** `Customers` sidebar item, `routes/customers.php`, index controller (paginated + searchable from the start — see §14.2), the table with search + status filter + count footer, true/filtered empty states. *Demo: an empty, well-copywritten Customers page.*
2. **Create customer drawer** (name + email + collapsed optionals) → toast → navigate to detail. *Demo: create a customer in five seconds.*
3. **Customer detail hub — static sections.** Header (monogram, status, copy-ID), Overview grid, all section scaffolding including the staged placeholders (§11) and the Developer block. *Demo: the full page shape, mostly empty but clearly staged.*
4. **Edit customer drawer** (grouped fields) + Metadata (reuse `edit-metadata-drawer`) + Archive/Restore with undo. *Demo: full customer CRUD, soft-delete.*
5. **Addresses** — list rows + progressive-disclosure add/edit drawer + set-default. *Demo: add a billing address without it feeling like a form.*
6. **Read-only Payment Methods section** — the list, PM detail drawer, set-default, and remove (`DELETE …/tokenized-card-data`), plus the educational empty state. No Add button. *Demo: a customer's saved cards, managed but not entered here.*
7. **The thin "Charge customer" checkout (test mode)** — the one card-collection trigger pulled forward (see `IMPLEMENTATION.md` Phase 4 way-forward). Nomba client wrapper (team keys) → `POST /v1/checkout/order` with `tokenizeCard:true` + real amount → redirect to `checkoutLink` → callback verifies (`/transactions/accounts/single`) + captures the token (minimal webhook extension §14.8) → persist the **`payment_methods` row only** (no `payments`/`invoices` yet). *Demo (the phase's exit criteria): charge a customer via test checkout, card stored against them.*
8. **Activity timeline** (derived from timestamps) + **bulk select/archive bar**, list polish, skeletons, toasts. *Demo: the page tells the customer's short story, Paddle-grade list ergonomics.*

Steps 1–6 stand alone (no checkout dependency) and can ship first — a fully usable Customers experience minus card collection. Step 7 is the only piece touching Nomba; its primitive is **verified** (§10.3) and reused by Phase 5 subscriptions, so building it thin now de-risks the hardest integration early.

---

**Bottom line.** The list stays intentionally Paddle-thin — one search, one status filter, one honest bulk bar — so daily use is calm. The detail page is built Stripe-deep and *whole from day one*: every future billing surface already has its reserved place, its forward-looking copy, and its disabled-but-visible action. Bouclay's promise to customers — *we never hold your card, only a token* — is stated exactly where it earns trust. Nothing here needs a redesign to become the complete billing hub of Phases 5–13; it only needs its reserved cells filled.
