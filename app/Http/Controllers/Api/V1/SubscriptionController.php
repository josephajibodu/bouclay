<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Dunning\RetryPastDueInvoice;
use App\Actions\Subscriptions\CreateSubscription;
use App\Actions\Subscriptions\UpdateSubscriptionItem;
use App\Enums\ScheduledChangeAction;
use App\Http\Controllers\Api\V1Controller;
use App\Models\Subscription;
use App\Support\Api\CursorPaginator;
use App\Support\Api\NormalizesSubscriptionInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SubscriptionController extends V1Controller
{
    use NormalizesSubscriptionInput;

    public function index(Request $request): JsonResponse
    {
        $context = $this->context($request);

        $query = $context->team->subscriptions()->with('customer');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('customerId')) {
            $customer = $this->findCustomer($context->team, (string) $request->query('customerId'));
            $query->where('customer_id', $customer->id);
        }

        $result = CursorPaginator::paginate($query, $request);

        return $this->collection(
            collect($result['items'])->map(fn (Subscription $subscription) => $subscription->toApiObject())->all(),
            $result['pagination'],
            $request,
        );
    }

    public function store(Request $request, CreateSubscription $create): JsonResponse
    {
        $context = $this->context($request);

        $request->validate([
            'customer' => ['required', 'string'],
            'collectionMode' => ['required', 'in:automatic,manual'],
            'paymentMethod' => ['nullable', 'string'],
            'trialEndBehavior' => ['nullable', 'in:cancel,pause,create_invoice'],
            'items' => ['required', 'array', 'min:1'],
        ]);

        try {
            $normalized = $this->normalizeSubscriptionInput($context->team, $request->all(), $context);
            $subscription = $create->handle($context->team, $normalized);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return $this->resource($subscription->fresh()->load(['customer', 'items', 'paymentMethod'])->toApiObject(), 201, $request);
    }

    public function show(Request $request, string $subscription): JsonResponse
    {
        $context = $this->context($request);

        $model = $this->findSubscription($context->team, $subscription);
        $model->load(['customer', 'items.product', 'items.price', 'paymentMethod', 'scheduledChanges']);

        return $this->resource($model->toApiObject(), request: $request);
    }

    public function pause(Request $request, string $subscription): JsonResponse
    {
        $context = $this->context($request);
        $model = $this->findSubscription($context->team, $subscription);

        $resumesAt = $request->filled('resumesAt')
            ? Carbon::parse($request->input('resumesAt'))
            : null;

        $model->apply('pause', $resumesAt);

        return $this->resource($model->fresh()->toApiObject(), request: $request);
    }

    public function resume(Request $request, string $subscription): JsonResponse
    {
        $context = $this->context($request);
        $model = $this->findSubscription($context->team, $subscription);

        $model->apply('resume');

        return $this->resource($model->fresh()->toApiObject(), request: $request);
    }

    public function cancel(Request $request, string $subscription): JsonResponse
    {
        $context = $this->context($request);
        $model = $this->findSubscription($context->team, $subscription);

        $mode = $request->validate([
            'mode' => ['required', 'in:immediately,period_end'],
        ])['mode'];

        if ($mode === 'immediately') {
            $model->apply('cancel');
        } else {
            $effectiveAt = $model->current_period_end ?? Carbon::now();

            $model->scheduledChanges()->create([
                'action' => ScheduledChangeAction::Cancel,
                'effective_at' => $effectiveAt,
            ]);

            $model->forceFill([
                'canceled_at' => Carbon::now(),
                'ends_at' => $effectiveAt,
            ])->save();
        }

        return $this->resource($model->fresh()->toApiObject(), request: $request);
    }

    public function undoCancel(Request $request, string $subscription): JsonResponse
    {
        $context = $this->context($request);
        $model = $this->findSubscription($context->team, $subscription);

        $model->scheduledChanges()
            ->where('action', ScheduledChangeAction::Cancel)
            ->whereNull('applied_at')
            ->delete();

        $model->forceFill(['canceled_at' => null, 'ends_at' => null])->save();

        return $this->resource($model->fresh()->toApiObject(), request: $request);
    }

    public function retryPayment(Request $request, string $subscription, RetryPastDueInvoice $retry): JsonResponse
    {
        $context = $this->context($request);
        $model = $this->findSubscription($context->team, $subscription);

        $retry->handle($model, force: true);

        return $this->resource($model->fresh()->toApiObject(), request: $request);
    }

    public function updateItem(Request $request, string $subscription, string $item, UpdateSubscriptionItem $update): JsonResponse
    {
        $context = $this->context($request);
        $model = $this->findSubscription($context->team, $subscription);
        $itemModel = $this->findSubscriptionItem($model, $item);

        $data = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'priceId' => ['nullable', 'string'],
        ]);

        $priceId = isset($data['priceId'])
            ? $this->findPrice($context->team, $data['priceId'])->id
            : null;

        try {
            $update->handle(
                subscription: $model,
                item: $itemModel,
                quantity: isset($data['quantity']) ? (int) $data['quantity'] : null,
                priceId: $priceId,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        return $this->resource($model->fresh()->load('items')->toApiObject(), request: $request);
    }
}
