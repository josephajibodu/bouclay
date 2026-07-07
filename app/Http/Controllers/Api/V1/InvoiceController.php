<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invoicing\CreateOneOffInvoice;
use App\Http\Controllers\Api\V1Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Support\Api\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class InvoiceController extends V1Controller
{
    public function index(Request $request): JsonResponse
    {
        $context = $this->context($request);

        $query = $context->team->invoices()->with(['customer', 'lines']);

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('customerId')) {
            $customer = $this->findCustomer($context->team, (string) $request->query('customerId'));
            $query->where('customer_id', $customer->id);
        }

        $result = CursorPaginator::paginate($query, $request);

        return $this->collection(
            collect($result['items'])->map(fn (Invoice $invoice) => $invoice->toApiObject())->all(),
            $result['pagination'],
            $request,
        );
    }

    public function store(Request $request, CreateOneOffInvoice $create): JsonResponse
    {
        $context = $this->context($request);

        $data = $request->validate([
            'customer' => ['required', 'string'],
            'collectionMode' => ['required', 'in:automatic,manual'],
            'paymentMethod' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.priceId' => ['nullable', 'string'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.unitAmount' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        /** @var Customer $customer */
        $customer = $this->findCustomer($context->team, $data['customer']);

        $normalized = [
            'customer_id' => $customer->id,
            'collection_mode' => $data['collectionMode'],
            'items' => [],
        ];

        if (! empty($data['paymentMethod'])) {
            /** @var PaymentMethod $paymentMethod */
            $paymentMethod = $customer->paymentMethods()->where('public_id', $data['paymentMethod'])->firstOrFail();
            $this->assertPaymentMethodModeMatchesKey($paymentMethod, $context);
            $normalized['payment_method_id'] = $paymentMethod->id;
        }

        foreach ($data['items'] as $index => $item) {
            $line = [
                'quantity' => (int) ($item['quantity'] ?? 1),
            ];

            if (! empty($item['priceId'])) {
                /** @var Price $price */
                $price = $context->team->prices()->where('public_id', $item['priceId'])->firstOrFail();
                $line['price_id'] = $price->id;
            } else {
                $line['description'] = $item['description'] ?? 'Line item';
                $line['unit_amount'] = $item['unitAmount'] ?? 0;
            }

            $normalized['items'][] = $line;
        }

        try {
            $invoice = $create->handle($context->team, $normalized);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return $this->resource($invoice->fresh()->load(['customer', 'lines'])->toApiObject(), 201, $request);
    }

    public function show(Request $request, string $invoice): JsonResponse
    {
        $context = $this->context($request);

        $model = $this->findInvoice($context->team, $invoice);
        $model->load(['customer', 'subscription', 'lines', 'payments']);

        return $this->resource($model->toApiObject(), request: $request);
    }

    public function void(Request $request, string $invoice): JsonResponse
    {
        $context = $this->context($request);

        $model = $this->findInvoice($context->team, $invoice);

        abort_unless($model->canBeCanceled(), 422, 'This invoice can no longer be voided.');

        $model->markVoid();

        return $this->resource($model->fresh()->toApiObject(), request: $request);
    }

    public function markUncollectible(Request $request, string $invoice): JsonResponse
    {
        $context = $this->context($request);

        $model = $this->findInvoice($context->team, $invoice);

        abort_unless($model->canBeCanceled(), 422, 'This invoice can no longer be marked uncollectible.');

        $model->markUncollectible();

        return $this->resource($model->fresh()->toApiObject(), request: $request);
    }
}
