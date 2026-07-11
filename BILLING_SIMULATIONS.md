# Bouclay — Billing Simulations

End-to-end scenario traces against the data model in [`schema.md`](schema.md). These exist to be turned into feature/integration tests and to pin down business rules **before** the billing code is written. Each simulation lists the starting state, the action, the exact rows that must be written, the outbound events that must fire, and the assertions a test should make.

When a simulation exposes a gap or an undecided rule, it's captured in [§ Open decisions & gaps](#open-decisions--gaps) rather than silently resolved — those are the things to settle at implementation time.

---

## Conventions used in these traces

- **Currency:** NGN. **All amounts are minor units (kobo).** ₦1,000 = `100000`. ₦5,000 = `500000`.
- **Merchant (Bouclay tenant):** *NaijaStream*, a streaming SaaS on Nomba BYOK (`teams` + `team_processor_connections`, live keys).
- **Notation:** `table{col=val, ...}` denotes a row write/update. `→ event x.y` denotes an outbound `events` row (and its `webhook_deliveries`). "Access check" = the application resolving a customer's `entitlements` from active subscriptions.
- **Subscription statuses:** `incomplete` / `incomplete_expired` / `trialing` / `active` / `past_due` / `paused` / `canceled`.
- **Invoice statuses:** `draft` / `open` / `paid` / `void` / `uncollectible`. **Payment statuses:** `pending` / `processing` / `succeeded` / `failed` / `refunded`.
- **Immutability:** a `prices` row referenced by any `subscription_item`/`invoice_line` is append-only; a merchant edit creates a new row (`replaces_price_id` → old, `version++`) and archives the old one.
- **Discounts on invoices:** `invoice_lines.discount_amount` on billable lines is authoritative; `discount_total = SUM(discount_amount)`; taxable base = `subtotal − discount_amount`; `kind=discount` lines are line-less adjustments only (see `schema.md` invoice_lines invariant).

---

## Shared fixture: the NaijaStream catalog

Used by every simulation below unless noted.

**Products:** `NaijaStream` (streaming), `Sports Pack` (add-on product).

**Plans:** `Premium` (product=NaijaStream, `status=active`), `Premium Plus` (added in SIM-06), `Sports Pack` (product=Sports Pack).

**Prices:**

| ref | plan | unit_amount | interval | trial | flags |
|---|---|---|---|---|---|
| `price_prem_m` | Premium | `500000` | month | `trial_length=7, trial_unit=day, trial_requires_payment_info=true` | `purchasable=true` |
| `price_sports_m` | Sports Pack | `150000` | month | none | `purchasable=true` |
| `price_seat_m` | Team (seats) | `100000` / seat | month | none | `purchasable=true`, used by SIM-07 |

**Entitlements:** `hd_streaming` ← grant `plan:Premium`; `sports_channels` ← grant `product:Sports Pack`.

**Discount:** `WELCOME20` — `type=percentage`, `percentage=20`, `duration=repeating`, `duration_in_intervals=3`, `eligible_plan_ids=[Premium]`.

**Customer:** Amina (`customers` row).

---

## SIM-01 — Happy path: free-trial signup → convert → renew → cancel

The baseline lifecycle. Every checkmarked step already has a home in the schema.

### Act 1 — Customer + card

- `customers{Amina}` → **event `customer.created`**
- Hosted checkout; Nomba tokenizes card → `payment_methods{customer=Amina, processor_token=…, brand=Verve, last4, is_default=true, status=active}` → **event `payment_method.added`**
- `trial_requires_payment_info=true` ⇒ card stored, **not charged**.

### Act 2 — Subscribe (free trial + add-on + discount)

- `subscriptions{customer=Amina, status=trialing, collection_mode=automatic, payment_method_id, discount_id=WELCOME20, currency=NGN, trial_ends_at=+7d, current_period_start=now, current_period_end=+7d}`
- `subscription_items`:
  - item A `{price=price_prem_m, plan=Premium, product=NaijaStream, kind=plan, quantity=1, trial_ends_at=+7d, current_phase_sequence=null}`
  - item B `{price=price_sports_m, plan=Sports Pack, product=Sports Pack, kind=addon, quantity=1, trial_ends_at=null}`
- `price_trial_redemptions{team, price=price_prem_m, customer=Amina, subscription_item=A, redeemed_at=now}` (locks `trial_once_per_customer`)
- `discount_redemptions{discount=WELCOME20, subscription, customer=Amina, applied_at=now}`
- → **event `subscription.created`**
- **Access check:** Premium → `hd_streaming`, Sports Pack → `sports_channels`. Amina streams.

