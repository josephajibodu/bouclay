<?php

namespace App\Actions\Invoicing;

use App\Enums\AddressType;
use App\Enums\CollectionMode;
use App\Enums\DiscountType;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Team;
use App\Support\DunningConfig;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Build and persist an invoice + its line items. The shared primitive behind
 * both a one-off invoice and a subscription's billed periods — one place
 * that assigns invoice numbers and computes totals, so both callers stay in
 * sync (IMPLEMENTATION.md Phase 6).
 */
class CreateInvoice
{
    /**
     * @param  array<int, array{price?: Price|null, product?: Product|null, subscriptionItem?: SubscriptionItem|null, kind: InvoiceLineKind, description: string, unitAmount: int, quantity: int, periodStart?: CarbonInterface|null, periodEnd?: CarbonInterface|null, proration?: bool}>  $lines
     */
    public function handle(
        Team $team,
        Customer $customer,
        InvoiceBillingReason $billingReason,
        CollectionMode $collectionMode,
        array $lines,
        ?Subscription $subscription = null,
        ?Carbon $dueAt = null,
        ?Discount $discount = null,
    ): Invoice {
        return DB::transaction(function () use ($team, $customer, $billingReason, $collectionMode, $lines, $subscription, $dueAt, $discount) {
            $subtotal = array_sum(array_map(
                fn (array $line): int => $line['unitAmount'] * $line['quantity'],
                $lines,
            ));

            // Discounts are recorded per billable line (the schema.md §8
            // discount-representation invariant) — the invoice `discount_total`
            // is derived from those, never from a `kind=discount` line.
            $lineDiscounts = $this->allocateDiscount($discount, $lines);
            $discountTotal = array_sum($lineDiscounts);
            $total = $subtotal - $discountTotal;

            $invoice = Invoice::create([
                'team_id' => $team->id,
                'customer_id' => $customer->id,
                // Who actually pays — always the same customer in MVP; the
                // distinct column is the account-hierarchy seam (schema.md §8).
                'billed_to_customer_id' => $customer->id,
                'subscription_id' => $subscription?->id,
                'number' => $this->nextNumber($team),
                'type' => InvoiceType::Charge,
                'status' => InvoiceStatus::Open,
                'billing_reason' => $billingReason,
                'collection_mode' => $collectionMode,
                'currency' => $customer->currency ?? $team->default_currency,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'total' => $total,
                'amount_due' => $total,
                'customer_snapshot' => $this->snapshotCustomer($customer),
                'billing_address' => $this->snapshotBillingAddress($customer),
                'period_start' => $subscription?->current_period_start,
                'period_end' => $subscription?->current_period_end,
                'due_at' => $dueAt,
                'finalized_at' => Carbon::now(),
            ]);

            foreach ($lines as $index => $line) {
                $amount = $line['unitAmount'] * $line['quantity'];
                $lineDiscount = $lineDiscounts[$index] ?? 0;
                $price = $line['price'] ?? null;
                $product = $line['product'] ?? null;

                $invoice->lines()->create([
                    'subscription_item_id' => ($line['subscriptionItem'] ?? null)?->id,
                    'price_id' => $price?->id,
                    'product_id' => $product?->id,
                    'kind' => $line['kind'],
                    'description' => $line['description'],
                    // Names frozen at finalise (schema.md §8) — prices are
                    // immutable so amounts are safe via price_id, but catalog
                    // names are edited freely; a rename must never rewrite an
                    // issued invoice.
                    'product_name_snapshot' => $product?->name,
                    'plan_name_snapshot' => $price?->plan?->name,
                    'price_name_snapshot' => $price?->name,
                    'quantity' => $line['quantity'],
                    'unit_amount' => $line['unitAmount'],
                    'subtotal' => $amount,
                    'discount_amount' => $lineDiscount,
                    'total' => $amount - $lineDiscount,
                    'period_start' => $line['periodStart'] ?? null,
                    'period_end' => $line['periodEnd'] ?? null,
                    'proration' => $line['proration'] ?? false,
                ]);
            }

            $this->assertDiscountInvariant($invoice);

            return $invoice;
        });
    }

