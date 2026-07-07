<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1Controller;
use App\Models\Payment;
use App\Support\Api\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends V1Controller
{
    public function index(Request $request): JsonResponse
    {
        $context = $this->context($request);

        $query = $context->team->payments()->with(['invoice', 'customer']);

        if ($request->filled('invoiceId')) {
            $invoice = $this->findInvoice($context->team, (string) $request->query('invoiceId'));
            $query->where('invoice_id', $invoice->id);
        }

        if ($request->filled('customerId')) {
            $customer = $this->findCustomer($context->team, (string) $request->query('customerId'));
            $query->where('customer_id', $customer->id);
        }

        $result = CursorPaginator::paginate($query, $request);

        return $this->collection(
            collect($result['items'])->map(fn (Payment $payment) => $payment->toApiObject())->all(),
            $result['pagination'],
            $request,
        );
    }

    public function show(Request $request, string $payment): JsonResponse
    {
        $context = $this->context($request);

        $model = $this->findPayment($context->team, $payment);
        $model->load(['invoice', 'customer', 'paymentMethod']);

        return $this->resource($model->toApiObject(), request: $request);
    }
}
