<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\Invoicing\InvoicePdfGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Initial action email when an invoice is finalized for manual collection or
 * automatic collection without a stored payment method.
 */
class InvoiceIssued extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public string $actionUrl,
        public string $actionLabel,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        $business = $this->invoice->team->name;

        return new Envelope(
            subject: "Invoice {$this->invoice->number} from {$business}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invoice-issued',
            with: [
                'invoice' => $this->invoice,
                'actionUrl' => $this->actionUrl,
                'actionLabel' => $this->actionLabel,
                'businessName' => $this->invoice->team->name,
                'amountDue' => $this->formatMoney($this->invoice->total, $this->invoice->currency),
                'dueDate' => $this->invoice->due_at?->toFormattedDateString(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $filename = ($this->invoice->number ?? $this->invoice->public_id).'.pdf';

        return [
            Attachment::fromData(
                fn (): string => app(InvoicePdfGenerator::class)->generate($this->invoice),
                $filename,
            )->withMime('application/pdf'),
        ];
    }

    private function formatMoney(int $amount, string $currency): string
    {
        return $currency.' '.number_format($amount / 100, 2);
    }
}
