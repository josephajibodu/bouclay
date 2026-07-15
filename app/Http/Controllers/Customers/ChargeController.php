<?php

namespace App\Http\Controllers\Customers;

use App\Actions\PaymentMethods\ResolveCheckoutToken;
use App\Actions\PaymentMethods\StoreTokenizedPaymentMethod;
use App\Enums\ApiKeyMode;
use App\Enums\PaymentProcessor;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Gateways\CheckoutIntents;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\GatewayModeResolver;
use App\Services\Gateways\GatewayOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Charge a customer via the team's gateway hosted checkout. The point is
 * tokenisation-as-a-byproduct: a card is saved when the customer pays
 * (CUSTOMERS_DESIGN §10.3, §10.8). The charge runs in whichever mode the
 * team has connected — live if a live account is connected, otherwise test.
 */
class ChargeController extends Controller
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly GatewayModeResolver $modeResolver,
        private readonly ResolveCheckoutToken $resolveCheckoutToken,
        private readonly StoreTokenizedPaymentMethod $storePaymentMethod,
    ) {
        //
    }

    /**
     * Create the checkout order and hand the hosted-page link back to the
     * dashboard so it can be copied to the customer or opened here — the
     * card saves against the customer once the link is paid.
     */
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($customer->team_id === $team->id, 404);

        Gate::authorize('manageCustomers', $team);

        $connection = $team->processorConnection;
        $mode = $this->modeResolver->forConnection($connection);

        if ($connection === null || $mode === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Connect a payment gateway to collect a card.',
            ]);

            return back();
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'set_default' => ['boolean'],
        ]);

        $currency = $customer->currency ?: $team->default_currency;
        $orderReference = (string) Str::uuid();

        // Remember what this checkout is for — including the mode — so the
        // callback finishes it against the same environment.
        CheckoutIntents::put($orderReference, [
            'customer_id' => $customer->id,
            'team_id' => $team->id,
            'mode' => $mode->value,
            'set_default' => (bool) ($validated['set_default'] ?? false),
        ]);

        try {
            $result = $this->gateways->forConnection($connection)->createCheckout($connection, $mode, new GatewayOrder(
                reference: $orderReference,
                customerEmail: $customer->email,
                amountMinor: (int) round(((float) $validated['amount']) * 100),
                currency: $currency,
                customerReference: $customer->public_id,
                callbackUrl: route('customers.charge.callback', $customer),
                // Tokenisation only works with cards — a transfer or USSD
                // payment mints no reusable token.
                cardOnly: true,
            ));
        } catch (GatewayException $e) {
            CheckoutIntents::clear($orderReference);

            Inertia::flash('toast', ['type' => 'error', 'message' => $this->friendlyError($e)]);

            return back();
        }

        Inertia::flash('checkoutLink', [
            'url' => $result['checkoutLink'],
            'customerEmail' => $customer->email,
        ]);

        return to_route('customers.show', $customer);
    }

    /**
     * The gateway redirects the customer here after payment. Verify the
     * charge, capture the token, and persist the payment method.
     */
    public function callback(Request $request, Customer $customer): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($customer->team_id === $team->id, 404);

        Gate::authorize('manageCustomers', $team);

        $orderReference = (string) $request->query('orderReference', '');
        $intent = CheckoutIntents::get($orderReference);

        if (! $orderReference || ! $intent || $intent['customer_id'] !== $customer->id) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'We couldn’t match that checkout. Please try again.']);

            return to_route('customers.show', $customer);
        }

        $mode = ApiKeyMode::from($intent['mode']);
        $connection = $team->processorConnection;

        try {
            $succeeded = $connection !== null
                && $this->gateways->forConnection($connection)
                    ->verifyCharge($connection, $mode, $orderReference);
        } catch (GatewayException) {
            $succeeded = false;
        }

        if (! $succeeded) {
            CheckoutIntents::clear($orderReference);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'That payment didn’t go through, so no card was saved. You can try again.',
            ]);

            return to_route('customers.show', $customer);
        }

        $card = $this->resolveCheckoutToken->handle($connection, $mode, $customer, $orderReference);

        if ($card === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Payment succeeded, but we couldn’t read the card token. Please retry.',
            ]);

            return to_route('customers.show', $customer);
        }

        $this->storePaymentMethod->handle(
            $customer,
            PaymentProcessor::from($connection->processor),
            $card,
            $mode,
            (bool) $intent['set_default'],
        );

        CheckoutIntents::clear($orderReference);

        $label = trim(($card['brand'] ?? 'Card').' ···· '.($card['last4'] ?? ''));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Card added — {$label}"]);

        return to_route('customers.show', $customer);
    }

    private function friendlyError(GatewayException $e): string
    {
        return match ($e->reason) {
            'unreachable' => "{$e->gateway} isn’t responding right now. Please try again in a moment.",
            'invalid_credentials' => "Your {$e->gateway} credentials were rejected. Reconnect and try again.",
            default => 'We couldn’t start the checkout. Please try again.',
        };
    }
}
