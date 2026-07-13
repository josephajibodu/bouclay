<?php

namespace App\Actions\Subscriptions;

use App\Enums\ScheduledChangeAction;
use App\Enums\SubscriptionItemStatus;
use App\Models\ScheduledChange;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Apply a queued cancel/pause/resume/update when its effective time arrives.
 * An `update` row carries a deferred item change (downgrade, quantity change,
 * add-on removal) the worker applies before the next cycle is billed
 * (schema.md §6, GAP-2).
 */
class ApplyScheduledChange
{
    public function handle(ScheduledChange $change): bool
    {
        if ($change->applied_at !== null) {
            return false;
        }

        if ($change->effective_at->isFuture()) {
            return false;
        }

        $subscription = $change->subscription()->firstOrFail();

        DB::transaction(function () use ($change, $subscription): void {
            match ($change->action) {
                ScheduledChangeAction::Cancel => $subscription->apply('cancel', $change->effective_at),
                ScheduledChangeAction::Pause => $subscription->apply(
                    'pause',
                    isset($change->payload['resumes_at'])
                        ? Carbon::parse((string) $change->payload['resumes_at'])
                        : null,
                ),
                ScheduledChangeAction::Resume => $subscription->apply('resume'),
                ScheduledChangeAction::Update => $this->applyItemUpdate($subscription, $change),
            };

            $change->update(['applied_at' => Carbon::now()]);
        });

        return true;
    }

    /**
     * Apply a deferred item change: remove the add-on, or swap its price/plan
     * and/or quantity to the payload state (schema.md §6). No proration — the
     * change lands before the next cycle invoice is computed, which bills the
     * new state directly.
     */
    private function applyItemUpdate(Subscription $subscription, ScheduledChange $change): void
    {
        $payload = $change->payload ?? [];

        $item = $subscription->items()->whereKey($payload['subscription_item_id'] ?? 0)->first();

        if ($item === null) {
            return;
        }

        if (($payload['remove'] ?? false) === true) {
            $item->forceFill(['status' => SubscriptionItemStatus::Removed])->save();

            return;
        }

        $attributes = [];

        if (isset($payload['price_id'])) {
            $price = $subscription->team->prices()->whereKey($payload['price_id'])->first();

            if ($price !== null) {
                $attributes['price_id'] = $price->id;
                $attributes['plan_id'] = $price->plan_id;
                $attributes['product_id'] = $price->product_id;
            }
        }

        if (isset($payload['quantity'])) {
            $attributes['quantity'] = (int) $payload['quantity'];
        }

        if ($attributes !== []) {
            $item->forceFill($attributes)->save();
        }
    }
}
