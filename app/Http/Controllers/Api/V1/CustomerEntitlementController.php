<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Entitlements\ResolveCustomerEntitlements;
use App\Http\Controllers\Api\V1Controller;
use App\Models\Entitlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a customer can access (IMPLEMENTATION_V2 §V2-5).
 *
 * The whole point of the entitlements layer: an integrator gates a feature on
 * this endpoint and never has to reach into invoices, payments, or dunning
 * state to work out whether someone should be let in.
 */
class CustomerEntitlementController extends V1Controller
{
    public function __construct(private readonly ResolveCustomerEntitlements $resolve)
    {
        //
    }

    public function index(Request $request, string $customer): JsonResponse
    {
        $context = $this->context($request);

        $customerModel = $this->findCustomer($context->team, $customer);

        return $this->collection(
            array_values($this->resolve->handle($customerModel)
                ->map(fn (Entitlement $entitlement) => $entitlement->toApiObject())
                ->all()),
            request: $request,
        );
    }

    /**
     * Check one entitlement by code — the cheap question ("can this customer
     * do X right now?") answered without the caller filtering a list.
     */
    public function show(Request $request, string $customer, string $code): JsonResponse
    {
        $context = $this->context($request);

        $customerModel = $this->findCustomer($context->team, $customer);
        $entitlement = $this->resolve->handle($customerModel)->get($code);

        // A code the customer doesn't hold is a 200 with granted:false, not a
        // 404: "no" is a legitimate answer to an access check, and a 404 would
        // be indistinguishable from an unknown customer.
        return $this->resource([
            'code' => $code,
            'granted' => $entitlement !== null,
            'entitlement' => $entitlement?->toApiObject(),
        ], request: $request);
    }
}
