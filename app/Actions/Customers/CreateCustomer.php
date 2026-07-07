<?php

namespace App\Actions\Customers;

use App\Actions\Webhooks\EmitOutboundEvent;
use App\Enums\OutboundEventType;
use App\Models\Customer;
use App\Models\Team;

class CreateCustomer
{
    public function __construct(
        private readonly EmitOutboundEvent $emitOutboundEvent,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Team $team, array $data): Customer
    {
        $customer = $team->customers()->create([
            'email' => $data['email'],
            'name' => $data['name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'currency' => $data['currency'] ?? null,
            'external_ref' => $data['external_ref'] ?? $data['externalRef'] ?? null,
            'custom_data' => $data['custom_data'] ?? $data['customData'] ?? null,
        ]);

        $this->emitOutboundEvent->handle(
            $team,
            OutboundEventType::CustomerCreated,
            ['object' => $customer->toWebhookObject()],
        );

        return $customer;
    }
}
