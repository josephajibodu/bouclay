<?php

use App\Enums\ApiKeyMode;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Services\Nomba\NombaModeResolver;

beforeEach(function () {
    $this->resolver = app(NombaModeResolver::class);
});

test('it returns null when no connection exists', function () {
    $team = Team::factory()->create();

    expect($this->resolver->resolve($team))->toBeNull();
});

test('it returns null when a connection exists but the configured mode is not connected', function () {
    config(['services.nomba.mode' => 'live']);

    $team = Team::factory()->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();

    expect($this->resolver->resolve($team))->toBeNull();
});

test('it returns test when the deployment is configured for test and test is connected', function () {
    config(['services.nomba.mode' => 'test']);

    $team = Team::factory()->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();

    expect($this->resolver->resolve($team))->toBe(ApiKeyMode::Test);
});

test('it returns live when the deployment is configured for live and live is connected', function () {
    config(['services.nomba.mode' => 'live']);

    $team = Team::factory()->create();
    TeamProcessorConnection::factory()->for($team)->liveConnected()->create();

    expect($this->resolver->resolve($team))->toBe(ApiKeyMode::Live);
});

test('configured mode defaults to live', function () {
    config(['services.nomba.mode' => null]);

    expect($this->resolver->configuredMode())->toBe(ApiKeyMode::Live);
});

test('it ignores a connected mode that does not match the deployment configuration', function () {
    config(['services.nomba.mode' => 'test']);

    $team = Team::factory()->create();
    TeamProcessorConnection::factory()->for($team)->liveConnected()->create();

    expect($this->resolver->resolve($team))->toBeNull();
});
