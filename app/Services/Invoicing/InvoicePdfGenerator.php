<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Render an invoice snapshot to a PDF byte string for email attachments.
 */
class InvoicePdfGenerator
{
    public function generate(Invoice $invoice): string
    {
        $invoice->loadMissing(['lines', 'team.settings']);

        $options = new Options;
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('invoices.pdf', ['invoice' => $invoice])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
