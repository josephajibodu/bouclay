<?php

use App\Enums\PaymentFailureCode;
use App\Services\Gateways\CardNetworkDeclines;
use App\Services\Gateways\GatewayManager;

/*
|--------------------------------------------------------------------------
| Decline mapping (IMPLEMENTATION_V2 §V2-4b)
|--------------------------------------------------------------------------
|
| Every driver maps its own decline vocabulary onto Bouclay's failure codes,
| so `subscriptions:process-dunning` behaves identically whichever gateway
| minted the token. The hard/soft split is what's actually at stake: a soft
| decline is retried on the team's schedule, a hard one skips straight to the
| terminal action.
*/

it('knows which codes are worth retrying', function () {
    expect(PaymentFailureCode::InsufficientFunds->isHard())->toBeFalse()
        ->and(PaymentFailureCode::GenericDecline->isHard())->toBeFalse()
        ->and(PaymentFailureCode::ProcessingError->isHard())->toBeFalse();

    // These never recover — retrying burns processor goodwill for nothing.
    expect(PaymentFailureCode::CardExpired->isHard())->toBeTrue()
        ->and(PaymentFailureCode::StolenCard->isHard())->toBeTrue()
        ->and(PaymentFailureCode::LostCard->isHard())->toBeTrue()
        ->and(PaymentFailureCode::InvalidCard->isHard())->toBeTrue()
        ->and(PaymentFailureCode::Fraudulent->isHard())->toBeTrue()
        ->and(PaymentFailureCode::TransactionNotPermitted->isHard())->toBeTrue();
});

it('treats an unknown or missing stored code as soft', function () {
    // Refusing to retry on a guess strands a subscription the customer would
    // have paid; the costlier mistake by far.
    expect(PaymentFailureCode::isHardCode(null))->toBeFalse()
        ->and(PaymentFailureCode::isHardCode('something_from_the_future'))->toBeFalse()
        ->and(PaymentFailureCode::isHardCode('stolen_card'))->toBeTrue();
});

it('maps the card networks’ own language', function (string $reason, PaymentFailureCode $expected) {
    expect((new CardNetworkDeclines)->classify($reason))->toBe($expected);
})->with([
    ['Insufficient funds', PaymentFailureCode::InsufficientFunds],
    ['Not sufficient funds in account', PaymentFailureCode::InsufficientFunds],
    ['Expired Card', PaymentFailureCode::CardExpired],
    ['Invalid card number', PaymentFailureCode::InvalidCard],
    ['No card record', PaymentFailureCode::InvalidCard],
    ['Stolen card', PaymentFailureCode::StolenCard],
    ['Lost card', PaymentFailureCode::LostCard],
    ['Suspected Fraud', PaymentFailureCode::Fraudulent],
    ['Pick up card', PaymentFailureCode::Fraudulent],
    ['Transaction not permitted', PaymentFailureCode::TransactionNotPermitted],
    ['Restricted card', PaymentFailureCode::TransactionNotPermitted],
    ['Timeout while processing', PaymentFailureCode::ProcessingError],
    ['Some phrasing nobody has seen before', PaymentFailureCode::GenericDecline],
    ['', PaymentFailureCode::GenericDecline],
]);

it('reads both spellings of do-not-honour', function () {
    // Paystack says "Do not honor", Flutterwave says "Do Not Honour". The same
    // issuer decision must not classify two different ways.
    $declines = new CardNetworkDeclines;

    expect($declines->classify('Do not honor'))->toBe(PaymentFailureCode::Fraudulent)
        ->and($declines->classify('Do Not Honour'))->toBe(PaymentFailureCode::Fraudulent);
});

it('prefers the more specific rule when a message matches two', function () {
    // "lost/stolen" contains "stolen"; the lost rule has to win, or the
    // needle would be dead config.
    expect((new CardNetworkDeclines)->classify('Lost/Stolen card'))->toBe(PaymentFailureCode::LostCard);
});

it('classifies the same issuer decline identically on every gateway', function (string $reason, PaymentFailureCode $expected) {
    // This is the point of the whole exercise: dunning must not depend on
    // which gateway happened to mint the card.
    $manager = app(GatewayManager::class);

    foreach (['nomba', 'paystack', 'flutterwave'] as $processor) {
        expect($manager->driver($processor)->classifyDecline($reason))
            ->toBe($expected, "{$processor} disagreed about “{$reason}”");
    }
})->with([
    ['Insufficient funds', PaymentFailureCode::InsufficientFunds],
    ['Expired card', PaymentFailureCode::CardExpired],
    ['Lost card', PaymentFailureCode::LostCard],
    ['Suspected fraud', PaymentFailureCode::Fraudulent],
    ['Declined', PaymentFailureCode::GenericDecline],
]);

it('lets a driver map phrasing that is its own, not the network’s', function () {
    $manager = app(GatewayManager::class);

    // Flutterwave's wording for a card that won't accept this transaction.
    expect($manager->driver('flutterwave')->classifyDecline('Transaction not permitted to cardholder'))
        ->toBe(PaymentFailureCode::TransactionNotPermitted);

    // Paystack treats a wrong PIN as the card refusing the transaction — and
    // a stored-card renewal has no PIN to correct, so it never recovers.
    expect($manager->driver('paystack')->classifyDecline('Invalid PIN'))
        ->toBe(PaymentFailureCode::TransactionNotPermitted);

    // Paystack's generic house phrasing must not be mistaken for fraud.
    expect($manager->driver('paystack')->classifyDecline('Declined by financial institution'))
        ->toBe(PaymentFailureCode::GenericDecline);
});

it('falls back to a soft generic decline on wording no driver recognises', function () {
    $manager = app(GatewayManager::class);

    foreach (['nomba', 'paystack', 'flutterwave'] as $processor) {
        expect($manager->driver($processor)->classifyDecline('¯\_(ツ)_/¯'))
            ->toBe(PaymentFailureCode::GenericDecline);
    }
});
