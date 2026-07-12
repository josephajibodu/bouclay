<?php

namespace App\Actions\Subscriptions;

use App\Actions\Webhooks\EmitOutboundEvent;
use App\Enums\BillingInterval;
use App\Enums\CollectionMode;
use App\Enums\OutboundEventType;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

class CreateSubscriptionFromPaymentLinkInvoice
{
    public function __construct(private readonly EmitOutboundEvent $emitOutboundEvent)
    {
        //
    }

    public function handle(Invoice $invoice, ?PaymentMethod $paymentMethod = null): ?Subscription
    {
        $invoice->loadMissing(['customer', 'team', 'subscription', 'lines.price.product']);

        if ($invoice->subscription instanceof Subscription) {
            return $invoice->subscription;
        }

        $pending = $invoice->custom_data['pending_subscription'] ?? null;

        if (! is_array($pending)) {
            return null;
        }

        $line = $this->pendingLine($invoice, (int) ($pending['price_id'] ?? 0));

        if (! $line instanceof InvoiceLine || ! $line->price instanceof Price) {
            return null;
        }

        // subscription_items.plan_id is NOT NULL (schema.md §6) — a plan-less
        // price cannot form a subscription. Checkout guards this up front
        // (StartPaymentLinkCheckout); this is the defensive backstop.
        if ($line->price->plan_id === null) {
            return null;
        }

        $now = Carbon::now();
        $price = $line->price;
        $periodEnd = $this->addInterval(
            $now->copy(),
            $price->billing_interval ?? BillingInterval::Month,
            $price->billing_frequency,
        );

        $subscription = $invoice->team->subscriptions()->create([
            'customer_id' => $invoice->customer_id,
            'type' => 'default',
            'status' => SubscriptionStatus::Active,
            'currency' => $invoice->currency,
            'collection_mode' => CollectionMode::Automatic,
            'payment_method_id' => $paymentMethod?->id,
            'current_period_start' => $now,
            'current_period_end' => $periodEnd,
            'custom_data' => [
                'source' => 'payment_link',
                'payment_link_id' => $pending['payment_link_id'] ?? null,
            ],
        ]);

        $item = $subscription->items()->create([
            'price_id' => $price->id,
            'plan_id' => $price->plan_id,
            'product_id' => $price->product_id,
            'quantity' => max(1, (int) ($pending['quantity'] ?? $line->quantity)),
            'status' => SubscriptionItemStatus::Active,
        ]);

        $line->update(['subscription_item_id' => $item->id]);

        $customData = $invoice->custom_data ?? [];
        unset($customData['pending_subscription']);

        $invoice->forceFill([
            'subscription_id' => $subscription->id,
            'period_start' => $now,
            'period_end' => $periodEnd,
            'custom_data' => [
                ...$customData,
                'subscription_created_from_payment_link' => true,
            ],
        ])->save();

        $subscription->loadMissing('customer');

        $this->emitOutboundEvent->handle(
            $invoice->team,
            OutboundEventType::SubscriptionCreated,
            ['object' => $subscription->toWebhookObject()],
        );

        return $subscription;
    }

    private function pendingLine(Invoice $invoice, int $priceId): ?InvoiceLine
    {
        return $invoice->lines->first(
            fn (InvoiceLine $line): bool => $line->price_id === $priceId,
        );
    }

    private function addInterval(Carbon $date, BillingInterval $interval, int $count): Carbon
    {
        return match ($interval) {
            BillingInterval::Day => $date->addDays($count),
            BillingInterval::Week => $date->addWeeks($count),
            BillingInterval::Month => $date->addMonths($count),
            BillingInterval::Year => $date->addYears($count),
        };
    }
}
