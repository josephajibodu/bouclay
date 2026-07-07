<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\BuildPortalContext;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer-facing self-service portal — authenticated by portal token, no login.
 */
class PortalController extends Controller
{
    public function __construct(private readonly BuildPortalContext $portal)
    {
        //
    }

    /**
     * Entry point — send customers to their subscription hub.
     */
    public function show(string $token): RedirectResponse
    {
        $customer = $this->portal->resolve($token);
        $this->portal->loadSubscriptions($customer);

        $visible = $customer->subscriptions->reject(fn (Subscription $subscription) => in_array($subscription->status, [
            SubscriptionStatus::IncompleteExpired,
        ], true));

        if ($visible->count() === 1) {
            return redirect()->route('portal.subscriptions.show', [
                'token' => $token,
                'publicId' => $visible->first()->public_id,
            ]);
        }

        return redirect()->route('portal.subscriptions.index', $token);
    }

    /**
     * List all subscriptions — Paddle-style hub when a customer has several.
     */
    public function subscriptions(string $token): Response
    {
        $customer = $this->portal->resolve($token);
        $this->portal->loadSubscriptions($customer);

        return Inertia::render('portal/subscriptions/index', [
            ...$this->portal->shared($customer),
            'subscriptions' => $customer->subscriptions
                ->map(fn (Subscription $subscription) => $this->portal->subscriptionListItem($subscription))
                ->all(),
        ]);
    }

    /**
     * Subscription detail — primary Paddle-style management screen.
     */
    public function subscription(string $token, string $publicId): Response
    {
        $customer = $this->portal->resolve($token);

        $subscription = Subscription::query()
            ->where('public_id', $publicId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        return Inertia::render('portal/subscriptions/show', [
            ...$this->portal->shared($customer),
            'subscription' => $this->portal->subscriptionDetail($subscription, $customer),
        ]);
    }

    /**
     * Full payment history for this customer.
     */
    public function payments(string $token): Response
    {
        $customer = $this->portal->resolve($token);

        $payments = Payment::query()
            ->where('customer_id', $customer->id)
            ->where('status', PaymentStatus::Succeeded)
            ->with(['invoice.lines', 'paymentMethod'])
            ->orderByDesc('processed_at')
            ->limit(50)
            ->get();

        $openInvoices = $customer->invoices()
            ->with('lines')
            ->where('status', InvoiceStatus::Open)
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('portal/payments/index', [
            ...$this->portal->shared($customer),
            'payments' => $payments
                ->map(fn (Payment $payment) => $this->portal->paymentListItem($payment))
                ->all(),
            'openInvoices' => $openInvoices
                ->map(fn ($invoice) => $this->portal->openInvoice($invoice))
                ->all(),
        ]);
    }

    /**
     * Saved payment methods and card update entry point.
     */
    public function paymentMethods(string $token): Response
    {
        $customer = $this->portal->resolve($token);
        $customer->load('paymentMethods');

        return Inertia::render('portal/payment-methods/index', [
            ...$this->portal->shared($customer),
            'paymentMethods' => $customer->paymentMethods
                ->sortByDesc('is_default')
                ->values()
                ->map(fn ($method) => [
                    'brand' => $method->brand,
                    'last4' => $method->last4,
                    'expMonth' => $method->exp_month,
                    'expYear' => $method->exp_year,
                    'isDefault' => $method->is_default,
                    'isExpired' => $method->isExpired(),
                ])
                ->all(),
        ]);
    }

    /**
     * Customer account details.
     */
    public function account(string $token): Response
    {
        $customer = $this->portal->resolve($token);

        return Inertia::render('portal/account/index', [
            ...$this->portal->shared($customer),
        ]);
    }
}
