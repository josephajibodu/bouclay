<?php

namespace App\Http\Controllers\Invoices;

use App\Actions\Invoicing\RefundPayment;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Process a (possibly partial) refund against a succeeded payment, through the
 * gateway that minted its token (schema.md §8, IMPLEMENTATION_V2 §V2-4). Gated
 * by `refunds.process`.
 */
class RefundController extends Controller
{
    public function store(Request $request, Invoice $invoice, Payment $payment, RefundPayment $refundPayment): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($invoice->team_id === $team->id, 404);
        abort_unless($payment->invoice_id === $invoice->id, 404);

        Gate::authorize('processRefunds', $team);

        // Amount arrives in major units; store minor.
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $amountMinor = (int) round(((float) $data['amount']) * 100);

        try {
            $refund = $refundPayment->handle($payment, $amountMinor, $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        Inertia::flash('toast', $refund->status->value === 'succeeded'
            ? ['type' => 'success', 'message' => 'Refund processed.']
            : ['type' => 'error', 'message' => 'The gateway declined the refund.']);

        return back();
    }
}
