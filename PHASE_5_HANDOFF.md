# Bouclay — Phase 4 → Phase 5 handoff

> **⚠ Historical — spent (noted 2026-07-16).** This was a working handoff note for
> a V1 phase that shipped long ago; the branch and PR it references are gone, and
> the V2 rework replaced most of what it describes. Kept only as a record of the
> seams Phase 4 left. Nothing here is actionable — live status is
> [`IMPLEMENTATION_V2.md`](IMPLEMENTATION_V2.md); the data model is
> [`schema.md`](schema.md).

## Where things stand
- **Phase 4 (Customers & payment methods) is done**, committed on branch `feat/phase-4-customers-payment-methods` (`8dbe512`), pushed to `origin`. **PR not yet opened** — no `gh` CLI/token in the local environment; open it via the compare link.
- Migrations already run on the dev DB. 31 customer/Nomba feature tests pass; typecheck, ESLint, PHPStan, Pint, and production build all clean.

## Phase 5 = Subscriptions & state machine
Tables: `subscriptions`, `subscription_items`, `subscription_item_trials` (`schema.md` §4–5).
States: `incomplete → trialing / active → past_due / paused / canceled / incomplete_expired`.

## The seams Phase 4 deliberately left (most important)
1. **Reuse the checkout primitive.** `NombaCheckout::createOrder` + the cache handshake (`nomba_checkout:{ref}` / `nomba_token:{ref}`) + the callback token capture already exist. The first subscription charge tokenises the card the same way.
2. **Build the recurring charge.** `POST /v1/checkout/tokenized-card-payment` (charge a saved `tokenKey`) is **not built yet** — this is the actual subscription/recurring charge. Add it to `NombaCheckout`; it takes `tokenKey` + an `order` object → wrap the order in the existing `scopeOrderToSubaccount()` helper (subaccount → `order.accountId`, parent stays in the header).
3. **Un-disable the UI stubs** in `resources/js/pages/customers/show.tsx`: the "Create subscription" Actions item and the Subscriptions `StagedSection` CTA (currently disabled with "Soon").
4. **Mode:** use `NombaModeResolver` (prefer live, else test). A payment method's mode is stored in `custom_data.mode` — charge a token in that mode.

## Conventions that save time
- Integer PKs + `HasPublicId` trait (prefix e.g. `sub_`), **not ULIDs** despite `schema.md`. Route-model binding is by integer `id`; frontend passes `.id`.
- Models use the `#[Fillable([...])]` attribute + a `casts()` method. Enums in `app/Enums`.
- Run `php artisan wayfinder:generate` after adding/changing routes; generated `resources/js/routes/*` and `resources/js/actions/*` are gitignored.
- A new permission = **4 edits**: `PermissionName` enum, `TeamPolicy` gates, `TeamPermissions` DTO + `HasTeams::toTeamPermissions`, frontend `resources/js/types/teams.ts`; assign in `SeedDefaultRoles`. **`subscriptions.view` / `.manage` already exist** and are seeded to Support.
- Toasts / reveal-once values: server `Inertia::flash('toast'|'someKey', [...])`; client `router.on('flash', ...)`.
- UI idiom: create/edit = **Sheet drawer** (controlled `open`); confirmations / one-time reveals = **Dialog**. A page's `.layout` static returns `{ breadcrumbs: [...] }`.
- Tests: Pest on **SQLite in-memory** → Postgres-only SQL breaks (e.g. `ilike`; use `LOWER(col) LIKE ?`). Helpers: `attachTeamOwner`, `attachTeamMember($team,$user,'Role')`, `$user->switchTeam($team)`. Factory states: `trashed()`, `testConnected()`, `liveConnected()`, `withTestSubaccount()`. Fake Nomba with `Http::fake`.

## Watch-outs / known debt
- A charge stores **only the payment method** — no `payments`/`invoices` rows until **Phase 6**.
- Charge success toast may not fire on the full-page return from Nomba (the card still saves). No live dashboard update after a customer pays a link — needs the Phase 7 inbound webhook or a poll.
- `customers.default_payment_method_id` is canonical for "default"; `payment_methods.is_default` mirrors it.
- Nomba returns no card fingerprint (cross-customer card dedupe not possible).
- ~7 pre-existing PHPStan level-7 errors in `Catalog`/`Developers` controllers — not from Phase 4; leave them.

## Read first
- `IMPLEMENTATION.md` — Phase 4 "Decisions locked" + "carried into later phases", and the Phase 5 section.
- `CUSTOMERS_DESIGN.md` — §10 (tokenization), §11 (staged sections), §17 (future-proofing).
- `schema.md` — §4 (subscriptions), §5 (trial offers).
