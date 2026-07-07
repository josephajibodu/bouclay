<?php

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Price;
use App\Models\Product;

test('one off invoices can be created and voided via api', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);
    $product = Product::factory()->for($team)->create();
    $price = Price::factory()->for($team)->for($product)->create(['currency' => 'NGN']);

    $create = $this->postJson('/api/v1/invoices', [
        'customer' => $customer->public_id,
        'collectionMode' => 'manual',
        'items' => [
            ['priceId' => $price->public_id, 'quantity' => 1],
        ],
    ], apiHeaders($token, 'inv-create-1'));

    $create->assertCreated();
    $invoiceId = $create->json('data.publicId');

    $this->getJson('/api/v1/invoices', apiHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/invoices/{$invoiceId}/void", [], apiHeaders($token, 'inv-void-1'))
        ->assertOk()
        ->assertJsonPath('data.status', InvoiceStatus::Void->value);
});
