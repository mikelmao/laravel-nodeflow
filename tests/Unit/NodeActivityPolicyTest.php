<?php

use Nodeflow\Execution\NodeActivityPolicy;
use Tests\Support\FakeRetryingAudienceNode;
use Workflow\V2\Support\ActivityOptions;

it('creates activity options from a node policy snapshot', function () {
    $policy = NodeActivityPolicy::fromNode(new FakeRetryingAudienceNode);

    expect($policy->toArray())->toBe([
        'max_attempts' => 5,
        'backoff' => [1, 5, 30, 120],
        'start_to_close_timeout' => 90,
        'non_retryable_error_types' => [\InvalidArgumentException::class],
    ]);

    $options = $policy->activityOptions();

    expect($options)->toBeInstanceOf(ActivityOptions::class)
        ->and($options->maxAttempts)->toBe(5)
        ->and($options->backoff)->toBe([1, 5, 30, 120])
        ->and($options->startToCloseTimeout)->toBe(90)
        ->and($options->nonRetryableErrorTypes)->toBe([\InvalidArgumentException::class]);
});

it('uses node defaults for a legacy policy snapshot with no fields', function () {
    expect(NodeActivityPolicy::fromArray([])->toArray())->toBe([
        'max_attempts' => 3,
        'backoff' => [1, 2, 5, 10, 15, 30, 60, 120],
        'start_to_close_timeout' => null,
        'non_retryable_error_types' => [],
    ]);
});

it('rejects invalid max attempts', function (mixed $value) {
    expect(fn () => NodeActivityPolicy::fromArray(['max_attempts' => $value]))
        ->toThrow(\InvalidArgumentException::class, 'max_attempts');
})->with([0, -1, 1.5, '3']);

it('rejects invalid backoff values', function (mixed $value) {
    expect(fn () => NodeActivityPolicy::fromArray(['backoff' => $value]))
        ->toThrow(\InvalidArgumentException::class, 'backoff');
})->with([
    -1,
    1.5,
    '1',
    [[1, '2']],
    [[1, -2]],
    [['first' => 1]],
]);

it('rejects invalid start-to-close timeouts', function (mixed $value) {
    expect(fn () => NodeActivityPolicy::fromArray(['start_to_close_timeout' => $value]))
        ->toThrow(\InvalidArgumentException::class, 'start_to_close_timeout');
})->with([0, -1, 1.5, '90']);

it('rejects invalid non-retryable error type lists', function (mixed $value) {
    expect(fn () => NodeActivityPolicy::fromArray(['non_retryable_error_types' => $value]))
        ->toThrow(\InvalidArgumentException::class, 'non_retryable_error_types');
})->with([
    [['exception' => \InvalidArgumentException::class]],
    [''],
    ['   '],
    [123],
]);
