<?php

namespace App\Http\Controllers\Customers;

use App\Enums\ApiKeyMode;
use App\Enums\PaymentMethodStatus;
use App\Exceptions\Nomba\NombaConnectionException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Team;
use App\Services\Nomba\NombaCheckout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class PaymentMethodController extends Controller
{
    public function __construct(private readonly NombaCheckout $checkout)
    {
        //
    }

    /**
     * Make a payment method the customer's default. The customer's
     * `default_payment_method_id` is canonical; `is_default` on the rows
     * mirrors it (CUSTOMERS_DESIGN §14.9).
     */
    public function makeDefault(Request $request, Customer $customer, PaymentMethod $paymentMethod): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        $this->authorizeMethod($team, $customer, $paymentMethod);

        abort_if($paymentMethod->isExpired(), 422, 'An expired card cannot be the default.');

        DB::transaction(function () use ($customer, $paymentMethod) {
            $customer->paymentMethods()->where('id', '!=', $paymentMethod->id)->update(['is_default' => false]);
            $paymentMethod->update(['is_default' => true]);
            $customer->update(['default_payment_method_id' => $paymentMethod->id]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Default payment method updated']);

        return back();
    }

    /**
     * Remove a payment method — locally, and best-effort on Nomba so the
     * token is revoked on both sides.
     */
    public function destroy(Request $request, Customer $customer, PaymentMethod $paymentMethod): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        $this->authorizeMethod($team, $customer, $paymentMethod);

        $this->revokeTokenOnNomba($team, $paymentMethod);

        DB::transaction(function () use ($customer, $paymentMethod) {
            if ($customer->default_payment_method_id === $paymentMethod->id) {
                $customer->update(['default_payment_method_id' => null]);
            }

            $paymentMethod->delete();

            // Promote another active card to default if the customer still has one.
            if ($customer->default_payment_method_id === null) {
                $next = $customer->paymentMethods()
                    ->where('status', PaymentMethodStatus::Active->value)
                    ->orderByDesc('created_at')
                    ->first();

                if ($next) {
                    $next->update(['is_default' => true]);
                    $customer->update(['default_payment_method_id' => $next->id]);
                }
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Payment method removed']);

        return back();
    }

    /**
     * Best-effort revoke of the token on Nomba. A processor failure must not
     * block local removal — we log it and move on.
     */
    private function revokeTokenOnNomba(Team $team, PaymentMethod $paymentMethod): void
    {
        $connection = $team->processorConnection;

        if ($connection === null) {
            return;
        }

        // A token is revoked in the same Nomba environment it was minted in.
        // The mode is stashed on the row's custom_data at capture time
        // (CUSTOMERS_DESIGN §10.7 — no schema change); default to test.
        $mode = ($paymentMethod->custom_data['mode'] ?? 'test') === 'live'
            ? ApiKeyMode::Live
            : ApiKeyMode::Test;

        try {
            $this->checkout->deleteTokenizedCard($connection, $mode, $paymentMethod->processor_token);
        } catch (NombaConnectionException $e) {
            Log::warning('Failed to revoke Nomba token on payment method removal', [
                'payment_method_id' => $paymentMethod->id,
                'reason' => $e->reason,
            ]);
        }
    }

    /**
     * Confirm the payment method belongs to this customer and team.
     */
    private function authorizeMethod(Team $team, Customer $customer, PaymentMethod $paymentMethod): void
    {
        abort_unless($customer->team_id === $team->id, 404);
        abort_unless($paymentMethod->customer_id === $customer->id, 404);

        Gate::authorize('manageCustomers', $team);
    }
}
