<?php

namespace App\Http\Controllers\Hosted;

use App\Actions\PaymentLinks\StartPaymentLinkCheckout;
use App\Http\Controllers\Controller;
use App\Models\PaymentLink;
use App\Models\Subscription;
use App\Services\Gateways\GatewayException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

class PaymentLinkController extends Controller
{
    public function show(Request $request, string $publicId): Response
    {
        $paymentLink = $this->findPaymentLink($publicId);

        return Inertia::render('hosted/payment-link', [
            'paymentLink' => $paymentLink->toHostedArray(),
            'prefill' => [
                'email' => $request->filled('email') ? $request->string('email')->trim()->toString() : '',
                'name' => $request->filled('name') ? $request->string('name')->trim()->toString() : '',
            ],
            'checkoutError' => session('checkoutError'),
            'checkoutSuccess' => session('checkoutSuccess'),
        ]);
    }

    public function checkout(
        Request $request,
        string $publicId,
        StartPaymentLinkCheckout $startCheckout,
    ): RedirectResponse|HttpFoundationResponse {
        $paymentLink = $this->findPaymentLink($publicId);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $result = $startCheckout->handle($paymentLink, $validated);
        } catch (InvalidArgumentException|GatewayException $exception) {
            return back()->with('checkoutError', $this->friendlyError($exception));
        }

        // A free trial has no gateway leg — there is nothing to pay today, so
        // the customer stays here and is told the trial is running.
        if (! $result->needsPayment()) {
            return back()->with('checkoutSuccess', $this->trialStartedMessage($result->subscription));
        }

        return Inertia::location($result->checkoutLink);
    }

    /**
     * Confirm a trial that started without a payment, naming the date billing
     * actually begins so "free trial" isn't left to mean an unbounded freebie.
     */
    private function trialStartedMessage(?Subscription $subscription): string
    {
        $trialEndsAt = $subscription?->trial_ends_at;

        if ($trialEndsAt === null) {
            return 'Your free trial has started. You have not been charged.';
        }

        return "Your free trial has started — you have not been charged. Billing begins on {$trialEndsAt->format('M j, Y')}, and you can cancel any time before then.";
    }

    private function findPaymentLink(string $publicId): PaymentLink
    {
        return PaymentLink::query()
            ->where('public_id', $publicId)
            ->with(['team.processorConnection', 'product', 'price'])
            ->firstOrFail();
    }

    private function friendlyError(InvalidArgumentException|GatewayException $exception): string
    {
        if ($exception instanceof GatewayException) {
            return match ($exception->reason) {
                'unreachable' => 'Payments are temporarily unavailable. Please try again in a moment.',
                'invalid_credentials' => 'This business needs to reconnect its payment account before checkout can start.',
                default => 'We could not start checkout. Please try again.',
            };
        }

        return $exception->getMessage();
    }
}
