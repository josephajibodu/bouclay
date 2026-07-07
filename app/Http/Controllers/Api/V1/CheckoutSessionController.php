<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invoicing\CreatePaymentMethodCheckoutSession;
use App\Exceptions\Nomba\NombaConnectionException;
use App\Http\Controllers\Api\V1Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Hosted checkout for payment-method tokenisation via the public API.
 */
class CheckoutSessionController extends V1Controller
{
    public function __construct(private readonly CreatePaymentMethodCheckoutSession $createCheckoutSession)
    {
        //
    }

    public function store(Request $request): JsonResponse
    {
        $context = $this->context($request);

        $data = $request->validate([
            'customer' => ['required', 'string'],
            'setDefault' => ['nullable', 'boolean'],
        ]);

        /** @var Customer $customer */
        $customer = $this->findCustomer($context->team, $data['customer']);

        $connection = $context->team->processorConnection;

        if ($connection === null || ! $connection->isConnected($context->mode)) {
            throw ValidationException::withMessages([
                'customer' => 'Connect Nomba in '.$context->mode->value.' mode before creating a checkout session.',
            ]);
        }

        try {
            $checkout = $this->createCheckoutSession->handle(
                team: $context->team,
                customer: $customer,
                mode: $context->mode,
                setDefault: $data['setDefault'] ?? true,
            );
        } catch (InvalidArgumentException|NombaConnectionException $e) {
            throw ValidationException::withMessages([
                'customer' => $e instanceof NombaConnectionException
                    ? 'Unable to create checkout session: '.$e->reason
                    : $e->getMessage(),
            ]);
        }

        return $this->resource([
            'id' => $checkout['orderReference'],
            'status' => 'open',
            'checkoutUrl' => $checkout['checkoutUrl'],
            'customerId' => $customer->public_id,
            'mode' => $context->mode->value,
        ], 201, $request);
    }

    public function show(Request $request, string $checkoutSession): JsonResponse
    {
        $context = $this->context($request);

        /** @var array<string, mixed>|null $completed */
        $completed = Cache::get("nomba_checkout_completed:{$checkoutSession}");

        if (is_array($completed) && ($completed['team_id'] ?? null) === $context->team->id) {
            return $this->resource([
                'id' => $checkoutSession,
                'status' => 'complete',
                'customerId' => Customer::query()->find($completed['customer_id'])?->public_id,
                'mode' => $completed['mode'] ?? null,
            ], request: $request);
        }

        /** @var array<string, mixed>|null $payload */
        $payload = Cache::get("nomba_checkout:{$checkoutSession}");

        if (! is_array($payload) || ($payload['team_id'] ?? null) !== $context->team->id) {
            abort(404);
        }

        return $this->resource([
            'id' => $checkoutSession,
            'status' => 'open',
            'customerId' => Customer::query()->find($payload['customer_id'])?->public_id,
            'mode' => $payload['mode'] ?? null,
        ], request: $request);
    }
}
