<?php

namespace App\Actions\Portal;

use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PriceType;
use App\Enums\ScheduledChangeAction;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionItem;

/**
 * Shared portal data loading and serialisation for customer-facing pages.
 */
class BuildPortalContext
{
    /**
     * Resolve an active customer from their portal token.
     */
    public function resolve(string $token): Customer
    {
        $customer = Customer::query()
            ->where('portal_token', $token)
            ->with('team.processorConnection')
            ->first();

        if ($customer === null || $customer->trashed()) {
            abort(404);
        }

        return $customer->load(['team.processorConnection', 'defaultPaymentMethod']);
    }

    /**
     * Props shared across every portal page (sidebar, branding, nav).
     *
     * @return array<string, mixed>
     */
    public function shared(Customer $customer): array
    {
        $team = $customer->team;
        $defaultPaymentMethod = $customer->defaultPaymentMethod;

        return [
            'token' => $customer->portal_token,
            'business' => [
                'name' => $team->name,
                'line1' => $team->line1,
                'line2' => $team->line2,
                'city' => $team->city,
                'postalCode' => $team->postal_code,
                'country' => $team->country,
                'website' => $team->website,
            ],
            'customer' => [
                'name' => $customer->name,
                'email' => $customer->email,
                'createdAt' => $customer->created_at?->toISOString(),
            ],
            'canUpdatePaymentMethod' => $team->processorConnection !== null,
            'paymentMethod' => $defaultPaymentMethod !== null ? [
                'brand' => $defaultPaymentMethod->brand,
                'last4' => $defaultPaymentMethod->last4,
                'expMonth' => $defaultPaymentMethod->exp_month,
                'expYear' => $defaultPaymentMethod->exp_year,
                'isExpired' => $defaultPaymentMethod->isExpired(),
            ] : null,
            'returnUrl' => $this->resolveReturnUrl($customer) ?? $team->website,
        ];
    }

    /**
     * The product-level "return to site" link (same one shown on the hosted
     * invoice) for whichever product this customer most recently subscribed
     * to — falls back to the team's own website in {@see shared()}.
     */
    private function resolveReturnUrl(Customer $customer): ?string
    {
        return SubscriptionItem::query()
            ->whereHas('subscription', fn ($query) => $query->where('customer_id', $customer->id))
            ->whereHas('product', fn ($query) => $query->whereNotNull('website_url'))
            ->with('product')
            ->latest('id')
            ->first()
            ?->product
            ?->website_url;
    }

    /**
     * Eager-load relations used across portal subscription views.
     */
    public function loadSubscriptions(Customer $customer): Customer
    {
        return $customer->load([
            'subscriptions' => fn ($query) => $query
                ->with([
                    'activeItems.product',
                    'activeItems.price',
                    'activeItems.currentTrial',
                    'paymentMethod',
                    'scheduledChanges',
                ])
                ->orderByDesc('created_at'),
        ]);
    }

    /**
     * Serialise a subscription for the portal list view.
     *
     * @return array<string, mixed>
     */
    public function subscriptionListItem(Subscription $subscription): array
    {
        $primaryItem = $subscription->activeItems->first();

        return [
            'publicId' => $subscription->public_id,
            'status' => $subscription->status->value,
            'planLabel' => $subscription->planLabel(),
            'productName' => $primaryItem?->product->name ?? $subscription->planLabel(),
            'priceLabel' => $this->priceLabel($primaryItem),
            'currency' => $subscription->currency,
            'createdAt' => $subscription->created_at?->toISOString(),
            'trialEndsAt' => $subscription->trial_ends_at?->toISOString(),
            'currentPeriodEnd' => $subscription->current_period_end?->toISOString(),
            'endsAt' => $subscription->canceled_at !== null ? $subscription->ends_at?->toISOString() : null,
            'scheduledCancelAt' => $this->scheduledCancelAt($subscription),
            'canCancel' => $this->canCancelAtPeriodEnd($subscription),
        ];
    }

    /**
     * Serialise a subscription for the Paddle-style detail view.
     *
     * @return array<string, mixed>
     */
    public function subscriptionDetail(Subscription $subscription, Customer $customer): array
    {
        $subscription->loadMissing([
            'activeItems.product',
            'activeItems.price',
            'activeItems.currentTrial',
            'paymentMethod',
            'scheduledChanges',
            'invoices' => fn ($query) => $query->with('lines')->orderByDesc('created_at'),
        ]);

        $primaryItem = $subscription->activeItems->first();
        $nextOpenInvoice = $subscription->invoices
            ->first(fn (Invoice $invoice) => $invoice->status === InvoiceStatus::Open);

        $recentPayments = Payment::query()
            ->where('customer_id', $customer->id)
            ->where('status', PaymentStatus::Succeeded)
            ->whereHas('invoice', fn ($query) => $query->where('subscription_id', $subscription->id))
            ->with(['invoice.lines', 'paymentMethod'])
            ->orderByDesc('processed_at')
            ->limit(5)
            ->get();

        $summaryLines = $this->nextPaymentLines($subscription);
        $subtotal = array_sum(array_column($summaryLines, 'amount'));
        $taxTotal = $nextOpenInvoice?->tax_total ?? 0;
        $total = $nextOpenInvoice?->amount_due ?? ($subtotal + $taxTotal);

        return [
            ...$this->subscriptionListItem($subscription),
            'collectionMode' => $subscription->collection_mode->value,
            'currentPeriodStart' => $subscription->current_period_start?->toISOString(),
            'items' => $subscription->activeItems->map(fn (SubscriptionItem $item) => [
                'productName' => $item->product->name,
                'priceLabel' => $item->price->toPickerLabel(),
                'quantity' => $item->quantity,
                'unitAmount' => $item->price->unit_amount,
                'currency' => $item->price->currency,
                'billingInterval' => $item->price->billing_interval?->value,
                'billingFrequency' => $item->price->billing_frequency,
                'type' => $item->price->type->value,
            ])->all(),
            'paymentMethod' => $subscription->paymentMethod !== null ? [
                'brand' => $subscription->paymentMethod->brand,
                'last4' => $subscription->paymentMethod->last4,
            ] : null,
            'nextPayment' => [
                'amount' => $total,
                'currency' => $subscription->currency,
                'dueAt' => $nextOpenInvoice?->due_at?->toISOString()
                    ?? $subscription->current_period_end?->toISOString()
                    ?? $subscription->trial_ends_at?->toISOString(),
                'subtotal' => $subtotal,
                'taxTotal' => $taxTotal,
                'lines' => $summaryLines,
            ],
            'recentPayments' => $recentPayments->map(fn (Payment $payment) => [
                'publicId' => $payment->public_id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status->value,
                'statusLabel' => $payment->status->label(),
                'description' => $payment->invoice->lines->pluck('description')->implode(', ') ?: 'Payment',
                'processedAt' => ($payment->processed_at ?? $payment->created_at)?->toISOString(),
                'invoicePublicId' => $payment->invoice->public_id,
                'invoicePayUrl' => route('hosted.invoices.show', $payment->invoice->public_id),
            ])->all(),
        ];
    }

