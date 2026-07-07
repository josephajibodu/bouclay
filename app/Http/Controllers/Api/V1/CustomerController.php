<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Customers\CreateCustomer;
use App\Http\Controllers\Api\V1Controller;
use App\Models\Customer;
use App\Support\Api\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerController extends V1Controller
{
    public function index(Request $request): JsonResponse
    {
        $context = $this->context($request);
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status') === 'archived' ? 'archived' : 'active';

        $query = $context->team->customers();

        if ($status === 'archived') {
            $query->onlyTrashed();
        }

        if ($search !== '') {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(function ($query) use ($term) {
                $query->whereRaw('lower(name) like ?', [$term])
                    ->orWhereRaw('lower(email) like ?', [$term]);
            });
        }

        $result = CursorPaginator::paginate($query, $request);

        return $this->collection(
            collect($result['items'])->map(fn (Customer $customer) => $customer->toApiObject())->all(),
            $result['pagination'],
            $request,
        );
    }

    public function store(Request $request, CreateCustomer $create): JsonResponse
    {
        $context = $this->context($request);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
            'externalRef' => [
                'nullable', 'string', 'max:255',
                Rule::unique('customers', 'external_ref')
                    ->where('team_id', $context->team->id)
                    ->whereNull('deleted_at'),
            ],
            'customData' => ['nullable', 'array'],
        ]);

        $customer = $create->handle($context->team, [
            'email' => $data['email'],
            'name' => $data['name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'currency' => $data['currency'] ?? null,
            'externalRef' => $data['externalRef'] ?? null,
            'customData' => $data['customData'] ?? null,
        ]);

        return $this->resource($customer->toApiObject(), 201, $request);
    }

    public function show(Request $request, string $customer): JsonResponse
    {
        $context = $this->context($request);

        /** @var Customer $model */
        $model = $this->findCustomer($context->team, $customer, withTrashed: true);

        return $this->resource($model->toApiObject(), request: $request);
    }

    public function update(Request $request, string $customer): JsonResponse
    {
        $context = $this->context($request);

        /** @var Customer $model */
        $model = $this->findCustomer($context->team, $customer);

        $data = $request->validate([
            'email' => ['sometimes', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
            'externalRef' => [
                'nullable', 'string', 'max:255',
                Rule::unique('customers', 'external_ref')
                    ->where('team_id', $context->team->id)
                    ->whereNull('deleted_at')
                    ->ignore($model->id),
            ],
            'customData' => ['nullable', 'array'],
        ]);

        $model->update([
            'email' => $data['email'] ?? $model->email,
            'name' => array_key_exists('name', $data) ? $data['name'] : $model->name,
            'phone' => array_key_exists('phone', $data) ? $data['phone'] : $model->phone,
            'currency' => array_key_exists('currency', $data) ? $data['currency'] : $model->currency,
            'external_ref' => array_key_exists('externalRef', $data) ? $data['externalRef'] : $model->external_ref,
            'custom_data' => array_key_exists('customData', $data) ? $data['customData'] : $model->custom_data,
        ]);

        return $this->resource($model->fresh()->toApiObject(), request: $request);
    }

    public function archive(Request $request, string $customer): JsonResponse
    {
        $context = $this->context($request);

        /** @var Customer $model */
        $model = $this->findCustomer($context->team, $customer);

        if ($model->trashed()) {
            throw ValidationException::withMessages(['customer' => 'Customer is already archived.']);
        }

        $model->delete();

        return $this->resource($model->toApiObject(), request: $request);
    }

    public function restore(Request $request, string $customer): JsonResponse
    {
        $context = $this->context($request);

        /** @var Customer $model */
        $model = $this->findCustomer($context->team, $customer, withTrashed: true);

        if (! $model->trashed()) {
            throw ValidationException::withMessages(['customer' => 'Customer is not archived.']);
        }

        $model->restore();

        return $this->resource($model->toApiObject(), request: $request);
    }
}
