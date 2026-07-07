<?php

namespace App\Http\Controllers\Subscriptions;

use App\Actions\Dunning\ResolveSubscriptionDunning;
use App\Actions\Dunning\RetryPastDueInvoice;
use App\Actions\Subscriptions\BuildSubscriptionCreateOptions;
use App\Actions\Subscriptions\CreateSubscription;
use App\Actions\Subscriptions\UpdateSubscriptionItem;
use App\Enums\CatalogStatus;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceStatus;
use App\Enums\PriceType;
use App\Enums\ScheduledChangeAction;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Subscriptions\IllegalStateTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\StoreSubscriptionRequest;
use App\Http\Requests\Subscriptions\UpdateSubscriptionItemRequest;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SubscriptionController extends Controller
{
    /**
     * List the team's subscriptions — Paddle-thin: search, one status filter,
     * server-side paginated (SUBSCRIPTIONS_DESIGN §6).
     */
    public function index(Request $request, BuildSubscriptionCreateOptions $createOptions, ResolveSubscriptionDunning $dunning): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewSubscriptions', $team);

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all_active');

        $subscriptions = $team->subscriptions()
            ->with([
                'customer',
                'activeItems.product',
                'team.settings',
                'invoices' => fn ($query) => $query
                    ->where('status', InvoiceStatus::Open)
                    ->where('billing_reason', InvoiceBillingReason::SubscriptionCycle)
                    ->with('payments')
                    ->orderByDesc('id'),
            ])
            ->when($status !== 'all', function ($query) use ($status) {
                $statuses = $status === 'all_active'
                    ? array_map(fn (SubscriptionStatus $s) => $s->value, SubscriptionStatus::activeSet())
                    : [$status];

                $query->whereIn('status', $statuses);
            })
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.mb_strtolower($search).'%';

                $query->where(function ($query) use ($term) {
                    $query->whereRaw('lower(public_id) like ?', [$term])
                        ->orWhereHas('customer', function ($query) use ($term) {
                            $query->whereRaw('lower(name) like ?', [$term])
                                ->orWhereRaw('lower(email) like ?', [$term]);
                        });
                });
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(function (Subscription $subscription) use ($dunning) {
                $row = $subscription->toListArray();
                $summary = $dunning->handle($subscription);

                if ($summary !== null && $summary['attempt'] !== null) {
                    $row['dunningAttempt'] = $summary['attempt'];
                    $row['dunningMaxAttempts'] = $summary['maxAttempts'];
                    $row['dunningNextRetryAt'] = $summary['nextRetryAt'];
                }

                return $row;
            });

        return Inertia::render('subscriptions/index', [
            'subscriptions' => $subscriptions,
            'filters' => ['search' => $search, 'status' => $status],
            'hasAny' => $team->subscriptions()->exists(),
            'hasRecurringPrices' => $team->prices()->where('type', PriceType::Recurring)->where('status', CatalogStatus::Active)->exists(),
            // The create drawer's data (SUBSCRIPTIONS_DESIGN §7) — it opens
            // in-place now rather than navigating to a dedicated page.
            'customers' => $this->customerOptions($team),
            ...$createOptions->handle($team),
            'teamCurrency' => $team->default_currency,
            'canManage' => $request->user()->toTeamPermissions($team)->canManageSubscriptions,
        ]);
    }

    /**
     * Create the subscription. Domain guards in the service (currency mismatch,
     * once-per-customer) surface as inline validation errors.
     */
    public function store(StoreSubscriptionRequest $request, CreateSubscription $create): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageSubscriptions', $team);

        try {
            $subscription = $create->handle($team, $request->validated());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->createdToast($subscription)]);

        return to_route('subscriptions.show', $subscription);
    }

    /**
     * The subscription detail hub (SUBSCRIPTIONS_DESIGN §8).
     */
    public function show(Request $request, Subscription $subscription, BuildSubscriptionCreateOptions $createOptions, ResolveSubscriptionDunning $dunning): Response
    {
        $team = $request->user()->currentTeam;

        abort_unless($subscription->team_id === $team->id, 404);

        Gate::authorize('viewSubscriptions', $team);

        $subscription->load([
            'customer',
            'team.settings',
            'paymentMethod',
            'items' => fn ($query) => $query->orderBy('created_at'),
            'items.product',
            'items.price',
            'items.currentTrial.transitionPrice',
            'scheduledChanges' => fn ($query) => $query->whereNull('applied_at'),
            'invoices' => fn ($query) => $query->orderByDesc('created_at'),
            'invoices.payments' => fn ($query) => $query->orderByDesc('created_at'),
            'invoices.payments.paymentMethod',
        ]);

        $status = $subscription->status;
        $openInvoice = $subscription->invoices
            ->first(fn ($invoice) => $invoice->status === InvoiceStatus::Open);
        $paymentLink = $openInvoice !== null && in_array($status, [SubscriptionStatus::Incomplete, SubscriptionStatus::PastDue], true)
            ? $openInvoice->paymentLink()
            : null;

        return Inertia::render('subscriptions/show', [
            'subscription' => [
                'id' => $subscription->id,
                'publicId' => $subscription->public_id,
                'status' => $status->value,
                'currency' => $subscription->currency,
                'collectionMode' => $subscription->collection_mode->value,
                'trialEndsAt' => $subscription->trial_ends_at?->toISOString(),
                'trialEndBehavior' => $subscription->trial_end_behavior?->value,
                'currentPeriodStart' => $subscription->current_period_start?->toISOString(),
                'currentPeriodEnd' => $subscription->current_period_end?->toISOString(),
                'pauseResumesAt' => $subscription->pause_resumes_at?->toISOString(),
                'canceledAt' => $subscription->canceled_at?->toISOString(),
                'endsAt' => $subscription->ends_at?->toISOString(),
                'customData' => $subscription->custom_data,
                'createdAt' => $subscription->created_at?->toISOString(),
            ],
            'customer' => [
                'id' => $subscription->customer->id,
                'name' => $subscription->customer->name,
                'email' => $subscription->customer->email,
            ],
            'paymentMethod' => $subscription->paymentMethod?->toDashboardArray(),
            'items' => $subscription->items->map(fn ($item) => $item->toDashboardArray())->all(),
            'scheduledChanges' => $subscription->scheduledChanges->map(fn ($change) => [
                'action' => $change->action->value,
                'effectiveAt' => $change->effective_at->toISOString(),
            ])->all(),
            'timeline' => $this->buildTimeline($subscription),
            // Invoices this subscription has generated so far, and every
            // payment attempted against them (IMPLEMENTATION.md Phase 6).
            'invoices' => $subscription->invoices->map(fn ($invoice) => $invoice->toDashboardArray())->all(),
            'payments' => $subscription->invoices
                ->flatMap(fn ($invoice) => $invoice->payments)
                ->sortByDesc('created_at')
                ->map(fn ($payment) => $payment->toDashboardArray())
                ->values()
                ->all(),
            'permissions' => [
                'canManage' => $request->user()->toTeamPermissions($team)->canManageSubscriptions,
            ],
            'products' => $createOptions->handle($team)['products'],
            'paymentLink' => $paymentLink,
            'dunning' => $dunning->handle($subscription),
        ]);
    }

    /**
     * Manually retry collection on a past-due subscription's open invoice.
     */
    public function retryPayment(Request $request, Subscription $subscription, RetryPastDueInvoice $retry): RedirectResponse
    {
        $subscription = $this->authorizeManage($request, $subscription);

        $result = $retry->handle($subscription, force: true);

        $message = match ($result) {
            'recovered' => 'Payment succeeded — subscription is active again.',
            'retried' => 'Payment retry attempted.',
            'exhausted' => 'Retries exhausted — the configured terminal action was applied.',
            default => 'Nothing to retry right now.',
        };

        Inertia::flash('toast', [
            'type' => $result === 'recovered' ? 'success' : ($result === 'skipped' ? 'info' : 'warning'),
            'message' => $message,
        ]);

        return back();
    }

    /**
     * Update a subscription item's plan or quantity and invoice proration.
     */
    public function updateItem(
        UpdateSubscriptionItemRequest $request,
        Subscription $subscription,
        SubscriptionItem $item,
        UpdateSubscriptionItem $update,
    ): RedirectResponse {
        $subscription = $this->authorizeManage($request, $subscription);

        abort_unless($item->subscription_id === $subscription->id, 404);

        $validated = $request->validated();

        try {
            $update->handle(
                subscription: $subscription,
                item: $item,
                quantity: isset($validated['quantity']) ? (int) $validated['quantity'] : null,
                priceId: isset($validated['price_id']) ? (int) $validated['price_id'] : null,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Subscription item updated.']);

        return back();
    }

    /**
     * Pause billing, optionally with a resume date.
     */
    public function pause(Request $request, Subscription $subscription): RedirectResponse
    {
        $subscription = $this->authorizeManage($request, $subscription);

        $resumesAt = $request->filled('resumes_at')
            ? Carbon::parse($request->date('resumes_at'))
            : null;

        return $this->runTransition($subscription, function () use ($subscription, $resumesAt) {
            $subscription->apply('pause', $resumesAt);

            return $resumesAt !== null
                ? 'Subscription paused — resumes '.$resumesAt->format('j M').'.'
                : 'Subscription paused.';
        });
    }

    /**
     * Resume a paused subscription.
     */
    public function resume(Request $request, Subscription $subscription): RedirectResponse
    {
        $subscription = $this->authorizeManage($request, $subscription);

        return $this->runTransition($subscription, function () use ($subscription) {
            $subscription->apply('resume');

            return 'Subscription resumed.';
        });
    }

    /**
     * Cancel — immediately or at the end of the current period. "At period end"
     * is a scheduled change, not a status flip (SUBSCRIPTIONS_DESIGN §4, §8.3).
     */
    public function cancel(Request $request, Subscription $subscription): RedirectResponse
    {
        $subscription = $this->authorizeManage($request, $subscription);

        $mode = $request->validate([
            'mode' => ['required', 'in:immediately,period_end'],
        ])['mode'];

        if ($mode === 'immediately') {
            return $this->runTransition($subscription, function () use ($subscription) {
                $subscription->apply('cancel');

                return 'Subscription canceled.';
            });
        }

        $effectiveAt = $subscription->current_period_end ?? Carbon::now();

        $subscription->scheduledChanges()->create([
            'action' => ScheduledChangeAction::Cancel,
            'effective_at' => $effectiveAt,
        ]);

        $subscription->forceFill([
            'canceled_at' => Carbon::now(),
            'ends_at' => $effectiveAt,
        ])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Cancellation scheduled for '.$effectiveAt->format('j M').'. You can undo anytime before then.',
        ]);

        return back();
    }

    /**
     * Undo a scheduled cancellation.
     */
    public function resumeSchedule(Request $request, Subscription $subscription): RedirectResponse
    {
        $subscription = $this->authorizeManage($request, $subscription);

        $subscription->scheduledChanges()
            ->where('action', ScheduledChangeAction::Cancel)
            ->whereNull('applied_at')
            ->delete();

        $subscription->forceFill(['canceled_at' => null, 'ends_at' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cancellation undone.']);

        return back();
    }

    /**
     * Resolve + authorize a manageable subscription for lifecycle actions.
     */
    private function authorizeManage(Request $request, Subscription $subscription): Subscription
    {
        $team = $request->user()->currentTeam;

        abort_unless($subscription->team_id === $team->id, 404);

        Gate::authorize('manageSubscriptions', $team);

        return $subscription;
    }

    /**
     * Run a state-machine transition, turning an illegal one into a friendly
     * error toast instead of a 500 (SUBSCRIPTIONS_DESIGN §14.3).
     */
    private function runTransition(Subscription $subscription, callable $transition): RedirectResponse
    {
        try {
            $message = $transition();
        } catch (IllegalStateTransition $e) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => "This subscription's status changed. Refresh to see the latest.",
            ]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customerOptions(Team $team): array
    {
        $customers = $team->customers()
            ->with(['paymentMethods' => fn ($query) => $query->orderByDesc('is_default')])
            ->orderByDesc('created_at')
            ->get();

        return array_map(fn (Customer $customer): array => [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'currency' => $customer->currency,
            'paymentMethods' => array_map(fn (PaymentMethod $pm): array => [
                'id' => $pm->id,
                'label' => trim(($pm->brand ?? 'Card').' ···· '.($pm->last4 ?? '••••')),
                'isDefault' => $pm->is_default,
            ], $customer->paymentMethods->all()),
        ], $customers->all());
    }

    /**
     * The success toast copy depends on which branch created the sub
     * (SUBSCRIPTIONS_DESIGN §14.2).
     */
    private function createdToast(Subscription $subscription): string
    {
        return match ($subscription->status) {
            SubscriptionStatus::Trialing => 'Subscription started — customer is on a free trial.',
            SubscriptionStatus::Incomplete => 'Subscription created — send the customer a checkout link to activate it.',
            default => 'Subscription created.',
        };
    }

    /**
     * Build the timeline from the timestamps Bouclay already keeps. Later
     * phases append rows to the same shape (SUBSCRIPTIONS_DESIGN §8.2).
     *
     * @return array<int, array<string, string|null>>
     */
    private function buildTimeline(Subscription $subscription): array
    {
        $events = [[
            'type' => 'subscription.created',
            'label' => 'Subscription created',
            'at' => $subscription->created_at?->toISOString(),
        ]];

        if ($subscription->trial_ends_at !== null) {
            $events[] = [
                'type' => 'trial.started',
                'label' => 'Free trial started',
                'at' => $subscription->created_at?->toISOString(),
            ];
        }

        if ($subscription->canceled_at !== null) {
            $events[] = [
                'type' => 'subscription.canceled',
                'label' => $subscription->status === SubscriptionStatus::Canceled ? 'Subscription canceled' : 'Cancellation scheduled',
                'at' => $subscription->canceled_at->toISOString(),
            ];
        }

        usort($events, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return $events;
    }
}
