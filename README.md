# Bouclay

Managed recurring-billing infrastructure for African payment gateways — so product
teams stop rebuilding subscriptions from scratch.

Bouclay is **infrastructure for integrators**: they connect their own gateway
keys (BYOK), call Bouclay's API for plans and subscriptions, and listen to
Bouclay webhooks. Bouclay handles gateway webhooks, billing cycles, proration,
dunning, entitlements, and subscription state on their behalf.

**Bouclay never holds funds.** Every charge, refund, and payout happens on the
integrator's own gateway account, with their own keys.

## What we're building

| Layer | Responsibility |
|---|---|
| **Gateway** (Nomba / Paystack / Flutterwave) | Payment primitives — checkout, tokenise, charge, refund |
| **Bouclay** | Subscriptions engine — catalog, billing cycles, invoices, dunning, entitlements, webhooks |
| **Integrator app** | Their product — UX and business logic, gated on Bouclay entitlements |

## Stack

- **Backend:** Laravel 13, PHP 8.4 (`composer.json` allows 8.3; the platform check needs 8.4)
- **Frontend:** Inertia v3 + React 19, Tailwind CSS v4
- **Auth & teams:** Fortify, multi-team membership (`teams`, `team_members`)
- **Tests:** Pest 4

## Architecture (short)

- **Tenancy:** `teams` — each integrator is a team; billing data is scoped by `team_id`.
- **Staff access:** global `users` + `team_members` + **roles & permissions** (many roles per member, Paddle-style).
- **Gateway BYOK:** `team_processor_connections` stores encrypted credentials per
  team **per mode** (test/live). The blob is opaque — only the driver knows what
  its keys mean.
- **Inbound webhooks:** gateway → `POST /webhooks/{processor}/{token}` (token generated per team).
- **Outbound webhooks:** Bouclay → integrator URLs in `webhook_endpoints`.
- **Catalog:** `products` → `plans` → `prices` (immutable once used; `price_phases` for ramps).
- **Billing:** `subscriptions` → `subscription_items` → `invoices` → `payments` → `refunds`.
- **Entitlements:** `entitlements` + `entitlement_grants` — named capabilities
  granted by plans/products, resolved independently of billing state.

### The gateway boundary

Every payment processor sits behind one interface, `App\Services\Gateways\PaymentGateway`.
Adding a gateway is **one class plus one registry entry** — connect form, checkout,
charge, refund, and webhooks all light up from there, with no migrations and no UI
work. Three drivers ship today: Nomba, Paystack, Flutterwave.

Two rules make that real, and both are enforced by tests:

- **No call site names a gateway.** Everything resolves through `GatewayManager`.
  A grep test fails the build on any `Nomba*`/`Paystack*`/`Flutterwave*` reference
  outside `app/Services/Gateways/`, *and* on any gateway credential key (`secret_key`,
  `account_id`, …) leaking out of it.
- **A stored card charges through the gateway that minted its token** — never the
  team's default. The default only governs *new* checkouts.

The drivers really are different, and the boundary absorbs it: three money formats
on the wire (major-unit string, minor-unit int, major-unit number), three webhook
verification schemes, and one gateway that addresses transactions by its own id
rather than ours. A dataset-driven suite runs the same 20 scenarios against all
three, so "they behave identically" is executable rather than asserted.

### Workers

All hourly except webhook delivery (every minute) — see `routes/console.php`:

`subscriptions:advance-phases`, `subscriptions:apply-scheduled-changes`,
`subscriptions:bill-renewals`, `subscriptions:expire-incomplete`,
`subscriptions:process-dunning`, `subscriptions:process-manual-dunning`,
`webhooks:deliver-pending`.

## Docs

| Doc | What it's for |
|---|---|
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | **Start here.** The whole system explained end to end — one map to everything below. |
| [`schema.md`](schema.md) | **The data model — the authority.** Locked decisions live here. |
| [`IMPLEMENTATION_V2.md`](IMPLEMENTATION_V2.md) | The live roadmap (V2-0 … V2-8). |
| [`BILLING_SIMULATIONS.md`](BILLING_SIMULATIONS.md) | Acceptance spec — SIM/ADV scenarios, executable as `tests/Feature/Simulations/`. |
| [`docs/api/README.md`](docs/api/README.md) + [`openapi.yaml`](docs/api/openapi.yaml) | Integrator-facing API. |
| [`CATALOG_DESIGN.md`](CATALOG_DESIGN.md), [`CUSTOMERS_DESIGN.md`](CUSTOMERS_DESIGN.md), [`SUBSCRIPTIONS_DESIGN.md`](SUBSCRIPTIONS_DESIGN.md) | Per-area design rationale. Pre-V2 in places — `schema.md` wins on conflict. |
| [`IMPLEMENTATION.md`](IMPLEMENTATION.md) | **Historical.** The V1 plan, superseded by V2. |

## Local setup

Requires PHP 8.4+, Composer, Node.js, and pnpm (or npm).

```bash
composer setup
```

Or step by step:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
pnpm install
pnpm run build
composer run dev
```

The app is served by [Laravel Herd](https://herd.laravel.com) at `https://bouclay.test` when using Herd.

Run tests:

```bash
php artisan test --compact
```

## Demo strategy

Bouclay is the product. A minimal **reference app** ("Acme Notes") will subscribe
via Bouclay's API and gate a feature purely on the entitlements endpoint — proving
the integrator story end-to-end without building a full SaaS. That's V2-7.

## Status

**V2-0 … V2-6 shipped.** Catalog rework (plans, immutable prices, phases),
subscriptions (trial anchoring, scheduled changes), billing correctness (discounts,
proration), the gateway abstraction + Paystack/Flutterwave, entitlements, and the
outbound event rename are all in.

**Next: V2-7** — reference app, regenerated docs, and the live test-mode smoke run
against all three gateways. That's the last phase before launch.

### Locked vocabulary

- **`Invoice`** = numbered billing record (`inv_`); **`Payment`** = charge attempt
  (`pay_`). There is **no "Transaction" entity** — the term is banned in dashboard
  labels *and* event names. See [`schema.md`](schema.md) § Dashboard vocabulary.
- **Outbound events are `*.created`/`*.updated` pairs only.** No `invoice.paid` —
  consumers read `status` off the object. See [`docs/api/README.md`](docs/api/README.md#event-catalog).

## License

MIT
