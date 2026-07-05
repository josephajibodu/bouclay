# Bouclay — Subscriptions & State Machine Design Proposal (Phase 5)

The heart of the platform. Everything before this phase — Nomba BYOK, catalog, customers, tokenized cards — existed to make this one object possible: a **subscription** that bills a real customer, on a real schedule, into the merchant's own Nomba account.

This document is the product/UX source of truth for Phase 5. It does **not** redesign the schema (`schema.md` §4–5 is authoritative) and it honours every decision locked in `IMPLEMENTATION.md` and the Phase 4→5 handoff. Where a decision touches money movement or invoice records, this phase **stages** it (Phase 6) rather than building it — see §17.

Sibling docs: [`CATALOG_DESIGN.md`](CATALOG_DESIGN.md) (Phase 3), [`CUSTOMERS_DESIGN.md`](CUSTOMERS_DESIGN.md) (Phase 4). This doc reuses their idioms deliberately.

---

## Contents

1. [Principles](#1-principles)
2. [Information architecture & navigation](#2-information-architecture--navigation)
3. [The mental model: Customer × Prices → Subscription](#3-the-mental-model-customer--prices--subscription)
4. [The state machine, in plain language](#4-the-state-machine-in-plain-language) — incl. the hand-rolled transition-class pattern (no package)
5. [User journeys](#5-user-journeys)
6. [Subscriptions list](#6-subscriptions-list)
7. [Subscription creation experience](#7-subscription-creation-experience)
8. [Subscription detail page — the hub](#8-subscription-detail-page--the-hub)
9. [State visualization system](#9-state-visualization-system)
10. [The trial experience](#10-the-trial-experience)
11. [Subscription items](#11-subscription-items)
12. [Customer page integration](#12-customer-page-integration)
13. [Empty states](#13-empty-states)
14. [Loading, success & error states](#14-loading-success--error-states)
15. [Microinteractions](#15-microinteractions)
16. [Copy deck](#16-copy-deck)
17. [Schema-adjacent notes & the Phase 5/6 cut-line](#17-schema-adjacent-notes--the-phase-56-cut-line)
18. [Future-proofing](#18-future-proofing)
19. [Build sequencing](#19-build-sequencing)

---

## 1. Principles

Seven rules govern every decision below. When two ideas conflict, the earlier rule wins.

1. **State is the product.** A subscription is a state machine wearing a UI. The single most important job of this phase is to make the *current state* — and *what happens next* — legible at a glance to someone who has never built recurring billing. Every screen answers "what state is this in, and why?" before anything else.

2. **Never expose the machine.** `incomplete_expired`, `billing_cycle_anchor_on_trial_end`, `collection_mode` — these are internal. Users read *"Waiting for the first payment"*, *"Free trial · 9 days left"*, *"Charging automatically"*. Plain language on the surface; enum fidelity underneath.

3. **Paddle simplicity, Stripe richness.** The list is Paddle-thin (few columns, one status filter). The detail page is Stripe-deep (a dashboard, not an edit form). We borrow Stripe's information density only where a user has already committed to looking closely.

4. **API-first, dashboard-convenient.** In production, most subscriptions are born from the **API** — an end customer subscribes to a plan inside the integrator's own app (Phase 10), and Bouclay creates the subscription server-side. The dashboard create flow is the *secondary, human* path: a merchant/support agent setting one up by hand, or migrating a customer. So the dashboard form must **mirror the API's object model 1:1** (same items, same trial-offer references, same collection mode) and never invent a concept the API can't express. Stripe's create dialog proves the pattern — it carries a live **Code** tab showing the equivalent API call. We design the API shape and the form as one thing.

5. **Creation is a guided two-pane flow, not a form.** Subscriptions are the first Bouclay object complex enough to earn a dedicated create surface (a two-pane overlay: builder on the left, live preview on the right) instead of the catalog/customer 420px side-drawer. We justify the departure in §7 — but the *sub-actions inside it* (add a line item, add a trial) stay drawer/inline, preserving the house idiom.

6. **Money is staged, not faked.** Phase 5 creates subscriptions and computes trial clocks; it does **not** record `invoices`/`payments` (Phase 6). Anywhere a number would imply an accounting record, we show an honest *"Upcoming"* / *"Available with invoicing"* staged section — the same `StagedSection` pattern Phase 4 established. We never render a fabricated invoice.

7. **Design the empty rooms now.** Timeline, Future invoices, Future payments, Usage — most are placeholders today. We lay out their slots in this phase so Phases 6–9 slide in without a redesign, exactly as the customer hub staged Subscriptions and Transactions before they existed.

---

## 2. Information architecture & navigation

### Recommendation: `Subscriptions` becomes a new top-level sidebar item, between Customers and Developers.

The current sidebar (`app-sidebar.tsx`) is: **Overview · Catalog · Customers · Developers**. Subscriptions is the reason the product exists and the object users will open most often after launch. It earns a top-level slot, gated on `subscriptions.view`:

```
Overview
Catalog        ▸ Products
Customers
Subscriptions          ← NEW (icon: RefreshCw / Repeat)
Developers     ▸ Nomba Integration · API Keys · Webhooks
```

**Why top-level, not under Catalog or Customers:**

- A subscription is neither a catalog object (it's an *instance*, not a definition) nor owned by the Customers section (it spans customer × catalog). It is its own aggregate root in the schema (`subscriptions` hangs off `teams` directly).
- It is the primary daily surface for Support and Subscription-KPI roles, who may never touch Catalog.
- It gives the future roadmap a natural home: **Invoices** (Phase 6) will sit directly below it, and the two read as a pair — *"what they're subscribed to"* and *"what they've been billed."*
- **Paddle-validated:** Paddle's sandbox nav is exactly this flat top-level shape — *Transactions · Invoices · Subscriptions · Customers · Catalog* — with Subscriptions as its own primary item. (Paddle orders Invoices *above* Subscriptions; we put it *below* because in Bouclay invoices are generated *from* subscriptions, so the reading order mirrors the data flow. Minor, non-blocking.)

**Forward-looking nav (do not build the greyed items yet):**

```
Overview
Catalog
Customers
Subscriptions
Invoices        ← Phase 6 (add adjacent; same visual weight)
Developers
Settings        ← already exists under the user menu
```

### Icon & permission notes

- Icon: `RefreshCw` (already used for the customer-page Subscriptions staged section and the disabled "Create subscription" action — reusing it keeps the mental thread intact). `Repeat` is an acceptable alternative if we want to visually distinguish nav from the in-page "renews" affordance.
- The nav item renders only when `currentTeam && teamPermissions?.canViewSubscriptions`. `subscriptions.view` / `subscriptions.manage` **already exist and are seeded** (to Support + Subscription KPIs + implicitly Admin) per the handoff — no new permission, no 4-edit dance.
- Sub-items: none for MVP. Unlike Catalog (Products/…) and Developers (three pages), Subscriptions is a single list → detail. Keep it flat; add children only if/when "Cancellations" or "Scheduled changes" earn their own view (they won't in Phase 5).

### Route shape (integer id + `HasPublicId`, per handoff)

```
GET  /subscriptions                     → index (list)
GET  /subscriptions/new                 → create (guided flow)   [subscriptions.manage]
POST /subscriptions                     → store                  [subscriptions.manage]
GET  /subscriptions/{subscription}      → show (the hub)         route-model bound by integer id
POST /subscriptions/{subscription}/cancel|pause|resume  → lifecycle actions [subscriptions.manage]
```

Public id prefix `sub_` (e.g. `sub_3xK9…`), shown in the UI and copyable; route binding stays on the integer `id`, frontend passes `.id`. Run `php artisan wayfinder:generate` after adding these.

---

## 3. The mental model: Customer × Prices → Subscription

Before any pixels, the user must hold this sentence in their head:

> **A subscription attaches one customer to one or more recurring prices, and Bouclay keeps charging their card on schedule until it's canceled.**

The entities the user already knows collapse into one new thing — and, crucially, **a subscription is built from line items**. Each line item is *either* a plain recurring price *or* a trial offer. They sit side by side in the same list:

```
   Customer                 Catalog line items (add one or more)
   (Phase 4)         ┌─────────────────────────┬─────────────────────────┐
      │              │  a recurring PRICE       │  a TRIAL OFFER           │
      │              │  (Add product)           │  (Add trial)             │
      │              │  → bills every cycle     │  → trial price, then     │
      │              │                          │    transitions to a price│
      │              └────────────┬─────────────┴─────────────┬───────────┘
      └───────────────────┬───────┴───────────────────────────┘
                          ▼
                     SUBSCRIPTION  ──contains──▶  Subscription items
                     (this phase)                 (one row per line item)
                          │                        a trial line item also
                          ▼                        carries a current_trial
                  a lifecycle state + a billing clock
```

**Trials are line items, not a property of a price.** This is the single most important model decision (confirmed against Stripe's create-subscription dialog, where **Add trial** is a distinct action alongside **Add product**, and a trial is a first-class *trial offer* object). Consequences:

- The merchant **explicitly** adds a trial by picking a `trial_offer` from the catalog — the same way they add a product. Adding a trial creates a `subscription_item` whose `current_trial` (`subscription_item_trials`) is snapshotted from that offer.
- Bouclay **never auto-applies** a trial just because a chosen price happens to be some offer's `transition_price_id`. A price is just a price; a trial is a deliberate, separate choice. (This reverses the auto-detect idea in an earlier draft.)
- A trial line and a plain-price line can coexist on one subscription (e.g. *Pro on a 14-day trial* + *Seats billed immediately*) — the schema already supports this because trials attach per item, not per subscription.

**The two clocks** every subscription carries, surfaced everywhere:

- **Trial clock** — `trial_ends_at` (present only during a free trial). "Trial ends in 9 days."
- **Billing clock** — `current_period_end` → the next renewal. "Renews on 14 Aug." Absent until the sub is actually billing (i.e. not during `incomplete`, and computed on activation).

**One decision the merchant makes that everything downstream hangs on — collection mode** (schema `collection_mode: automatic | manual`, surfaced in Paddle's exact words):

| We store | We say (Paddle's exact framing) | What it means to the merchant |
|---|---|---|
| `automatic` | **"Automatically, using a stored payment method"** | Bouclay charges the customer's tokenized card each cycle. Needs a card on file (or collects one at signup). |
| `manual` | **"Manually, via invoice"** | Bouclay issues an invoice each cycle; the customer pays a link. No stored card required. |

Both labels are taken from Paddle's live create-subscription dialog (sandbox-verified). This choice drives which states are even reachable (see §4) and shapes the create flow's billing decision (§7.2).

---

## 4. The state machine, in plain language

The schema defines seven states (`subscriptions.status`). Users never see these words alone — each pairs with a color, a plain-language label, a one-line description, and an implied "what happens next." This table is the **canonical mapping** used by the status badge, the detail banner, and the copy deck (§16).

| Enum (internal) | Badge label | Dot color | Plain-language description | What happens next |
|---|---|---|---|---|
| `incomplete` | **Awaiting payment** | Amber | "The first payment hasn't gone through yet. Access shouldn't be granted until it does." | Customer completes the checkout → becomes **Active**. If it's not paid soon, it expires. |
| `incomplete_expired` | **Expired** | Zinc (muted) | "The first payment was never completed, so this subscription never started." | Terminal. Start a new subscription to try again. |
| `trialing` | **On trial** | Blue | "Currently on a free trial. No payment has been taken yet." | When the trial ends, it converts to the regular price and starts billing. |
| `active` | **Active** | Emerald | "Billing on schedule. The customer has access." | Renews automatically on the billing date. |
| `past_due` | **Past due** | Red | "A renewal payment failed. Bouclay is retrying." | Retries run on a schedule (dunning). Recovers to **Active**, or ends per your rules. |
| `paused` | **Paused** | Violet | "Billing is paused. No charges will be made while paused." | Resumes on the scheduled date, or when you resume it manually. |
| `canceled` | **Canceled** | Zinc (muted) | "This subscription has ended. No further charges." | Terminal. History stays visible. |

### Two derived UI states that are *not* enum values (but must be shown)

The schema encodes these with dates, not a status; the UI must surface them as first-class badges/banners so users aren't surprised at the period boundary:

- **"Active · cancels 14 Aug"** — `status = active` **and** `canceled_at`/`ends_at` set (a `scheduled_changes` row, action `cancel`). Emerald badge + amber inline banner. Stripe/Paddle both treat "cancel at period end" as the default cancel; showing it as a doomed-but-active state prevents the classic "I canceled but was still charged / lost access early" confusion.
- **"Paused · resumes 1 Sep"** — `status = paused` with `pause_resumes_at` set. Violet badge, resume date inline.

### The transition diagram (for the detail page's Status section — rendered as a simple horizontal stepper, not this graph)

```
                       ┌─────────────────────────────┐
   (paid trial /       │                             ▼
    automatic,   ┌──────────┐  first payment   ┌──────────┐  renewal fails  ┌──────────┐
    no card yet) │incomplete│ ───────────────▶ │  active  │ ──────────────▶ │ past_due │
                 └──────────┘                  └──────────┘ ◀────────────── └──────────┘
                       │ not paid in time            ▲  recovers /              │ dunning
                       ▼                             │  resume                  │ exhausted
                 ┌──────────────────┐                │                          ▼
                 │incomplete_expired│          ┌──────────┐               ┌──────────┐
                 └──────────────────┘          │  paused  │               │ canceled │
   (free trial, ┌──────────┐  trial ends       └──────────┘               └──────────┘
    no charge)  │ trialing │ ───────────────────────▲  ▲                        ▲
                └──────────┘  converts to price ─────┘  └── pause/cancel ────────┘
```

**Phase-5 honesty:** `past_due` and the dunning retries are **displayed** (a sub can be moved there), but the retry *worker* is Phase 8. `paused`/`canceled`/`scheduled_changes` transitions are user-triggered actions we build now (they need no money movement). The renewal charge that would drive `active → past_due` lands with the billing worker in Phase 6. We design the states now; some transitions animate in later phases (§18).

### Which states are reachable at creation, by branch

| Create branch | Lands in | Note |
|---|---|---|
| **Free trial** (trial price `unit_amount = 0`) | `trialing` | Skips `incomplete` entirely — no payment at signup. This is the exit-criteria happy path. `trial_ends_at` computed from the offer's `duration_iterations` × interval. |
| **Automatic + card already on file** | `incomplete` → `active` | First charge attempted via the tokenized-card recurring charge; success flips to `active`. (Money-record deferred — see §17.) |
| **Automatic + no card yet** | `incomplete` | Bouclay generates a Nomba hosted checkout link (reusing the Phase 4 tokenize-on-payment primitive). On payment (webhook), the card saves and the sub flips to `active`/`trialing`. |
| **Manual (invoice)** | `active` (or `trialing` if free trial) | No card required. The first invoice is *staged* this phase; the sub is created active so the merchant can see the lifecycle. |

### The state machine as code — a hand-rolled transition-class pattern (no package)

The seven states above are not a loose `status` string with `if`-checks scattered across controllers. We implement a **proper state machine** in the spirit of `spatie/laravel-model-states`, but **hand-rolled with zero dependencies**. The core idea:

- A **contract** (`SubscriptionState`) declares one method per lifecycle **action** — `activate`, `convert`, `pause`, `resume`, `cancel`, `markPastDue`, `recover`, `expire`. All unimplemented.
- An **abstract base** (`BaseSubscriptionState`) implements *every* contract method to **throw `IllegalStateTransition`** by default.
- **Seven concrete state classes** extend the base and override **only the actions legal from that state**. Everything they *don't* override inherits the throwing default. **Legality is expressed by omission** — a state's silence about an action *is* the guard. Calling `->pause()` on a `CanceledState` throws automatically, because `CanceledState` never implements `pause()`.

This makes illegal transitions impossible to reach by accident, turns the legality rules into small readable classes instead of a giant `match`, and makes each state trivially unit-testable.

**The legality matrix** (✔ = the class implements it; blank = inherits the throwing default):

| from ↓ · action → | `activate` | `convert` | `pause` | `resume` | `cancel` | `markPastDue` | `recover` | `expire` |
|---|---|---|---|---|---|---|---|---|
| **Incomplete** | ✔ → Active | | | | ✔ → Canceled | | | ✔ → IncompleteExpired |
| **Trialing** | | ✔ → Active | ✔ → Paused | | ✔ → Canceled | | | |
| **Active** | | | ✔ → Paused | | ✔ → Canceled | ✔ → PastDue | | |
| **PastDue** | | | | | ✔ → Canceled | | ✔ → Active | |
| **Paused** | | | | ✔ → Active/Trialing | ✔ → Canceled | | | |
| **Canceled** *(terminal)* | | | | | | | | |
| **IncompleteExpired** *(terminal)* | | | | | | | | |

`cancel` here means *immediate* cancellation. **"Cancel at period end" is not a state transition** — it writes a `scheduled_changes` row and leaves `status` untouched (§8.3); the scheduler later calls `->cancel(immediately: true)` when `effective_at` arrives. Same for scheduled pause/resume.

**Sketch** (`app/States/Subscription/`, enum in `app/Enums/`):

```php
// The contract — one method per action, nothing implemented.
interface SubscriptionState
{
    public function activate(): SubscriptionState;      // first payment captured
    public function convert(): SubscriptionState;       // trial ended → regular price
    public function pause(?CarbonInterface $resumesAt = null): SubscriptionState;
    public function resume(): SubscriptionState;
    public function cancel(bool $immediately = false): SubscriptionState;
    public function markPastDue(): SubscriptionState;   // renewal charge failed
    public function recover(): SubscriptionState;       // a dunning retry succeeded
    public function expire(): SubscriptionState;        // incomplete timed out
    public function status(): SubscriptionStatus;       // the enum value we persist
}

// The base — every action illegal until a subclass says otherwise.
abstract class BaseSubscriptionState implements SubscriptionState
{
    public function __construct(protected readonly Subscription $subscription) {}

    public function activate(): SubscriptionState { return $this->illegal('activate'); }
    public function convert(): SubscriptionState { return $this->illegal('convert'); }
    public function pause(?CarbonInterface $r = null): SubscriptionState { return $this->illegal('pause'); }
    public function resume(): SubscriptionState { return $this->illegal('resume'); }
    public function cancel(bool $immediately = false): SubscriptionState { return $this->illegal('cancel'); }
    public function markPastDue(): SubscriptionState { return $this->illegal('markPastDue'); }
    public function recover(): SubscriptionState { return $this->illegal('recover'); }
    public function expire(): SubscriptionState { return $this->illegal('expire'); }

    protected function illegal(string $action): never
    {
        throw IllegalStateTransition::make($this->subscription, $this->status(), $action);
    }

    protected function to(string $state): SubscriptionState
    {
        return new $state($this->subscription);
    }
}

// A concrete state implements ONLY its legal actions; the rest throw via the base.
final class ActiveState extends BaseSubscriptionState
{
    public function status(): SubscriptionStatus { return SubscriptionStatus::Active; }

    public function pause(?CarbonInterface $resumesAt = null): SubscriptionState
    {
        $this->subscription->pause_resumes_at = $resumesAt;   // state-owned field
        return $this->to(PausedState::class);
    }

    public function cancel(bool $immediately = false): SubscriptionState
    {
        return $this->to(CanceledState::class);
    }

    public function markPastDue(): SubscriptionState
    {
        return $this->to(PastDueState::class);
    }
    // activate / convert / resume / recover / expire → inherited → throw
}
```

**How the model drives it.** `status` casts to the `SubscriptionStatus` backed enum; the enum is the factory that resolves the state class, and it *also* carries the UI metadata the §9 kit reads — one source of truth for both the machine and the badge:

```php
enum SubscriptionStatus: string
{
    case Incomplete = 'incomplete';  case IncompleteExpired = 'incomplete_expired';
    case Trialing = 'trialing';      case Active = 'active';
    case PastDue = 'past_due';       case Paused = 'paused';   case Canceled = 'canceled';

    public function stateFor(Subscription $s): SubscriptionState
    {
        return match ($this) {
            self::Incomplete        => new IncompleteState($s),
            self::Trialing          => new TrialingState($s),
            self::Active            => new ActiveState($s),
            self::PastDue           => new PastDueState($s),
            self::Paused            => new PausedState($s),
            self::Canceled          => new CanceledState($s),
            self::IncompleteExpired => new IncompleteExpiredState($s),
        };
    }

    public function label(): string { /* "On trial", "Active", … (§4 table) */ }
    public function color(): string { /* emerald / amber / … (§9.4) */ }
    public function description(): string { /* plain-language line (§4) */ }
}

// Subscription model
public function state(): SubscriptionState
{
    return $this->status->stateFor($this);
}

/** Apply an action: throws if illegal, else persists status + fires side effects. */
public function apply(string $action, mixed ...$args): void
{
    $next = $this->state()->{$action}(...$args);   // guard — throws IllegalStateTransition
    $this->status = $next->status();
    $this->save();
    // centralized side effects: append a timeline entry, fire a domain event,
    // recompute current_period_* where the transition demands it.
}
```

**Side-effect discipline.** State classes stay thin: they validate legality and mutate only *state-owned* fields (e.g. `pause_resumes_at`), returning the next state. The `apply()` orchestrator owns the cross-cutting effects (persist `status`, timeline, events, period math), so states unit-test with no DB. Two transitions do real work beyond a status flip — **`activate`** (set `current_period_start/end`, run the first charge) and **`convert`** (swap the item's `trial_price_id → transition_price_id`, reset the billing anchor). For those, the state method may delegate to a small invokable **transition/action class** rather than inline the logic — the one place we borrow Spatie's "Transition" concept.

**Who calls each action, and when it lands:**

| Action | Triggered by | Phase it's wired |
|---|---|---|
| `activate` | first-charge success (create flow) / inbound webhook | **5** (sync) → **7** (webhook) |
| `pause` / `resume` / `cancel` (immediate) | dashboard lifecycle actions (§8.3) | **5** |
| `cancel` (at period end) | `scheduled_changes` + scheduler calls `cancel(immediately:true)` | **5** (schedule) → worker **6/8** |
| `markPastDue` | renewal charge failure | **6** (billing worker) / **7** (webhook) |
| `recover` | dunning retry success | **8** |
| `convert` | trial worker at `trial_ends_at` | **6/8** |
| `expire` | incomplete-timeout job | **8** |

**Phase 5 builds the entire machine** (contract, base, all seven states, the enum factory, `IllegalStateTransition`, `apply()`), plus the user-triggered transitions (`activate`, `pause`, `resume`, `cancel`). The workers that call `markPastDue`/`recover`/`convert`/`expire` arrive in Phases 6–8 — but they call the **same methods on the same machine**, so nothing is re-architected later. This is the code-level expression of the doc's "design the states now; some transitions animate in later phases."

**Testing (a Phase-13 deliverable made cheap).** The pattern turns state coverage into a table-driven unit test: for every (state × action) cell, assert the ✔ cells return the expected next state and **every blank cell throws `IllegalStateTransition`** — no database, no HTTP. The legality matrix above *is* the test's data provider.

---

## 5. User journeys

Five journeys, written as the story a real merchant lives. Each names the screens and the state transitions.

### Journey A — "Put my first customer on the Pro plan with a 14-day free trial" (the demo path)

1. Merchant clicks **Subscriptions → New subscription** (or, from a customer's page, **Actions → Create subscription** — now un-disabled).
2. **Customer**: searches "Ada" → picks Ada Obi. (Launched from the customer page, this is pre-filled and collapsed.)
3. **Line items**: instead of Add product, the merchant clicks **Add trial** → picks the *Pro · 14-day free trial* offer. A trial line appears: *"🎁 Pro · Free · then Pro Monthly ₦15,000/mo."* The Preview timeline updates: *"Free until 19 Jul, then ₦15,000/mo."*
4. **Billing**: because the only line is a free trial and Ada has no card, the CTA reads **Start free trial** with the consequence *"No payment today. First charge ₦15,000 on 19 Jul."* (Merchant may set the trial-end behavior in Advanced, but the default is fine.)
5. Clicks **Start free trial**. Sub is created **On trial**, `trial_ends_at = 19 Jul` (from the offer snapshot). Redirect to the **detail hub** with a success toast: *"Subscription started — Ada is on a free trial until 19 Jul."*

✅ Exit criteria met: customer subscribed, status visible (`trialing`), trial end date computed.

### Journey B — "Subscribe them and charge the card I have on file now"

1–2 as above.
3. **Line items**: clicks **Add product** → *Pro → Pro Monthly ₦15,000/mo*. No trial line added.
4. **Billing** = **Automatic**; Bouclay shows Ada's default card (`···· 4242`); the CTA reads **Start subscription** · *"₦15,000 charged to Visa ···· 4242 now, then monthly."*
5. **Start subscription** → sub created `incomplete`, first charge fires against the token. On success → **Active**, `current_period_end` set. Toast: *"Subscription active — ₦15,000 charged to Visa ···· 4242."* On decline → stays **Awaiting payment** with a red banner and a **Retry / update card** action (see §14).

### Journey C — "Subscribe a brand-new customer who has no card yet"

1–3 as B (an Add-product line, no trial), but Ada has no payment method and collection is **Automatic**.
4. **Billing** shows an amber note under Automatic: *"Ada doesn't have a card on file. We'll create the subscription and send a secure checkout link to collect one. Access starts once they pay."* The CTA becomes **Create & send payment link**.
5. **Start** → sub created **Awaiting payment**; a hosted Nomba checkout link is generated (tokenize-on-payment) and surfaced on the detail hub with **Copy link** + **Send to customer** (email is Phase 6+; copy works now). When paid → card tokenizes, sub flips **Active**. The hub's amber banner clears.

### Journey D — "What's going on with this subscription?" (the read path — most frequent)

Support opens **Subscriptions**, filters **Past due**, sees Ada's row glow red with *"Renewal failed · retry 2 of 4."* Clicks in → the hub's **Status banner** explains in plain language what failed, when the next retry runs, and offers **Update payment method** / **Retry now**. No enum jargon anywhere.

### Journey E — "Cancel at the end of the period"

From the hub, **Actions → Cancel subscription** opens a **Dialog** (destructive confirmation, house idiom): two choices — **At end of billing period (recommended)** vs **Immediately**. Merchant picks end-of-period. The sub *stays* **Active** with a new amber sub-badge *"Cancels 14 Aug"* and a timeline entry *"Cancellation scheduled."* An **Undo** affordance persists until the date. This writes a `scheduled_changes` row (`action: cancel`, `effective_at`), never an immediate status flip.

---

## 6. Subscriptions list

### Recommendation: Paddle-thin. One status filter, search, six columns, server-side.

Mirrors the Phase 4 customers list decision (server-side search + pagination from the start — this table grows fastest of all). No dozens of filters.

### 6.1 Layout

```
┌───────────────────────────────────────────────────────────────────────────────┐
│  Subscriptions                                            [ + New subscription ] │
│                                                                                  │
│  [ 🔍 Search customer, plan, or ID… ]        Status ▾ [ All active ]             │
│                                                                                  │
│  CUSTOMER            PLAN                STATUS         NEXT BILLING    CREATED   │
│  ───────────────────────────────────────────────────────────────────────────── │
│  ● Ada Obi           Pro Monthly         ● On trial     19 Jul (trial) 5 Jul     │
│    ada@acme.io       +1 more                                                     │
│  ● Bola Ade          Team Annual         ● Active       14 Aug         2 Jul     │
│    bola@nova.co                                                                  │
│  ● Chidi Eze         Pro Monthly         ● Past due      —             28 Jun     │
│    chidi@x.dev                             retry 2 of 4                           │
│  ● Dami Ola          Starter Monthly     ● Active        1 Sep                    │
│    dami@io.africa                          cancels 1 Sep ⚠                        │
│  ─────────────────────────────────────────────────────────────────────────────  │
│                                        ‹ Prev   Page 1 of 4   Next ›              │
└───────────────────────────────────────────────────────────────────────────────┘
```

### 6.2 Columns (exactly six)

1. **Customer** — monogram + name over email (reuse `CustomerMonogram`). Falls back to email when name is null (Phase 4 convention).
2. **Plan** — the primary item's product/price name; *"+N more"* pill when the sub has multiple items. Keeps the row scannable without a sub-table.
3. **Status** — the badge from §9. For `past_due`/scheduled-cancel, a tiny second line ("retry 2 of 4", "cancels 1 Sep ⚠") — the only place we allow a status subtitle.
4. **Next billing** — `current_period_end` formatted. Shows **"19 Jul (trial)"** when trialing, **"—"** when `incomplete`/`canceled`. This single column doubles as the "trial end" signal the brief asked for, avoiding a seventh column.
5. **Created** — `created_at`, `formatDate` (Phase 4 helper).
6. (implicit) **Row → detail** on click; no visible ID column — the `sub_…` id lives on the detail page and in search.

### 6.3 Search & filter

- **Search** (server-side, `LOWER(col) LIKE ?` — never `ilike`, SQLite tests): matches customer name/email, product/price name, and the `sub_…` public id. Debounced 300ms, preserves the status filter in the query string.
- **Status filter**: a single dropdown, default **"All active"** (a friendly union of `trialing + active + past_due` — the states a merchant cares about day-to-day). Options: *All active* · *On trial* · *Active* · *Past due* · *Paused* · *Canceled* · *Awaiting payment* · *All*. One control, plain-language labels, no multi-select — Paddle discipline.

### 6.4 Sorting & row interaction

- Default sort: **Created (newest)**. Secondary useful sort: **Next billing (soonest)** — the "what's about to renew/fail" view. Sortable headers on those two only.
- Whole row is a link to the hub (cursor-pointer, hover tint), matching the customers list.
- **No bulk actions in Phase 5.** (Phase 8 dunning / Phase 11 portal may add "cancel selected"; not now.)

---

## 7. Subscription creation experience

### Recommendation: a two-pane create surface at `/subscriptions/new` — builder on the left, live **Preview** on the right — that mirrors the API object 1:1. This is the one deliberate departure from the 420px side-drawer idiom.

**Why not the 420px drawer** (the catalog/customer idiom)? A subscription composes a customer, one-or-more **line items** (each a price *or* a trial offer), a billing decision, and it branches (collection mode × card-on-file × trial). That's past what a narrow drawer serves well. Stripe's own dashboard uses a wide two-pane overlay here — form left, **Summary / Invoice / Code** preview right — and we adopt the same shape. We keep the house idiom *inside* the flow: adding a line item is an inline row + small picker; the picker itself is a popover, not a page.

**Why two panes, not a stepper.** A wizard hides later fields, but here **later choices change earlier meaning** (adding a trial rewrites the billing copy; a cardless customer changes the collection options). The left pane is a single scroll; the right pane is a **live preview** that answers *"what will actually happen when I click Start?"* — the review is continuous, not a gate. The preview has two tabs, matching Stripe:

- **Summary** — a plain-language timeline: *"Starts today (free trial) → first charge ₦15,000 on 19 Jul → renews monthly."*
- **Invoice** — the first-invoice breakdown (subtotal, tax, total) plus the **First payment / Recurring payment** split borrowed from Paddle's create dialog — because a trial or proration makes *today's* charge differ from the *steady-state* charge, and showing both prevents "why was I only charged ₦0 / why did the amount change" confusion. For a free trial: *First payment ₦0.00 today · Recurring ₦15,000/mo from 19 Jul.* (Honest about Phase 5: a **preview**, not a stored invoice — see §17.6.)

*(A future **Code** tab — the equivalent API call — is the natural home for Phase 10's API-first story; reserve the slot, don't build it now.)*

**Cross-checked against Paddle's create-subscription dialog** (sandbox), which validated four choices and refined three: it (a) leads with the **collection-mode** question in near-identical words, (b) calls each row a **line item**, (c) shows the **First payment / Recurring payment** split, (d) exposes **Preview invoice** + **Save draft**, and (e) still carries the **Business (optional)** B2B field we deliberately dropped (`IMPLEMENTATION.md` #8). The one place we follow **Stripe over Paddle**: trials. Paddle bakes a trial into the *price* (trial-period days on the catalog price, no separate action); Stripe — and Bouclay — treat a trial as a first-class object added explicitly (**Add trial**), because our `trial_offers` schema is the richer Stripe shape (trial price → transition price, repeatable).

### 7.1 Wireframe

```
┌─────────────────────────────────────────────┬───────────────────────────────┐
│  New subscription                     [ ✕ ]  │   Preview                     │
│                                               │  [ Summary ] [ Invoice ]      │
│  ┌─ Customer ───────────────────────────────┐ │  ───────────────────────────  │
│  │ [ 🔍 Search or create a customer…    ]    │ │   ▸ Starts today              │
│  │  ● Ada Obi  ada@acme.io   Visa ···· 4242  │ │     Free trial (14 days)      │
│  └───────────────────────────────────────────┘ │   ▸ 19 Jul                    │
│                                               │     Trial ends → first charge │
│  ┌─ Line items ─────────────────────────────┐ │     ₦15,000, then monthly     │
│  │  PRODUCT / TRIAL          QTY     AMOUNT   │ │                               │
│  │  ────────────────────────────────────────  │ │   Due today        ₦0.00      │
│  │  🎁 Pro · 14-day free trial  1   Free      │ │                               │
│  │     then Pro Monthly ₦15,000/mo        ⋯   │ │  ───────────────────────────  │
│  │  Seats add-on             [3]   ₦6,000/mo⋯ │ │                               │
│  │                                           │ │  [    Start subscription   ]  │
│  │  [ + Add product ]   [ + Add trial ]      │ │   Creates the subscription    │
│  └───────────────────────────────────────────┘ │   and its first period.       │
│                                               │                               │
│  ┌─ Billing ────────────────────────────────┐ │                               │
│  │ How do you want your customer to pay?     │ │                               │
│  │ (●) Automatically, using a stored card    │ │                               │
│  │ ( ) Manually, via invoice                 │ │                               │
│  │ Card:  Visa ···· 4242 (default) ▾         │ │                               │
│  │ ▸ Advanced (trial-end behavior, anchor)   │ │                               │
│  └───────────────────────────────────────────┘ │                               │
└─────────────────────────────────────────────┴───────────────────────────────┘
```

### 7.2 The sections (Customer → Line items → Billing), with the live Preview as the continuous "Review"

**Customer.** Type-ahead over the team's customers (server search), each row showing card-on-file status (this pre-informs Billing). A **＋ Create a customer** opens the existing Phase-4 `EditCustomerDrawer` inline — no context loss — and selects the new customer on save. Launched **from a customer's page** (`?customer={id}`), this is pre-filled and collapsed with a "Change" link.

**Line items — the heart of the flow, with two add actions side by side.** This section replaces the old separate "Products" and "Trial" sections. A subscription is a list of line items; each is one of two kinds:

- **＋ Add product** → popover picker: choose product → choose one of its **recurring** prices (one-time prices filtered out). Adds a plain price line with an inline **quantity** stepper. This is the ordinary billed item.
- **＋ Add trial** → popover picker of the team's **trial offers** (catalog `trial_offers`). Adds a **trial line item**: it shows the product, the trial price (**Free** or **₦X**), the duration, and — critically — *"then transitions to {transition price}"*. Under the hood this creates a `subscription_item` whose `current_trial` snapshots the offer (§17.6). **We do not auto-add or auto-suggest a trial from a chosen price** — the merchant decides, per principle 4 and §3.

Both kinds coexist in one list (trial line + billed add-on). Each row carries a **⋯** (remove). The empty state nudges the simple path: *"Add the product or plan you're billing for. Add a free trial if you offer one."* Currency is guarded at the source: only prices/offers in the **customer's currency** (or team default) are selectable — a subscription is single-currency for life (schema) — so a mixed-currency cart is *prevented*, not error-validated.

Why this beats a trial toggle: it matches the schema (trials attach per `subscription_item`, not per subscription), it matches the API (a trial is an item with a `trial_offer_id`, not a flag on the subscription), and it matches Stripe (Add trial is a first-class action). A merchant who wants *no* trial simply never clicks Add trial — there is no hidden auto-application to reason about.

**Billing.** The `collection_mode` choice, phrased as Paddle phrases it (their sandbox uses these near-verbatim):
- **(●) Automatically, using a saved card** (`automatic`) — *"The saved card is charged automatically each time a payment is due."* Card selector defaults to the customer's default PM. If **no card**, still selectable, with the Journey-C amber note ("we'll send a secure checkout link to collect one; access starts once they pay").
- **( ) Manually, via invoice** (`manual`) — *"An invoice is sent each time payment is due, paid by link or transfer."* No card required.
- **▸ Advanced** (collapsed `Disclosure`, off by default): **When a free trial ends** (`trial_end_behavior`: *Start billing* / *Pause* / *Cancel* — only relevant when a free-trial line exists **and** there's no card, the Stripe "missing payment method" fork) and **Billing date after trial** (`billing_cycle_anchor_on_trial_end`). Power controls, hidden until relevant.

*Ordering note:* Paddle leads its whole dialog with this collection-mode question (before customer or items). We deliberately place it **after** Customer and Line items instead, because in Bouclay the items decide whether money is even due today (a free-trial-only subscription needs no card now), and the customer decides whether a card already exists — so the payment decision is best made *informed* by both. The Preview keeps the consequence visible regardless of order.

**Preview pane (the continuous "Review").** Always visible right (desktop) / a bottom sheet toggled by a "Preview" pill (mobile). Live-updates the **Summary** timeline and the **Invoice** breakdown / **Due today** as items and billing change. The primary CTA **Start subscription** lives here with a one-line consequence statement that adapts to the branch (§7.3).

### 7.3 Validation, guardrails & the adaptive CTA (inline, never a wall of errors)

- **Start subscription** is disabled until a customer is chosen **and** ≥1 line item added. Tooltip on the disabled button names the missing piece.
- The CTA label and consequence line adapt to the branch (honest labeling beats a blocking error):
  - Free-trial line, no charge today → **"Start free trial"** · *"No payment today. First charge ₦15,000 on 19 Jul."*
  - Automatic + card on file → **"Start subscription"** · *"₦15,000 charged to Visa ···· 4242 now, then monthly."*
  - Automatic + no card → **"Create & send payment link"** · *"Ada has no card — access starts once they pay the checkout link."*
  - Manual → **"Start subscription"** · *"We'll invoice Ada ₦15,000; no card is charged."*
- Currency mismatch is *prevented* (only compatible prices/offers selectable), not validated after the fact.
- Server-side: `subscriptions.manage` gate; `once_per_customer` trial-offer check — a customer who already used a trial offer gets a quiet inline note on that Add-trial row (*"Ada already used this trial — add the regular price instead."*), enforcing the anti-abuse rule at pick time.

### 7.4 The dashboard flow ↔ API parity

Because the dashboard form mirrors the API object (principle 4), the same three inputs map straight to the Phase-10 `POST /subscriptions` body: `customer`, `items[]` (each either `{ price, quantity }` or `{ trial_offer }`), and `collection_mode` (+ optional `payment_method`, `trial_end_behavior`). Designing them together now means Phase 10 exposes what the dashboard already does — no second model, no divergence. The Preview's future **Code** tab is where that equivalence becomes visible to developers.

---

## 8. Subscription detail page — the hub

### Recommendation: a single scrollable dashboard (like the product and customer hubs), not tabs, not an edit page. Sections stack; every mutation is a drawer or dialog.

This page is the **long-term home** for a subscription. Phases 6–9 add invoices, payments, proration, and events *into slots we place now*. The layout intentionally leaves those rooms empty-but-labeled (`StagedSection`), exactly as the customer hub did.

### 8.1 Page skeleton

```
‹ Subscriptions

┌───────────────────────────────────────────────────────────────────────────┐
│  ● Ada Obi — Pro Monthly            [ On trial ]            [ Actions ▾ ]    │  ← header
│  Trial ends in 9 days · 19 Jul                                              │
│  sub_3xK9d2 · Started 5 Jul  ⧉                                              │
└───────────────────────────────────────────────────────────────────────────┘

┌─ Status banner (contextual, color-coded) ─────────────────────────────────┐
│ 🎁 On a free trial. No payment has been taken yet. On 19 Jul this converts │
│    to Pro Monthly (₦15,000/mo) and the first charge runs.                  │
└───────────────────────────────────────────────────────────────────────────┘

Overview                              ┌── quick facts grid ──────────────────┐
                                      │ Status      On trial                  │
                                      │ Customer    Ada Obi                    │
                                      │ Amount      ₦15,000 / month            │
                                      │ Collection  Automatic · Visa ···· 4242 │
                                      │ Trial ends  19 Jul (9 days)            │
                                      │ Next bill   19 Jul                     │
                                      └───────────────────────────────────────┘

Subscription items
  ┌──────────────────────────────────────────────────────────────────────┐
  │ Pro · Pro Monthly      ₦15,000 × 1     /month     🎁 On trial          │
  │ Seats add-on           ₦2,000  × 3     /month                          │
  └──────────────────────────────────────────────────────────────────────┘
                                                        Total  ₦21,000 /month

Trial                         🎁  Free trial · 14 days · started 5 Jul
  ┌── mini timeline ─────────────────────────────────────────────────────┐
  │  ●━━━━━━━━━━━━━━━○─────────────────  Ends 19 Jul → converts to Pro     │
  │  Started        Today (day 5)        First charge ₦15,000              │
  └──────────────────────────────────────────────────────────────────────┘

Billing schedule
  Starts 5 Jul · Trial until 19 Jul · First charge 19 Jul · then monthly

Payment method
  Visa ···· 4242 (default)                       [ Change ]  ← reuses PM list

Timeline
  ● Subscription created            5 Jul, 10:02
  ● Free trial started (14 days)    5 Jul, 10:02

Upcoming invoices   ▸ StagedSection  "Invoices will appear here once billing runs (with invoicing)."
Payments            ▸ StagedSection  "Charges against this subscription will be listed here."
Usage               ▸ StagedSection  "Usage-based items will show here."   (only if ever relevant)

Metadata            key/value (custom_data), same component as customer hub

Developer
  Subscription ID  sub_3xK9d2  ⧉
  Customer ID      cus_…       ⧉
  Created          5 Jul 2026, 10:02
```

### 8.2 Section-by-section rationale

- **Header** — customer + primary plan as the title (the two things a human identifies a sub by), the **status badge**, and a **one-line "what's next"** (trial countdown / next bill / retry). `sub_…` id is copyable inline (⧉), matching the customer hub's copy-id affordance and the "Copy Subscription ID" microinteraction the brief asked for.
- **Status banner** — the single most important element. A color-matched, icon-led sentence that changes per state (full copy in §16). It's where we *explain the situation in plain language* per the brief. Only shown when there's something to say (trial, past_due, scheduled cancel/pause, awaiting payment); hidden for a boring healthy `active`.
- **Overview facts grid** — same `Fact` component and 2/3-col grid as the customer hub, for visual continuity. Six facts, no more.
- **Subscription items** — see §11.
- **Trial** — see §10; renders only when a trial exists, otherwise absent (not an empty box).
- **Billing schedule** — a plain-language sentence + (later) a horizontal period ruler. Phase 5 ships the sentence; Phase 6 adds the visual ruler into the same slot.
- **Payment method** — reuses the Phase-4 read-only PM row; **Change** swaps the sub's `payment_method_id` (a small dialog listing the customer's cards). For manual/cardless subs, this becomes the "collect a card" affordance (checkout link).
- **Timeline** — reuses the customer-hub activity list component. Phase 5 emits: *created, trial started, item added/removed, canceled/scheduled, paused/resumed, payment method changed.* Phases 6–9 append *invoice finalized, payment succeeded/failed, retry, converted from trial* — same component, no redesign.
- **Upcoming invoices / Payments / Usage** — `StagedSection`s. This is where principle 7 ("design the empty rooms") pays off: Phase 6 replaces *Upcoming invoices* and *Payments* placeholders with real tables **in the same slot**, exactly as Phase 6 will replace the customer-page Transactions placeholder.
- **Metadata / Developer** — identical pattern to the customer hub (`custom_data` k/v; copyable ids; timestamps).

### 8.3 The Actions menu (state-aware)

A single **Actions ▾** (house pattern, like the customer hub), items conditioned on state and `subscriptions.manage`:

| Action | Shown when | Opens |
|---|---|---|
| Manage items (add/remove) | active, trialing, past_due | drawer |
| Change payment method | any non-terminal | dialog |
| Pause subscription | active, trialing | dialog |
| Resume subscription | paused | dialog |
| Cancel subscription | active, trialing, past_due, paused | dialog (end-of-period vs immediate) |
| Retry payment / Collect card | incomplete, past_due | action / checkout link |
| Copy subscription ID | always | — |

Destructive/irreversible (cancel immediately) uses a **Dialog** with type-nothing-just-confirm, matching the house rule (drawers for create/edit, dialogs for confirmations). "Cancel at period end" is **not** treated as destructive-scary — it's the recommended, reversible default (§5-E).

---

## 9. State visualization system

A small, reusable kit so state reads identically on the list, the hub, the customer page, and future invoice rows.

### 9.1 `SubscriptionStatusBadge`

A dot + label, color per §4. Built on the existing `Badge` (`variant="secondary"` shell + colored dot, matching the customer `StatusBadge` idiom — e.g. `<span className="size-1.5 rounded-full bg-emerald-500" /> Active`). One component, one source of truth for label+color, imported everywhere. Sizes: `sm` (list/table) and `default` (header).

```
● Awaiting payment   (amber)      ● Active        (emerald)     ● Paused    (violet)
● On trial           (blue)       ● Past due      (red)         ● Canceled  (zinc)
● Expired            (zinc)
```

### 9.2 `SubscriptionStatusBanner`

The contextual explainer on the hub (§8.2). Props: `status`, dates, retry info, scheduled change. Renders icon + color band + the plain-language sentence + up to two inline actions. Copy in §16. Returns `null` for a healthy `active` with nothing scheduled (no noise).

### 9.3 State stepper (Status section, later)

A horizontal 3–4 node stepper showing where the sub is in its life: `Started → Trialing → Active → (Renewing)`. Current node filled, future nodes hollow, terminal (canceled/expired) shown greyed with a strike. Phase 5 can ship a static version; it's the natural slot for the transition diagram from §4 rendered as UI, not ASCII.

### 9.4 Color discipline

Colors are **semantic, not decorative**: amber = needs attention/pending, emerald = healthy, red = failing, blue = trial (neutral-good), violet = intentionally-suspended, zinc = ended/inert. This same palette should later color invoice statuses (paid=emerald, open=amber, uncollectible=red) so the whole product speaks one color language.

---

## 10. The trial experience

Trials already exist in the catalog (`trial_offers`, Phase 3). Phase 5 turns them into a lived experience via `subscription_item_trials`. The brief's demand: the user must *immediately* understand duration, start, end, what happens after, and whether payment will occur.

**A trial is added explicitly, as its own line item** (via **Add trial** in the create flow, §7.2) — never auto-applied because a chosen price is some offer's transition target. So everywhere below, a trial is *an item that happens to carry a `current_trial`*, not a mode the whole subscription is in. A subscription can hold a trial line and ordinary billed lines at once.

### 10.1 The four questions, answered in one card

Every trial surface answers, in order:

1. **How long?** — "14-day free trial" (from `duration_iterations` × interval, or `duration_ends_at`).
2. **When does it end?** — "Ends 19 Jul" + a live countdown "9 days left".
3. **What happens then?** — "Converts to Pro Monthly (₦15,000/mo)" (the `transition_price_id`, named).
4. **Will they be charged?** — the crux: **"First charge: ₦15,000 on 19 Jul"** (automatic w/ card) · **"We'll send an invoice on 19 Jul"** (manual) · **"No card on file — add one before 19 Jul to avoid interruption"** (the `trial_end_behavior` fork).

### 10.2 Free vs paid, made obvious

- **Free trial** (`trial_price.unit_amount = 0`): badge **🎁 Free trial**, sub state **On trial**, "Due today ₦0.00", no charge at signup. This is the reassuring, no-surprise default.
- **Paid trial** (`trial_price.unit_amount > 0`, e.g. "₦1 for 14 days"): badge **Paid trial**, and — per the schema's state-machine note — the sub follows `incomplete → active`, **not** `trialing` (Stripe treats paid trials as active). We reflect that honestly: the badge says **Active**, and the Trial card explains "You're on the ₦1 intro price until 19 Jul, then ₦15,000/mo." No pretending it's a free ride.

### 10.3 The trial card & mini-timeline (hub)

```
Trial                                    🎁  Free trial · 14 days
┌──────────────────────────────────────────────────────────────────────┐
│  ●━━━━━━━━━━━━━●─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ○                                   │
│  Started 5 Jul   Today (day 5/14)     Ends 19 Jul                       │
│                                                                        │
│  When the trial ends → converts to Pro Monthly · first charge ₦15,000  │
│  Paid automatically with Visa ···· 4242                                │
└──────────────────────────────────────────────────────────────────────┘
```

The progress bar is the emotional core — it makes "time left" felt, not read. It also gives Phase 8 dunning a natural place to show "trial ended, awaiting payment."

### 10.4 Where trials appear (without overwhelming)

- **List**: only as the "Next billing" cell showing "19 Jul (trial)" + the **On trial** badge. No dedicated column.
- **Create flow**: the **Add trial** line item + the Preview timeline — no separate trial section.
- **Hub**: the Trial card (above) + one Overview fact ("Trial ends 19 Jul · 9 days"). That's it — prominent but not repeated five times.
- **Customer page**: the subscription row shows the trial badge (§12).

### 10.5 Trial-end honesty (the `trial_end_behavior` fork)

When a **free trial has no card** at signup, Bouclay must decide what to do at `ends_at`. We surface `trial_end_behavior` as a plain choice in the create flow's Advanced billing (§7.2) and echo the consequence in the Trial card:

- `create_invoice` → "We'll send an invoice when the trial ends."
- `pause` → "The subscription pauses until a card is added."
- `cancel` → "The subscription cancels if no card is added by 19 Jul."

This is the single biggest source of "why was I charged / why did I lose access" confusion in real billing products; naming it up front is the whole point of the trial UX.

---

## 11. Subscription items

A subscription is a list of line items. Each item is one of **two kinds** — an ordinary **priced item** or a **trial item** (a priced item that carries a `current_trial`). They render in the same list, visually distinguished. Design it elegant and upgrade-ready.

### 11.1 Display (hub)

```
Subscription items                                        [ Manage items ]
┌──────────────────────────────────────────────────────────────────────┐
│ 🎁 Pro        14-day free trial   Free    × 1   /month   On trial      │
│               then Pro Monthly ₦15,000/mo · first charge 19 Jul        │
│ ▣ Add-on      Seats               ₦2,000  × 3   /month                 │
└──────────────────────────────────────────────────────────────────────┘
                              Billing now  ₦6,000/mo   ·   From 19 Jul  ₦21,000/mo
```

Each row communicates the five things the brief requires: **Product · Price (name) · Quantity · Billing interval · Trial (if the item carries one)**. A **trial item** shows the trial price (Free / ₦X), the **transition line** (*"then Pro Monthly ₦15,000/mo · first charge 19 Jul"*), and the **On trial** state; a plain item shows just its price. The trial badge sits on the *item*, not the subscription — correct, because `subscription_item_trials` attaches per item (`items[].current_trial`), and a mixed subscription (one item trialing, another billing now) renders correctly for free. Instead of one ambiguous Total, the footer uses Paddle's **now vs recurring** split (*Billing now* / *From {date}*) so the merchant sees exactly what the trial changes at conversion.

### 11.2 Interaction

- **Quantity** is inline-editable on `active` subs (stepper) → in a full billing world this triggers proration; Phase 5 changes quantity without proration, and the row shows a quiet *"Changes apply on the next renewal"* note (honest until Phase 6 wires proration). Recommended: read-only inline, editable in the **Manage items** drawer, to avoid implying instant proration.
- **Manage items** opens a drawer with the same two add actions as the create flow — **Add product** and **Add trial** — plus remove (`subscription_items.status = removed`, never a hard delete — history matters). Removing the last billable item warns and offers Cancel instead.
- **Upgrade/downgrade** (swap price) is intentionally **not** in Phase 5 (it's a proration event → Phase 6). But the row's `⋯` menu reserves the slot with a disabled **"Change plan · Soon"** item, so the affordance's home is already decided.

### 11.3 Empty/degenerate states

- A one-item sub hides the **Total** line (redundant) and shows just the single row — no ceremony.
- A sub can never have zero active items (guarded at create and on remove-last).

---

## 12. Customer page integration

Phase 4 deliberately staged two things on `customers/show.tsx`; Phase 5 activates them **without a redesign** — that was the point of the `StagedSection` pattern.

### 12.1 Un-disable the two stubs (per the handoff)

1. **Actions → "Create subscription"** — currently `disabled` with a "Soon" pill (`show.tsx:754`). Enable it; it deep-links to `/subscriptions/new?customer={id}`, launching the create flow with section 1 pre-filled (§7).
2. **Subscriptions `StagedSection` CTA** — the disabled "New subscription" button (`show.tsx:433`). When the customer has **zero** subscriptions, the section keeps its educational empty copy but the CTA becomes live. When they have **one or more**, the `StagedSection` is *replaced in the same slot* by a real list:

```
Subscriptions                                          [ + New subscription ]
┌──────────────────────────────────────────────────────────────────────┐
│ Pro Monthly + 1 more     ● On trial     Trial ends 19 Jul    →         │
│ Starter Monthly          ● Canceled     Ended 2 Jun          →         │
└──────────────────────────────────────────────────────────────────────┘
```

Each row: primary plan (+N more), status badge, the relevant date (trial end / next bill / ended), links to the sub hub. This is the customer-scoped mirror of the global list, thin.

### 12.2 Overview grid gets one new fact

The customer Overview grid (`show.tsx:266`) gains an **"Active subscriptions"** fact ("2 active · 1 trialing" or "None"), slotting beside the existing Default payment method / Currency facts. No layout change — it's another `Fact`.

### 12.3 The connection, stated

From the customer you see *their* subscriptions; from a subscription you see *its* customer (header links back). The two hubs cross-link, so a support rep can hop customer ↔ subscription without the list. This is the "design how both experiences connect" the brief asked for: one shared row component, two entry points, bidirectional links.

---

## 13. Empty states

Every empty state educates (the three questions: *what is this / how does it relate / what happens next*), staged rather than unfinished.

### 13.1 Subscriptions list — first-ever visit (zero subscriptions)

```
                          ⟳
              No subscriptions yet

   A subscription bills a customer for one or more of your
   prices on a repeating schedule — and keeps charging their
   card until it's canceled. It's how recurring revenue works
   in Bouclay.

   You'll need a customer and at least one recurring price first.

            [ + Create your first subscription ]

   New here?  Read how subscriptions work →   (docs link, later)
```

If the team has **no recurring prices yet**, the CTA becomes **"Create a price first"** (deep-link to Catalog) with copy: *"Subscriptions bill a recurring price — create one in your Catalog to get started."* — we never dead-end the user at a disabled button.

### 13.2 Subscriptions list — filtered to zero (search/status, not true-empty)

```
   No subscriptions match "chidi" with status Past due.
   [ Clear filters ]
```

Distinct from true-empty (no illustration, no education — just a reset), matching the Phase-4 filtered-empty pattern.

### 13.3 Customer page — no subscriptions (the staged section, CTA now live)

Keep the existing educational copy (`show.tsx:429–431`), enable the CTA:

> **Subscriptions will live here** — When you subscribe this customer to a plan, their active and past subscriptions — status, renewal date, and plan — show up here. **[ + New subscription ]**

### 13.4 Hub — staged future sections

Reuse `StagedSection` verbatim:

- **Upcoming invoices** — "Invoices will appear here once a billing period runs. Turn on invoicing to start generating them." *Available with invoicing.*
- **Payments** — "Every charge against this subscription — succeeded, failed, or refunded — will be listed here." *Available with invoicing.*
- **Trial** (on a non-trial sub) — the section simply doesn't render (absence, not an empty box) — a trial isn't a "coming soon," it's just not present.

### 13.5 Create flow — empty line-item list

> **Add what you're billing for** — Pick a product and one of its recurring prices with **Add product**, or start someone on a **free trial** with **Add trial**. You can combine both and add more anytime.

---

## 14. Loading, success & error states

### 14.1 Loading

- **List**: skeleton rows (monogram circle + two text bars + a badge pill), 8 rows, matching the customers list skeleton. Never a spinner on a full page.
- **Create flow**: customer/product searches show inline skeleton rows; the summary rail shows shimmer on price lines while a price loads.
- **Hub**: full-page section skeletons on first load; **partial reloads** (`preserveScroll`) for in-place mutations (change PM, add item) so the page never flashes — the Phase-4 idiom.

### 14.2 Success

- **Created (free trial)**: redirect to hub + toast *"Subscription started — Ada is on a free trial until 19 Jul."* A subtle one-shot 🎁 confetti-free micro-pulse on the status badge (see §15).
- **Created (charged)**: toast *"Subscription active — ₦15,000 charged to Visa ···· 4242."*
- **Created (awaiting payment)**: redirect to hub, which leads with the amber banner + **Copy payment link**; toast *"Subscription created — send Ada the checkout link to activate it."*
- **Lifecycle actions**: toast per action — *"Subscription paused — resumes 1 Sep."* · *"Cancellation scheduled for 14 Aug. You can undo anytime before then."* · *"Subscription canceled."* · *"Payment method updated."* Toasts via `Inertia::flash('toast', …)` + `router.on('flash')` (house mechanism).

### 14.3 Errors

- **First charge declined** (automatic, card on file): sub stays **Awaiting payment**; hub banner turns red: *"The first payment was declined (insufficient funds). Update the card or retry."* with **Update card** / **Retry** actions. No data lost — the sub exists, it's just not active.
- **Nomba unreachable / token issue**: the create flow's CTA shows an inline error toast *"Couldn't reach Nomba to start billing. Your subscription was saved as draft-incomplete — retry from its page."* — we save what we can and never lose the merchant's input.
- **Validation**: inline, next to the field (missing customer/item), never a top-of-page error wall.
- **Permission**: `subscriptions.manage`-gated actions are hidden (not shown-then-403) for view-only roles, mirroring the customer page's `canManage` gating.
- **Concurrent state change** (someone canceled it in another tab): action dialogs re-check state on submit; a stale action shows *"This subscription's status changed. Refresh to see the latest."*

---

## 15. Microinteractions

Elegant, understated — the brief's word. All optional polish; none block the happy path.

- **Copy subscription ID** — inline ⧉ on the hub header and Developer section; click → check-flash for 2s (the exact `copied` pattern from `customers/show.tsx:107`) + toast *"Subscription ID copied."*
- **Status badge pulse** — on first render after a state change (created, activated, converted), the badge does a single soft scale/opacity pulse to draw the eye to what changed. Once, then still.
- **Trial countdown** — the "9 days left" and the mini-timeline bar are computed client-side from `trial_ends_at` so they feel live without a request; the bar fills proportionally.
- **Optimistic quantity/PM change** — the row updates instantly with a subtle saving shimmer, reconciled on the `preserveScroll` reload; rolls back with a toast on failure.
- **Undo cancel** — the "Cancellation scheduled" toast and the hub's amber sub-badge both carry an **Undo**; clicking removes the `scheduled_changes` row and clears the badge with a fade.
- **Skeletons** — list, hub sections, and search results all skeleton rather than spin.
- **Timeline append** — new timeline entries slide in at the top with a brief highlight, so a state change feels like it *happened* rather than just being there on reload.
- **Row hover** — list rows lift with a hover tint + cursor-pointer (customers-list parity).
- **Summary rail count-up** — "Due today" and "Total" animate a short number count-up when items change, making the price feel responsive to the merchant's choices.

---

## 16. Copy deck

Central, so tone stays consistent (modern, trustworthy, developer-friendly — never scary, never cute about money).

### 16.1 Status banners (hub) — the plain-language explainers

| State | Banner copy |
|---|---|
| `incomplete` (awaiting card) | "Waiting for the first payment. Send Ada the checkout link — access should start once it's paid." + **Copy link** |
| `incomplete` (charge declined) | "The first payment was declined ({reason}). Update the card or retry to activate this subscription." + **Update card** · **Retry** |
| `incomplete_expired` | "This subscription never started — the first payment wasn't completed in time. Start a new one to try again." |
| `trialing` (free, card on file) | "On a free trial. No payment yet. On {date} this converts to {plan} ({amount}/{interval}) and the first charge runs on {card}." |
| `trialing` (free, no card) | "On a free trial. Add a card before {date} — otherwise this will {trial_end_behavior_verb} when the trial ends." + **Add card** |
| `active` (healthy) | *(no banner)* |
| `active` (cancels at period end) | "Set to cancel on {date}. It stays active until then, and you can undo this anytime." + **Undo cancellation** |
| `past_due` | "A renewal payment failed. Bouclay will retry automatically ({attempt} of {max}, next on {date}). Update the card to recover sooner." + **Update card** · **Retry now** |
| `paused` | "Billing is paused — no charges will be made. Resumes on {date}." + **Resume now** |
| `canceled` | "This subscription ended on {date}. Its history stays here for your records." |

### 16.2 Empty states — see §13 (list, filtered, staged, item-list).

### 16.3 Confirmation dialogs

- **Cancel** (title): "Cancel this subscription?" — Body: "Choose when it ends. Canceling at the end of the period lets Ada keep access until {date} — the recommended choice." Options: **Cancel at period end (14 Aug)** / **Cancel immediately**. Immediate adds: "Access ends now. This can't be undone."
- **Pause**: "Pause billing?" — "No charges will be made while paused. You can set a resume date or resume manually anytime." Field: *Resume on (optional)*.
- **Remove item**: "Remove {item} from this subscription?" — "It stops billing on the next renewal. This won't refund the current period." (If last item: "This is the only item — removing it will cancel the subscription. Cancel instead?")
- **Change payment method**: "Charge a different card?" — "New renewals will use {card}. The current period isn't re-charged."

### 16.4 Tooltips & helpers

- Add product: "Bills the customer for this price every cycle."
- Add trial: "Starts on a trial price, then transitions to the regular price when it ends."
- Collection mode Automatic: "Bouclay charges the customer's saved card each cycle."
- Collection mode Manual: "Bouclay sends an invoice each cycle; the customer pays a link. No saved card needed."
- Trial-end behavior: "What to do when a free trial ends and there's still no card on file."
- Billing anchor: "When the first real charge lands after the trial."
- Quantity (Phase 5): "Changes apply on the next renewal." (until proration ships)

### 16.5 Success toasts — see §14.2.

### 16.6 Validation

- No customer: "Choose a customer to subscribe."
- No items: "Add a product or a trial to bill for."
- Currency (prevented, but if hit): "This price is in {cur}; the subscription is billed in {cur2}. Pick a matching price."
- Trial already used: "Ada already used this trial — add the regular price instead."

### 16.7 Voice rules

- Money is always explicit and formatted (₦15,000, not "15000" or "the fee").
- Dates are human ("19 Jul", "in 9 days"), never ISO on the surface.
- We say **"charge"**, **"renews"**, **"card"** — the customer's words — not "capture", "invoice line", "MRR".
- Never blame the customer for a decline; state the fact and the fix.

---

## 17. Schema-adjacent notes & the Phase 5/6 cut-line

No schema redesign (per the brief). These are *usage* clarifications and the honest boundary of what Phase 5 builds.

1. **Public id, not ULID.** Despite `schema.md` saying `ulid`, the app uses integer PK + `HasPublicId` (`sub_` prefix) per the handoff. Route binding by integer id; the UI shows `sub_…`.
2. **`trial_ends_at` is a mirror.** It denormalizes the earliest active `subscription_item_trials.ends_at`. The UI reads `subscriptions.trial_ends_at` for the countdown but the *source of truth per item* is the item trial row — which is why the trial badge lives on the item (§11).

   **2a. Trials are line items, not an auto-applied property.** This is the model correction that shapes the whole create flow (§3, §7.2). Adding a trial via **Add trial** creates a `subscription_item` (referencing the offer's `product_id` + `trial_price_id`) **plus** a `subscription_item_trials` row that *snapshots* the chosen `trial_offer` (`trial_price_id`, `transition_price_id`, `duration_*`, `starts_at`, computed `ends_at`). Bouclay **never** infers a trial from a plain price being some offer's `transition_price_id` — the merchant picks a trial offer explicitly, exactly as Stripe's "Add trial" adds a first-class trial offer, not a flag on a price. At `ends_at`, the item's effective price swaps `trial_price_id → transition_price_id` and the trial row goes `active → converted` (the worker; the *display* of this is built now). Because the trial lives on the item, a subscription can carry a trial line and a billed line simultaneously.
3. **Paid trial ⇒ `active`, not `trialing`.** Honour the schema's state-machine note (§5 of `schema.md`): only free trials enter `trialing`. The UI reflects this (§10.2) — don't badge a paid trial as "On trial".
4. **`collection_mode` already exists** on `subscriptions`; surface it as the two Paddle choices (§3). No new column.
5. **`scheduled_changes` drives cancel/pause-at-boundary.** "Cancel at period end" and "Pause with resume date" write a `scheduled_changes` row + set `canceled_at`/`ends_at`/`pause_resumes_at`; they do **not** flip `status` immediately. The UI's "Active · cancels 14 Aug" / "Paused · resumes 1 Sep" derived states read these (§4, §8.3).
6. **The Phase 5/6 cut-line (money is staged).** Phase 5 **does not** create `invoices`, `invoice_lines`, or `payments` rows. Concretely:
   - **Free trial** → `trialing`, zero money, `trial_ends_at` computed. ✅ fully built (exit-criteria path).
   - **Automatic + card** → first charge via the **new** `tokenized-card-payment` recurring charge (handoff seam #2), flips `incomplete → active`. The *charge happens* (real Nomba call) but is **not recorded** as a Bouclay `payment`/`invoice` until Phase 6 — same stance Phase 4 took for "Charge customer". The hub's **Payments**/**Upcoming invoices** stay `StagedSection`.
   - **Automatic + no card** / **Manual** → sub created; card collected via the Phase-4 checkout-redirect primitive (seam #1); invoicing staged.
   - **Renewals, proration, dunning retries, plan changes** → Phases 6/8. Phase 5 *shows* `past_due`/retry copy and reserves the plan-change affordance, but the workers land later.
7. **Mode (test/live).** Charges use `NombaModeResolver` (prefer live, else test); a PM's mode is in `custom_data.mode` — charge in that mode (handoff #4). Phase 5 enables **live-mode** collection (first subscription payment mints the live token) per the Phase-4 "carried forward" note. The create flow can show a subtle **Test / Live** indicator matching the API-keys mode chip.
8. **`once_per_customer` trial guard** enforced via `subscription_item_trials (customer_id, trial_offer_id)`; the UI pre-warns (§7.3, §16.6) rather than erroring at submit.
9. **Timestamp-duration trials deferred** (`duration_type: timestamp`) — ship `relative` only, matching the Phase-3 cut. The trial card handles both shapes but only `relative` is creatable now.
10. **Discounts** exist in schema but are Phase 13-deferred; the create flow and item total **reserve a "Add discount" slot** (disabled) so the future line item has a home, but no discount logic ships.
11. **Dashboard ↔ API are one model (§7.4).** The create form's inputs map 1:1 to the Phase-10 `POST /subscriptions` body — `customer`, `items[]` (each `{ price, quantity }` **or** `{ trial_offer }`), `collection_mode`, optional `payment_method` / `trial_end_behavior`. Build the request-shaping logic (validation, currency guard, trial snapshotting, state selection) in a shared service both the dashboard controller and the future API controller call, so the API is a thin wrapper over what the dashboard already exercises. The Preview's future **Code** tab surfaces this equivalence to developers.

---

## 18. Future-proofing

The whole layout is a bet that future phases *slot in* rather than *rewrite*. Where each lands:

| Future phase | Slots into (built now as…) |
|---|---|
| **6 · Invoices & charges** | Hub **Upcoming invoices** + **Payments** `StagedSection`s → real tables, same slot. Billing-schedule sentence → visual period ruler. Customer-page & sub totals gain real amounts. |
| **6 · Proration** | Item row `⋯` **"Change plan · Soon"** → live; quantity change → real proration line (the "applies next renewal" helper is replaced by a proration preview). |
| **7 · Inbound webhooks** | The `incomplete → active` and `active → past_due` flips currently driven synchronously become webhook-driven; the hub's amber "awaiting payment" banner clears on the webhook. No UI change — the states already exist. |
| **8 · Dunning** | `past_due` banner's "retry 2 of 4 · next 12 Jul" is *copy we already wrote* (§16.1); Phase 8 just feeds it real attempt data. Timeline gains retry entries. |
| **9 · Outbound events** | Timeline entries (created, canceled, converted…) are the same lifecycle moments that emit `subscription.created`/`.updated`; the hooks that write the timeline become the event emitters. |
| **11 · Portal** | The "collect a card" checkout link and "cancel at period end" are the exact customer-facing actions the portal exposes; Phase 5 builds them merchant-side first. |
| **Analytics** | The status taxonomy (§4) and color language (§9.4) are the dimensions KPIs slice by (active/trialing/past_due counts, trial-conversion). Built once, reused. |

**The core future-proofing move:** the detail page is a **dashboard of sections**, not a form. Adding a capability = adding/upgrading a section, never restructuring the page. This is the same lesson the customer hub proved when it staged Subscriptions and Transactions — we're applying it one level deeper.

---

## 19. Build sequencing

A suggested order so each step is demoable. (Product/UX sequencing — the engineering plan lives in `IMPLEMENTATION.md` Phase 5.)

1. **Models + state machine + create service + nav.** `Subscription`, `SubscriptionItem`, `SubscriptionItemTrial` models (`#[Fillable]` + `casts()`); the **hand-rolled state machine** (§4) — `SubscriptionState` contract, `BaseSubscriptionState` (throws by default), the seven concrete state classes, `IllegalStateTransition`, and the `SubscriptionStatus` backed enum that both resolves state classes (`stateFor`) and carries the `label()`/`color()`/`description()` metadata §9 reads; the model's `state()` + `apply()`; a **`CreateSubscription` service** that takes `{ customer, items[] (price|trial_offer), collection_mode, … }` and does validation + currency guard + trial snapshotting + initial-state selection (the seam the dashboard **and** Phase-10 API both call — §17.11); the `subscriptions` routes and the top-level nav item. Ship the table-driven state-machine unit test here. Wayfinder regen.
2. **List page** (thin, server-side search + status filter) with the true-empty and filtered-empty states. Demoable with seeded data.
3. **The state kit** — `SubscriptionStatusBadge` + `SubscriptionStatusBanner` + the §4 mapping as a single source of truth. Used by 2, 4, 5.
4. **Create flow** (`/subscriptions/new`, two-pane) — the **free-trial line-item** branch first (exit-criteria path: customer → Add trial → create → `trialing` with `trial_ends_at`). Then Add-product lines, the automatic-with-card and awaiting-payment branches (reuse Phase-4 checkout + the new tokenized-card charge), and the live Preview (Summary/Invoice).
5. **Detail hub** — header, status banner, overview, items (priced + trial line kinds), trial card, billing sentence, payment method, timeline, and the staged Invoices/Payments sections.
6. **Lifecycle actions** — pause/resume/cancel routed through `subscription->apply('pause'|'resume'|'cancel', …)` (the state machine guards legality; the UI only offers actions the current state allows, §8.3), cancel-at-period-end via `scheduled_changes`, change PM, manage items. Dialogs + toasts + timeline entries. An `IllegalStateTransition` should never reach a user (the UI gates it), but it's caught as a 409 defense-in-depth for the API path.
7. **Customer-page activation** — un-disable the two stubs, swap the Subscriptions `StagedSection` for the real list when populated, add the Overview fact.
8. **Polish** — microinteractions (§15), skeletons, copy pass against §16, live/test mode chip.

**Definition of done (Phase 5):** a merchant creates a subscription from Subscriptions **or** a customer page; a free trial lands in **On trial** with a correct, human trial-end date; an automatic-with-card subscription reaches **Active**; every state reads in plain language on the list, the hub, and the customer page; and the hub visibly reserves the rooms Phases 6–9 will fill.
