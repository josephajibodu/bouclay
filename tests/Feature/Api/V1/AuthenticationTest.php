<?php

use App\Enums\ApiKeyKind;
use App\Enums\ApiKeyMode;
use App\Models\ApiKey;
use App\Models\Team;
use App\Models\User;

test('a valid secret key authenticates api requests', function () {
    ['token' => $token] = apiAuthFixture();

    $this->postJson('/api/v1/customers', [
        'email' => 'ada@example.com',
    ], apiHeaders($token, 'cust-create-1'))
        ->assertCreated()
        ->assertJsonStructure(['data' => ['publicId', 'email'], 'meta' => ['requestId']]);
});

test('missing bearer token returns 401', function () {
    $this->postJson('/api/v1/customers', ['email' => 'ada@example.com'], [
        'Accept' => 'application/json',
        'Idempotency-Key' => 'missing-auth',
    ])->assertUnauthorized()
        ->assertJsonPath('error.code', 'authentication_failed');
});

test('revoked keys are rejected', function () {
    ['token' => $token, 'apiKey' => $apiKey] = apiAuthFixture();
    $apiKey->forceFill(['revoked_at' => now()])->save();

    $this->postJson('/api/v1/customers', [
        'email' => 'revoked@example.com',
    ], apiHeaders($token, 'revoked-key-'.uniqid()))
        ->assertUnauthorized();
});

test('publishable keys are rejected on write endpoints', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $user);

    $generated = ApiKey::generate(ApiKeyMode::Test, ApiKeyKind::Publishable);

    ApiKey::factory()->create([
        'team_id' => $team->id,
        'created_by' => $user->id,
        'mode' => ApiKeyMode::Test,
        'kind' => ApiKeyKind::Publishable,
        'hashed_secret' => $generated['hashedSecret'],
        'last_four' => $generated['lastFour'],
    ]);

    $this->postJson('/api/v1/customers', [
        'email' => 'ada@example.com',
    ], apiHeaders($generated['key'], 'pk-reject'))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'permission_denied');
});
