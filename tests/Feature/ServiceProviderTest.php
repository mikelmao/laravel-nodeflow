<?php

it('boots and publishes config', function () {
    expect(config('nodeflow.tables.prefix'))->toBe('nodeflow_');
});

it('registers the node packaging commands', function () {
    expect(\Illuminate\Support\Facades\Artisan::all())->toHaveKeys([
        'nodeflow:make-node-package',
        'nodeflow:extract-node',
        'nodeflow:make-trigger',
        'nodeflow:make-trigger-source',
        'nodeflow:make-trigger-driver',
    ]);
});

it('shares one fact provider registry across the application', function () {
    expect(app(\Nodeflow\Facts\FactProviderRegistry::class))
        ->toBe(app(\Nodeflow\Facts\FactProviderRegistry::class));
});
