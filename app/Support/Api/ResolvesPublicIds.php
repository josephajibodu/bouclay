<?php

namespace App\Support\Api;

use App\Enums\ApiKeyMode;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ResolvesPublicIds
{
    protected function apiContext(Request $request): ApiContext
    {
        /** @var ApiContext $context */
        $context = $request->attributes->get('api_context');

        return $context;
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $modelClass
     * @return T
     */
    protected function findByPublicId(Team $team, string $modelClass, string $publicId, bool $withTrashed = false): Model
    {
        $query = $modelClass::query()->where('team_id', $team->id)->where('public_id', $publicId);

        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        return $query->firstOrFail();
    }

    protected function findCustomer(Team $team, string $publicId, bool $withTrashed = false): Customer
    {
        /** @var Customer */
        return $this->findByPublicId($team, Customer::class, $publicId, $withTrashed);
    }

    protected function findProduct(Team $team, string $publicId): Product
    {
        /** @var Product */
        return $this->findByPublicId($team, Product::class, $publicId);
    }

    protected function findPlan(Team $team, string $publicId): Plan
    {
        /** @var Plan */
        return $this->findByPublicId($team, Plan::class, $publicId);
    }

    protected function findPrice(Team $team, string $publicId): Price
    {
        /** @var Price */
        return $this->findByPublicId($team, Price::class, $publicId);
    }

    protected function findSubscription(Team $team, string $publicId): Subscription
    {
        /** @var Subscription */
        return $this->findByPublicId($team, Subscription::class, $publicId);
    }

    protected function findSubscriptionItem(Subscription $subscription, string $publicId): SubscriptionItem
    {
        return $subscription->items()->where('public_id', $publicId)->firstOrFail();
    }

    protected function findDiscount(Team $team, string $publicId): Discount
    {
        /** @var Discount */
        return $this->findByPublicId($team, Discount::class, $publicId);
    }

    protected function findInvoice(Team $team, string $publicId): Invoice
    {
        /** @var Invoice */
        return $this->findByPublicId($team, Invoice::class, $publicId);
    }

    protected function findPayment(Team $team, string $publicId): Payment
    {
        /** @var Payment */
        return $this->findByPublicId($team, Payment::class, $publicId);
    }

    protected function findPaymentMethod(Customer $customer, string $publicId): PaymentMethod
    {
        return $customer->paymentMethods()->where('public_id', $publicId)->firstOrFail();
    }

    protected function findPaymentMethodForApi(Customer $customer, string $publicId, ApiContext $context): PaymentMethod
    {
        return $this->scopePaymentMethodsForApiContext($customer->paymentMethods(), $context)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /**
     * @param  Builder<PaymentMethod>|Relation<PaymentMethod, Customer, *>  $query
     * @return Builder<PaymentMethod>|Relation<PaymentMethod, Customer, *>
     */
    protected function scopePaymentMethodsForApiContext(Builder|\Illuminate\Database\Eloquent\Relations\Relation $query, ApiContext $context): Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return $query->where(function (Builder $query) use ($context): void {
            $query->where('custom_data->mode', $context->mode->value);

            if ($context->mode === ApiKeyMode::Live) {
                $query->orWhereNull('custom_data->mode');
            }
        });
    }

    protected function resolvePaymentMethodMode(PaymentMethod $paymentMethod): ApiKeyMode
    {
        $storedMode = $paymentMethod->custom_data['mode'] ?? ApiKeyMode::Live->value;

        return $storedMode === ApiKeyMode::Live->value
            ? ApiKeyMode::Live
            : ApiKeyMode::Test;
    }

    /**
     * @throws ValidationException
     */
    protected function assertPaymentMethodModeMatchesKey(PaymentMethod $paymentMethod, ApiContext $context): void
    {
        $storedMode = $paymentMethod->custom_data['mode'] ?? ApiKeyMode::Live->value;

        if ((string) $storedMode !== $context->mode->value) {
            throw ValidationException::withMessages([
                'paymentMethod' => 'The payment method was tokenized in a different mode than this API key.',
            ]);
        }
    }
}
