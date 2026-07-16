<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\Team;

class CreateCustomer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Team $team, array $data): Customer
    {
        // customer.created is emitted by the model, so every creation path
        // announces itself — see Customer::booted().
        return $team->customers()->create([
            'email' => $data['email'],
            'name' => $data['name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'currency' => $data['currency'] ?? null,
            'external_ref' => $data['external_ref'] ?? $data['externalRef'] ?? null,
            'custom_data' => $data['custom_data'] ?? $data['customData'] ?? null,
        ]);
    }
}
