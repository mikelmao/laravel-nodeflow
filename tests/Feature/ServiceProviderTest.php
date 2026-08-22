<?php

it('boots and publishes config', function () {
    expect(config('nodeflow.tables.prefix'))->toBe('nodeflow_');
});

it('registers the node packaging commands', function () {
    expect(\Illuminate\Support\Facades\Artisan::all())->toHaveKeys([
        'nodeflow:make-node-package',
        'nodeflow:extract-node',
    ]);
});
