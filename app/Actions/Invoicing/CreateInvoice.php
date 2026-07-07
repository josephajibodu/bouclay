<?php

namespace App\Actions\Invoicing;

use App\Enums\AddressType;
use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceStatus;
use App\Models\Address;
use App\Models\Customer;
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
    ): Invoice {
        return DB::transaction(function () use ($team, $customer, $billingReason, $collectionMode, $lines, $subscription, $dueAt) {
            $subtotal = array_sum(array_map(
                fn (array $line): int => $line['unitAmount'] * $line['quantity'],
                $lines,
            ));

            $invoice = Invoice::create([
                'team_id' => $team->id,
                'customer_id' => $customer->id,
                'subscription_id' => $subscription?->id,
                'number' => $this->nextNumber($team),
                'status' => InvoiceStatus::Open,
                'billing_reason' => $billingReason,
                'collection_mode' => $collectionMode,
                'currency' => $customer->currency ?? $team->default_currency,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'amount_due' => $subtotal,
                'customer_snapshot' => $this->snapshotCustomer($customer),
                'billing_address' => $this->snapshotBillingAddress($customer),
                'period_start' => $subscription?->current_period_start,
                'period_end' => $subscription?->current_period_end,
                'due_at' => $dueAt,
                'finalized_at' => Carbon::now(),
            ]);

            foreach ($lines as $line) {
                $amount = $line['unitAmount'] * $line['quantity'];

                $invoice->lines()->create([
                    'subscription_item_id' => ($line['subscriptionItem'] ?? null)?->id,
                    'price_id' => ($line['price'] ?? null)?->id,
                    'product_id' => ($line['product'] ?? null)?->id,
                    'kind' => $line['kind'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_amount' => $line['unitAmount'],
                    'subtotal' => $amount,
                    'total' => $amount,
                    'period_start' => $line['periodStart'] ?? null,
                    'period_end' => $line['periodEnd'] ?? null,
                    'proration' => $line['proration'] ?? false,
                ]);
            }

            return $invoice;
        });
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
