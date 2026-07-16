# Bouclay — Phase 6 invoice UI handoff ✅ (completed)

> **⚠ Historical (noted 2026-07-16).** Done 2026-07-06 and kept for context. Invoicing has since gained discounts and proration (V2-3) and refunds (V2-4). Live status is [`IMPLEMENTATION_V2.md`](IMPLEMENTATION_V2.md); the data model is [`schema.md`](schema.md).

## What was built

### Invoice list + detail
- `GET /invoices` — paginated list (`resources/js/pages/invoices/index.tsx`)
- `GET /invoices/{invoice}` — detail page with operational overview, payment breakdown, charge attempts, and paper-style invoice document (`resources/js/pages/invoices/show.tsx`)
- `POST /invoices` — one-off invoice via `CreateOneOffInvoice` + `CreateInvoiceDrawer`
- `POST /invoices/{invoice}/void` and `/uncollectible`

### Snapshots at creation
- `CreateInvoice` now populates `customer_snapshot` and `billing_address` at finalise time
- Detail page reads snapshots first, falls back to live customer only if empty (legacy dev rows)

### Click-through
- Global **Invoices** list rows → detail
- Subscription hub **Upcoming invoices** rows → detail
- Customer hub **Invoices** section rows → detail

### Naming (2026-07-06 refactor)
Removed interim "Transactions" dashboard naming. Canonical vocabulary:

| Was | Now |
|---|---|
| `TransactionController`, `routes/transactions.php` | `InvoiceController`, `routes/invoices.php` |
| `CreateTransaction` | `CreateOneOffInvoice` |
| `viewTransactions` / `canViewTransactions` | `viewInvoices` / `canViewInvoices` |
| Sidebar "Transactions" | Sidebar **Invoices** only |
| Hub "Transactions" (invoice rows) | **Invoices** |
| Hub "Transactions" (charge rows) | **Payments** |
| `Payment` public ID `txn_` | `pay_` |
| `types/transactions.ts` | `types/invoices.ts` |
| `components/transactions/` | `components/invoices/` |

No `/transactions` routes — greenfield project, no legacy URLs.

## Key files

| Area | Path |
|---|---|
| Routes | `routes/invoices.php` |
| Controller | `app/Http/Controllers/Invoices/InvoiceController.php` |
| Actions | `app/Actions/Invoicing/{CreateInvoice,CreateOneOffInvoice,ChargeInvoice}.php` |
| Serializers | `Invoice::toListArray()`, `toDashboardArray()`, `toShowArray()`; `Payment::toDashboardArray()` |
| List page | `resources/js/pages/invoices/index.tsx` |
| Detail page | `resources/js/pages/invoices/show.tsx` |
| Create drawer | `resources/js/components/invoices/create-invoice-drawer.tsx` |
| Types | `resources/js/types/invoices.ts` |
| Tests | `tests/Feature/Invoices/InvoiceTest.php` |

## Still deferred (Phase 6)
- Period billing worker (renewal invoices)
- Proration lines
- Checkout link for open manual invoices
- PDF export (Download PDF action is stubbed)

## Conventions
- Integer PKs + `HasPublicId` (`inv_` invoices, `pay_` payments). Route-model binding by integer `id`.
- Run `php artisan wayfinder:generate` after route changes.
- Full detail pages use `.layout` breadcrumbs; create flows use drawers only.
- Tests: SQLite in-memory — use `LOWER(col) LIKE ?`, not `ilike`. Helpers in `tests/Pest.php`: `attachTeamOwner`, `attachTeamMember`, `fakeNombaCharge`.