**✅ Rule locked — add-on during trial (GAP-4): respect the base plan item's trial, Stripe-style.** The subscription's trial is anchored to item A (the plan). Item B (add-on, no trial of its own) does **not** bill at day 0 — it rides the subscription trial and is first invoiced at conversion (Act 3), alongside the plan. A free-trial subscription charges ₦0 on day 0 even with a paid add-on present. So during the trial, **no invoice is generated for either item**; Act 3 is the first invoice.

### Act 3 — Day 7: trial converts

- Worker `subscriptions:convert-trials`
- item A effective price becomes `price_prem_m`; `subscriptions{status: trialing→active}` → **event `subscription.updated`**
- `invoices{billing_reason=subscription_create, status=draft→open, customer_id=Amina, billed_to_customer_id=Amina, currency=NGN, period_start, period_end, customer_snapshot, billing_address}`
- `invoice_lines`:
  - `{kind=plan, price=price_prem_m, product_name_snapshot="NaijaStream", plan_name_snapshot="Premium", price_name_snapshot="Premium Monthly", quantity=1, unit_amount=500000, subtotal=500000, discount_amount=100000, total=400000}`
  - `{kind=addon, price=price_sports_m, quantity=1, unit_amount=150000, subtotal=150000, discount_amount=30000, total=120000}`
- Invoice totals: `subtotal=650000, discount_total=130000, tax_total=0, total=520000, amount_due=520000`
- `ChargeInvoice` → Nomba tokenized charge → `payments{invoice, status=succeeded, amount=520000, attempt_number=1, processor_reference}`
- `invoices{status→paid, amount_paid=520000, paid_at}` → **event `invoice.paid`**

**Assertions:** `discount_total == SUM(invoice_lines.discount_amount) == 130000`; no `kind=discount` line exists; `subscription.status == active`; `price_trial_redemptions` row present.

### Act 4 — Month-2 renewal

- Worker `subscriptions:bill-renewals`
- `invoices{billing_reason=subscription_cycle}`, same two lines, `total=520000` (WELCOME20 interval 2 of 3), payment succeeds → **event `invoice.paid`**

**⚠ Must-fix (GAP-1).** Nothing durably records that this was interval "2 of 3." The renewal worker cannot currently know when WELCOME20 is exhausted.

### Act 5 — Month-4 renewal fails → dunning → recover

- Card now declines. `payments{status=failed, failure_code, attempt_number=1}`
- `subscriptions{active→past_due}` (`markPastDue`); invoice stays `open` → **events `invoice.payment_failed`, `subscription.updated`**
- Retry worker: `payments{attempt_number=2, status=failed}`, then `payments{attempt_number=3, status=succeeded}` — **three `payments` rows, one `invoice_id`**
- `subscriptions{past_due→active}` (`recover`); `invoices{status→paid}` → **event `invoice.paid`**

**Assertions:** exactly 3 `payments` rows for the invoice; final `subscription.status == active`; invoice `amount_paid == total`.

### Act 6 — Partial refund

- NaijaStream refunds ₦2,000 → `refunds{payment_id, invoice_id, amount=200000, reason, status=succeeded}`; source `payments{status→refunded}`.

**Assertion:** refund is its own row; `refunds.amount <= payments.amount`.

### Act 7 — Cancel at period end

- `scheduled_changes{action=cancel, effective_at=current_period_end, applied_at=null}`; `subscriptions{canceled_at=now}` but `status` stays `active` → **event `subscription.updated`**
- At boundary, worker `subscriptions:apply-scheduled-changes`: `subscriptions{status→canceled, ends_at}`; `scheduled_changes{applied_at=now}` → **event `subscription.updated`**
- **Access check** now returns nothing — HD + sports revoked.

**Assertions:** dashboards read `status`, not `canceled_at`, to decide "still active"; entitlement resolution returns empty after `ends_at`.

**SIM-01 verdict:** ✅ runs end to end with no missing table. Open items surfaced: GAP-1 (discount intervals), GAP-4 (add-on-during-trial rule).

---

## SIM-02 — Mid-cycle upgrade with proration (quantity **increase** proven safe)

**Purpose:** prove that proration history does **not** require a temporal column on `subscription_items` — the invoice ledger is the durable record.

**Fixture override:** a Team subscription on `price_seat_m` (₦1,000/seat/mo), 30-day cycle, currently `quantity=10`, cycle start day 0. Change on **day 12** (18 days remaining) to `quantity=15`.

- `UpdateSubscriptionItem` writes a `billing_reason=subscription_update` invoice with two `proration=true` lines, both `period_start=day12, period_end=day30`:

| kind | description | quantity | unit_amount (prorated) | total |
|---|---|---|---|---|
| proration | Unused 10 seats | 10 | −(100000×18/30) | `−600000` |
| proration | 15 seats, remainder of period | 15 | (100000×18/30) | `+900000` |

- Net charged now: `+300000` (= 5 extra seats × 18/30). `subscription_items{quantity: 10→15}`.
- Day 30 renewal: normal cycle bills `15 × 100000 = 1500000`.

