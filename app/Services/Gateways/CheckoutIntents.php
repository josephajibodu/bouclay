<?php

namespace App\Services\Gateways;

use Illuminate\Support\Facades\Cache;

/**
 * Bouclay's own record of a hosted checkout in flight: what the customer is
 * paying for, in which mode, and whether to keep the card — stashed when the
 * checkout is created and read back when the customer returns or the gateway's
 * webhook lands (whichever wins the race).
 *
 * This is Bouclay plumbing, not a processor concept: it's keyed by the order
 * reference and holds the same shape for every driver, which is why the shared
 * settlement path can read it without knowing which gateway is settling.
 *
 * The `nomba_*` key prefix is a leftover from the single-gateway era and is
 * kept so checkouts already in flight still resolve after a deploy; the
 * contents are gateway-agnostic.
 */
final class CheckoutIntents
{
    private const string INTENT_PREFIX = 'nomba_checkout:';

    private const string TOKEN_PREFIX = 'nomba_token:';

    private const string COMPLETED_PREFIX = 'nomba_checkout_completed:';

    /**
     * Record what a newly created checkout is for.
     *
     * @param  array<string, mixed>  $intent
     */
    public static function put(string $orderReference, array $intent): void
    {
        Cache::put(self::INTENT_PREFIX.$orderReference, $intent, now()->addDays(7));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $orderReference): ?array
    {
        $intent = Cache::get(self::INTENT_PREFIX.$orderReference);

        return is_array($intent) ? $intent : null;
    }

    /**
     * Merge values into an existing intent, leaving a missing one alone.
     *
     * @param  array<string, mixed>  $values
     */
    public static function merge(string $orderReference, array $values): void
    {
        $intent = self::get($orderReference);

        if ($intent === null) {
            return;
        }

        self::put($orderReference, [...$intent, ...$values]);
    }

    /**
     * Stash the card token a webhook delivered, so the customer's return leg
     * can store the card even if the processor's token lookup lags.
     *
     * @param  array<string, mixed>  $token
     */
    public static function putToken(string $orderReference, array $token): void
    {
        Cache::put(self::TOKEN_PREFIX.$orderReference, $token, now()->addHour());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function token(string $orderReference): ?array
    {
        $token = Cache::get(self::TOKEN_PREFIX.$orderReference);

        return is_array($token) && ! empty($token['tokenKey']) ? $token : null;
    }

    /**
     * Mark an API-initiated checkout session finished, so a later poll can
     * still report the outcome once the intent itself is gone.
     *
     * @param  array<string, mixed>  $data
     */
    public static function markCompleted(string $orderReference, array $data): void
    {
        Cache::put(self::COMPLETED_PREFIX.$orderReference, $data, now()->addDays(7));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function completed(string $orderReference): ?array
    {
        $completed = Cache::get(self::COMPLETED_PREFIX.$orderReference);

        return is_array($completed) ? $completed : null;
    }

    /**
     * Drop the intent and any stashed token — the checkout is settled or dead.
     */
    public static function clear(string $orderReference): void
    {
        Cache::forget(self::INTENT_PREFIX.$orderReference);
        Cache::forget(self::TOKEN_PREFIX.$orderReference);
    }
}
