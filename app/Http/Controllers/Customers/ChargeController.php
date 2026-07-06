<?php

namespace App\Http\Controllers\Customers;

use App\Actions\PaymentMethods\StoreTokenizedPaymentMethod;
use App\Enums\ApiKeyMode;
use App\Exceptions\Nomba\NombaConnectionException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Nomba\NombaCheckout;
use App\Services\Nomba\NombaModeResolver;
use App\Services\Nomba\ResolveNombaTokenizedCard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Charge a customer via a Nomba hosted checkout. The point is
 * tokenisation-as-a-byproduct: a card is saved when the customer pays
 * (CUSTOMERS_DESIGN §10.3, §10.8). The charge runs in whichever mode the
 * team has connected — live if a live account is connected, otherwise test.
 */
class ChargeController extends Controller
{
    public function __construct(
        private readonly NombaCheckout $checkout,
        private readonly NombaModeResolver $modeResolver,
        private readonly ResolveNombaTokenizedCard $resolveTokenizedCard,
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

        if ($mode === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Connect your Nomba account to collect a card.',
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
        // callback finishes it against the same Nomba environment.
        Cache::put("nomba_checkout:{$orderReference}", [
            'customer_id' => $customer->id,
            'team_id' => $team->id,
            'mode' => $mode->value,
            'set_default' => (bool) ($validated['set_default'] ?? false),
        ], now()->addHour());

        try {
            $result = $this->checkout->createOrder($connection, $mode, [
                'amount' => number_format((float) $validated['amount'], 2, '.', ''),
                'currency' => $currency,
                'orderReference' => $orderReference,
                'customerId' => $customer->public_id,
                'customerEmail' => $customer->email,
                'callbackUrl' => route('customers.charge.callback', $customer),
                // Tokenisation only works with cards, so restrict the hosted
                // page to card payments (Nomba won't tokenise a transfer/USSD).
                'allowedPaymentMethods' => ['Card'],
            ]);
        } catch (NombaConnectionException $e) {
            Cache::forget("nomba_checkout:{$orderReference}");

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
     * Nomba redirects the customer here after payment. Verify the charge,
     * capture the token, and persist the payment method.
     */
    public function callback(Request $request, Customer $customer): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($customer->team_id === $team->id, 404);

        Gate::authorize('manageCustomers', $team);

        $orderReference = (string) $request->query('orderReference', '');
        $intent = Cache::get("nomba_checkout:{$orderReference}");

        if (! $orderReference || ! $intent || $intent['customer_id'] !== $customer->id) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'We couldn’t match that checkout. Please try again.']);

            return to_route('customers.show', $customer);
        }

        $mode = ApiKeyMode::from($intent['mode']);
        $connection = $team->processorConnection;

        try {
            $succeeded = $connection !== null
                && $this->checkout->verifyOrderSucceeded($connection, $mode, $orderReference);
        } catch (NombaConnectionException) {
            $succeeded = false;
        }

        if (! $succeeded) {
            Cache::forget("nomba_checkout:{$orderReference}");

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'That payment didn’t go through, so no card was saved. You can try again.',
            ]);

            return to_route('customers.show', $customer);
        }

        $card = $this->resolveTokenizedCard->handle($connection, $mode, $customer, $orderReference);

        if ($card === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Payment succeeded, but we couldn’t read the card token from Nomba. Please retry.',
            ]);

            return to_route('customers.show', $customer);
        }

        $this->storePaymentMethod->handle($customer, $card, $mode, (bool) $intent['set_default']);

        Cache::forget("nomba_checkout:{$orderReference}");
        Cache::forget("nomba_token:{$orderReference}");

        $label = trim(($card['brand'] ?? 'Card').' ···· '.($card['last4'] ?? ''));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Card added — {$label}"]);

        return to_route('customers.show', $customer);
    }

    private function friendlyError(NombaConnectionException $e): string
    {
        return match ($e->reason) {
            'unreachable' => 'Nomba isn’t responding right now. Please try again in a moment.',
            'invalid_credentials' => 'Your Nomba test credentials were rejected. Reconnect Nomba and try again.',
            default => 'We couldn’t start the checkout. Please try again.',
        };
    }
}
