<?php

it('boots and publishes config', function () {
    expect(config('nodeflow.tables.prefix'))->toBe('nodeflow_');
});
