<?php

namespace App\Http\Controllers\Hosted;

use App\Actions\PaymentLinks\StartPaymentLinkCheckout;
use App\Exceptions\Nomba\NombaConnectionException;
use App\Http\Controllers\Controller;
use App\Models\PaymentLink;
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
            $checkoutLink = $startCheckout->handle($paymentLink, $validated);
        } catch (InvalidArgumentException|NombaConnectionException $exception) {
            return back()->with('checkoutError', $this->friendlyError($exception));
        }

        return Inertia::location($checkoutLink);
    }

    private function findPaymentLink(string $publicId): PaymentLink
    {
        return PaymentLink::query()
            ->where('public_id', $publicId)
            ->with(['team.processorConnection', 'product', 'price'])
            ->firstOrFail();
    }

    private function friendlyError(InvalidArgumentException|NombaConnectionException $exception): string
    {
        if ($exception instanceof NombaConnectionException) {
            return match ($exception->reason) {
                'unreachable' => 'Payments are temporarily unavailable. Please try again in a moment.',
                'invalid_credentials' => 'This business needs to reconnect its payment account before checkout can start.',
                default => 'We could not start checkout. Please try again.',
            };
        }

        return $exception->getMessage();
    }
}
