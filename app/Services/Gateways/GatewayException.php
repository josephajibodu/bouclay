<?php

namespace App\Services\Gateways;

use RuntimeException;

/**
 * The one failure type a driver throws when a processor rejects, refuses, or
 * can't be reached (IMPLEMENTATION_V2 §V2-4). Call sites catch this — never a
 * gateway-specific exception — so adding a driver needs no new catch blocks.
 *
 * `reason` is the machine-readable classification every driver maps its own
 * error codes into; `gateway` is the human label used in copy.
 */
class GatewayException extends RuntimeException
{
    /**
     * @param  'invalid_credentials'|'unreachable'|'unknown'  $reason
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $gateway,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalidCredentials(string $gateway, string $description): self
    {
        return new self(
            'invalid_credentials',
            $gateway,
            "That key was rejected by {$gateway}: {$description}. Double-check the credentials for this mode.",
        );
    }

    public static function unreachable(string $gateway): self
    {
        return new self(
            'unreachable',
            $gateway,
            "We couldn't reach {$gateway} right now. Nothing was saved — try again in a moment.",
        );
    }

    public static function unknown(string $gateway, string $description): self
    {
        return new self(
            'unknown',
            $gateway,
            "We couldn't verify this right now ({$description}). Nothing was saved — try again, or contact support if this keeps happening.",
        );
    }

    /**
     * Short, customer-safe copy for a failed money movement — the message
     * shown when a charge or refund couldn't be attempted at all.
     */
    public function friendlyMessage(): string
    {
        return match ($this->reason) {
            'unreachable' => "{$this->gateway} isn’t responding right now.",
            'invalid_credentials' => "{$this->gateway} credentials were rejected.",
            default => 'The request could not be completed.',
        };
    }
}