**Reconstruct "10 seats for 12 days, 15 for 18 days":** it is `(original full-period charge for 10) − (credit for 10 × 18 days) + (charge for 15 × 18 days)`. Every term is an immutable `invoice_line` with `period_start`/`period_end`. **Nothing is stored as "10 for 12 days"; it's derivable from the ledger.**

**Verdict:** ✅ quantity **increase** is ready, no schema change. The subscription item holds current state only; the invoice lines are the system of record.

---

## SIM-03 — Mid-cycle **decrease** (this is where it stalls)

**Fixture:** same Team sub, now `quantity=15`, change on **day 20** (10 days remaining) down to `quantity=10`.

- Proration lines (`period_start=day20, period_end=day30`):
  - Credit 15 unused seats: `−(15 × 100000 × 10/30) = −500000`
  - Charge 10 seats remainder: `+(10 × 100000 × 10/30) = +333333`
  - **Net: `−166667` (₦1,667 owed *to* the customer)**
- The current cycle invoice is already **paid**. This net credit has **nowhere to land** — Bouclay has no customer credit-balance table.

**Verdict (updated after GAP-2/3 resolution):** the mid-cycle-credit trace above is **what MVP deliberately avoids** — a decrease now writes a `scheduled_changes{action=update, payload:{subscription_item_id, quantity:10}, effective_at=current_period_end}` row instead; the day-30 renewal bills `10 × 100000` with no proration lines and no credit. The trace is kept as documentation of *why* the policy exists and as the test spec for the future `customer_balance_transactions` ledger if instant downgrades ship.

---

## SIM-04 — Merchant edits a live price (immutability proven)

**Fixture:** `Premium Plus` exists at ₦8,000 (`price_prem_plus_m`), Amina subscribed to it. Merchant raises it to ₦9,000.

- New row `prices{ref=price_prem_plus_m_v2, unit_amount=900000, replaces_price_id=price_prem_plus_m, version=2, status=active}`
- Old row `prices{price_prem_plus_m, status→archived}` — **never UPDATED in place**.
- Amina's `subscription_items.price_id` still points at `price_prem_plus_m` (₦8,000) → grandfathered.
- Amina's past `invoice_lines` still read ₦8,000 via the immutable row **and** `price_name_snapshot`.
- New signups pick `price_prem_plus_m_v2` (₦9,000).

**Assertions:** editing a referenced price creates a row, never mutates; superseded row is `archived`; historical invoices unchanged; `replaces_price_id` chain walkable for lineage.

**Verdict:** ✅ ready — validates the immutability + name-snapshot work.

---

## Adversarial suite

The simulations above are mostly the happy path. This suite deliberately targets where billing systems break. Status legend: ✅ ready · ⚠ partial (rule/table to add) · ❌ breaks (structural decision required). Traces to be filled in as each is implemented; predictions are recorded now so we test against them.

| # | Scenario | Prediction | Blocking gap |
|---|---|---|---|
| ADV-01 | Upgrade **during** a free trial (no money moved yet) | ✅ | Rule locked (GAP-6): apply immediately, no proration; conversion invoice reflects final composition |
| ADV-02 | **Downgrade / quantity change scheduled for next renewal** | ✅ | Resolved (GAP-2): `scheduled_changes.action = update` with item payload |
| ADV-03 | Remove an add-on mid-cycle | ✅ | Resolved by policy (GAP-3): removal takes effect at next renewal; no mid-cycle credit in MVP |
| ADV-04 | Quantity increase **and** decrease with proration | ✅ | Increase prorated now (SIM-02); decrease deferred to period end (SIM-03 / GAP-3 policy) |
| ADV-05 | **Two recurring items on different billing intervals** (monthly + annual in one sub) | ✅ (forbidden) | Locked (GAP-5): mixed cadence rejected at create/update; use multiple subscriptions |
| ADV-06 | Switch payment method while in dunning | ✅ | Update `subscription.payment_method_id`; retry worker uses new one |
| ADV-07 | Trial expires with no card, per each `trial_end_behavior` (`cancel`/`pause`/`create_invoice`) incl. late-pay → activate | ⚠ | `create_invoice → open → pay 10 days later → active` path must be wired; trickiest state path |
| ADV-08 | Apply / remove a discount mid-subscription | ⚠ | Single `discount_id` FK: no stacking, no history; re-hits GAP-1 |
| ADV-09 | Backdated subscription creation | ✅ | Timestamps store fine; catch-up invoicing is worker logic |
| ADV-10 | Same customer, two subs, **different currencies** | ✅ (strength) | `currency` is per-subscription and per-invoice; nothing forces a customer-level currency. External constraint only: can the processor charge that card in that currency |

---

## Open decisions & gaps

