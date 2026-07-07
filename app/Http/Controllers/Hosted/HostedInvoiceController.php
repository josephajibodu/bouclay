<?php

namespace App\Http\Controllers\Hosted;

use App\Actions\Invoicing\CompleteHostedCheckoutPayment;
use App\Actions\Invoicing\GenerateInvoiceCheckout;
use App\Enums\CollectionMode;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

/**
 * Customer-facing hosted invoice page and checkout entry points — no auth.
 */
class HostedInvoiceController extends Controller
{
    /**
     * Show the hosted invoice the customer can view and pay.
     */
    public function show(string $publicId): Response
    {
        $invoice = $this->findInvoice($publicId);

        return Inertia::render('hosted/invoice', [
            'invoice' => $invoice->toHostedArray(),
            'paymentMessage' => session('paymentMessage'),
            'paymentSuccess' => session('paymentSuccess'),
        ]);
    }

    /**
     * Start Nomba hosted checkout for this invoice and redirect the customer.
     */
    public function pay(string $publicId, GenerateInvoiceCheckout $generateCheckout): RedirectResponse|HttpFoundationResponse
    {
        $invoice = $this->findInvoice($publicId);

        abort_unless($invoice->status === InvoiceStatus::Open, 422);

        try {
            $checkout = $generateCheckout->handle(
                team: $invoice->team,
                invoice: $invoice,
                tokenizeCard: $invoice->collection_mode === CollectionMode::Automatic,
                allowedPaymentMethods: $invoice->collection_mode === CollectionMode::Automatic ? ['Card'] : null,
                setDefaultPaymentMethod: $invoice->subscription_id !== null || is_array($invoice->custom_data['pending_subscription'] ?? null),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('paymentMessage', $exception->getMessage());
        }

        return Inertia::location($checkout['checkoutLink']);
    }

    /**
     * Nomba redirects here after payment — verify, settle the invoice, return
     * to the hosted invoice page.
     */
    public function callback(Request $request, CompleteHostedCheckoutPayment $complete): RedirectResponse
    {
        $orderReference = (string) $request->query('orderReference', '');
        $result = $complete->handle($orderReference);

        if ($result['invoice'] === null) {
            return redirect()->route('home')->with('paymentMessage', $result['message']);
        }

        return redirect()
            ->route('hosted.invoices.show', $result['invoice']->public_id)
            ->with([
                'paymentSuccess' => $result['success'],
                'paymentMessage' => $result['message'],
            ]);
    }

    private function findInvoice(string $publicId): Invoice
    {
        return Invoice::query()
            ->where('public_id', $publicId)
            ->with(['customer', 'team.settings', 'lines.product', 'lines.price'])
            ->firstOrFail();
    }
}
