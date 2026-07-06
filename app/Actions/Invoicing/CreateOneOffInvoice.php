<?php

namespace App\Actions\Invoicing;

use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceLineKind;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Product;
use App\Models\Team;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Create a one-off invoice — pick a customer, bill one or more line items
 * once, and choose how to collect (IMPLEMENTATION.md Phase 6).
 */
class CreateOneOffInvoice
{
    public function __construct(
        private readonly CreateInvoice $createInvoice,
        private readonly CollectInvoice $collectInvoice,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $data  the validated request body (customer_id,
     *                                      collection_mode, items[], payment_method_id?)
     *
     * @throws InvalidArgumentException
     */
    public function handle(Team $team, array $data): Invoice
    {
        $customer = $team->customers()->findOrFail((int) $data['customer_id']);
        $collectionMode = CollectionMode::from((string) $data['collection_mode']);
        $currency = $customer->currency ?? $team->default_currency;

        /** @var array<int, array<string, mixed>> $items */
        $items = $data['items'] ?? [];
        $lines = $this->resolveLines($team, $currency, $items);

        $invoice = $this->createInvoice->handle(
            team: $team,
            customer: $customer,
            billingReason: InvoiceBillingReason::Manual,
            collectionMode: $collectionMode,
            lines: $lines,
            dueAt: $collectionMode === CollectionMode::Manual ? Carbon::now()->addDays(7) : null,
        );

        $paymentMethod = null;

        if ($collectionMode === CollectionMode::Automatic && ! empty($data['payment_method_id'])) {
            $paymentMethod = $customer->paymentMethods()->findOrFail((int) $data['payment_method_id']);
        }

        $this->collectInvoice->handle($team, $invoice, $paymentMethod);

        return $invoice->fresh(['lines', 'payments']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{price: Price|null, product: Product|null, kind: InvoiceLineKind, description: string, unitAmount: int, quantity: int}>
     *
     * @throws InvalidArgumentException
     */
    private function resolveLines(Team $team, string $currency, array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('Add at least one line item.');
        }

        return array_map(function (array $item) use ($team, $currency): array {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            if (! empty($item['price_id'])) {
                $price = $team->prices()->with('product')->findOrFail((int) $item['price_id']);

                if ($price->currency !== $currency) {
                    throw new InvalidArgumentException("This price is in {$price->currency}; this invoice bills in {$currency}. Pick a matching price.");
                }

                if ($price->unit_amount === null) {
                    throw new InvalidArgumentException('Tiered and graduated prices aren\'t billable on a one-off invoice yet — use a standard price.');
                }

                return [
                    'price' => $price,
                    'product' => $price->product,
                    'kind' => InvoiceLineKind::OneTime,
                    'description' => $price->product->name.' · '.$price->toPickerLabel(),
                    'unitAmount' => $price->unit_amount,
                    'quantity' => $quantity,
                ];
            }

            $amount = (int) round(((float) ($item['unit_amount'] ?? 0)) * 100);

            if ($amount <= 0) {
                throw new InvalidArgumentException('Enter an amount for the custom line item.');
            }

            return [
                'price' => null,
                'product' => null,
                'kind' => InvoiceLineKind::OneTime,
                'description' => trim((string) ($item['description'] ?? '')) ?: 'Custom charge',
                'unitAmount' => $amount,
                'quantity' => $quantity,
            ];
        }, $items);
    }
}