Ranked by how much they block implementation. IDs are referenced from the simulations above.

### GAP-1 — Repeating-discount interval tracking · **✅ RESOLVED in schema.md**

Nothing recorded how many intervals of a `duration=repeating` discount a subscription had consumed, so the renewal worker couldn't tell "2 of 3" from an expired discount.

**Fix applied:** `discount_redemptions.remaining_intervals` (nullable), snapshotted at redemption from the discount duration — `once` → `1`, `repeating` → `duration_in_intervals`, `forever` → `null`. The renewal worker applies the discount only while it's `null` or `> 0`, decrementing by 1 each cycle and stamping `last_applied_at`. Surfaced in SIM-01 Act 4, ADV-08.

### GAP-2 — Scheduled plan/quantity change · **✅ RESOLVED in schema.md**

`scheduled_changes.action` only supported `cancel`/`pause`/`resume`; a downgrade or seat change effective at next renewal had no home.

**Fix applied:** `action` enum widened with `update`; `payload` spec is `{subscription_item_id, price_id?, plan_id?, quantity?, remove?}`, one row per item change, applied by `subscriptions:apply-scheduled-changes` at `effective_at`. Pending rows are shown on the subscription detail page and deletable until applied. Surfaced in ADV-02; enables the GAP-3 resolution.

### GAP-3 — No customer credit balance · **✅ RESOLVED by policy (schema.md §6, "Mid-cycle changes & proration")**

A mid-cycle decrease or add-on removal yields a net credit with nowhere to land (current invoice already paid). SIM-03, ADV-03, ADV-04.

**Policy locked:** decreases and add-on removals take effect **at next renewal** via a `scheduled_changes` `update` row — no mid-cycle credit is ever created in MVP. Instant downgrades later = add an append-only `customer_balance_transactions` ledger (credits land there; the next invoice draws it down before charging); purely additive.

### GAP-4 — Add-on-during-trial billing rule · **✅ LOCKED (documented in schema.md §5)**

Whether a no-trial add-on added to a `trialing` subscription bills immediately or waits for conversion was unspecified. SIM-01 Act 2, ADV-07.

**Decision (locked):** respect the **base plan item's trial, Stripe-style** — the subscription trial is anchored to the plan item; add-ons without their own trial ride it and are first invoiced at conversion, not at day 0. A free-trial subscription charges ₦0 on day 0 even with a paid add-on. An add-on that itself defines a trial keeps it.

### GAP-5 — One billing cadence per subscription · **✅ LOCKED (schema.md §6 constraint)**

`current_period_start/end` are on `subscriptions`, so two items with different intervals (monthly + annual) can't share one period. ADV-05.

**Decision (locked):** option (a) — **mixed intervals in one subscription are forbidden**; `CreateSubscription`/`UpdateSubscriptionItem` validate that all recurring items share `billing_interval` + `billing_frequency`. Multi-cadence = multiple subscriptions (the `subscriptions.type` named slot supports this). Per-item periods only if per-item cadence ever becomes a hard product requirement.

### GAP-6 — Proration behavior is not data · **✅ RESOLVED by policy (schema.md §6)**

Charge-now vs. defer-to-cycle was implicit in worker code.

**Policy locked:** `proration_behavior` is an explicit **request parameter** on item-update operations (`always` / `none` / `next_cycle`), mirroring Stripe — not a column. Defaults: increases → `always` (prorate + charge now); decreases → `next_cycle` (writes the scheduled `update` row); changes while `trialing` → apply immediately with **no proration** (no money has moved; the conversion invoice reflects final composition). Surfaced in SIM-02/03, ADV-01.

### Deferred (not gaps — consciously additive, do not build now)

- **Customer-specific / manual entitlements** — grants are catalog-only (`grantor_type = plan|product`) *by design*. The grant is a Laravel polymorphic `morphTo('grantor')` relation with an enforced morph map, so adding a `customer` grantor alias later is a resolver change with **no migration**. Bouclay is a billing engine, not IAM.
- **Native tax engine** — `tax_amount`/`tax_total` are caller-supplied today (external engine or flat team rate); `tax_rates`/`tax_jurisdictions`/`invoice_tax_lines` are a future feature, additive on top of the per-line `tax_amount` that already exists, not a structural hole. Noted in schema.md §8.
- **Subscription revision history** — an append-only `subscription_item_events`/revision log for *display/audit* timelines is additive; billing history is already answered by the immutable invoice ledger (SIM-02).

---

## How to use this file

- Each `SIM-*` and `ADV-*` maps to one or more Pest feature tests. The "row writes," "events," and "Assertions" lines are the test body.
- Before implementing a phase, resolve any GAP it touches (see rankings above) and update this file with the locked decision.
- Keep amounts in minor units and assert on them exactly — money bugs hide in rounding (`18/30` prorations above are the ones to watch).
