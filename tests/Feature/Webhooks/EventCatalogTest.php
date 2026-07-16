<?php

use App\Enums\OutboundEventType;
use App\Models\Customer;
use App\Models\Event;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;

/*
|--------------------------------------------------------------------------
| The outbound event catalog (IMPLEMENTATION_V2 §V2-6)
|--------------------------------------------------------------------------
|
| One `*.created` per object, one `*.updated` reused for every subsequent
| change. This is the integrator-facing contract, and it ships as one atomic
| break before anyone depends on it — so the shape is pinned here rather than
| left to whoever adds the next event.
*/

it('is exactly created/updated pairs, and nothing else', function () {
    $names = collect(OutboundEventType::cases())->map(fn ($case) => $case->value);

    // A name like `invoice.paid` would pass a naive "contains a dot" check;
    // the point is that the suffix is only ever created/updated.
    $names->each(function (string $name) {
        expect($name)->toMatch('/^[a-z_]+\.(created|updated)$/');
    });

    // Every object has BOTH halves — a `.created` with no `.updated` means
    // integrators can't track the object, and the reverse is worse.
    $names->groupBy(fn (string $name) => str($name)->before('.')->value())
        ->each(function ($pair, $object) {
            expect($pair->sort()->values()->all())
                ->toBe(["{$object}.created", "{$object}.updated"], "{$object} is missing half its pair");
        });
});

it('covers every object the catalog promises', function () {
    $objects = collect(OutboundEventType::cases())
        ->map(fn (OutboundEventType $case) => $case->object())
        ->unique()
        ->sort()
        ->values()
        ->all();

    // schema.md §9's table, minus `payment`. Deferred deliberately: a payment
    // already rides on `invoice.updated` as `data.object.payment`, so
    // `payment.*` would announce the same occurrence twice. Add it here the
    // day a payment can change without its invoice changing.
    expect($objects)->toBe([
        'customer',
        'invoice',
        'payment_method',
        'plan',
        'product',
        'subscription',
    ]);
});

it('has no name the old convention would have used', function () {
    $names = collect(OutboundEventType::cases())->map(fn ($case) => $case->value)->all();

    // The rename this phase exists for. `transaction.*` is here because
    // "Transaction is not a Bouclay entity" applies to event names too.
    foreach ([
        'invoice.paid',
        'invoice.payment_failed',
        'payment_method.added',
        'subscription.canceled',
        'subscription.renewed',
        'transaction.created',
        'transaction.updated',
    ] as $forbidden) {
        expect($names)->not->toContain($forbidden);
    }
});

it('emits a created and an updated for a customer', function () {
    $fx = naijaStreamFixture();

    $customer = Customer::factory()->for($fx['team'])->create();

    expect(eventTypesFor($fx['team']->id))->toContain('customer.created');

    $customer->update(['name' => 'Renamed']);
    expect(eventTypesFor($fx['team']->id))->toContain('customer.updated');
});

it('treats archiving a customer as an update, not a name of its own', function () {
    $fx = naijaStreamFixture();
    $customer = Customer::factory()->for($fx['team'])->create();

    Event::query()->delete();

    $customer->delete();

    // Archiving is a soft delete, so it's a status change on an object the
    // integrator already knows — not `customer.archived`.
    $events = Event::query()->where('team_id', $fx['team']->id)->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->type)->toBe(OutboundEventType::CustomerUpdated)
        ->and($events->first()->data['object']['status'])->toBe('archived');
});

it('emits a created and an updated for a product', function () {
    $fx = naijaStreamFixture();

    Event::query()->delete();

    $product = Product::factory()->for($fx['team'])->create(['name' => 'Docs']);

    $created = Event::query()->where('type', OutboundEventType::ProductCreated)->firstOrFail();

    // A freshly created row relies on the column default for status, so this
    // also pins that the payload knows its own status without a round trip.
    expect($created->data['object']['name'])->toBe('Docs')
        ->and($created->data['object']['status'])->toBe('active');

    $product->update(['status' => 'archived']);

    $updated = Event::query()->where('type', OutboundEventType::ProductUpdated)->firstOrFail();

    expect($updated->data['object']['status'])->toBe('archived');
});

it('emits a created and an updated for a plan', function () {
    $fx = naijaStreamFixture();

    Event::query()->delete();

    $plan = Plan::factory()->for($fx['team'])->for($fx['naijastream'])->create(['name' => 'Basic']);

    $created = Event::query()->where('type', OutboundEventType::PlanCreated)->firstOrFail();

    expect($created->data['object']['name'])->toBe('Basic')
        ->and($created->data['object']['status'])->toBe('active')
        // A plan is meaningless without knowing what it's a plan for.
        ->and($created->data['object']['product']['id'])->toBe($fx['naijastream']->public_id);

    $plan->update(['name' => 'Starter']);

    expect(Event::query()->where('type', OutboundEventType::PlanUpdated)->count())->toBe(1);
});

it('emits payment_method.updated when a card is removed', function () {
    $fx = naijaStreamFixture();
    $card = PaymentMethod::factory()->for($fx['team'])->for($fx['amina'])->create();

    Event::query()->delete();

    $card->delete();

    // A card going away matters to an integrator as much as one arriving.
    expect(eventTypesFor($fx['team']->id))->toBe(['payment_method.updated']);
});

/**
 * @return list<string>
 */
function eventTypesFor(int $teamId): array
{
    return Event::query()
        ->where('team_id', $teamId)
        ->pluck('type')
        ->map(fn ($type) => $type instanceof OutboundEventType ? $type->value : (string) $type)
        ->unique()
        ->values()
        ->all();
}
