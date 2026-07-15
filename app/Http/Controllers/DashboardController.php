<?php

namespace App\Http\Controllers;

use App\Enums\ApiKeyMode;
use App\Enums\CatalogStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $email = strtolower($user->email);

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        return Inertia::render('dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'onboarding' => $user->currentTeam ? $this->onboardingState($user->currentTeam) : null,
            'summary' => $this->summaryState($user->currentTeam),
        ]);
    }

    /**
     * @return array{currency: string, revenueLast30: int, successfulPaymentsLast30: int, activeSubscriptions: int, trialingSubscriptions: int, pastDueSubscriptions: int, customers: int, activeProducts: int, activePrices: int, openInvoices: int, openInvoiceAmountDue: int, recentPayments: list<array<string, mixed>>, recentInvoices: list<array<string, mixed>>}
     */
    private function summaryState(Team $team): array
    {
        $paymentsLast30Days = Payment::query()
            ->where('team_id', $team->id)
            ->where('status', PaymentStatus::Succeeded)
            ->where('processed_at', '>=', now()->subDays(30));

        $recentPayments = Payment::query()
            ->with(['customer', 'paymentMethod', 'invoice.lines'])
            ->where('team_id', $team->id)
            ->latest('processed_at')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Payment $payment) => $payment->toDashboardArray())
            ->all();

        $recentInvoices = Invoice::query()
            ->with(['customer', 'lines', 'payments.paymentMethod'])
            ->where('team_id', $team->id)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Invoice $invoice) => $invoice->toListArray())
            ->all();

        return [
            'currency' => $team->default_currency,
            'revenueLast30' => (int) (clone $paymentsLast30Days)->sum('amount'),
            'successfulPaymentsLast30' => (int) (clone $paymentsLast30Days)->count(),
            'activeSubscriptions' => Subscription::query()
                ->where('team_id', $team->id)
                ->whereIn('status', SubscriptionStatus::activeSet())
                ->count(),
            'trialingSubscriptions' => Subscription::query()
                ->where('team_id', $team->id)
                ->where('status', SubscriptionStatus::Trialing)
                ->count(),
            'pastDueSubscriptions' => Subscription::query()
                ->where('team_id', $team->id)
                ->where('status', SubscriptionStatus::PastDue)
                ->count(),
            'customers' => Customer::query()
                ->where('team_id', $team->id)
                ->count(),
            'activeProducts' => Product::query()
                ->where('team_id', $team->id)
                ->where('status', CatalogStatus::Active)
                ->count(),
            'activePrices' => Price::query()
                ->where('team_id', $team->id)
                ->where('status', CatalogStatus::Active)
                ->count(),
            'openInvoices' => Invoice::query()
                ->where('team_id', $team->id)
                ->where('status', InvoiceStatus::Open)
                ->count(),
            'openInvoiceAmountDue' => (int) Invoice::query()
                ->where('team_id', $team->id)
                ->where('status', InvoiceStatus::Open)
                ->sum('amount_due'),
            'recentPayments' => $recentPayments,
            'recentInvoices' => $recentInvoices,
        ];
    }

    /**
     * @return array{businessConfirmed: bool, nombaConnected: bool, apiKeyGenerated: bool, webhookVerified: bool, firstProductCreated: bool, links: array{nomba: string, apiKeys: string, webhooks: string, products: string}}
     */
    private function onboardingState(Team $team): array
    {
        $connection = $team->processorConnection;

        return [
            'businessConfirmed' => $team->line1 !== null && $team->city !== null && $team->country !== null,
            'nombaConnected' => $connection !== null
                && ($connection->isConnected(ApiKeyMode::Test) || $connection->isConnected(ApiKeyMode::Live)),
            'apiKeyGenerated' => $team->apiKeys()->whereNull('revoked_at')->exists(),
            'webhookVerified' => $connection?->webhook_verified_at !== null,
            'firstProductCreated' => $team->products()->exists(),
            'links' => [
                'nomba' => route('developers.gateways.show', ['processor' => 'nomba']),
                'apiKeys' => route('developers.api-keys.index'),
                'webhooks' => route('developers.webhooks.show'),
                'products' => route('catalog.products.index'),
            ],
        ];
    }
}
