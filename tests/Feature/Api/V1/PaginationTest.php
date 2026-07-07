<?php

use App\Models\Customer;

test('unknown pagination cursor returns structured 400', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    Customer::factory()->for($team)->create();

    $this->getJson('/api/v1/customers?after=prc_unknown_cursor', apiHeaders($token))
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'invalid_cursor');
});

test('using after and before together returns structured 400', function () {
    ['token' => $token, 'team' => $team] = apiAuthFixture();
    $customer = Customer::factory()->for($team)->create();

    $this->getJson('/api/v1/customers?after='.$customer->public_id.'&before='.$customer->public_id, apiHeaders($token))
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'invalid_cursor');
});
