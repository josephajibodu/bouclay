# Bouclay — Architecture Guide

**Start here.** This is the one document that explains the *whole system* —
what it does, how the pieces fit together, and where to look in the code for
each piece. Everything else (`schema.md`, `CATALOG_DESIGN.md`,
`SUBSCRIPTIONS_DESIGN.md`, `CUSTOMERS_DESIGN.md`, `IMPLEMENTATION_V2.md`) goes
deeper on one slice; this document is the map that tells you which slice to
open.

Written to be read top to bottom once, then used as a reference after.

---

## 1. What Bouclay actually is

Say you're a startup selling software with a monthly subscription. You need:
a pricing page, a way to charge cards every month, retry logic when a card
fails, invoices, upgrade/downgrade proration, coupons, and a way to know
"is this customer allowed to use the Pro feature right now?" Building all of
that yourself is months of work and it's easy to get the money-math wrong.

**Bouclay is that engine, extracted into its own product.** A company (an
"integrator") connects their own payment gateway account (Nomba, Paystack, or
Flutterwave — BYOK, "bring your own keys"), defines their pricing catalog in
Bouclay, and then:

- calls Bouclay's HTTP API to create subscriptions, and
- listens to Bouclay's webhooks to know when something changed, and
- asks Bouclay "does this customer have access to X?" before gating a feature.

**Bouclay never holds money.** Every charge and refund happens directly on
the integrator's own gateway account. Bouclay only tracks *state* — what's
owed, what's been paid, what a customer is entitled to — and *orchestrates*
the gateway calls that move money.

```
┌─────────────────┐        ┌─────────────────┐        ┌──────────────────┐
│  Payment Gateway │◄──────►│     Bouclay      │◄──────►│  Integrator App   │
│ (Nomba/Paystack/ │  BYOK  │ (this codebase)  │  API + │ (their product,   │
│  Flutterwave)    │  keys  │                  │  webhooks│  their UX)      │
└─────────────────┘        └─────────────────┘        └──────────────────┘
      charges,                catalog, billing            gates features on
      refunds,                cycles, dunning,             entitlements,
      tokenises cards         entitlements, invoices       renders their own UI
```

So this one codebase actually serves **four different audiences**, through
four different "surfaces":

| Surface | Who uses it | Where in the code |
|---|---|---|
| **Dashboard** | The integrator's own team, logged in | Inertia pages under `resources/js/pages/*`, most controllers in `app/Http/Controllers/*` |
| **Public API** | The integrator's backend server, via API key | `routes/api.php`, `app/Http/Controllers/Api/V1/*` |
| **Customer Portal** | The integrator's *end customer*, via a magic link (no login) | `routes/portal.php`, `app/Http/Controllers/Portal/*`, pages under `resources/js/pages/portal/*` |
| **Hosted checkout pages** | A shopper paying an invoice or payment link Bouclay generated | `routes/hosted.php`, `app/Http/Controllers/Hosted/*` |

Keep these four surfaces in your head throughout — a lot of "why does this
code look different over here" questions are answered by "different
surface, different trust model."

---

## 2. Tech stack, in one paragraph

**Backend:** Laravel 13 on PHP 8.4. **Frontend:** Inertia.js v3 + React 19 +
TypeScript, styled with Tailwind CSS v4. Inertia means there is **no separate
REST API for the dashboard** — controllers return React page components
directly (`Inertia::render('catalog/products', [...])`), and the props are
just the data the page needs. The **actual** JSON API (for integrators) is a
separate, versioned thing under `/api/v1`, authenticated by API key instead
of a browser session. Routes are exposed to the frontend type-safely via
**Laravel Wayfinder** (`resources/js/routes/*`, `resources/js/actions/*` are
generated — never hand-edit them). Tests are **Pest 4**, database is
**PostgreSQL** in production and **SQLite in tests** (this matters — see
§9). Auth is **Laravel Fortify**.

---

## 3. Tenancy, users, and permissions

Every integrator is a **`Team`**. All billing data belongs to a team via a
`team_id` column — there is no cross-team data, ever. A human **`User`** can
belong to multiple teams (`team_members` is the join table), and
`users.current_team_id` says which team's data they're currently looking at
in the dashboard.

Permissions are **role-based, per membership**: a `Role` (e.g. "Admin",
"Billing manager") holds a set of `Permission`s (e.g. `invoices.manage`,
`catalog.manage`), and a `team_members` row can hold *several* roles at
once (Paddle-style — this is why it's `team_member_roles`, a pivot, not a
single `role_id` column). To check access in code:

