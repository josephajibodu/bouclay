<?php

namespace App\Http\Controllers\Customers;

use App\Actions\PaymentMethods\RevokePaymentMethodToken;
use App\Enums\PaymentMethodStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class PaymentMethodController extends Controller
{
    public function __construct(private readonly RevokePaymentMethodToken $revokeToken)
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
     * Remove a payment method — locally, and best-effort on the gateway that
     * minted it so the token is revoked on both sides.
     */
    public function destroy(Request $request, Customer $customer, PaymentMethod $paymentMethod): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        $this->authorizeMethod($team, $customer, $paymentMethod);

        $this->revokeToken->handle($team, $paymentMethod);

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
     * Confirm the payment method belongs to this customer and team.
     */
    private function authorizeMethod(Team $team, Customer $customer, PaymentMethod $paymentMethod): void
    {
        abort_unless($customer->team_id === $team->id, 404);
        abort_unless($paymentMethod->customer_id === $customer->id, 404);

        Gate::authorize('manageCustomers', $team);
    }
}
