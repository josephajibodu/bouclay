<?php

namespace App\Services\Gateways;

use App\Enums\PaymentProcessor;
use App\Models\PaymentMethod;
use App\Models\TeamProcessorConnection;
use App\Services\Gateways\Nomba\NombaGateway;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the {@see PaymentGateway} driver for a processor (IMPLEMENTATION_V2
 * §V2-4). The registry is the single place a new gateway is wired — the
 * smoothness contract: one driver class + one line here, zero migrations.
 *
 * Tokens are gateway-bound (schema.md routing rule): a stored card always
 * charges through the processor that minted it, so callers resolve by
 * `payment_methods.processor`, never the team default.
 */
class GatewayManager
{
    /**
     * @var array<string, class-string<PaymentGateway>>
     */
    private array $drivers = [
        'nomba' => NombaGateway::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * Register (or override) a driver for a processor key — used by tests to
     * bind a {@see FakeGateway}, and by future gateways.
     *
     * @param  class-string<PaymentGateway>  $driver
     */
    public function extend(string $processor, string $driver): void
    {
        $this->drivers[$processor] = $driver;
    }

    /**
     * Resolve the driver for a processor.
     */
    public function driver(PaymentProcessor|string $processor): PaymentGateway
    {
        $key = $processor instanceof PaymentProcessor ? $processor->value : $processor;

        if (! isset($this->drivers[$key])) {
            throw new InvalidArgumentException("No payment gateway driver registered for [{$key}].");
        }

        return $this->container->make($this->drivers[$key]);
    }

    /**
     * The driver for a team's processor connection.
     */
    public function forConnection(TeamProcessorConnection $connection): PaymentGateway
    {
        return $this->driver($connection->processor);
    }

    /**
     * The driver that minted a stored card's token (the gateway it must charge
     * through).
     */
    public function forPaymentMethod(PaymentMethod $paymentMethod): PaymentGateway
    {
        return $this->driver($paymentMethod->processor);
    }

    /**
     * Whether a driver is registered for the processor key.
     */
    public function has(string $processor): bool
    {
        return isset($this->drivers[$processor]);
    }

    /**
     * Every registered driver, keyed by processor — what the connect UI
     * enumerates so a newly registered gateway appears with no UI change.
     *
     * @return array<string, PaymentGateway>
     */
    public function all(): array
    {
        $drivers = [];

        foreach (array_keys($this->drivers) as $processor) {
            $drivers[$processor] = $this->driver($processor);
        }

        return $drivers;
    }
}
