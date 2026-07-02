<?php

namespace App\Exceptions\Nomba;

use RuntimeException;

class NombaConnectionException extends RuntimeException
{
    /**
     * @param  'invalid_credentials'|'unreachable'|'unknown'  $reason
     */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidCredentials(string $description): self
    {
        return new self(
            'invalid_credentials',
            "That key was rejected by Nomba: {$description}. Double-check your account ID, client ID, and client secret.",
        );
    }

    public static function unreachable(): self
    {
        return new self(
            'unreachable',
            "We couldn't reach Nomba right now. Your key wasn't saved — try again in a moment.",
        );
    }

    public static function unknown(string $description): self
    {
        return new self(
            'unknown',
            "We couldn't verify this right now ({$description}). Your key wasn't saved — try again, or contact support if this keeps happening.",
        );
    }
}
