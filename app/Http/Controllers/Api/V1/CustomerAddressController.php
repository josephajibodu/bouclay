<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AddressType;
use App\Http\Controllers\Api\V1Controller;
use App\Models\Address;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerAddressController extends V1Controller
{
    public function index(Request $request, string $customer): JsonResponse
    {
        $context = $this->context($request);

        /** @var Customer $customerModel */
        $customerModel = $this->findCustomer($context->team, $customer);

        $addresses = $customerModel->addresses()->orderByDesc('created_at')->get();

        return $this->collection(
            $addresses->map(fn (Address $address) => $address->toApiObject())->all(),
            request: $request,
        );
    }

    public function store(Request $request, string $customer): JsonResponse
    {
        $context = $this->context($request);

        /** @var Customer $customerModel */
        $customerModel = $this->findCustomer($context->team, $customer);

        $data = $request->validate([
            'type' => ['required', Rule::enum(AddressType::class)],
            'name' => ['nullable', 'string', 'max:255'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'postalCode' => ['nullable', 'string', 'max:32'],
            'country' => ['required', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:50'],
            'isDefault' => ['nullable', 'boolean'],
        ]);

        $type = AddressType::from($data['type']);
        $isFirstOfType = ! $customerModel->addresses()->where('type', $type->value)->exists();
        $makeDefault = $isFirstOfType || ($data['isDefault'] ?? false);

        $address = DB::transaction(function () use ($customerModel, $context, $data, $type, $makeDefault): Address {
            if ($makeDefault) {
                $customerModel->addresses()->where('type', $type->value)->update(['is_default' => false]);
            }

            return $customerModel->addresses()->create([
                'team_id' => $context->team->id,
                'type' => $type,
                'name' => $data['name'] ?? null,
                'line1' => $data['line1'],
                'line2' => $data['line2'] ?? null,
                'city' => $data['city'] ?? null,
                'region' => $data['region'] ?? null,
                'postal_code' => $data['postalCode'] ?? null,
                'country' => $data['country'],
                'phone' => $data['phone'] ?? null,
                'is_default' => $makeDefault,
            ]);
        });

        return $this->resource($address->toApiObject(), 201, $request);
    }

    public function show(Request $request, string $customer, int $address): JsonResponse
    {
        $context = $this->context($request);

        /** @var Customer $customerModel */
        $customerModel = $this->findCustomer($context->team, $customer);

        $addressModel = $customerModel->addresses()->findOrFail($address);

        return $this->resource($addressModel->toApiObject(), request: $request);
    }

    public function update(Request $request, string $customer, int $address): JsonResponse
    {
        $context = $this->context($request);

        /** @var Customer $customerModel */
        $customerModel = $this->findCustomer($context->team, $customer);

        $addressModel = $customerModel->addresses()->findOrFail($address);

        $data = $request->validate([
            'type' => ['sometimes', Rule::enum(AddressType::class)],
            'name' => ['nullable', 'string', 'max:255'],
            'line1' => ['sometimes', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'postalCode' => ['nullable', 'string', 'max:32'],
            'country' => ['sometimes', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:50'],
            'isDefault' => ['nullable', 'boolean'],
        ]);

        $type = isset($data['type']) ? AddressType::from($data['type']) : $addressModel->type;
        $makeDefault = $addressModel->is_default || ($data['isDefault'] ?? false);

        DB::transaction(function () use ($customerModel, $addressModel, $data, $type, $makeDefault) {
            if ($makeDefault) {
                $customerModel->addresses()
                    ->where('type', $type->value)
                    ->where('id', '!=', $addressModel->id)
                    ->update(['is_default' => false]);
            }

            $addressModel->update([
                'type' => $type,
                'name' => array_key_exists('name', $data) ? $data['name'] : $addressModel->name,
                'line1' => $data['line1'] ?? $addressModel->line1,
                'line2' => array_key_exists('line2', $data) ? $data['line2'] : $addressModel->line2,
                'city' => array_key_exists('city', $data) ? $data['city'] : $addressModel->city,
                'region' => array_key_exists('region', $data) ? $data['region'] : $addressModel->region,
                'postal_code' => array_key_exists('postalCode', $data) ? $data['postalCode'] : $addressModel->postal_code,
                'country' => $data['country'] ?? $addressModel->country,
                'phone' => array_key_exists('phone', $data) ? $data['phone'] : $addressModel->phone,
                'is_default' => $makeDefault,
            ]);
        });

        return $this->resource($addressModel->fresh()->toApiObject(), request: $request);
    }
}
