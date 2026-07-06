<?php

namespace App\Actions\Invoicing;

use App\Enums\CollectionMode;
use App\Enums\PaymentStatus;
use App\Exceptions\Nomba\NombaConnectionException;
use App\Mail\InvoiceIssued;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Collection engine — runs when an invoice is finalized and decides how to
 * collect based on collection_mode and available payment methods.
 */
class CollectInvoice
{
    public function __construct(
        private readonly ChargeInvoice $chargeInvoice,
        private readonly GenerateInvoiceCheckout $generateCheckout,
        private readonly SettleSubscriptionOnInvoicePayment $settleSubscription,
    ) {
        //
    }

    public function handle(Team $team, Invoice $invoice, ?PaymentMethod $paymentMethod = null): void
    {
        $invoice->loadMissing(['customer', 'subscription', 'team']);

        if ($invoice->collection_mode === CollectionMode::Automatic) {
            $this->collectAutomatic($team, $invoice, $paymentMethod);

            return;
        }

        $this->collectManual($invoice);
    }

    private function collectAutomatic(Team $team, Invoice $invoice, ?PaymentMethod $paymentMethod): void
    {
        if ($paymentMethod !== null) {
            $payment = $this->chargeInvoice->handle($team, $invoice, $paymentMethod);

            if ($payment->status === PaymentStatus::Succeeded) {
                $this->settleSubscription->onPaymentSucceeded($invoice);
            } else {
                $this->settleSubscription->onAutomaticChargeFailed($invoice);
            }

            return;
        }

        try {
            $checkout = $this->generateCheckout->handle(
                team: $team,
                invoice: $invoice,
                tokenizeCard: true,
                allowedPaymentMethods: ['Card'],
                setDefaultPaymentMethod: true,
            );
        } catch (InvalidArgumentException|NombaConnectionException $exception) {
            Log::warning('Invoice checkout generation failed', [
                'invoice_id' => $invoice->id,
                'team_id' => $team->id,
                'message' => $exception->getMessage(),
            ]);

            $invoice->forceFill([
                'custom_data' => array_merge($invoice->custom_data ?? [], [
                    'collection_error' => $exception->getMessage(),
                ]),
            ])->save();

            return;
        }

        $this->queueInitialEmail(
            invoice: $invoice,
            actionUrl: $checkout['checkoutLink'],
            actionLabel: 'Pay now',
        );
    }

    private function collectManual(Invoice $invoice): void
    {
        $hostedUrl = route('hosted.invoices.show', $invoice->public_id);

        $this->queueInitialEmail(
            invoice: $invoice,
            actionUrl: $hostedUrl,
            actionLabel: 'View and pay invoice',
        );
    }

    private function queueInitialEmail(Invoice $invoice, string $actionUrl, string $actionLabel): void
    {
        $email = $invoice->customer_snapshot['email'] ?? $invoice->customer->email;

        Mail::to($email)->queue(new InvoiceIssued($invoice, $actionUrl, $actionLabel));
    }
}
