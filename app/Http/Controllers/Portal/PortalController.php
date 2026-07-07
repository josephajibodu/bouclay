<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer-facing self-service portal — authenticated by portal token, no login.
 */
class PortalController extends Controller
{
    /**
     * Show the read-only portal dashboard for a customer.
     */
    public function show(string $token): Response
    {
        $customer = Customer::query()
            ->where('portal_token', $token)
            ->with([
                'team.settings',
                'defaultPaymentMethod',
                'subscriptions' => fn ($query) => $query->with('activeItems.product')->orderByDesc('created_at'),
                'invoices' => fn ($query) => $query->with('lines')->orderByDesc('created_at'),
            ])
            ->firstOrFail();

        abort_if($customer->trashed(), 404);

        $team = $customer->team;
        $defaultPaymentMethod = $customer->defaultPaymentMethod;

        return Inertia::render('portal/dashboard', [
            'business' => [
                'name' => $team->name,
                'line1' => $team->line1,
                'line2' => $team->line2,
                'city' => $team->city,
                'postalCode' => $team->postal_code,
                'country' => $team->country,
                'website' => $team->website,
            ],
            'customer' => [
                'name' => $customer->name,
                'email' => $customer->email,
            ],
            'paymentMethod' => $defaultPaymentMethod !== null ? [
                'brand' => $defaultPaymentMethod->brand,
                'last4' => $defaultPaymentMethod->last4,
                'expMonth' => $defaultPaymentMethod->exp_month,
                'expYear' => $defaultPaymentMethod->exp_year,
                'isExpired' => $defaultPaymentMethod->isExpired(),
            ] : null,
            'subscriptions' => $customer->subscriptions->map(fn ($subscription) => [
                'publicId' => $subscription->public_id,
                'status' => $subscription->status->value,
                'planLabel' => $subscription->planLabel(),
                'trialEndsAt' => $subscription->trial_ends_at?->toISOString(),
                'currentPeriodEnd' => $subscription->current_period_end?->toISOString(),
                'endsAt' => $subscription->canceled_at !== null ? $subscription->ends_at?->toISOString() : null,
            ])->all(),
            'openInvoices' => $customer->invoices
                ->where('status', InvoiceStatus::Open)
                ->map(fn ($invoice) => [
                    'publicId' => $invoice->public_id,
                    'number' => $invoice->number,
                    'currency' => $invoice->currency,
                    'amountDue' => $invoice->amount_due,
                    'dueAt' => $invoice->due_at?->toISOString(),
                    'createdAt' => $invoice->created_at?->toISOString(),
                    'productsLabel' => $invoice->lines->pluck('description')->implode(', ') ?: '—',
                    'payUrl' => route('hosted.invoices.show', $invoice->public_id),
                ])
                ->values()
                ->all(),
        ]);
    }
}
