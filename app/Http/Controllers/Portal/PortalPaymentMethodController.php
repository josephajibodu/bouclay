<?php

namespace App\Http\Controllers\Portal;

use App\Actions\PaymentMethods\StoreTokenizedPaymentMethod;
use App\Enums\ApiKeyMode;
use App\Enums\CollectionMode;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Nomba\NombaConnectionException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesPortalCustomer;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Services\Nomba\NombaCheckout;
use App\Services\Nomba\NombaModeResolver;
use App\Services\Nomba\ResolveNombaTokenizedCard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

/**
 * Customer portal card update via Nomba hosted checkout + tokenisation.
 */
class PortalPaymentMethodController extends Controller
{
    use ResolvesPortalCustomer;

    /**
     * Nomba requires a charge to tokenise — a small verification amount.
     */
    private const string VERIFICATION_AMOUNT = '100.00';

    public function __construct(
        private readonly NombaCheckout $checkout,
        private readonly NombaModeResolver $modeResolver,
        private readonly ResolveNombaTokenizedCard $resolveTokenizedCard,
        private readonly StoreTokenizedPaymentMethod $storePaymentMethod,
    ) {
        //
    }

    /**
     * Start Nomba hosted checkout to collect and tokenise a new card.
     */
    public function store(string $token): RedirectResponse|HttpFoundationResponse
    {
        $customer = $this->resolvePortalCustomer($token);
        $team = $customer->team;
        $connection = $team->processorConnection;
        $mode = $this->modeResolver->forConnection($connection);

        if ($mode === null) {
            return redirect()
                ->route('portal.show', $customer->portal_token)
                ->with('toast', [
                    'type' => 'error',
                    'message' => 'Online card updates are not available right now. Please contact support.',
                ]);
        }

        $currency = $customer->currency ?: $team->default_currency;
        $orderReference = (string) Str::uuid();

        Cache::put("nomba_checkout:{$orderReference}", [
            'customer_id' => $customer->id,
            'team_id' => $team->id,
            'mode' => $mode->value,
            'set_default' => true,
            'attach_subscriptions' => true,
            'portal_token' => $customer->portal_token,
        ], now()->addHour());

        try {
            $result = $this->checkout->createOrder($connection, $mode, [
                'amount' => self::VERIFICATION_AMOUNT,
                'currency' => $currency,
                'orderReference' => $orderReference,
                'customerId' => $customer->public_id,
                'customerEmail' => $customer->email,
                'callbackUrl' => route('portal.payment-method.callback', $customer->portal_token),
                'allowedPaymentMethods' => ['Card'],
            ], tokenizeCard: true);
        } catch (NombaConnectionException $e) {
            Cache::forget("nomba_checkout:{$orderReference}");

            return redirect()
                ->route('portal.show', $customer->portal_token)
                ->with('toast', [
                    'type' => 'error',
                    'message' => $this->friendlyError($e),
                ]);
        }

        return Inertia::location($result['checkoutLink']);
    }

    /**
     * Nomba redirects here after payment — verify, tokenise, attach to subscriptions.
     */
    public function callback(Request $request, string $token): RedirectResponse
    {
        $customer = $this->resolvePortalCustomer($token);
        $team = $customer->team;
        $orderReference = (string) $request->query('orderReference', '');
        $intent = Cache::get("nomba_checkout:{$orderReference}");

        if ($orderReference === '' || ! is_array($intent) || ($intent['customer_id'] ?? null) !== $customer->id) {
            return redirect()
                ->route('portal.show', $customer->portal_token)
                ->with('toast', [
                    'type' => 'error',
                    'message' => 'We couldn’t match that checkout. Please try again.',
                ]);
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

            return redirect()
                ->route('portal.show', $customer->portal_token)
                ->with('toast', [
                    'type' => 'error',
                    'message' => 'That payment didn’t go through, so your card wasn’t updated. Please try again.',
                ]);
        }

        $card = $this->resolveTokenizedCard->handle($connection, $mode, $customer, $orderReference);

        if ($card === null) {
            return redirect()
                ->route('portal.show', $customer->portal_token)
                ->with('toast', [
                    'type' => 'error',
                    'message' => 'Payment succeeded, but we couldn’t read the card token. Please try again.',
                ]);
        }

        $paymentMethod = $this->storePaymentMethod->handle(
            $customer,
            $card,
            $mode,
            (bool) ($intent['set_default'] ?? true),
        );

        if ($intent['attach_subscriptions'] ?? false) {
            $this->attachToAutomaticSubscriptions($customer, $paymentMethod);
        }

        Cache::forget("nomba_checkout:{$orderReference}");
        Cache::forget("nomba_token:{$orderReference}");

        $label = trim(($card['brand'] ?? 'Card').' ···· '.($card['last4'] ?? ''));

        return redirect()
            ->route('portal.payment-methods.index', $customer->portal_token)
            ->with('toast', [
                'type' => 'success',
                'message' => "Payment method updated — {$label}",
            ]);
    }

    /**
     * Attach the new card to automatic subscriptions that bill on a schedule.
     */
    private function attachToAutomaticSubscriptions(Customer $customer, PaymentMethod $paymentMethod): void
    {
        $customer->subscriptions()
            ->where('collection_mode', CollectionMode::Automatic)
            ->whereIn('status', [
                SubscriptionStatus::Incomplete,
                SubscriptionStatus::Trialing,
                SubscriptionStatus::Active,
                SubscriptionStatus::PastDue,
                SubscriptionStatus::Paused,
            ])
            ->update(['payment_method_id' => $paymentMethod->id]);
    }

    private function friendlyError(NombaConnectionException $e): string
    {
        return match ($e->reason) {
            'unreachable' => 'Nomba isn’t responding right now. Please try again in a moment.',
            'invalid_credentials' => 'Card updates are temporarily unavailable. Please contact support.',
            default => 'We couldn’t start the checkout. Please try again.',
        };
    }
}
