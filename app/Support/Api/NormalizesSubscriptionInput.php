<?php

namespace App\Support\Api;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\Team;
use App\Models\TrialOffer;
use Illuminate\Validation\ValidationException;

trait NormalizesSubscriptionInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalizeSubscriptionInput(Team $team, array $input, ?ApiContext $context = null): array
    {
        $customerPublicId = $input['customer'] ?? null;

        if (! is_string($customerPublicId) || $customerPublicId === '') {
            throw ValidationException::withMessages(['customer' => 'Choose a customer to subscribe.']);
        }

        /** @var Customer $customer */
        $customer = $team->customers()->where('public_id', $customerPublicId)->firstOrFail();

        $normalized = [
            'customer_id' => $customer->id,
            'collection_mode' => $input['collectionMode'] ?? $input['collection_mode'] ?? 'manual',
            'trial_end_behavior' => $input['trialEndBehavior'] ?? $input['trial_end_behavior'] ?? null,
            'items' => [],
        ];

        $paymentMethodPublicId = $input['paymentMethod'] ?? $input['payment_method'] ?? null;

        if (is_string($paymentMethodPublicId) && $paymentMethodPublicId !== '') {
            /** @var PaymentMethod $paymentMethod */
            $paymentMethod = $customer->paymentMethods()->where('public_id', $paymentMethodPublicId)->firstOrFail();

            if ($context !== null) {
                $this->assertPaymentMethodModeMatchesKey($paymentMethod, $context);
            }

            $normalized['payment_method_id'] = $paymentMethod->id;
        }

        /** @var array<int, array<string, mixed>> $items */
        $items = $input['items'] ?? [];

        foreach ($items as $index => $item) {
            $pricePublicId = $item['priceId'] ?? $item['price'] ?? $item['price_id'] ?? null;

            if (is_string($pricePublicId) && $pricePublicId !== '') {
                /** @var Price $price */
                $price = $team->prices()->where('public_id', $pricePublicId)->firstOrFail();

                $normalized['items'][] = [
                    'kind' => 'price',
                    'price_id' => $price->id,
                    'quantity' => (int) ($item['quantity'] ?? 1),
                ];

                continue;
            }

            $trialPublicId = $item['trialOffer'] ?? $item['trial_offer'] ?? $item['trial_offer_id'] ?? null;

            if (is_string($trialPublicId)) {
                /** @var TrialOffer $trial */
                $trial = TrialOffer::query()
                    ->where('team_id', $team->id)
                    ->where('public_id', $trialPublicId)
                    ->firstOrFail();

                $normalized['items'][] = [
                    'kind' => 'trial',
                    'trial_offer_id' => $trial->id,
                    'quantity' => (int) ($item['quantity'] ?? 1),
                ];

                continue;
            }

            throw ValidationException::withMessages(["items.{$index}" => 'Each item must include either a priceId or trialOffer.']);
        }

        return $normalized;
    }
}