```php
$user->hasTeamPermission($team, 'invoices.manage');
// or, to get the whole bundle for an Inertia page's `permissions` prop:
$user->toTeamPermissions($team)->canManageInvoices;
```

Every Inertia dashboard request automatically gets `auth.user`, `currentTeam`,
`teams` (for the team switcher), and `teamPermissions` shared onto every page
— see `app/Http/Middleware/HandleInertiaRequests.php:34`. That's why page
components can just read `usePage().props.currentTeam` without every
controller wiring it up by hand.

The **public API** doesn't use sessions at all — it authenticates by
`ApiKey` (`app/Http/Middleware/AuthenticateApiKey.php`), one key per team,
scoped to test/live mode.

The **customer portal** doesn't use accounts at all — a `Customer` gets a
signed, opaque `{token}` URL (`/portal/{token}/...`), and that token *is*
the auth. No password, no login page, because the end customer isn't a
Bouclay user — they're the integrator's customer.

---

## 4. The domain model (the part that matters most)

This is the heart of the system. If you understand this section, you
understand 80% of Bouclay. The authoritative version with every column is
[`schema.md`](schema.md) — this is the "why it's shaped this way," in plain
language.

### 4.1 Catalog: what you're selling

```
Product ──< Plan ──< Price ──< PriceTier
 "Cursor"    "Pro"    "$20/mo"   (for usage-based pricing)
             "Max"    "$100/mo"
```

- **`Product`** — the thing you sell ("Cursor", "NaijaStream").
- **`Plan`** — a named tier under a product ("Pro", "Max", "Team"). This
  is the layer Stripe doesn't have and Recurly does — it's what lets one
  product hold several priced variants instead of a flat list of prices.
- **`Price`** — the actual number: amount, currency, billing interval
  (`month`/`year`/…), and a pricing model (`standard` flat-rate, or
  `graduated` — different per-unit rates by volume, via `PriceTier` rows).
  **Prices are immutable once used** by a real subscription or invoice line
  — you can't edit history out from under a paying customer. "Editing" a
  price in the dashboard actually archives the old one and creates a new
  one (see `Price::hasBeenUsed()`).
- **`PricingJourney`** (née "price phases") — a reusable multi-step offer,
  e.g. *"$1/mo for 3 months, then $10/mo forever."* A subscription can start
  through a journey; its steps get copied into that subscription's own
  `SubscriptionSchedule` so editing the journey later never touches
  subscriptions already running on it.

### 4.2 Entitlements: "can this customer do X?"

```
Entitlement "hd_streaming" ──< EntitlementGrant >── Plan "Premium"
                                                  >── Product "NaijaStream"
```

An `Entitlement` is just a named capability code (`hd_streaming`,
`api_access`). An `EntitlementGrant` says "this Plan (or Product) grants
this Entitlement." Resolution (`Customer::entitlements()`) walks the
customer's *active* subscriptions → their plan/product → the grants — and
is intentionally **independent of invoice/payment state**: a `past_due`
subscription may still grant access depending on `SubscriptionStatus::grantsAccess()`,
which is exactly the kind of business rule you want in one place, not
copy-pasted into every integrator's app. This is the whole point of
Bouclay from the integrator's side: `GET /api/v1/customers/{id}/entitlements`
replaces a pile of ad-hoc "is this user premium?" checks.

### 4.3 Billing: money actually moving

```
Subscription ──< SubscriptionItem >── Price
     │
     ├──< Invoice ──< InvoiceLine
     │       │
     │       └──< Payment ──< Refund
     │
     └──< ScheduledChange (a pending plan/price swap)
```

- **`Subscription`** — one customer's relationship to one or more prices
  over time. Has a state machine (§6).
- **`SubscriptionItem`** — one priced line on that subscription (usually
  one, but supports multi-item subscriptions).
- **`Invoice`** — the numbered, immutable billing record (`inv_...`). This
  is the **only** thing called an "invoice" or a "transaction" in this
  codebase — see the locked vocabulary box below.
- **`Payment`** — one *attempt* to charge an invoice (`pay_...`). An invoice
  can have several failed `Payment`s before one succeeds — that's exactly
  how dunning retries are recorded.
- **`Refund`** — a reversal of a settled `Payment`, possibly partial.

