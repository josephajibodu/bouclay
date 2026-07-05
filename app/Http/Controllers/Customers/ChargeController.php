<?php

namespace App\Http\Controllers\Customers;

use App\Enums\ApiKeyMode;
use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentProcessor;
use App\Exceptions\Nomba\NombaConnectionException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\TeamProcessorConnection;
use App\Services\Nomba\NombaCheckout;
use App\Services\Nomba\NombaModeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        $card = $this->resolveTokenizedCard($connection, $mode, $customer, $orderReference);

        if ($card === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Payment succeeded, but we couldn’t read the card token from Nomba. Please retry.',
            ]);

            return to_route('customers.show', $customer);
        }

        $this->storePaymentMethod($customer, $card, $mode, (bool) $intent['set_default']);

        Cache::forget("nomba_checkout:{$orderReference}");
        Cache::forget("nomba_token:{$orderReference}");

        $label = trim(($card['brand'] ?? 'Card').' ···· '.($card['last4'] ?? ''));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Card added — {$label}"]);

        return to_route('customers.show', $customer);
    }

    /**
     * Resolve the tokenised card: prefer the webhook-stashed payload (exact
     * order → token match), fall back to the tokenised-card list by email.
     *
     * @return array<string, mixed>|null
     */
    private function resolveTokenizedCard(?TeamProcessorConnection $connection, ApiKeyMode $mode, Customer $customer, string $orderReference): ?array
    {
        $fromWebhook = Cache::get("nomba_token:{$orderReference}");

        if (is_array($fromWebhook) && ! empty($fromWebhook['tokenKey'])) {
            return $fromWebhook;
        }

        if ($connection === null) {
            return null;
        }

        try {
            $cards = $this->checkout->listTokenizedCards($connection, $mode, $customer->email);
        } catch (NombaConnectionException) {
            return null;
        }

        $latest = collect($cards)->last();

        if (! is_array($latest) || empty($latest['tokenKey'])) {
            return null;
        }

        return [
            'tokenKey' => $latest['tokenKey'],
            'brand' => $latest['cardType'] ?? null,
            'last4' => $this->last4($latest['cardPan'] ?? null),
            'expiry' => $latest['tokenExpirationDate'] ?? null,
        ];
    }

    /**
     * Persist (or refresh) the payment method for a captured token.
     *
     * @param  array<string, mixed>  $card
     */
    private function storePaymentMethod(Customer $customer, array $card, ApiKeyMode $mode, bool $makeDefault): void
    {
        DB::transaction(function () use ($customer, $card, $mode, $makeDefault) {
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
                    // Mode isn't a schema column; stash it in custom_data so
                    // later charges/removals hit the right Nomba environment.
                    'custom_data' => ['mode' => $mode->value],
                ],
            );

            if ($shouldDefault) {
                $customer->paymentMethods()->where('id', '!=', $paymentMethod->id)->update(['is_default' => false]);
                $paymentMethod->update(['is_default' => true]);
                $customer->update(['default_payment_method_id' => $paymentMethod->id]);
            }
        });
    }

    /**
     * Pull a 2-digit expiry month from either shape Nomba returns.
     *
     * @param  array<string, mixed>  $card
     */
    private function expMonth(array $card): ?int
    {
        if (isset($card['tokenExpiryMonth']) && is_numeric($card['tokenExpiryMonth'])) {
            return (int) $card['tokenExpiryMonth'];
        }

        // tokenExpirationDate is "MM/YY" or similar; take the leading month.
        if (! empty($card['expiry']) && preg_match('/^(\d{1,2})/', (string) $card['expiry'], $m)) {
            return (int) $m[1];
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

        if (! empty($card['expiry']) && preg_match('/(\d{2,4})$/', (string) $card['expiry'], $m)) {
            return $this->normalizeYear((int) $m[1]);
        }

        return null;
    }

    private function normalizeYear(int $year): int
    {
        return $year < 100 ? 2000 + $year : $year;
    }

    private function last4(?string $pan): ?string
    {
        if ($pan === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $pan) ?? '';

        return $digits === '' ? null : substr($digits, -4);
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
