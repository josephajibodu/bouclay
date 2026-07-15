<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PaymentMethods\RevokePaymentMethodToken;
use App\Enums\PaymentMethodStatus;
use App\Http\Controllers\Api\V1Controller;
use App\Models\Customer;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerPaymentMethodController extends V1Controller
{
    public function __construct(private readonly RevokePaymentMethodToken $revokeToken)
    {
        //
    }

    public function index(Request $request, string $customer): JsonResponse
    {
        $context = $this->context($request);

        /** @var Customer $customerModel */
        $customerModel = $this->findCustomer($context->team, $customer);

        $methods = $this->scopePaymentMethodsForApiContext($customerModel->paymentMethods(), $context)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return $this->collection(
            $methods->map(fn (PaymentMethod $pm) => $pm->toApiObject())->all(),
            request: $request,
        );
    }

    public function show(Request $request, string $customer, string $paymentMethod): JsonResponse
    {
        $context = $this->context($request);

        /** @var Customer $customerModel */
        $customerModel = $this->findCustomer($context->team, $customer);

        $method = $this->findPaymentMethodForApi($customerModel, $paymentMethod, $context);

        return $this->resource($method->toApiObject(), request: $request);
    }

    public function destroy(Request $request, string $customer, string $paymentMethod): JsonResponse
    {
        $context = $this->context($request);

        /** @var Customer $customerModel */
        $customerModel = $this->findCustomer($context->team, $customer);

        $method = $this->findPaymentMethodForApi($customerModel, $paymentMethod, $context);

        $this->revokeToken->handle($context->team, $method);

        DB::transaction(function () use ($customerModel, $method, $context) {
            if ($customerModel->default_payment_method_id === $method->id) {
                $customerModel->update(['default_payment_method_id' => null]);
            }

            $method->delete();

            if ($customerModel->default_payment_method_id === null) {
                $next = $this->scopePaymentMethodsForApiContext($customerModel->paymentMethods(), $context)
                    ->where('status', PaymentMethodStatus::Active->value)
                    ->orderByDesc('created_at')
                    ->first();

                if ($next) {
                    $next->update(['is_default' => true]);
                    $customerModel->update(['default_payment_method_id' => $next->id]);
                }
            }
        });

        return response()->json(null, 204);
    }
}
