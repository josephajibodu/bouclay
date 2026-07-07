<?php

test('api docs are publicly reachable from the website', function () {
    $this->get('/docs/api')
        ->assertSuccessful()
        ->assertSee('Bouclay API Docs')
        ->assertSee('redoc')
        ->assertSee('/docs/api/openapi.yaml');
});

test('openapi contract is publicly served for the docs renderer', function () {
    $this->get('/docs/api/openapi.yaml')
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/yaml; charset=UTF-8')
        ->assertSee('openapi: 3.1.0')
        ->assertSee('/subscriptions');
});
