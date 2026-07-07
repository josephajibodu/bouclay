<?php

namespace App\Http\Controllers\Invoices;

use App\Actions\Invoicing\CreateOneOffInvoice;
use App\Enums\CatalogStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoices\StoreInvoiceRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\Product;
use App\Models\Team;
use App\Services\Invoicing\InvoicePdfGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

class InvoiceController extends Controller
{
    /**
     * List the team's invoices — search, one status filter, paginated.
     */
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewInvoices', $team);

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

        return Inertia::render('invoices/index', [
            'invoices' => $invoices,
            'filters' => ['search' => $search, 'status' => $status],
            'hasAny' => $team->invoices()->exists(),
            'customers' => $this->customerOptions($team),
            'products' => $this->productOptions($team),
            'teamCurrency' => $team->default_currency,
            'canManage' => $request->user()->toTeamPermissions($team)->canManageInvoices,
        ]);
    }

    /**
     * The invoice detail page — operational overview plus the paper document.
     */
    public function show(Request $request, Invoice $invoice): Response
    {
        $team = $request->user()->currentTeam;

        abort_unless($invoice->team_id === $team->id, 404);

        Gate::authorize('viewInvoices', $team);

        $invoice->load([
            'customer',
            'subscription',
            'team.settings',
            'lines.product',
            'lines.price',
            'payments.paymentMethod',
        ]);

        $permissions = $request->user()->toTeamPermissions($team);

        return Inertia::render('invoices/show', [
            'invoice' => $invoice->toShowArray(),
            'permissions' => [
                'canManage' => $permissions->canManageInvoices,
            ],
        ]);
    }

    /**
     * Download a PDF snapshot of this invoice.
     */
    public function download(Request $request, Invoice $invoice, InvoicePdfGenerator $pdf): HttpFoundationResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($invoice->team_id === $team->id, 404);

        Gate::authorize('viewInvoices', $team);

        $invoice->load(['customer', 'lines', 'team.settings']);

        $filename = ($invoice->number ?? $invoice->public_id).'.pdf';

        return response($pdf->generate($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Create a one-off invoice and charge it when automatic with a card on file.
     */
    public function store(StoreInvoiceRequest $request, CreateOneOffInvoice $create): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageInvoices', $team);

        try {
            $invoice = $create->handle($team, $request->validated());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        $payment = $invoice->payments->last();

        Inertia::flash('toast', [
            'type' => $payment !== null && $payment->status === PaymentStatus::Failed ? 'error' : 'success',
            'message' => match (true) {
                $payment !== null && $payment->status === PaymentStatus::Succeeded => 'Invoice created and charged.',
                $payment !== null && $payment->status === PaymentStatus::Failed => 'Invoice created, but the charge was declined.',
                default => 'Invoice created.',
            },
        ]);

        return to_route('invoices.show', $invoice);
    }

    /**
     * Void an open invoice — it can no longer be paid or collected.
     */
    public function void(Request $request, Invoice $invoice): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($invoice->team_id === $team->id, 404);

        Gate::authorize('manageInvoices', $team);

        abort_unless($invoice->canBeCanceled(), 422);

        $invoice->markVoid();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Invoice voided.']);

        return to_route('invoices.show', $invoice);
    }

    /**
     * Mark an open invoice uncollectible — the debt is written off.
     */
    public function markUncollectible(Request $request, Invoice $invoice): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($invoice->team_id === $team->id, 404);

        Gate::authorize('manageInvoices', $team);

        abort_unless($invoice->canBeCanceled(), 422);

        $invoice->markUncollectible();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Invoice marked uncollectible.']);

        return to_route('invoices.show', $invoice);
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