> **Locked vocabulary — do not use "Transaction."** There is no
> `Transaction` model, route, or permission anywhere in this codebase.
> Stripe/Paddle sometimes call this an invoice a "transaction" — when
> reading their docs, mentally substitute "Invoice." The only place
> "transaction" is a legitimate word is Laravel's own `DB::transaction()`
> (a database transaction) and Nomba's own API paths, which are their
> vocabulary, not Bouclay's.

### 4.4 Discounts

A `Discount` (percentage or flat amount, "once"/"repeating N cycles"/
"forever") can be scoped to specific plans or prices, or apply to
everything. `DiscountRedemption` records which subscription used it. The
locked rule: **price-level eligibility wins outright** — a discount scoped
to a specific price never touches a different price on the same product,
even a different billing interval of the *same* plan (each cadence is its
own subscription).

### 4.5 The full picture

The authoritative ER diagram lives in [`schema.md` § Entity Relationship
Diagram](schema.md#entity-relationship-diagram) — open it side-by-side with
this section once the plain-English version above makes sense.

---

## 5. How a request actually flows through the code

Three different shapes, depending on which surface (§1) you're in.

### 5.1 Dashboard (Inertia) request

```
Browser → routes/*.php → Controller → Inertia::render('page/name', [props])
                                            │
                                            ▼
                                    resources/js/pages/page/name.tsx
                                    (receives props, renders React)
```

There's no JSON API in between — the controller *is* the API, and it
returns a component name + props in one response. Example:
`app/Http/Controllers/Catalog/ProductController.php:62` renders
`catalog/create` with `defaultCurrency`; the page component
(`resources/js/pages/catalog/create.tsx`) just destructures that prop.

Forms post back to routes generated by **Wayfinder** — e.g.
`import { store } from '@/routes/catalog/products'` gives you a
type-checked `{ url, method }` pair, so a renamed backend route becomes a
*compile* error in the frontend instead of a silent 404.

Controllers are kept thin. Anything with real business logic — creating a
subscription, charging an invoice, applying a discount — is a single-purpose
**Action** class under `app/Actions/*`, with one public `handle()` method.
This is the single most important convention in the codebase: **if you're
looking for "where does X actually happen," it's in `app/Actions/`, not the
controller.**

### 5.2 Public API request

```
Integrator's server → Authorization: Bearer <api_key> → AuthenticateApiKey middleware
                                                              │
                                                              ▼
                                          app/Http/Controllers/Api/V1/*Controller
                                                              │
                                                              ▼
                                          same Action classes as the dashboard
                                                              │
                                                              ▼
                                          JSON (Eloquent API Resources / hand-shaped arrays)
```

The key idea: **the dashboard and the API call the same Actions.** Creating
a subscription from the dashboard and creating one via
`POST /api/v1/subscriptions` both end up in
`app/Actions/Subscriptions/CreateSubscription.php`. The controller's only
job is translating its surface's input shape into what the Action expects,
and its output into what that surface returns (Inertia props vs. JSON).
That's why business rules can't drift between "the dashboard let you do this
but the API silently didn't."

Every write endpoint is also gated by `idempotency_keys`
(`app/Http/Middleware/EnsureIdempotency.php`) — an integrator can safely
retry a POST after a timeout without double-creating a subscription.

### 5.3 Portal / hosted-page request

Same Inertia mechanism as §5.1, but:
- No `auth` middleware — the `{token}` in the URL *is* the identity.
- A much smaller, deliberately customer-safe set of props — see
  `app/Actions/Portal/BuildPortalContext.php`, which assembles exactly what
  a customer-facing page is allowed to see (no internal team data, no other
  customers, gateway names scrubbed — see §7's boundary rule).

---

## 6. Subscriptions: the state machine

A `Subscription` is never just "active" or "not" — there's a real state
machine, one class per state, under `app/States/Subscription/`:

```
Incomplete ──► Trialing ──► Active ──► PastDue ──► Canceled
     │                         ▲   │        │
     └──► IncompleteExpired    │   └► Paused┘
   (first payment never came)  └───(resume)──┘
```

(`SubscriptionStatus` enum: `incomplete`, `incomplete_expired`, `trialing`,
`active`, `past_due`, `paused`, `canceled`.)

Every state class extends `BaseSubscriptionState`, which makes **every
transition illegal by default** (`app/States/Subscription/BaseSubscriptionState.php:9`)
— a concrete state (e.g. `ActiveState`) only overrides the transitions that
are actually legal *from* that state. Trying an illegal one throws
`IllegalStateTransition` instead of silently corrupting state. You never
call `$subscription->status = 'canceled'` directly — you call:

```php
$subscription->apply('cancel', $endsAt);
```

`Subscription::apply()` (`app/Models/Subscription.php:91`) resolves the
current state object and dispatches to it. This is why the codebase can
say with confidence "a paused subscription can never be double-charged" —
it's not a convention, it's enforced by the type system.

**Pending changes** (a scheduled upgrade/downgrade, a scheduled cancellation)
live in `ScheduledChange` rows, applied by the hourly
`subscriptions:apply-scheduled-changes` worker (§8) — not by mutating the
subscription immediately, so a customer can see "you're switching to Pro on
March 1st" before it happens.

---

## 7. The gateway abstraction (the trickiest, most important part)

Bouclay supports three payment processors today (Nomba, Paystack,
Flutterwave) and is built so a fourth costs "one class + one registry line,"
never a migration or new UI. The entire abstraction rests on one interface:

**`App\Services\Gateways\PaymentGateway`** (`app/Services/Gateways/PaymentGateway.php`)
— every driver (`NombaGateway`, `PaystackGateway`, `FlutterwaveGateway`,
under `app/Services/Gateways/{Nomba,Paystack,Flutterwave}/`) implements:

| Method | What it does |
|---|---|
| `capabilities()` | what this gateway can do (currencies, refunds, tokenization) |
| `configSchema()` | which credential fields the connect form should render — *this* is why adding a gateway needs no bespoke settings UI |
| `verifyCredentials()` | test a team's keys actually work, before saving them |
| `createCheckout()` | hosted checkout URL, optionally tokenizing the card |
| `chargeToken()` | server-to-server charge of a stored card (renewals) |
| `verifyCharge()` | confirm a charge *actually* settled — never trust the synchronous response alone |
| `refund()` | reverse a settled charge |
| `resolveToken()` | recover a minted card token if its webhook hasn't arrived yet |
| `revokeToken()` | delete a token upstream when removed in Bouclay |
| `verifyWebhookSignature()` / `parseWebhookEvent()` | is this inbound payload real, and what does it mean |
| `classifyDecline()` | map this gateway's decline vocabulary to Bouclay's shared `PaymentFailureCode` enum, so dunning behaves identically regardless of gateway |

**`GatewayManager`** (`app/Services/Gateways/GatewayManager.php`) is the
registry — a plain `array<string, class-string<PaymentGateway>>` map from
processor key to driver class (`GatewayManager.php:28`). Every call site
resolves through it:

```php
$gateway = $this->gateways->driver($connection->processor); // never `new NombaGateway()`
```

**Two rules are enforced by tests, not just convention:**

1. **No call site outside `app/Services/Gateways/` may name a concrete
   gateway** (`Nomba*`, `Paystack*`, `Flutterwave*`) or a gateway-specific
   credential key (`secret_key`, `account_id`, …). A grep-based test fails
   CI if this boundary leaks. This is also why customer-facing text (portal,
   invoices) must never say "your card is charged via Nomba" — see the
   memory of a real bug that shipped exactly that leak.
2. **A stored card always charges through the gateway that minted its
   token** — never "whatever the team's current default gateway is." This
   is why `chargeToken()` is resolved by `payment_methods.processor`, not by
   `TeamProcessorConnection::default()`.

**Credentials** (`team_processor_connections`) are stored as opaque
encrypted JSON — Bouclay's core code never has a column called
`nomba_secret_key`. Each driver's own `configSchema()` says what shape its
blob is; nothing outside the driver ever parses it.

### 7.1 Inbound webhooks (gateway → Bouclay)

```
POST /webhooks/{processor}/{token}
        │
        ▼
Resolve TeamProcessorConnection by {token}
        │
        ▼
ReceiveGatewayWebhook::handle()  (app/Actions/Webhooks/ReceiveGatewayWebhook.php)
        │
        ├─ gateway->verifyWebhookSignature()   (is this genuinely from them?)
        ├─ gateway->parseWebhookEvent()        (normalize to Bouclay's own event shape)
        └─ SettleGatewayPayment::handle()      (apply the effect — mark invoice paid, etc.)
```

Every gateway gets the *same* URL shape and the *same* shared settlement
logic; only the two driver calls above differ per gateway.

### 7.2 Outbound events (Bouclay → integrator)

Separate concern, separate direction: when something an integrator cares
about happens (`customer.created`, `subscription.updated`, `invoice.updated`,
…), an `Event` row is written and a `WebhookDelivery` is queued for each of
the team's registered `webhook_endpoints`. Delivery is retried by the
`webhooks:deliver-pending` worker (runs every minute — the only worker that
frequent; see §8).

Two locked rules here too:

- **Only `*.created` / `*.updated` pairs are emitted** — no
  `invoice.paid`-style status-specific events. A consumer reads `status`
  off the object instead of pattern-matching event names. This was a
  deliberate simplification during V2-6 (see memory / `IMPLEMENTATION_V2.md`).
- **No `payment.*` events at all**, by design — payments are attempts, not
  something worth its own webhook stream; invoice status changes cover it.

---

## 8. Background workers

Nothing bills itself — a subscription's next invoice, a retried failed
payment, a pending plan swap: all of these happen because a scheduled
command ran, not because of a request. See `routes/console.php` and
`app/Console/Commands/*`:

| Command | Cadence | What it does |
|---|---|---|
| `subscriptions:advance-phases` | hourly | moves a subscription to the next step of its pricing journey/schedule |
| `subscriptions:apply-scheduled-changes` | hourly | applies a due `ScheduledChange` (plan swap, scheduled cancel) |
| `subscriptions:bill-renewals` | hourly | generates + charges the next cycle's invoice for subscriptions due to renew |
| `subscriptions:process-dunning` | hourly | retries a `past_due` subscription's failed invoice per its dunning schedule |
| `subscriptions:process-manual-dunning` | hourly | dunning for invoices without an active subscription behind them (one-off invoices) |
| `subscriptions:expire-incomplete` | hourly | expires a subscription whose first payment never landed |
| `webhooks:deliver-pending` | **every minute** | delivers/retries queued outbound webhook payloads |

If something "should have happened by now but didn't," this table is where
to look — either the worker hasn't run (check the scheduler is actually
running: `php artisan schedule:work` locally), or the Action it calls threw.

---

## 9. Conventions that hold the whole thing together

These aren't style preferences — each one prevents a real class of bug.

- **Integer PKs internally, `HasPublicId` externally.** Every model has a
  normal auto-increment `id` for joins/FKs, but exposes a prefixed public id
  (`cust_...`, `sub_...`, `inv_...`, `prod_...`) via a `HasPublicId` trait
  for anything that leaves the server (URLs, API responses, webhooks).
  Internal joins never leak a sequential integer to the outside world.
- **Money is always integer minor units** (kobo/cents) + a `currency`
  `char(3)` column, never a float. Conversion to/from major units for
  display happens right at the API/Inertia boundary (`unit_amount / 100`),
  never in the middle of billing math.
- **Prices are immutable once used.** `Price::hasBeenUsed()` checks whether
  a subscription item or invoice line already references it; if so, an
  "edit" in the UI actually archives it and creates a successor row.
- **Enums as PHP backed enums, cast on the model**, not raw strings floating
  around — `SubscriptionStatus`, `InvoiceStatus`, `PaymentProcessor`, etc.
  under `app/Enums/`.
- **Tests run on SQLite; production runs on PostgreSQL.** This means
  Postgres-only features (like `ILIKE`) can't be used in queries that tests
  need to hit — a real, recurring gotcha in this codebase.
- **4-place permission naming**: `{resource}.{view|manage|...}`, e.g.
  `invoices.manage`, seeded in `schema.md § RBAC seed appendix`.
- **Flash toasts, not inline banners**, for one-off success/error messages
  after a dashboard action — `Inertia::flash('toast', [...])`, read by a
  shared frontend hook.

---

## 10. Frontend map

```
resources/js/
├── pages/            One file per Inertia-rendered page (catalog/, customers/,
│                      subscriptions/, portal/, hosted/, settings/, teams/, …).
│                      Each default-exports a component taking the controller's
│                      props, and often sets `.layout` for breadcrumbs/shell.
├── components/        Reusable pieces, grouped by domain (catalog/, subscriptions/,
│                      invoices/, ui/ — the shadcn/Radix primitive layer).
├── layouts/            Page shells (dashboard chrome, portal chrome, auth chrome).
├── routes/, actions/   Generated by Wayfinder — never hand-edit; regenerate via
│                       the Vite plugin when routes/controllers change.
├── lib/               Shared helpers — formatMoney, formatPriceInterval, cn(), etc.
│                      If you're formatting an amount or a date twice, it belongs here.
├── hooks/              Small reusable React hooks.
└── types/              Shared TypeScript types mirroring backend shapes.
```

Dashboard pages assume `currentTeam`/`teamPermissions` are already on
`usePage().props` (shared globally, §3) — don't thread them through route
params yourself.

---

## 11. Testing

Pest 4, organized to mirror the domain, not the file structure:
`tests/Feature/{Catalog,Subscriptions,Invoices,Discounts,Entitlements,
Webhooks,Gateways,Portal,Teams,...}`. A few worth knowing about specifically:

- **`tests/Feature/Simulations/`** — executable versions of the scenarios
  written out in [`BILLING_SIMULATIONS.md`](BILLING_SIMULATIONS.md) (e.g.
  "customer upgrades mid-cycle, gets prorated correctly"). If you're
  unsure whether a billing-math change is safe, this suite is the
  acceptance test.
- **`tests/Feature/Gateways/`** — the dataset-driven suite that runs the
  *same* ~20 scenarios against all three gateway drivers, so "Nomba,
  Paystack, and Flutterwave behave identically" is a test, not a hope.
- **Grep-based boundary tests** — assert no file outside
  `app/Services/Gateways/` names a concrete gateway class or credential key
  (§7). Cheap, but has known blind spots (a leaked *shape*, or an English
  string, won't be caught by a grep — verify gateway-boundary changes by
  reading the diff too, not just a green check).

Run everything: `php artisan test --compact`. Run one area:
`php artisan test --compact --filter=Subscriptions`.

---

## 12. Where to go deeper

This document is the map. For the territory:

| Question | Go to |
|---|---|
| "What exact columns/types does table X have?" | [`schema.md`](schema.md) — the authority, wins on any conflict |
| "Why is the catalog shaped Product→Plan→Price?" | [`CATALOG_DESIGN.md`](CATALOG_DESIGN.md) |
| "How do customers, addresses, payment methods work?" | [`CUSTOMERS_DESIGN.md`](CUSTOMERS_DESIGN.md) |
| "How does proration / trial anchoring / mid-cycle change work, exactly?" | [`SUBSCRIPTIONS_DESIGN.md`](SUBSCRIPTIONS_DESIGN.md) |
| "What billing scenario should I test against?" | [`BILLING_SIMULATIONS.md`](BILLING_SIMULATIONS.md) |
| "What's the actual public API contract?" | [`docs/api/README.md`](docs/api/README.md) + [`docs/api/openapi.yaml`](docs/api/openapi.yaml) |
| "What's been built, what's left?" | [`IMPLEMENTATION_V2.md`](IMPLEMENTATION_V2.md) (live roadmap) — `IMPLEMENTATION.md` is V1, historical only |
| "How do I run this locally / what commands exist?" | [`README.md`](README.md) |
| "What are Claude's working conventions on this repo?" | [`AGENTS.md`](AGENTS.md) |

---

## 13. Glossary

| Term | Meaning |
|---|---|
| **Team** | One integrator (tenant). Everything is scoped to a team. |
| **Integrator** | The company using Bouclay to power their own billing. |
| **Gateway / Processor** | Nomba, Paystack, or Flutterwave — where money actually moves. |
| **BYOK** | Bring your own (gateway API) keys — Bouclay never has its own merchant account. |
| **Product / Plan / Price** | What you sell / a named tier / the actual amount+cadence. |
| **Entitlement** | A named capability ("hd_streaming") a plan or product grants. |
| **Subscription / SubscriptionItem** | A customer's ongoing billing relationship / one priced line on it. |
| **Invoice** | The numbered billing record. Never call this a "transaction." |
| **Payment** | One charge *attempt* against an invoice (may fail; may retry). |
| **Refund** | A reversal of a settled payment. |
| **Dunning** | The retry process for a failed renewal payment. |
| **Entitlement grant** | The row that connects a Plan/Product to an Entitlement. |
| **Pricing Journey** | A reusable multi-step offer a subscription can start through. |
| **Scheduled Change** | A queued future subscription change (plan swap, scheduled cancel). |
| **Idempotency key** | A client-supplied token that makes retrying a POST safe. |
| **Public ID** | The prefixed, non-sequential id (`cust_...`) exposed outside the server. |
| **Portal** | The token-authenticated, no-login pages an end customer sees. |
| **Hosted page** | A public checkout page for a one-off invoice or payment link. |
