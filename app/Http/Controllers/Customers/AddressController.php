<?php

namespace App\Http\Controllers\Customers;

use App\Enums\AddressType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreAddressRequest;
use App\Http\Requests\Customers\UpdateAddressRequest;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class AddressController extends Controller
{
    /**
     * Add an address to a customer's address book.
     */
    public function store(StoreAddressRequest $request, Customer $customer): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($customer->team_id === $team->id, 404);

        Gate::authorize('manageCustomers', $team);

        $type = AddressType::from($request->validated('type'));

        // The first address of a type is always the default for that type;
        // otherwise honour the checkbox.
        $isFirstOfType = ! $customer->addresses()->where('type', $type->value)->exists();
        $makeDefault = $isFirstOfType || $request->boolean('is_default');

        DB::transaction(function () use ($customer, $team, $request, $type, $makeDefault) {
            if ($makeDefault) {
                $this->clearDefaultFor($customer, $type);
            }

            $customer->addresses()->create([
                'team_id' => $team->id,
                'type' => $type,
                'name' => $request->validated('name'),
                'line1' => $request->validated('line1'),
                'line2' => $request->validated('line2'),
                'city' => $request->validated('city'),
                'region' => $request->validated('region'),
                'postal_code' => $request->validated('postal_code'),
                'country' => $request->validated('country'),
                'phone' => $request->validated('phone'),
                'is_default' => $makeDefault,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Address saved']);

        return back();
    }

    /**
     * Update an existing address.
     */
    public function update(UpdateAddressRequest $request, Customer $customer, Address $address): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        $this->authorizeAddress($team, $customer, $address);

        $type = AddressType::from($request->validated('type'));
        $makeDefault = $address->is_default || $request->boolean('is_default');

        DB::transaction(function () use ($customer, $request, $address, $type, $makeDefault) {
            if ($makeDefault) {
                $this->clearDefaultFor($customer, $type, exceptId: $address->id);
            }

            $address->update([
                'type' => $type,
                'name' => $request->validated('name'),
                'line1' => $request->validated('line1'),
                'line2' => $request->validated('line2'),
                'city' => $request->validated('city'),
                'region' => $request->validated('region'),
                'postal_code' => $request->validated('postal_code'),
                'country' => $request->validated('country'),
                'phone' => $request->validated('phone'),
                'is_default' => $makeDefault,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Address updated']);

        return back();
    }

    /**
     * Make an address the default for its type.
     */
    public function makeDefault(Request $request, Customer $customer, Address $address): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        $this->authorizeAddress($team, $customer, $address);

        DB::transaction(function () use ($customer, $address) {
            $this->clearDefaultFor($customer, $address->type, exceptId: $address->id);
            $address->update(['is_default' => true]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Default address updated']);

        return back();
    }

    /**
     * Remove an address.
     */
    public function destroy(Request $request, Customer $customer, Address $address): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        $this->authorizeAddress($team, $customer, $address);

        $address->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Address removed']);

        return back();
    }

    /**
     * Confirm the address belongs to this customer and team, and the user
     * may manage it.
     */
    private function authorizeAddress(Team $team, Customer $customer, Address $address): void
    {
        abort_unless($customer->team_id === $team->id, 404);
        abort_unless($address->customer_id === $customer->id, 404);

        Gate::authorize('manageCustomers', $team);
    }

    /**
     * Clear the default flag on the customer's other addresses of a type.
     */
    private function clearDefaultFor(Customer $customer, AddressType $type, ?int $exceptId = null): void
    {
        $customer->addresses()
            ->where('type', $type->value)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }
}
