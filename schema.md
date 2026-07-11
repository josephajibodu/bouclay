# Bouclay — Database Schema

Processor-agnostic subscription billing engine (Laravel). This is the authoritative data model: every table, field, type, relationship, and enum. It supersedes the Figma "Plan Board". Built on the Stripe/Paddle billing model for the transactional core — Laravel Cashier Paddle only ships four tables (`customers`, `subscriptions`, `subscription_items`, `transactions`) because Paddle is the merchant-of-record and owns the catalog; Bouclay *is* the engine, so it models everything Paddle keeps server-side. The catalog layer additionally borrows Recurly's separate **Plan** entity (`Product → Plan → Price`) so a single product can hold multiple named tiers, each with its own priced, trial-capable variants.

---

## Conventions

These apply to every table — assume them rather than repeating per row.

- **Primary key**: `ulid` column named `id`.
- **Money**: always `bigInteger` in **minor units** (kobo/cents) paired with an ISO-4217 `currency` `char(3)`. Never floats.
- **Tenancy**: every tenant-owned table carries `team_id` (`ulid`, FK, indexed). All queries scope by it; workers never trust a join to infer the tenant. Staff access the dashboard through `team_members`; `users.current_team_id` is the active tenant context in the session.
- **Flexibility**: `custom_data` `json` (nullable) on the major entities — Paddle's `custom_data` / Stripe's `metadata`. Avoids a polymorphic metadata table.
- **Timestamps**: `created_at` + `updated_at` on every table. `deleted_at` (SoftDeletes) on catalog and customer rows only.
- **Enums**: stored as `string`, cast to PHP enums in the model. Values listed per column and collected in the [Enums appendix](#enums-appendix).
- **Idempotency**: all external write endpoints gate on `idempotency_keys`.
- **Processor (Nomba BYOK)**: each team connects **their own** Nomba API keys. Bouclay charges and tokenises on their merchant account; settlement stays with Nomba. Bouclay exposes a **generated inbound webhook URL** per team for the Nomba dashboard; integrators register **outbound** URLs in `webhook_endpoints` for subscription lifecycle events.
- **Authorization**: Spatie-lite RBAC — `permissions` attach to `roles` only; staff receive permissions through **roles assigned per `team_members` row** (many roles per member, Paddle-style). No direct user permissions. Gate dashboard routes and APIs with `$user->hasTeamPermission($team, 'invoices.manage')`, which unions permissions across all roles on that membership.

### Dashboard vocabulary (locked 2026-07-06)

Bouclay is a billing **engine** (Stripe-shaped data model), not a Paddle MoR mirror. Use these terms consistently in code, routes, UI, and docs:

| Concept | Model / table | Public ID | Dashboard label |
|---|---|---|---|
| **Invoice** | `Invoice` / `invoices` | `inv_` | **Invoice** — the numbered billing record and legal document |
| **Payment** | `Payment` / `payments` | `pay_` | **Payment** — one processor charge *attempt* against an invoice (includes failed attempts) |

**Rules:**

- **"Transaction" is not a Bouclay entity.** There is no `Transaction` model, no `/transactions` routes, and no `transactions.*` permissions. Paddle uses "transaction" for what Bouclay models as an `Invoice`; when comparing to Paddle docs, map `transaction` → `invoice`.
- **Permissions** use `invoices.view`, `invoices.manage`, `invoices.finalize` (seeded). Policy/DTO methods: `viewInvoices` / `manageInvoices`; Inertia props: `canViewInvoices` / `canManageInvoices`.
- **Routes:** `GET/POST /invoices`, `GET /invoices/{invoice}`, void/uncollectible actions — see `routes/invoices.php`, `InvoiceController`.
- **Hub sections:** subscription and customer detail pages show **Invoices** (invoice rows) and **Payments** (charge-attempt rows). Never label `Payment` rows "Transactions".
- **Nomba API** paths like `/v1/transactions/accounts/single` are Nomba's terminology — unrelated to Bouclay dashboard naming.
- **Laravel** `DB::transaction()` is a database transaction — unrelated to billing vocabulary.

---

## Entity Relationship Diagram

```mermaid
erDiagram
    teams ||--o| team_settings : configures
    teams ||--o| team_processor_connections : connects
    teams ||--o{ team_members : has
    teams ||--o{ team_invitations : invites
    roles ||--o{ role_permission : grants
    permissions ||--o{ role_permission : includes
    team_members ||--o{ team_member_roles : assigned
    roles ||--o{ team_member_roles : receives
    team_invitations ||--o{ team_invitation_roles : offers
    roles ||--o{ team_invitation_roles : receives
    teams ||--o{ api_keys : issues
    teams ||--o{ customers : owns
    teams ||--o{ products : owns
    teams ||--o{ plans : owns
    teams ||--o{ entitlements : defines
    teams ||--o{ subscriptions : owns
    teams ||--o{ invoices : owns
    teams ||--o{ discounts : defines
    teams ||--o{ events : emits
    teams ||--o{ webhook_endpoints : registers
    teams ||--o{ idempotency_keys : tracks

    users ||--o{ team_members : belongs
    users }o--o| teams : currentTeam

    customers }o--o| customers : parent_account
    customers ||--o{ addresses : has
    customers ||--o{ payment_methods : has
    customers ||--o{ subscriptions : holds
    customers ||--o{ invoices : billed_on
    customers ||--o{ payments : pays
    customers ||--o{ price_trial_redemptions : redeems
    customers ||--o{ discount_redemptions : redeems
    payment_methods }o--o| addresses : billed_to

    products ||--o{ plans : contains
    plans ||--o{ prices : priced_by
    prices ||--o{ price_tiers : tiered_by
    prices ||--o{ price_currency_options : priced_in
    prices ||--o{ price_phases : phased_from
    prices ||--o{ price_phases : charged_during
    prices ||--o{ price_trial_redemptions : redeemed_as

    entitlements ||--o{ entitlement_grants : granted_via
    plans ||--o{ entitlement_grants : grants
    products ||--o{ entitlement_grants : grants

    subscriptions ||--o{ subscription_items : contains
    subscriptions ||--o{ scheduled_changes : schedules
    subscriptions ||--o{ invoices : generates
    subscriptions ||--o{ discount_redemptions : applies
    subscriptions }o--o| payment_methods : charges
    subscriptions }o--o| discounts : discounted_by
    subscription_items }o--|| prices : references
    subscription_items }o--|| plans : references
    subscription_items }o--|| products : references

    discounts ||--o{ discount_redemptions : redeemed_as

    invoices ||--o{ invoice_lines : itemized_by
    invoices ||--o{ payments : settled_by
    invoices }o--|| customers : billed_to
    payment_methods ||--o{ payments : used_for
    payments ||--o{ refunds : refunded_by
    invoice_lines }o--o| prices : from
    invoice_lines }o--o| subscription_items : from

    events ||--o{ webhook_deliveries : delivered_as
    webhook_endpoints ||--o{ webhook_deliveries : targets
```

> Note: `team_id` lives on nearly every billing table; the diagram only draws the team edges to aggregate roots to stay legible.

---

## 1. Platform & Tenancy

### `teams`
The tenant / merchant using Bouclay. **Already implemented** in the app (`Team` model); billing tables attach via `team_id`.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| name | string | no | business name, collected at signup |
| slug | string | no | unique; route key |
| is_personal | boolean | no | default false; auto-created personal workspace on signup |
| business_type | string | yes | enum: `individual` / `private` / `public`; collected at signup |
| website | string | yes | |
| country | char(2) | yes | ISO-3166; business address, collected at signup |
| line1 | string | yes | business address street line 1 |
| line2 | string | yes | business address street line 2 |
| city | string | yes | business address city/town |
| postal_code | string | yes | business address postal/zip code |
| default_currency | char(3) | no | billing default for this team |
| custom_data | json | yes | |
| created_at / updated_at / deleted_at | timestamp | yes | SoftDeletes |

The `business_type` / `website` / `country` / `line1` / `line2` / `city` / `postal_code` columns are nullable at the schema level (teams created later via "create team" only carry a name), but the signup flow requires all of them except `website` and `line2`. This is the team's *own* business address — distinct from `addresses`, which stores each *customer's* billing/shipping address.

### `team_settings`
One row per team — invoice numbering, dunning, and other billing config tenants tweak.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams, unique |
| invoice_prefix | string | no | e.g. `BCL` |
| next_invoice_number | unsignedBigInteger | no | sequence counter, default 1 |
| invoice_template | string | yes | template key |
| invoice_footer | text | yes | |
| billing_timezone | string | no | e.g. `Africa/Lagos`; anchors when "due today" fires |
| tax_behavior | string | no | enum: `inclusive` / `exclusive` — team default |
| dunning_config | json | yes | retry schedule + terminal action override |
| created_at / updated_at | timestamp | no | |

### `team_processor_connections`
Bring-your-own-key (BYOK) link between a team and Nomba — like connecting API keys on OpenCode. Created when a team first connects Nomba; the **Nomba webhook URL** is generated here and shown in the dashboard for paste into Nomba.

Nomba authenticates via OAuth2 client-credentials (`accountId` + `clientId` + `clientSecret` exchanged for a short-lived access token via `POST /v1/auth/token/issue`), not a single static secret key — hence three credential fields per environment instead of one. Access/refresh tokens themselves are never persisted; `NombaClient` re-mints and caches them on demand.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams, unique |
| processor | string | no | enum: `nomba`; extensible |
| nomba_test_account_id | text | yes | encrypted; parent business account, always authenticates |
| nomba_test_subaccount_id | text | yes | encrypted; optional — when set, business-operation requests scope to this instead of the parent account |
| nomba_test_client_id | text | yes | encrypted |
| nomba_test_client_secret | text | yes | encrypted |
| nomba_live_account_id | text | yes | encrypted |
| nomba_live_subaccount_id | text | yes | encrypted |
| nomba_live_client_id | text | yes | encrypted |
| nomba_live_client_secret | text | yes | encrypted |
| inbound_webhook_token | string | no | unique; unguessable segment in the Nomba → Bouclay URL |
| webhook_verified_at | timestamp | yes | set when the inbound URL has actually received something (a real Nomba event or the dashboard's "Send test event" self-check); null reads as "not yet verified", never assumed reachable |
| nomba_test_webhook_secret | text | yes | encrypted; signing key the integrator set on Nomba's dashboard (test) — pasted in, never revealed again after save |
| nomba_live_webhook_secret | text | yes | encrypted; signing key the integrator set on Nomba's dashboard (live) |
| test_connected_at | timestamp | yes | set when test credentials first verified against Nomba and saved |
| live_connected_at | timestamp | yes | set when live credentials first verified against Nomba and saved |
| created_at / updated_at | timestamp | no | |

**Generated inbound URL** (display only, not stored):

`POST {APP_URL}/webhooks/nomba/{inbound_webhook_token}`

Nomba sends payment/checkout events here. Bouclay resolves `team_id` from the token; today this just marks `webhook_verified_at` reachable. Signature verification (using that team's Nomba webhook secret) and mapping events to subscriptions/invoices/payments lands in Phase 7; outbound events to the team's `webhook_endpoints` follow in Phase 9.

**Charge path**: API request scoped to team → read this row's credentials for the request's mode (`test` / `live`) → exchange for an access token via `NombaClient` → call Nomba Charge/Checkout APIs, scoped to the subaccount if one is set, otherwise the parent account.

### `users`
Global auth identity for staff. **Already implemented** in the app (`User` model). A user belongs to many teams via `team_members`; authorization is via roles on that membership, not columns on this row.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| first_name | string | no | |
| last_name | string | no | |
| email | string | no | unique globally |
| password | string | no | |
| current_team_id | ulid | yes | FK → teams; active tenant context for the session |
| phone | string | yes | |
| email_verified_at | timestamp | yes | |
| created_at / updated_at | timestamp | no | |

`name` (full name) is a computed accessor (`first_name` + `last_name`), not a stored column.

### `permissions`
App-global permission catalog (seeded). Permissions attach to **roles only** — never directly to users.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| name | string | no | unique machine name, e.g. `invoices.manage` |
| label | string | no | human label for UI |
| description | text | yes | |
| group | string | no | UI grouping, e.g. `invoicing`, `finance`, `technical` |
| created_at / updated_at | timestamp | no | |

### `roles`
App-global role catalog (seeded). Paddle-style preset roles; not tenant-customisable in MVP.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| name | string | no | unique slug, e.g. `admin`, `finance` |
| label | string | no | display name, e.g. `Admin`, `Finance` |
| description | text | yes | shown on role assignment UI |
| is_system | boolean | no | default true; system roles cannot be deleted |
| sort_order | smallInteger | no | default 0; display order in UI |
| created_at / updated_at | timestamp | no | |

### `role_permission`
Pivot — which permissions each role grants.

| Column | Type | Null | Notes |
|---|---|---|---|
| role_id | ulid | no | FK → roles |
| permission_id | ulid | no | FK → permissions |

Primary key `(role_id, permission_id)`.

### `team_members`
Which users belong to which teams. **Partially implemented** (`Membership` model / `team_members` table) — migrate off the legacy single `role` enum to `team_member_roles` + `is_owner`.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| user_id | ulid | no | FK → users |
| is_owner | boolean | no | default false; exactly one `true` per team — billing owner, can delete team, transfer ownership |
| created_at / updated_at | timestamp | no | |

Unique `(team_id, user_id)`. Team creator gets `is_owner = true` and the **Admin** role. `is_owner` is not a role; it is a guard on destructive team actions.

### `team_member_roles`
Pivot — roles assigned to a team member (many per member; Paddle-style checkboxes).

| Column | Type | Null | Notes |
|---|---|---|---|
| team_member_id | ulid | no | FK → team_members |
| role_id | ulid | no | FK → roles |

Primary key `(team_member_id, role_id)`.

Effective permissions = union of all permissions from all assigned roles. The **Admin** role receives every permission via seeder.

### `team_invitations`
Pending invites before a user joins a team. **Partially implemented** (`TeamInvitation` model) — migrate off legacy single `role` to `team_invitation_roles`.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| code | string | no | unique; token for accept/decline links |
| team_id | ulid | no | FK → teams |
| email | string | no | invitee |
| invited_by | ulid | no | FK → users |
| expires_at | timestamp | yes | |
| accepted_at | timestamp | yes | null until accepted |
| created_at / updated_at | timestamp | no | |

### `team_invitation_roles`
Roles pre-assigned on invite; copied to `team_member_roles` when accepted.

| Column | Type | Null | Notes |
|---|---|---|---|
| team_invitation_id | ulid | no | FK → team_invitations |
| role_id | ulid | no | FK → roles |

Primary key `(team_invitation_id, role_id)`. **Admin** and owner transfer are not assignable via invite without existing owner approval.

### `api_keys`
Per-team **Bouclay** API credentials — for downstream developers calling Bouclay (not Nomba keys; those live in `team_processor_connections`).

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| created_by | ulid | yes | FK → users, null on delete; who generated the key |
| name | string | no | integrator-chosen label, e.g. "Backend server" |
| mode | string | no | enum: `test` / `live`; a live key cannot be created without a connected live Nomba account |
| kind | string | no | enum: `publishable` / `secret` |
| hashed_secret | string | no | unique; store a hash (`sha256`), show the raw key once at creation and never again |
| last_four | string | yes | last 4 chars of the raw key, for display (e.g. `sk_test_••••••••f2a2`) |
| last_used_at | timestamp | yes | |
| revoked_at | timestamp | yes | |
| created_at / updated_at | timestamp | no | |

### `idempotency_keys`
Replay guard for all external writes.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| key | string | no | unique with team_id |
| request_hash | string | no | guards against key reuse with a different body |
| response_code | smallInteger | yes | |
| response_body | json | yes | replayed on duplicate |
| locked_at | timestamp | yes | in-flight guard |
| created_at | timestamp | no | |

---

## 2. Customers & Payment Methods

### `customers`
The end-customers being billed.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| external_ref | string | yes | the tenant's own customer id; unique with team_id when set |
| name | string | yes | |
| email | string | no | |
| phone | string | yes | |
| currency | char(3) | yes | defaults to team currency |
| locale | string | yes | e.g. `en`, `fr` |
| country | char(2) | yes | ISO-3166 |
| default_payment_method_id | ulid | yes | FK → payment_methods (see migration order — added after payment_methods exists) |
| parent_customer_id | ulid | yes | FK → customers (self); reserved for future parent/child billing (Recurly Account Hierarchy) — unused in MVP logic, cheap to reserve now vs. expensive to retrofit onto historical invoice rows later |
| custom_data | json | yes | |
| created_at / updated_at / deleted_at | timestamp | yes | SoftDeletes |

### `addresses`
A customer's address book. Invoices snapshot the address at finalise time — never rely on this live FK for a historical invoice.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| customer_id | ulid | no | FK → customers |
| type | string | no | enum: `billing` / `shipping` |
| name | string | yes | |
| line1 | string | no | |
| line2 | string | yes | |
| city | string | yes | |
| region | string | yes | |
| postal_code | string | yes | |
| country | char(2) | no | |
| phone | string | yes | |
| is_default | boolean | no | per type, default false |
| created_at / updated_at | timestamp | no | |

### `payment_methods`
Tokenised payment instruments. Processor-agnostic.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| customer_id | ulid | no | FK → customers |
| processor | string | no | enum: `nomba` (extensible) |
| processor_token | string | no | tokenised reference |
| type | string | no | enum: `card` / `bank` / `wallet` |
| brand | string | yes | visa / mastercard |
| last4 | string | yes | |
| exp_month | smallInteger | yes | |
| exp_year | smallInteger | yes | |
| fingerprint | string | yes | dedupes the same card across customers |
| issuer | string | yes | |
| billing_address_id | ulid | yes | FK → addresses |
| is_default | boolean | no | default false |
| status | string | no | enum: `active` / `expired` / `revoked` |
| custom_data | json | yes | |
| created_at / updated_at | timestamp | no | |

---

## 3. Catalog & Pricing

`Product → Plan → Price`. Product is a grouping/display container. Plan is the named tier a customer actually picks — "Cursor Pro" — and owns only identity and lifecycle. Price is the billable variant of that plan: interval × currency × amount, plus its own trial config, because different variants of the same plan (monthly vs. yearly) commonly need different cadence and different trial treatment. One product holds many plans; one plan holds many prices.

### `products`

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| name | string | no | |
| description | text | yes | |
| category | string | yes | keep as string unless you truly need a categories table |
| image_url | string | yes | |
| status | string | no | enum: `active` / `archived` |
| custom_data | json | yes | |
| created_at / updated_at / deleted_at | timestamp | yes | SoftDeletes |

### `plans`
The tier. Deliberately thin — no `billing_interval`, no `pricing_model`, no trial fields here; all of that varies per billable variant and lives on `prices`.

**`draft` plans and their prices**: `plans.status` and `prices.status` are independent columns, but a `draft` plan should not be purchasable — enforce at the application layer that a price cannot be attached to a new subscription (or surfaced in a payment link/picker) while its plan is `draft` or `archived`, regardless of the price's own `status`. Not a DB constraint. `products.status` has no `draft` value at all, so this rule only applies one level down, at the plan.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| product_id | ulid | no | FK → products |
| code | string | yes | merchant-facing identifier |
| name | string | no | what shows in the picker — "Cursor Pro" |
| status | string | no | enum: `draft` / `active` / `archived` |
| custom_data | json | yes | |
| created_at / updated_at | timestamp | no | |

### `prices`

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| product_id | ulid | no | FK → products (denormalised — always resolvable whether or not `plan_id` is set) |
| plan_id | ulid | yes | FK → plans; set when this price is a variant of a plan, null for a one-time price sold directly off the product with no plan involved |
| name | string | yes | e.g. "Pro Monthly" |
| type | string | no | enum: `recurring` / `one_time` |
| pricing_model | string | no | enum: `standard` / `tiered` / `volume` / `graduated` / `package` |
| unit_amount | bigInteger | yes | minor units; used for `standard` + `package`; null for tiered/volume/graduated |
| currency | char(3) | no | simple multi-currency = one price row per currency |
| billing_interval | string | yes | enum: `day` / `week` / `month` / `year`; null for `one_time` |
| billing_frequency | smallInteger | no | default 1; `3` + `month` = every 3 months |
| package_size | integer | yes | for `package`; units per block |
| tax_mode | string | no | enum: `inclusive` / `exclusive` / `account`; default `account` |
| status | string | no | enum: `active` / `archived` |
| replaces_price_id | ulid | yes | FK → prices (self); set when this row supersedes an earlier price (a merchant "edit"). Null for an original. Walk the chain to see a price's full lineage. |
| version | integer | no | default 1; human-facing label only — a display hint for "v2 of this price," **not** a signal that the row was mutated in place. Incremented on the superseding row, never used to UPDATE an existing one. |
| trial_length | integer | yes | null = no trial on this price |
| trial_unit | string | yes | enum: `day` / `week` / `month`; null when `trial_length` is null |
| trial_requires_payment_info | boolean | no | default false — mirrors the `missing_payment_method` framing used on `subscriptions.trial_end_behavior` |
| trial_once_per_customer | boolean | no | default true — anti-abuse toggle, enforced via `price_trial_redemptions` |
| purchasable | boolean | no | default true — false for a price that exists only as a `price_phases.charge_price_id` target, never meant to be offered directly. The "New Price" picker and the Products list both filter `WHERE purchasable = true`, so phase-only prices never surface as something a merchant could confuse for a sellable option — see `price_phases` below. |
| custom_data | json | yes | |
| created_at / updated_at | timestamp | no | |

**Constraint**: a price with `plan_id = null` (a one-time price sold directly off the product) can **never** be referenced by a `subscription_item` — only by `invoice_lines`/`payment_links` directly. `subscription_items.plan_id` is `NOT NULL`, so only `plan_id`-bearing (i.e. `type = recurring`) prices are valid there; enforce this at the application layer when attaching a price to a subscription.

**Immutability invariant** (the single most important rule for this table): once a price row is referenced by any `subscription_item` or `invoice_line`, it is **append-only — never UPDATE it**. A merchant "editing" a price creates a *new* row (`replaces_price_id` → the old one), archives the old row (`status = archived`), and repoints the catalog picker at the new row; existing subscriptions keep referencing the original price forever. This is what lets you answer "exactly what did this customer buy in 2024?" without archaeology, and why `version` is a label rather than an in-place counter. The only fields ever safe to mutate on a live price are `status` (active → archived) and `custom_data`. Everything price-defining — `unit_amount`, `currency`, `pricing_model`, `billing_interval`, tiers — is frozen at creation.

### `price_tiers`
Rows that drive tiered / volume / graduated pricing. **One table, three behaviours** — only the application differs.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| price_id | ulid | no | FK → prices |
| tier_index | smallInteger | no | 0-based order |
| up_to | bigInteger | yes | null = final "infinity" tier |
| unit_amount | bigInteger | no | minor units, per unit in this tier |
| flat_amount | bigInteger | yes | minor units, flat fee for landing in this tier |

Application at billing time:
- **volume** — the whole quantity is priced at the single tier its total lands in.
- **graduated** — units are priced progressively across every tier they span, then summed.
- **package** — ignores this table: `ceil(quantity / package_size) × unit_amount` off the price row.
- **standard** — `quantity × unit_amount`, no tiers.

### `price_currency_options` *(optional — defer for MVP)*
Present one logical price in many currencies instead of a row per currency. If used, add `currency` to `price_tiers` too.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| price_id | ulid | no | FK → prices |
| currency | char(3) | no | unique with price_id |
| unit_amount | bigInteger | no | minor units |

### `price_phases`
The generalized mechanism for anything beyond a simple trial: a paid multi-iteration trial, a transition to a different plan/price when a trial ends, or a genuine multi-step ramp schedule. Deliberately named for the *price* it's scoped to, not "ramp" — same underlying shape as Stripe subscription-schedule phases or Recurly's `PlanPhase`, adapted to Bouclay's Plan→Price split.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| price_id | ulid | no | FK → prices — the "home" price this schedule is attached to (what a subscription_item nominally references, e.g. "Pro Monthly") |
| sequence | smallInteger | no | 0-based ordering |
| charge_price_id | ulid | no | FK → prices — the price actually charged during this phase; distinct from `price_id` because a phase can charge a trial-priced row, or **a price under a different plan entirely** for "transition to a different plan after trial" — no dedicated `transition_plan_id` field needed |
| duration_interval | string | no | enum: `day` / `week` / `month` / `year` |
| duration_count | integer | no | |
| created_at / updated_at | timestamp | no | |

A simple trial (`prices.trial_length` set) never touches this table. A phased trial is phase 0 (`charge_price_id` = a trial-priced row) → phase 1 (`charge_price_id` = the regular price, possibly under a different plan). A genuine 3+ step ramp is just more rows.

**Why `charge_price_id` points at a full `prices` row instead of `price_phases` carrying its own amount/currency/tiering columns**: a phase's charge still needs everything a normal price gets — reuse (phase 1 is routinely just the plan's existing regular price), `version` bumps for grandfathering, `pricing_model`/`price_tiers` for a tiered or graduated phase, and a real `price_id` for `invoice_lines` to reference so every charge — phase-originated or not — settles through the one invoicing code path. Duplicating that shape onto `price_phases` instead would mean reimplementing tiering/currency/versioning twice.

The actual cost of that is prices that exist purely to be a phase's trial-priced target and were never meant to be sold on their own — `prices.purchasable = false` is what keeps those out of the merchant-facing catalog (picker, Products list) without giving up any of the above. A price auto-generated while authoring a phase should default `purchasable = false`; a price created directly from the Products page defaults `purchasable = true`.

### `price_trial_redemptions`
The one piece of trial state worth its own durable row: enforcing `trial_once_per_customer`.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams — anti-abuse is *the* table where "never trust a join to infer the tenant" matters most; query this directly by `team_id`, don't rely on `price_id`/`customer_id` joins to scope it |
| price_id | ulid | no | FK → prices |
| customer_id | ulid | no | FK → customers |
| subscription_item_id | ulid | no | FK → subscription_items |
| redeemed_at | timestamp | no | |

---

## 4. Entitlements

Decoupled access-control layer. What a customer can *access* is a separate concept from what they've *paid for* — an entitlement is a named capability, granted by one or more Plans/Products, checked independently of invoice/payment state, so application access logic never has to reach into billing internals.

### `entitlements`

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| code | string | no | unique with team_id; the key application code checks against |
| name | string | no | |
| description | text | yes | |
| created_at / updated_at | timestamp | no | |

### `entitlement_grants`
Polymorphic join — one entitlement can be granted by multiple Plans/Products; one Plan/Product can grant multiple entitlements. Implemented as a **Laravel `morphTo('grantor')` relation**: `grantor_type` / `grantor_id` are the standard `morphs('grantor')` pair, with an **enforced morph map** (`Relation::enforceMorphMap([...])`) so `grantor_type` stores the stable alias (`plan` / `product`), never a raw class FQN.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams (denormalised — per the tenancy convention, don't rely on the `entitlement_id` join to scope queries) |
| entitlement_id | ulid | no | FK → entitlements |
| grantor_type | string | no | morph alias: `plan` / `product` |
| grantor_id | ulid | no | morph target id, resolved via the `grantor` relation |
| created_at | timestamp | no | |

Grants are **catalog-only by design** — a customer's access is resolved purely from the plans/products on their active subscriptions. Per-customer / manual / promo grants are deliberately not modelled for MVP; because `grantor` is already a polymorphic relation, adding a `customer` alias to the morph map later is a resolver change with **no migration**. Bouclay is a billing engine, not an IAM platform.

---

## 5. Trials & Phased Pricing

Simple trials live directly on `prices.trial_length` / `trial_unit` / `trial_requires_payment_info` (§3) — a price either has a trial or it doesn't, no separate catalog object for the common case. Free vs. paid is inferred from the trial-phase price (`unit_amount = 0` → free); whether a card is required at signup is `trial_requires_payment_info`.

Complex cases — a paid multi-iteration trial, a transition to a different plan's price when the trial ends, or a true multi-step ramp — use `price_phases` (§3): an ordered `(charge_price_id, duration)` list anchored to a price.

`subscription_items.trial_ends_at` and `current_phase_sequence` (§6) track where one subscription item is in this, snapshotted at creation so a later edit to the catalog doesn't rewrite an active subscriber's history. `subscriptions.trial_ends_at` stays the denormalised clock the billing/access workers read (earliest active item trial end). `price_trial_redemptions` (§3) is the durable row kept purely for `trial_once_per_customer` anti-abuse.

**State-machine threading**: free trial (`unit_amount = 0` during the trial phase, no payment method required) → subscription starts in `trialing`, skips `incomplete`. Paid trial (`unit_amount > 0`) → payment captured at signup, subscription follows the normal `incomplete → active` path (paid trials are treated as active, not trialing). At `trial_ends_at` (or a phase boundary) → the item's effective price swaps, subscription → `active` (or `canceled`/`paused` per `trial_end_behavior` if no payment method).

**Add-ons during a trial (Stripe-style, locked):** the subscription's trial is **anchored to its base plan item**. An add-on item that has no trial of its own does **not** bill on its own at signup — it rides the subscription's trial and is first invoiced at conversion, alongside the plan. A "free trial" subscription therefore charges nothing at day 0 even when it carries a paid add-on. (An add-on that *itself* defines a trial keeps that trial; the anchor rule only covers add-ons without one.) This matches Stripe's subscription-level trial and is why the first real invoice (`billing_reason = subscription_create`) bundles plan + add-on lines together.

---

## 6. Subscriptions

### `subscriptions`

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams (denormalised) |
| customer_id | ulid | no | FK → customers |
| type | string | no | named slot, default `default`; lets one customer hold multiple distinct subs |
| status | string | no | enum: `incomplete` / `incomplete_expired` / `trialing` / `active` / `past_due` / `paused` / `canceled` |
| currency | char(3) | no | fixed for the life of the sub |
| collection_mode | string | no | enum: `automatic` / `manual` |
| payment_method_id | ulid | yes | FK → payment_methods |
| discount_id | ulid | yes | FK → discounts |
| billing_anchor | string | yes | e.g. month-end anchor metadata |
| current_period_start | timestamp | yes | |
| current_period_end | timestamp | yes | next renewal charge fires here |
| trial_ends_at | timestamp | yes | denormalised clock; mirrors the earliest active `subscription_items.trial_ends_at` on this sub |
| trial_end_behavior | string | yes | enum: `cancel` / `pause` / `create_invoice`; when trial ends without a payment method (Stripe `missing_payment_method`) |
| billing_cycle_anchor_on_trial_end | string | yes | enum: `now` / `unchanged`; default `now` — reset anchor when trial transitions to regular price |
| paused_at | timestamp | yes | |
| pause_resumes_at | timestamp | yes | |
| canceled_at | timestamp | yes | set when cancellation is scheduled |
| ends_at | timestamp | yes | grace-period end; `subscribed` stays true until now() passes this |
| custom_data | json | yes | |
| created_at / updated_at | timestamp | no | |

### `subscription_items`
A subscription carries many priced items (base + add-ons).

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| subscription_id | ulid | no | FK → subscriptions |
| price_id | ulid | no | FK → prices |
| plan_id | ulid | no | FK → plans (denormalised, alongside `price_id`) |
| product_id | ulid | no | FK → products (denormalised) |
| kind | string | no | enum: `plan` / `addon`; default `plan` — distinguishes the base charge from add-ons |
| quantity | integer | no | default 1 |
| status | string | no | enum: `active` / `removed` |
| trial_ends_at | timestamp | yes | snapshotted from `price.trial_length`/`trial_unit` at creation — a later edit to the price's trial fields doesn't rewrite history for already-active items |
| current_phase_sequence | smallInteger | yes | null unless this item is progressing through `price_phases` |
| created_at / updated_at | timestamp | no | |

### `scheduled_changes`
Future cancel / pause / resume at the next boundary (the Paddle "borrow" pattern).

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| subscription_id | ulid | no | FK → subscriptions |
| action | string | no | enum: `cancel` / `pause` / `resume` |
| effective_at | timestamp | no | |
| payload | json | yes | |
| applied_at | timestamp | yes | worker marks done (audit trail) |
| created_at / updated_at | timestamp | no | |

---

## 7. Discounts

### `discounts`

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| code | string | yes | unique with team_id when set |
| type | string | no | enum: `percentage` / `flat` |
| amount | bigInteger | yes | minor units (flat); null for percentage |
| percentage | decimal(5,2) | yes | null for flat |
| currency | char(3) | yes | required for flat |
| duration | string | no | enum: `once` / `repeating` / `forever` |
| duration_in_intervals | integer | yes | for `repeating` |
| max_redemptions | integer | yes | |
| times_redeemed | integer | no | default 0 |
| eligible_plan_ids | json | yes | array of plan ids; null = all plans |
| eligible_price_ids | json | yes | array of price ids; when set, this is the **complete, authoritative** eligibility list and `eligible_plan_ids` is ignored — this is what makes "monthly only" promos expressible without discounting the yearly price too. When null, falls back to `eligible_plan_ids` (or everything, if that's also null). The two fields are never combined/intersected. |
| starts_at | timestamp | yes | |
| expires_at | timestamp | yes | |
| active | boolean | no | default true |
| created_at / updated_at | timestamp | no | |

### `discount_redemptions`

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| discount_id | ulid | no | FK → discounts |
| subscription_id | ulid | no | FK → subscriptions |
| customer_id | ulid | no | FK → customers |
| remaining_intervals | integer | yes | how many billing cycles this discount may **still** be applied to this subscription. Snapshotted at redemption from the discount's duration: `once` → `1`, `repeating` → `duration_in_intervals`, `forever` → `null` (never decrements). The renewal worker applies the discount only while this is `null` or `> 0`, decrementing by 1 each cycle it applies it. This is the durable answer to "is `WELCOME20` still live on cycle N?" — without it the worker can't tell interval 2-of-3 from an expired discount. |
| applied_at | timestamp | no | first cycle applied |
| last_applied_at | timestamp | yes | most recent cycle applied (audit / reporting) |

---

## 8. Billing: Invoices, Lines, Payments

### `invoices`
A frozen legal document — numbered, with a full money breakdown and snapshots taken at finalise time. Dashboard label: **Invoice**. Public ID prefix: `inv_` (via `HasPublicId` on the `Invoice` model). At creation, `CreateInvoice` populates `customer_snapshot` and `billing_address`.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| customer_id | ulid | no | FK → customers — the subscription/order owner |
| billed_to_customer_id | ulid | no | FK → customers — who actually pays; distinct field from `customer_id` from day one even though always equal in MVP (account-hierarchy seam — the field that makes parent/child billing addable later without migrating invoice history) |
| subscription_id | ulid | yes | FK → subscriptions (null for one-off) |
| number | string | yes | `{prefix}-{sequence}`, assigned at finalise; unique with team_id |
| type | string | no | enum: `charge` / `credit`; default `charge` — seam for future credit notes |
| status | string | no | enum: `draft` / `open` / `paid` / `void` / `uncollectible` |
| billing_reason | string | no | enum: `subscription_create` / `subscription_cycle` / `subscription_update` / `manual` |
| collection_mode | string | no | enum: `automatic` / `manual` |
| currency | char(3) | no | |
| subtotal | bigInteger | no | minor units, before tax/discount |
| discount_total | bigInteger | no | minor units, default 0 |
| tax_total | bigInteger | no | minor units, default 0 |
| total | bigInteger | no | minor units |
| amount_paid | bigInteger | no | minor units, default 0 |
| amount_due | bigInteger | no | = total − amount_paid |
| billing_address | json | yes | snapshot |
| customer_snapshot | json | yes | name/email at issue time |
| period_start | timestamp | yes | |
| period_end | timestamp | yes | |
| due_at | timestamp | yes | |
| finalized_at | timestamp | yes | |
| paid_at | timestamp | yes | |
| voided_at | timestamp | yes | |
| custom_data | json | yes | |
| created_at / updated_at | timestamp | no | |

### `invoice_lines`

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| invoice_id | ulid | no | FK → invoices |
| subscription_item_id | ulid | yes | FK → subscription_items |
| price_id | ulid | yes | FK → prices — the row *as it was*; safe to keep because prices are immutable (see prices §), but the names below are still snapshotted so a rename can't rewrite history |
| product_id | ulid | yes | FK → products |
| kind | string | no | enum: `plan` / `addon` / `proration` / `one_time` / `tax` / `discount` / `credit`. **`discount` is presentation/adjustment only** — never the source of a product discount; see the discount-representation invariant below. |
| description | string | no | free-text line description shown on the invoice |
| product_name_snapshot | string | yes | product name frozen at finalise; null for `tax`/`discount`/`credit` lines with no catalog origin |
| plan_name_snapshot | string | yes | plan name frozen at finalise; null when no plan applies |
| price_name_snapshot | string | yes | price name frozen at finalise (e.g. "Pro Monthly"); null when no catalog price applies |
| quantity | integer | no | default 1 |
| unit_amount | bigInteger | no | minor units |
| subtotal | bigInteger | no | minor units |
| discount_amount | bigInteger | no | minor units, default 0 — the **authoritative** representation of a discount applied to this billable line; see invariant below |
| tax_amount | bigInteger | no | minor units, default 0 |
| total | bigInteger | no | minor units |
| period_start | timestamp | yes | window this line covers (drives proration) |
| period_end | timestamp | yes | |
| proration | boolean | no | default false |
| created_at / updated_at | timestamp | no | |

**Name snapshots** extend the same discipline `invoices.customer_snapshot` already applies to the buyer: at finalise, `CreateInvoice` copies the product / plan / price *names* onto the line, not just their FKs. Prices are immutable so the amount is already safe via `price_id`, but names are edited freely on the catalog — without the snapshot, renaming "Pro" → "Professional" would silently rewrite every invoice ever issued. An invoice is a frozen legal document; its line labels must read the same in five years as the day it was sent. Render from the `*_snapshot` columns, never by joining live catalog rows.

**Discount-representation invariant** — the single source of truth for discounts, so totals, tax, refunds, and reporting never disagree:

1. **`invoice_lines.discount_amount` on billable lines is the canonical representation of product discounts.** A discount that applies to a product/plan/add-on is recorded by reducing that line's `discount_amount` (and `total`), pro-rated across the lines it covers.
2. **All derived money — invoice `subtotal`/`discount_total`/`tax_total`/`total`, per-line tax bases, proration, refunds, and every analytics query — is computed from the billable lines' `discount_amount`, never by summing `kind=discount` lines.** `discount_total = SUM(invoice_lines.discount_amount)`; a line's taxable base is `subtotal − discount_amount`.
3. **`kind=discount` is reserved for standalone financial adjustments that cannot be allocated to a billable item** — manual credits, goodwill adjustments, account credits. These are optional presentation/adjustment lines and **must never** be the primary source for discount calculation.

The same discount is therefore never recorded in both places, which is what prevents the double-count (`650000 − 130000 − 130000`) and the silent under-report (`SUM(discount_amount) = 0` when a discount was hidden in a `kind=discount` line's total). This is more robust than "Model A only" or "Model B only": one authoritative source for accounting, while invoices can still express adjustments that aren't naturally tied to a specific product.

**Tax (deferred native calc).** For MVP, `invoice_lines.tax_amount` and `invoices.tax_total` are **populated by the caller** — an external tax engine or a flat per-team rate — and Bouclay does not compute tax itself. Native calculation is a future feature: introduce `tax_rates`, `tax_jurisdictions`, and `invoice_tax_lines` when needed. That is purely additive on top of the per-line `tax_amount` that already exists (which is what keeps it from being a structural hole), not a change to the tables above. Until then, treat tax as an input, not a derived value.

### `payments`
One charge attempt against an invoice on the processor. Records every attempt (succeeded **and** failed) because Bouclay runs its own dunning — not just settled money. Dashboard label: **Payment**. Public ID prefix: `pay_` (via `HasPublicId` on the `Payment` model).

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| invoice_id | ulid | no | FK → invoices |
| customer_id | ulid | no | FK → customers |
| payment_method_id | ulid | yes | FK → payment_methods |
| processor | string | no | enum: `nomba` |
| processor_reference | string | yes | Nomba's reference for this charge attempt |
| amount | bigInteger | no | minor units |
| currency | char(3) | no | |
| status | string | no | enum: `pending` / `processing` / `succeeded` / `failed` / `refunded` |
| risk_level | string | yes | |
| failure_code | string | yes | drives dunning classification (hard vs soft decline) |
| failure_reason | string | yes | |
| attempt_number | integer | no | default 1 |
| idempotency_key | string | no | unique; one charge per (invoice, attempt) |
| raw_response | json | yes | full Nomba callback payload |
| processed_at | timestamp | yes | |
| created_at / updated_at | timestamp | no | |

### `refunds`
`payments.status = refunded` marks the terminal state on the original charge row, but the refund event itself needs its own auditable record — amount (may be partial), reason, gateway reference, timestamp — rather than overwriting the only copy of what happened.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| payment_id | ulid | no | FK → payments — the original charge being reversed |
| invoice_id | ulid | no | FK → invoices (denormalised, avoids a join through payments for invoice-scoped queries) |
| amount | bigInteger | no | minor units; may be partial |
| currency | char(3) | no | |
| reason | string | yes | |
| status | string | no | enum: `pending` / `succeeded` / `failed` |
| processor_reference | string | yes | |
| created_at / updated_at | timestamp | no | |

---

## 9. Events & Webhooks

Two directions — do not conflate them:

| Direction | Configured where | Purpose |
|---|---|---|
| **Inbound** (Nomba → Bouclay) | Nomba dashboard → paste URL from `team_processor_connections.inbound_webhook_token` | Raw payment/checkout events; drives dunning and subscription state |
| **Outbound** (Bouclay → integrator) | `webhook_endpoints` in Bouclay dashboard / API | Normalised billing events (`invoice.updated`, `subscription.updated`, …) |

Integrators never wire Nomba webhooks into their app for subscription logic. They wire **Bouclay** webhooks.

### Outbound event naming (target convention)
One `*.created` event when an object is instantiated; one `*.updated` event reused for every subsequent status change, renewal, or modification — never a new event name per transition. Consumers read the object's `status` field off the payload.

| Object | Events |
|---|---|
| Customer | `customer.created`, `customer.updated` |
| Payment method | `payment_method.created`, `payment_method.updated` |
| Product | `product.created`, `product.updated` |
| Plan | `plan.created`, `plan.updated` |
| Subscription | `subscription.created`, `subscription.updated` |
| Invoice | `invoice.created`, `invoice.updated` — no separate `invoice.paid`/`invoice.payment_failed` names; consumers read `status` off `invoice.updated` |
| Payment | `payment.created`, `payment.updated` — **not** `transaction.*`; "Transaction is not a Bouclay entity" (see [Dashboard vocabulary](#dashboard-vocabulary-locked-2026-07-06) above) applies to event names too, not just dashboard labels |

**This is a breaking rename against already-shipped code, not a free consequence of this doc.** Phase 9 (`IMPLEMENTATION.md`) is built and tested: it currently emits the concrete names `invoice.paid` / `invoice.payment_failed` / `payment_method.added`, covered by `OutboundWebhookEndpointTest`, `OutboundWebhookDeliveryTest`, `OutboundWebhookRetryTest`, and consumed by the Phase 12 reference app's webhook handler. Collapsing to `*.created`/`*.updated` pairs is the right target shape, but landing it means updating `OutboundEventType`, every emission call site, those three test files, and the reference app — track it as its own work item when this catalog rework actually ships, not as a side effect of editing `schema.md`.

### `events`
Normalised event log emitted **to integrators**.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| type | string | no | |
| data | json | no | |
| created_at | timestamp | no | |

### `webhook_endpoints`
Integrator-owned URLs Bouclay POSTs to when `events` fire. Distinct from the inbound Nomba URL.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| team_id | ulid | no | FK → teams |
| url | string | no | |
| signing_secret | string | no | HMAC secret |
| active | boolean | no | default true |
| created_at / updated_at | timestamp | no | |

### `webhook_deliveries`
At-least-once delivery with exponential backoff.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | ulid | no | PK |
| webhook_endpoint_id | ulid | no | FK → webhook_endpoints |
| event_id | ulid | no | FK → events |
| status | string | no | enum: `pending` / `succeeded` / `failed` |
| attempts | integer | no | default 0 |
| next_attempt_at | timestamp | yes | backoff schedule |
| created_at / updated_at | timestamp | no | |

---

## Eloquent relationship map

| Model | Relationships |
|---|---|
| Team | hasOne settings, processorConnection; hasMany members (through teamMembers), invitations, apiKeys, customers, products, plans, entitlements, subscriptions, invoices, discounts, events, webhookEndpoints |
| User | belongsTo currentTeam; belongsToMany teams (through teamMembers); hasMany teamMemberships, sentInvitations |
| Permission | belongsToMany roles (through rolePermission) |
| Role | belongsToMany permissions (through rolePermission); belongsToMany teamMembers (through teamMemberRoles); belongsToMany teamInvitations (through teamInvitationRoles) |
| RolePermission | belongsTo role, permission |
| Membership (team_members) | belongsTo team, user; belongsToMany roles (through teamMemberRoles) |
| TeamMemberRole | belongsTo teamMember, role |
| TeamInvitation | belongsTo team, inviter (user); belongsToMany roles (through teamInvitationRoles) |
| TeamInvitationRole | belongsTo teamInvitation, role |
| TeamProcessorConnection | belongsTo team |
| Customer | belongsTo team, parentCustomer; hasMany childCustomers, addresses, paymentMethods, subscriptions, invoices, payments, priceTrialRedemptions; belongsTo defaultPaymentMethod |
| Address | belongsTo team, customer |
| PaymentMethod | belongsTo team, customer, billingAddress; hasMany payments |
| Product | belongsTo team; hasMany plans, entitlementGrants (as grantor) |
| Plan | belongsTo team, product; hasMany prices, entitlementGrants (as grantor) |
| Price | belongsTo team, product, plan; hasMany tiers, currencyOptions, subscriptionItems, phases (as home price), trialRedemptions |
| PricePhase | belongsTo price (home), chargePrice |
| PriceTrialRedemption | belongsTo price, customer, subscriptionItem |
| Entitlement | belongsTo team; hasMany grants |
| EntitlementGrant | belongsTo entitlement; morphTo grantor (plan or product) |
| Subscription | belongsTo team, customer, paymentMethod, discount; hasMany items, scheduledChanges, invoices |
| SubscriptionItem | belongsTo subscription, price, plan, product |
| ScheduledChange | belongsTo subscription |
| Discount | belongsTo team; hasMany redemptions |
| DiscountRedemption | belongsTo discount, subscription, customer |
| Invoice | belongsTo team, customer, billedToCustomer, subscription; hasMany lines, payments |
| InvoiceLine | belongsTo invoice, subscriptionItem, price, product |
| Payment | belongsTo team, invoice, customer, paymentMethod; hasMany refunds |
| Refund | belongsTo team, payment, invoice |
| Event | belongsTo team; hasMany deliveries |
| WebhookEndpoint | belongsTo team; hasMany deliveries |
| WebhookDelivery | belongsTo webhookEndpoint, event |

---

## Enums appendix

| Column | Values |
|---|---|
| teams.business_type | individual, private, public |
| roles.name (seed) | admin, finance, invoicing, subscription_kpis, support, technical |
| api_keys.mode | test, live |
| api_keys.kind | publishable, secret |
| team_settings.tax_behavior | inclusive, exclusive |
| addresses.type | billing, shipping |
| payment_methods.processor | nomba |
| payment_methods.type | card, bank, wallet |
| payment_methods.status | active, expired, revoked |
| products.status | active, archived |
| plans.status | draft, active, archived |
| prices.type | recurring, one_time |
| prices.pricing_model | standard, tiered, volume, graduated, package |
| prices.billing_interval | day, week, month, year |
| prices.tax_mode | inclusive, exclusive, account |
| prices.status | active, archived |
| prices.trial_unit | day, week, month |
| price_phases.duration_interval | day, week, month, year |
| entitlement_grants.grantor_type | plan, product |
| subscriptions.status | incomplete, incomplete_expired, trialing, active, past_due, paused, canceled |
| subscriptions.collection_mode | automatic, manual |
| subscriptions.trial_end_behavior | cancel, pause, create_invoice |
| subscriptions.billing_cycle_anchor_on_trial_end | now, unchanged |
| subscription_items.kind | plan, addon |
| subscription_items.status | active, removed |
| scheduled_changes.action | cancel, pause, resume |
| discounts.type | percentage, flat |
| discounts.duration | once, repeating, forever |
| invoices.type | charge, credit |
| invoices.status | draft, open, paid, void, uncollectible |
| invoices.billing_reason | subscription_create, subscription_cycle, subscription_update, manual |
| invoices.collection_mode | automatic, manual |
| invoice_lines.kind | plan, addon, proration, one_time, tax, discount, credit |
| payments.processor | nomba |
| payments.status | pending, processing, succeeded, failed, refunded |
| refunds.status | pending, succeeded, failed |
| webhook_deliveries.status | pending, succeeded, failed |

---

## RBAC seed appendix

Seeded on deploy. Permission names use `resource.action`. **Admin** receives all permissions.

### Permissions

| name | group | label |
|---|---|---|
| `team.view` | team | View team settings |
| `team.update` | team | Update team settings |
| `team.delete` | team | Delete team |
| `members.view` | team | View team members |
| `members.invite` | team | Invite team members |
| `members.update` | team | Update team members |
| `members.remove` | team | Remove team members |
| `members.assign_roles` | team | Assign roles to members |
| `customers.view` | catalog | View customers |
| `customers.manage` | catalog | Manage customers |
| `products.view` | catalog | View products |
| `products.manage` | catalog | Manage products |
| `plans.view` | catalog | View plans |
| `plans.manage` | catalog | Manage plans |
| `prices.view` | catalog | View prices |
| `prices.manage` | catalog | Manage prices |
| `entitlements.view` | catalog | View entitlements |
| `entitlements.manage` | catalog | Manage entitlements |
| `invoices.view` | invoicing | View invoices |
| `invoices.manage` | invoicing | Manage invoices |
| `invoices.finalize` | invoicing | Finalize invoices |
| `subscriptions.view` | subscriptions | View subscriptions |
| `subscriptions.manage` | subscriptions | Manage subscriptions |
| `subscription_kpis.view` | subscriptions | View subscription KPIs |
| `orders.view` | finance | View orders |
| `orders.manage` | finance | Manage orders |
| `payments.view` | finance | View payments |
| `financial_reports.view` | finance | View financial reports |
| `transfers.view` | finance | View transfers |
| `transfers.manage` | finance | Manage transfers |
| `refunds.view` | support | View refunds |
| `refunds.process` | support | Process refunds |
| `licenses.view` | support | View licenses |
| `licenses.manage` | support | Manage licenses |
| `api_keys.view` | technical | View API keys |
| `api_keys.manage` | technical | Manage API keys |
| `webhooks.view` | technical | View webhook endpoints |
| `webhooks.manage` | technical | Manage webhook endpoints |
| `integrations.view` | technical | View integrations |
| `integrations.manage` | technical | Manage integrations (Nomba BYOK) |
| `diagnostics.view` | technical | View diagnostics |
| `team_settings.view` | technical | View vendor/billing settings |
| `team_settings.manage` | technical | Manage vendor/billing settings |

### Default roles → permissions

| Role | Description | Permissions |
|---|---|---|
| **Admin** | Account administrator. Full access to all Bouclay functions. | **All** |
| **Finance** | Finance and accounting. View orders and financial reports; manage transfers. | `orders.view`, `payments.view`, `financial_reports.view`, `transfers.view`, `transfers.manage`, `invoices.view` |
| **Invoicing** | B2B invoicing plus customers, products, plans, and prices. | `invoices.view`, `invoices.manage`, `invoices.finalize`, `customers.view`, `customers.manage`, `products.view`, `products.manage`, `plans.view`, `plans.manage`, `prices.view`, `prices.manage` |
| **Subscription KPIs** | Read-only subscription analytics. | `subscription_kpis.view`, `subscriptions.view` |
| **Support** | End-user support — orders, refunds, licenses. | `orders.view`, `orders.manage`, `refunds.view`, `refunds.process`, `licenses.view`, `licenses.manage`, `customers.view`, `subscriptions.view` |
| **Technical** | API integration, keys, catalog, diagnostics, vendor settings. | `api_keys.view`, `api_keys.manage`, `webhooks.view`, `webhooks.manage`, `integrations.view`, `integrations.manage`, `diagnostics.view`, `team_settings.view`, `team_settings.manage`, `products.view`, `products.manage`, `plans.view`, `plans.manage`, `prices.view`, `prices.manage`, `entitlements.view`, `entitlements.manage` |

**Owner guard (not a role):** `team.delete`, `members.assign_roles`, and ownership transfer require `team_members.is_owner = true` in addition to the permission.

---

## Indexing & constraints

- **FK indexes**: index every FK column.
- **Tenancy**: index `team_id` on every table; add composite `(team_id, status)` on `subscriptions`, `invoices`, `payments` for dashboard filters.
- **Billing scheduler hot path**: composite index on `subscriptions (status, current_period_end)` — the scheduler scans for due subs by this.
- **Unique**: `teams.slug`; `users.email`; `team_members (team_id, user_id)`; `team_invitations.code`; `team_processor_connections.inbound_webhook_token`; `team_processor_connections (team_id)`; `api_keys.hashed_secret`; `customers (team_id, external_ref)` (when not null); `idempotency_keys (team_id, key)`; `invoices (team_id, number)`; `payments.idempotency_key`; `price_currency_options (price_id, currency)`.
- **Anti-abuse**: index `price_trial_redemptions (team_id, price_id, customer_id)` and `discount_redemptions (discount_id, customer_id)` — lead with `team_id` on the trial-redemption index specifically, since that table is the one place a cross-tenant join bug would actually leak a free trial.
- **Money**: enforce non-negative amounts at the application layer; keep everything in the subscription's single currency (don't mix currencies on one invoice).

---

## Migration order (FK-safe)

Two FKs are circular and must be deferred: `customers.default_payment_method_id ↔ payment_methods.customer_id`, and `payment_methods.billing_address_id ↔ addresses.customer_id`. Create the base tables first, then add the back-reference in a follow-up migration. `customers.parent_customer_id` is self-referencing and deferred the same way.

1. teams *(already in app — add billing columns `default_currency`, `custom_data` via alter)*
2. users *(already in app — add `current_team_id` via alter if not present)*
3. permissions, roles, role_permission *(global seed data)*
4. team_members *(already in app — migrate: drop `role`, add `is_owner`)*
5. team_member_roles, team_invitations *(already in app)*, team_invitation_roles
6. team_settings, team_processor_connections, api_keys, idempotency_keys, events, webhook_endpoints
7. webhook_deliveries
8. customers *(without `default_payment_method_id`, without `parent_customer_id`)*
9. addresses
10. payment_methods
11. **alter** customers → add `default_payment_method_id` FK, add `parent_customer_id` self-FK
12. products
13. plans *(refs products)*
14. prices *(refs plans, products)*
15. price_tiers, price_currency_options, price_phases *(refs prices twice — home + charge)*
16. entitlements, entitlement_grants *(refs plans, products via polymorphic grantor)*
17. discounts
18. subscriptions *(refs customers, payment_methods, discounts)*
19. subscription_items, scheduled_changes, price_trial_redemptions, discount_redemptions
20. invoices *(refs customers twice — `customer_id`, `billed_to_customer_id`)*
21. invoice_lines
22. payments
23. refunds

---

## Build order & cut-lines (MVP)

**Build now** — a complete, demoable engine: teams, team_members, team_member_roles, permissions, roles, team_invitations, team_processor_connections (Nomba BYOK + inbound webhook URL), users, api_keys, customers, payment_methods, products, plans, prices (standard + graduated), price_tiers, price_phases, price_trial_redemptions, entitlements, entitlement_grants, subscriptions, subscription_items, invoices, invoice_lines, payments, refunds, the lifecycle + dunning workers, events, webhook_endpoints, webhook_deliveries, idempotency_keys.

**Defer** — keep the tables, don't wire the logic: price_currency_options, volume pricing model (graduated ships instead), discounts + discount_redemptions if time-pressed.

---

## Cashier / Paddle mapping (positioning)

- Bouclay's `products` / `plans` / `prices` / `subscriptions` / `subscription_items` correspond to Paddle's catalog and Cashier's mirror — except Bouclay *owns* them rather than mirroring Paddle, and adds the Plan layer Paddle/Cashier don't have.
- **Nomba BYOK**: each `team` connects their own Nomba keys via `team_processor_connections`. Bouclay orchestrates checkout/charge/dunning; money settles to the integrator's Nomba merchant account. Inbound Nomba webhooks hit a generated Bouclay URL; outbound billing events hit the integrator's `webhook_endpoints`.
- Trials are a property of the billable `Price` (`trial_length`/`trial_unit`), not a separate Stripe-style Trial Offer catalog object — the simple case needs no extra entity. Complex transitions (paid multi-step trials, moving to a different plan) are ordinary `price_phases`, not a bespoke feature.
- **Paddle "Transaction" → Bouclay `Invoice`.** Paddle's central billing entity maps to Bouclay's numbered invoice. Paddle/Cashier "transactions" (completed money movement) map loosely to Bouclay `Payment` rows where `status = succeeded`, but Bouclay deliberately stores *every* charge attempt, not just successes.
- The `incomplete` / `incomplete_expired` states and the first-class dunning machine are the deliberate divergence from Paddle: Bouclay charges the token itself, so it needs the pre-active states Paddle hides behind hosted checkout.
- **Entitlements** (`entitlements`/`entitlement_grants`) are net-new — no Paddle/Cashier equivalent. A genuine Bouclay differentiator: access is decoupled from billing status by design, not inferred from subscription state at check time.