    /**
     * Allocate a discount across the invoice's billable lines (plan / add-on),
     * returning `[lineIndex => discountAmount]` in minor units. Percentage is
     * applied per line; a flat discount is spread pro-rata across the lines'
     * subtotals with the rounding remainder landing on the last line, and is
     * capped at the total it's discounting. Proration and non-billable lines
     * are never discounted here (schema.md §8).
     *
     * @param  array<int, array{kind: InvoiceLineKind, unitAmount: int, quantity: int}>  $lines
     * @return array<int, int>
     */
    private function allocateDiscount(?Discount $discount, array $lines): array
    {
        if ($discount === null) {
            return [];
        }

        $billable = [];
        foreach ($lines as $index => $line) {
            if (in_array($line['kind'], [InvoiceLineKind::Plan, InvoiceLineKind::Addon], true)) {
                $amount = $line['unitAmount'] * $line['quantity'];
                if ($amount > 0) {
                    $billable[$index] = $amount;
                }
            }
        }

        if ($billable === []) {
            return [];
        }

        if ($discount->type === DiscountType::Percentage) {
            return array_map(
                fn (int $amount): int => $discount->amountForLineSubtotal($amount),
                $billable,
            );
        }

        // Flat: cap at the billable total, then spread pro-rata with the
        // remainder on the final line so the per-line amounts sum exactly.
        $billableTotal = array_sum($billable);
        $flat = min((int) ($discount->amount ?? 0), $billableTotal);

        $allocation = [];
        $allocated = 0;
        $lastIndex = array_key_last($billable);

        foreach ($billable as $index => $amount) {
            if ($index === $lastIndex) {
                $allocation[$index] = $flat - $allocated;

                break;
            }

            $share = (int) round($flat * $amount / $billableTotal);
            $allocation[$index] = $share;
            $allocated += $share;
        }

        return $allocation;
    }

    /**
     * The discount-representation invariant (schema.md §8): the invoice's
     * `discount_total` must equal the sum of its billable lines' `discount_amount`.
     * Enforced in code, not just tests, so a future change can't silently break
     * accounting.
     */
    private function assertDiscountInvariant(Invoice $invoice): void
    {
        $lineSum = (int) $invoice->lines()->sum('discount_amount');

        if ($lineSum !== $invoice->discount_total) {
            throw new RuntimeException(
                "Discount invariant violated on invoice {$invoice->id}: discount_total={$invoice->discount_total} but SUM(line discount_amount)={$lineSum}."
            );
        }
    }

    /**
     * Assign the next sequential invoice number from the team's own counter
     * (schema.md `team_settings.invoice_prefix` / `next_invoice_number`),
     * locking the row so concurrent invoices never collide.
     *
     * Nothing in the app creates a team's `team_settings` row yet (a Phase 2
     * gap — the table exists but is never seeded), so this creates it on
     * demand rather than assuming it's already there. The defaults are
     * spelled out explicitly rather than left to the migration's DB-level
     * defaults, since Eloquent doesn't refresh those into the in-memory
     * model after `create()` — leaving `invoice_prefix` null in PHP even
     * though the DB row correctly got `'INV'`.
     */
    private function nextNumber(Team $team): string
    {
        $settings = $team->settings()->lockForUpdate()->first()
            ?? $team->settings()->create([
                'invoice_prefix' => 'INV',
                'next_invoice_number' => 1,
                'billing_timezone' => 'UTC',
                'tax_behavior' => 'exclusive',
                'dunning_config' => DunningConfig::defaults()->toArray(),
            ]);

        $number = $settings->invoice_prefix.'-'.$settings->next_invoice_number;

        $settings->increment('next_invoice_number');

        return $number;
    }

    /**
     * Freeze the customer's identity at issue time — never rely on the live
     * FK for a historical invoice (schema.md §7).
     *
     * @return array{name: string|null, email: string}
     */
    private function snapshotCustomer(Customer $customer): array
    {
        return [
            'name' => $customer->name,
            'email' => $customer->email,
        ];
    }

    /**
     * Freeze the customer's billing address at issue time, if one exists.
     *
     * @return array<string, mixed>|null
     */
    private function snapshotBillingAddress(Customer $customer): ?array
    {
        $address = $customer->addresses()
            ->where('type', AddressType::Billing)
            ->orderByDesc('is_default')
            ->first()
            ?? $customer->addresses()->first();

        if (! $address instanceof Address) {
            return null;
        }

        return [
            'name' => $address->name,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'region' => $address->region,
            'postalCode' => $address->postal_code,
            'country' => $address->country,
            'phone' => $address->phone,
            'singleLine' => $address->toSingleLine(),
        ];
    }
}
