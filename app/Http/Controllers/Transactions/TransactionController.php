<?php

namespace App\Http\Controllers\Transactions;

use App\Actions\Transactions\CreateTransaction;
use App\Enums\CatalogStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\StoreTransactionRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\Product;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Transactions — Paddle's word for an invoice + its charge attempts
 * (schema.md's `invoices`/`payments`). One-off "New transaction" mirrors the
 * subscription create flow: a customer, one or more line items, and how to
 * collect (IMPLEMENTATION.md Phase 6).
 */
class TransactionController extends Controller
{
    /**
     * Paddle-thin: search, one status filter, server-side paginated.
     *
     * Invoice-centric, not payment-centric: a manually-billed or
     * not-yet-charged invoice has zero `Payment` rows, so listing payments
     * would make it invisible the moment it's created. Paddle's own
     * "Transactions" list is the same shape — one row per invoice, its
     * latest charge attempt (if any) shown alongside.
     */
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewTransactions', $team);

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');

        $invoices = $team->invoices()
            ->with(['customer', 'lines', 'payments.paymentMethod'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.mb_strtolower($search).'%';

                $query->where(function ($query) use ($term) {
                    $query->whereRaw('lower(public_id) like ?', [$term])
                        ->orWhereRaw('lower(number) like ?', [$term])
                        ->orWhereHas('customer', function ($query) use ($term) {
                            $query->whereRaw('lower(name) like ?', [$term])
                                ->orWhereRaw('lower(email) like ?', [$term]);
                        });
                });
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Invoice $invoice) => $invoice->toListArray());

        return Inertia::render('transactions/index', [
            'transactions' => $invoices,
            'filters' => ['search' => $search, 'status' => $status],
            'hasAny' => $team->invoices()->exists(),
            'customers' => $this->customerOptions($team),
            'products' => $this->productOptions($team),
            'teamCurrency' => $team->default_currency,
            'canManage' => $request->user()->toTeamPermissions($team)->canManageTransactions,
        ]);
    }

    /**
     * Create a one-off Transaction — invoice it, and charge it now when
     * automatic with a card on file.
     */
    public function store(StoreTransactionRequest $request, CreateTransaction $create): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageTransactions', $team);

        try {
            $invoice = $create->handle($team, $request->validated());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        $payment = $invoice->payments->last();

        Inertia::flash('toast', [
            'type' => $payment !== null && $payment->status === PaymentStatus::Failed ? 'error' : 'success',
            'message' => match (true) {
                $payment !== null && $payment->status === PaymentStatus::Succeeded => 'Transaction created and charged.',
                $payment !== null && $payment->status === PaymentStatus::Failed => 'Transaction created, but the charge was declined.',
                default => 'Transaction created.',
            },
        ]);

        return to_route('transactions.index');
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
     * Every active price (recurring or one-time) is billable on a one-off
     * Transaction — unlike a subscription, it isn't limited to recurring.
     *
     * @return array<int, array<string, mixed>>
     */
    private function productOptions(Team $team): array
    {
        $products = $team->products()
            ->where('status', CatalogStatus::Active)
            ->with(['prices' => fn ($query) => $query->where('status', CatalogStatus::Active)])
            ->orderBy('name')
            ->get()
            ->filter(fn (Product $product) => $product->prices->isNotEmpty());

        return array_map(fn (Product $product): array => [
            'id' => $product->id,
            'name' => $product->name,
            'prices' => array_map(fn (Price $price): array => [
                'id' => $price->id,
                'label' => $price->toPickerLabel(),
                'unitAmount' => $price->unit_amount !== null ? $price->unit_amount / 100 : null,
                'currency' => $price->currency,
            ], $product->prices->all()),
        ], array_values($products->all()));
    }
}