    /**
     * Serialise a payment for the portal payments list.
     *
     * @return array<string, mixed>
     */
    public function paymentListItem(Payment $payment): array
    {
        $payment->loadMissing(['invoice.lines', 'paymentMethod']);

        return [
            'publicId' => $payment->public_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status->value,
            'statusLabel' => $payment->status->label(),
            'description' => $payment->invoice->lines->pluck('description')->implode(', ') ?: 'Payment',
            'processedAt' => ($payment->processed_at ?? $payment->created_at)?->toISOString(),
            'invoicePublicId' => $payment->invoice->public_id,
            'invoiceNumber' => $payment->invoice->number,
            'invoicePayUrl' => route('hosted.invoices.show', $payment->invoice->public_id),
            'paymentMethodLabel' => $payment->paymentMethod !== null
                ? trim(($payment->paymentMethod->brand ?? 'Card').' ···· '.($payment->paymentMethod->last4 ?? '••••'))
                : null,
        ];
    }

    /**
     * Serialise an open invoice for the portal.
     *
     * @return array<string, mixed>
     */
    public function openInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('lines');

        return [
            'publicId' => $invoice->public_id,
            'number' => $invoice->number,
            'currency' => $invoice->currency,
            'amountDue' => $invoice->amount_due,
            'total' => $invoice->total,
            'dueAt' => $invoice->due_at?->toISOString(),
            'createdAt' => $invoice->created_at?->toISOString(),
            'productsLabel' => $invoice->lines->pluck('description')->implode(', ') ?: '—',
            'payUrl' => route('hosted.invoices.show', $invoice->public_id),
        ];
    }

    /**
     * Whether a customer can self-cancel at period end from the portal.
     */
    public function canCancelAtPeriodEnd(Subscription $subscription): bool
    {
        if (! in_array($subscription->status, [
            SubscriptionStatus::Active,
            SubscriptionStatus::Trialing,
            SubscriptionStatus::PastDue,
            SubscriptionStatus::Paused,
        ], true)) {
            return false;
        }

        return $this->scheduledCancelAt($subscription) === null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nextPaymentLines(Subscription $subscription): array
    {
        return $subscription->activeItems->map(function (SubscriptionItem $item): array {
            $unitAmount = $item->price->unit_amount ?? 0;
            $amount = $unitAmount * $item->quantity;
            $isRecurring = $item->price->type === PriceType::Recurring;

            return [
                'description' => $item->product->name,
                'detail' => $isRecurring
                    ? $this->intervalLabel($item->price->billing_interval, $item->price->billing_frequency)
                    : 'One-time',
                'quantity' => $item->quantity,
                'amount' => $amount,
                'currency' => $item->price->currency,
                'isRecurring' => $isRecurring,
            ];
        })->all();
    }

    private function scheduledCancelAt(Subscription $subscription): ?string
    {
        return $subscription->scheduledChanges
            ->first(fn ($change) => $change->action === ScheduledChangeAction::Cancel && $change->applied_at === null)
            ?->effective_at?->toISOString();
    }

    private function priceLabel(?SubscriptionItem $item): ?string
    {
        if ($item === null || $item->price->unit_amount === null) {
            return null;
        }

        $amount = number_format($item->price->unit_amount / 100, 2);
        $interval = $item->price->billing_interval;

        if ($interval === null || $item->price->type !== PriceType::Recurring) {
            return "{$item->price->currency} {$amount}";
        }

        $suffix = match ($interval) {
            BillingInterval::Month => '/month',
            BillingInterval::Year => '/year',
            BillingInterval::Week => '/week',
            BillingInterval::Day => '/day',
        };

        return "{$item->price->currency} {$amount}{$suffix}";
    }

    private function intervalLabel(?BillingInterval $interval, int $frequency): string
    {
        if ($interval === null) {
            return 'One-time';
        }

        if ($frequency === 1) {
            return match ($interval) {
                BillingInterval::Month => 'Monthly',
                BillingInterval::Year => 'Yearly',
                BillingInterval::Week => 'Weekly',
                BillingInterval::Day => 'Daily',
            };
        }

        return "Every {$frequency} {$interval->label($frequency)}";
    }
}
