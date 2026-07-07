<?php

namespace App\Http\Controllers\Portal\Concerns;

use App\Models\Customer;

trait ResolvesPortalCustomer
{
    /**
     * Resolve an active customer from their portal token.
     */
    protected function resolvePortalCustomer(string $token): Customer
    {
        $customer = Customer::query()
            ->where('portal_token', $token)
            ->with('team.processorConnection')
            ->first();

        if ($customer === null || $customer->trashed()) {
            abort(404);
        }

        return $customer;
    }
}
