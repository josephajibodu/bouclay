# Bouclay

Managed recurring-billing layer on top of [Nomba](https://nomba.com) checkout, tokenised cards, and charge APIs — so product teams stop rebuilding subscriptions from scratch.

Bouclay is **infrastructure for integrators**: they connect their own Nomba keys (BYOK), call Bouclay's API for plans and subscriptions, and listen to Bouclay webhooks. Bouclay handles Nomba webhooks, billing cycles, proration, dunning, and subscription state on their behalf.

## What we're building

| Layer | Responsibility |
|---|---|
| **Nomba** | Payment primitives — checkout, tokenise, charge, transfers |
| **Bouclay** | Subscriptions engine — catalog, billing cycles, invoices, dunning, webhooks |
| **Integrator app** | Their product — entitlements, UX, business logic |

Judged on: state-machine completeness, dunning sophistication, multi-tenant cleanliness, and API ergonomics.

## Stack

- **Backend:** Laravel 13, PHP 8.4
- **Frontend:** Inertia v3 + React 19, Tailwind CSS v4
- **Auth & teams:** Fortify, multi-team membership (`teams`, `team_members`)
- **Tests:** Pest 4

## Architecture (short)

- **Tenancy:** `teams` — each integrator is a team; billing data is scoped by `team_id`.
- **Staff access:** global `users` + `team_members` + **roles & permissions** (many roles per member, Paddle-style).
- **Nomba BYOK:** `team_processor_connections` stores encrypted Nomba keys per team.
- **Inbound webhooks:** Nomba → `POST /webhooks/nomba/{token}` (generated per team).
- **Outbound webhooks:** Bouclay → integrator URLs in `webhook_endpoints`.
- **Catalog:** `products` + `prices` (+ `trial_offers` for intro pricing).
- **Billing:** `subscriptions` → `subscription_items` → `invoices` → `payments`.

Full data model: [`schema.md`](schema.md)

Implementation roadmap: [`IMPLEMENTATION.md`](IMPLEMENTATION.md)

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

Bouclay is the product. A minimal **reference app** (e.g. “Acme Notes”) will subscribe via Bouclay's API and react to outbound webhooks — proving the integrator story end-to-end without building a full SaaS.

## Status

**In progress** — platform auth and teams exist; **Phase 1 (roles & permissions)** is next, then billing engine and Nomba integration per `IMPLEMENTATION.md`.

## License

MIT
