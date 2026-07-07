<?php

namespace App\Actions\PaymentMethods;

use App\Actions\Webhooks\EmitOutboundEvent;
use App\Enums\ApiKeyMode;
use App\Enums\OutboundEventType;
use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentProcessor;
use App\Models\Customer;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

/**
 * Persist a Nomba token as a customer payment method — shared by the merchant
 * charge-customer flow and invoice hosted checkout completion.
 */
class StoreTokenizedPaymentMethod
{
    public function __construct(
        private readonly EmitOutboundEvent $emitOutboundEvent,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $card
     */
    public function handle(Customer $customer, array $card, ApiKeyMode $mode, bool $makeDefault = false): PaymentMethod
    {
        $paymentMethod = DB::transaction(function () use ($customer, $card, $mode, $makeDefault): PaymentMethod {
            $isFirstCard = ! $customer->paymentMethods()->exists();
            $shouldDefault = $makeDefault || $isFirstCard;

            $paymentMethod = $customer->paymentMethods()->updateOrCreate(
                ['processor_token' => $card['tokenKey']],
                [
                    'team_id' => $customer->team_id,
                    'processor' => PaymentProcessor::Nomba,
                    'type' => PaymentMethodType::Card,
                    'brand' => $card['brand'] ?? null,
                    'last4' => $card['last4'] ?? null,
                    'exp_month' => $this->expMonth($card),
                    'exp_year' => $this->expYear($card),
                    'status' => PaymentMethodStatus::Active,
                    'custom_data' => ['mode' => $mode->value],
                ],
            );

            if ($shouldDefault) {
                $customer->paymentMethods()->where('id', '!=', $paymentMethod->id)->update(['is_default' => false]);
                $paymentMethod->update(['is_default' => true]);
                $customer->update(['default_payment_method_id' => $paymentMethod->id]);
            }

            return $paymentMethod;
        });

        if ($paymentMethod->wasRecentlyCreated) {
            $customer->loadMissing('team');

            $this->emitOutboundEvent->handle(
                $customer->team,
                OutboundEventType::PaymentMethodAdded,
                ['object' => $paymentMethod->toWebhookObject()],
            );
        }

        return $paymentMethod;
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function expMonth(array $card): ?int
    {
        if (isset($card['tokenExpiryMonth']) && is_numeric($card['tokenExpiryMonth'])) {
            return (int) $card['tokenExpiryMonth'];
        }

        if (! empty($card['expiry']) && preg_match('/^(\d{1,2})/', (string) $card['expiry'], $match)) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function expYear(array $card): ?int
    {
        if (isset($card['tokenExpiryYear']) && is_numeric($card['tokenExpiryYear'])) {
            return $this->normalizeYear((int) $card['tokenExpiryYear']);
        }

        if (! empty($card['expiry']) && preg_match('/(\d{2,4})$/', (string) $card['expiry'], $match)) {
            return $this->normalizeYear((int) $match[1]);
        }

        return null;
    }

    private function normalizeYear(int $year): int
    {
        return $year < 100 ? 2000 + $year : $year;
    }
}
