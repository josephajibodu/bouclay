<?php

namespace App\Actions\Entitlements;

use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * What a customer can access right now (IMPLEMENTATION_V2 §V2-5).
 *
 * The union of every entitlement granted by the plans and products on their
 * access-granting subscriptions. Resolved purely from the catalog, never from
 * invoice or payment state — that decoupling is the point of the whole layer
 * (schema.md §4), and it's why an unpaid invoice doesn't revoke access on its
 * own. Dunning revokes access by moving the subscription's status, which is a
 * decision the billing engine makes explicitly.
 *
 * Two subscriptions granting the same entitlement is normal (a plan and an
 * add-on can overlap); the answer is a set, so it dedupes.
 */
class ResolveCustomerEntitlements
{
    /**
     * The entitlements this customer holds, keyed by code.
     *
     * @return Collection<string, Entitlement>
     */
    public function handle(Customer $customer): Collection
    {
        $grantors = $this->grantorKeys($customer);

        if ($grantors === []) {
            return collect();
        }

        return Entitlement::query()
            ->where('team_id', $customer->team_id)
            ->whereHas('grants', fn ($query) => $this->matchingGrantors($query, $grantors))
            ->orderBy('code')
            ->get()
            ->keyBy('code');
    }

    /**
     * Just the codes — what an integrator gates on, and what rides along in
     * `subscription.*` event payloads so they can gate on webhooks alone.
     *
     * @return list<string>
     */
    public function codes(Customer $customer): array
    {
        return array_values($this->handle($customer)->keys()->all());
    }

    /**
     * The entitlements a single subscription grants, ignoring the customer's
     * other subscriptions — what an event payload describes.
     *
     * @return list<string>
     */
    public function codesForSubscription(Subscription $subscription): array
    {
        $grantors = $this->grantorKeysForItems($subscription->items);

        if ($grantors === []) {
            return [];
        }

        return array_values(Entitlement::query()
            ->where('team_id', $subscription->team_id)
            ->whereHas('grants', fn ($query) => $this->matchingGrantors($query, $grantors))
            ->orderBy('code')
            ->pluck('code')
            ->all());
    }

    /**
     * Narrow an `entitlement_grants` query to any of these (morph alias, id)
     * pairs — the shared predicate behind both public resolvers.
     *
     * @param  list<array{0: string, 1: int}>  $grantors
     */
    private function matchingGrantors(Builder $query, array $grantors): void
    {
        $query->where(function ($grants) use ($grantors) {
            foreach ($grantors as [$type, $id]) {
                $grants->orWhere(fn ($match) => $match
                    ->where('grantor_type', $type)
                    ->where('grantor_id', $id));
            }
        });
    }

    /**
     * The (morph alias, id) pairs granting access to this customer.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function grantorKeys(Customer $customer): array
    {
        $subscriptions = $customer->subscriptions()
            ->whereIn('status', SubscriptionStatus::grantingAccess())
            // The grace window: `ends_at` is when a cancelled or lapsed
            // subscription's access actually stops, which is why status alone
            // is not the answer (schema.md §6).
            ->where(fn ($query) => $query
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>', now()))
            ->with('items')
            ->get();

        return $this->grantorKeysForItems($subscriptions->flatMap(fn (Subscription $s) => $s->items));
    }

    /**
     * @param  Collection<int, SubscriptionItem>|EloquentCollection<int, SubscriptionItem>  $items
     * @return list<array{0: string, 1: int}>
     */
    private function grantorKeysForItems(Collection|EloquentCollection $items): array
    {
        $keys = [];

        foreach ($items as $item) {
            // A removed item is history, not access.
            if ($item->status !== SubscriptionItemStatus::Active) {
                continue;
            }

            // plan_id/product_id are denormalised onto the item and snapshotted
            // at creation, so this resolves what the customer actually bought.
            $keys[] = ['plan', $item->plan_id];
            $keys[] = ['product', $item->product_id];
        }

        return array_values(array_unique($keys, SORT_REGULAR));
    }
}
