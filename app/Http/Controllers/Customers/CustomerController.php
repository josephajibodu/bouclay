<?php

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    /**
     * List the team's customers — Paddle-thin: search, one status filter,
     * server-side paginated (CUSTOMERS_DESIGN §5).
     */
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewCustomers', $team);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status') === 'archived' ? 'archived' : 'active';

        $customers = $team->customers()
            ->when($status === 'archived', fn ($query) => $query->onlyTrashed())
            ->when($search !== '', function ($query) use ($search) {
                // LOWER(...) LIKE keeps the match case-insensitive on both
                // Postgres (prod) and SQLite (tests) — `ilike` is Postgres-only.
                $term = '%'.mb_strtolower($search).'%';

                $query->where(function ($query) use ($term) {
                    $query->whereRaw('lower(name) like ?', [$term])
                        ->orWhereRaw('lower(email) like ?', [$term]);
                });
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Customer $customer) => [
                'id' => $customer->id,
                'publicId' => $customer->public_id,
                'name' => $customer->name,
                'email' => $customer->email,
                'status' => $customer->trashed() ? 'archived' : 'active',
                'createdAt' => $customer->created_at?->toISOString(),
            ]);

        return Inertia::render('customers/index', [
            'customers' => $customers,
            'filters' => ['search' => $search, 'status' => $status],
            'hasAny' => $team->customers()->withTrashed()->exists(),
            'teamCurrency' => $team->default_currency,
            'canManage' => $request->user()->toTeamPermissions($team)->canManageCustomers,
        ]);
    }

    /**
     * Create a customer. Kept deliberately minimal — email required, the
     * rest optional (CUSTOMERS_DESIGN §6). Lands the user on the new
     * customer's detail page, where the next actions live.
     */
    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageCustomers', $team);

        $customer = $team->customers()->create([
            'email' => $request->validated('email'),
            'name' => $request->validated('name'),
            'phone' => $request->validated('phone'),
            'currency' => $request->validated('currency'),
            'external_ref' => $request->validated('external_ref'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Customer created']);

        return to_route('customers.show', $customer);
    }

    /**
     * The customer detail hub — the single source of truth for a customer
     * (CUSTOMERS_DESIGN §7). Renders whether the customer is active or
     * archived (soft-deleted), so the archived banner + Restore can show.
     */
    public function show(Request $request, Customer $customer): Response
    {
        $team = $request->user()->currentTeam;

        abort_unless($customer->team_id === $team->id, 404);

        Gate::authorize('viewCustomers', $team);

        $customer->load([
            'addresses' => fn ($query) => $query->orderByDesc('is_default')->orderBy('created_at'),
            'paymentMethods' => fn ($query) => $query->orderByDesc('is_default')->orderByDesc('created_at'),
        ]);

        $defaultAddress = $customer->addresses->firstWhere('is_default', true)
            ?? $customer->addresses->first();

        return Inertia::render('customers/show', [
            'customer' => [
                'id' => $customer->id,
                'publicId' => $customer->public_id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'currency' => $customer->currency,
                'externalRef' => $customer->external_ref,
                'status' => $customer->trashed() ? 'archived' : 'active',
                'defaultPaymentMethodId' => $customer->default_payment_method_id,
                'customData' => $customer->custom_data,
                'createdAt' => $customer->created_at?->toISOString(),
                'archivedAt' => $customer->deleted_at?->toISOString(),
            ],
            'addresses' => $customer->addresses->map(fn ($address) => $address->toDashboardArray())->all(),
            'paymentMethods' => $customer->paymentMethods->map(fn ($pm) => $pm->toDashboardArray())->all(),
            'defaultAddress' => $defaultAddress?->toDashboardArray(),
            'activity' => $this->buildActivity($customer),
            'teamCurrency' => $team->default_currency,
            'permissions' => [
                'canManage' => $request->user()->toTeamPermissions($team)->canManageCustomers,
            ],
        ]);
    }

    /**
     * Update the customer's core details.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($customer->team_id === $team->id, 404);

        Gate::authorize('manageCustomers', $team);

        $customer->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Customer updated']);

        return back();
    }

    /**
     * Archive (soft-delete) a customer.
     */
    public function archive(Request $request, Customer $customer): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($customer->team_id === $team->id, 404);

        Gate::authorize('manageCustomers', $team);

        $customer->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Customer archived']);

        return to_route('customers.index');
    }

    /**
     * Restore an archived customer.
     */
    public function restore(Request $request, Customer $customer): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($customer->team_id === $team->id, 404);

        Gate::authorize('manageCustomers', $team);

        $customer->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Customer restored']);

        return back();
    }

    /**
     * Archive a batch of customers from the list's bulk action bar.
     */
    public function bulkArchive(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageCustomers', $team);

        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ])['ids'];

        $count = $team->customers()->whereIn('id', $ids)->get()->each->delete()->count();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $count === 1 ? 'Customer archived' : "{$count} customers archived",
        ]);

        return to_route('customers.index');
    }

    /**
     * Build the activity timeline from the timestamps Bouclay already keeps.
     * Only event types with real data render today; the shape scales as later
     * phases add rows (CUSTOMERS_DESIGN §12).
     *
     * @return list<array<string, string|null>>
     */
    private function buildActivity(Customer $customer): array
    {
        $events = [];

        $events[] = [
            'type' => 'customer.created',
            'label' => 'Customer created',
            'at' => $customer->created_at?->toISOString(),
        ];

        foreach ($customer->addresses as $address) {
            $events[] = [
                'type' => 'address.added',
                'label' => 'Address added · '.$address->type->label(),
                'at' => $address->created_at?->toISOString(),
            ];
        }

        foreach ($customer->paymentMethods as $paymentMethod) {
            $events[] = [
                'type' => 'payment_method.added',
                'label' => trim('Payment method added · '.($paymentMethod->brand ?? 'Card').' ···· '.($paymentMethod->last4 ?? '')),
                'at' => $paymentMethod->created_at?->toISOString(),
            ];
        }

        if ($customer->trashed()) {
            $events[] = [
                'type' => 'customer.archived',
                'label' => 'Customer archived',
                'at' => $customer->deleted_at?->toISOString(),
            ];
        }

        usort($events, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return $events;
    }
}
