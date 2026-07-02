<?php

use App\Enums\BusinessType;
use App\Models\User;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'password' => 'password',
        'business_name' => 'Acme Inc',
        'business_type' => 'individual',
        'website' => 'https://acme.test',
        'country' => 'NG',
        'line1' => '1 Broad Street',
        'line2' => null,
        'city' => 'Lagos',
        'postal_code' => '100001',
    ]);

    $this->assertAuthenticated();

    $user = User::where('email', 'test@example.com')->first();
    $response->assertRedirect(route('dashboard'));

    expect($user->first_name)->toBe('Test');
    expect($user->last_name)->toBe('User');

    $team = $user->currentTeam;
    expect($team->name)->toBe('Acme Inc');
    expect($team->business_type)->toBe(BusinessType::Individual);
    expect($team->country)->toBe('NG');
});
