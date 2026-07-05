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

test('it returns null when a connection exists but neither mode is connected', function () {
    $team = Team::factory()->create();
    TeamProcessorConnection::factory()->for($team)->create();

    expect($this->resolver->resolve($team))->toBeNull();
});

test('it returns test when only test is connected', function () {
    $team = Team::factory()->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();

    expect($this->resolver->resolve($team))->toBe(ApiKeyMode::Test);
});

test('it returns live when only live is connected', function () {
    $team = Team::factory()->create();
    TeamProcessorConnection::factory()->for($team)->liveConnected()->create();

    expect($this->resolver->resolve($team))->toBe(ApiKeyMode::Live);
});

test('it prefers live when both modes are connected', function () {
    $team = Team::factory()->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->liveConnected()->create();

    expect($this->resolver->resolve($team))->toBe(ApiKeyMode::Live);
});
