<?php

use Nodeflow\Execution\NodeActivityPolicy;
use Tests\Support\FakeRetryingAudienceNode;
use Workflow\V2\Support\ActivityOptions;

it('creates activity options from a node policy snapshot', function () {
    $policy = NodeActivityPolicy::fromNode(new FakeRetryingAudienceNode);

    expect($policy->toArray())->toBe([
        'snapshot_version' => 1,
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
        'snapshot_version' => 1,
        'max_attempts' => 3,
        'backoff' => [1, 2, 5, 10, 15, 30, 60, 120],
        'start_to_close_timeout' => null,
        'non_retryable_error_types' => [],
    ]);
});

it('uses stable defaults for unmarked published activity metadata', function (mixed $legacyMetadata) {
    expect(NodeActivityPolicy::fromPublishedSnapshot($legacyMetadata)->toArray())->toBe([
        'snapshot_version' => 1,
        'max_attempts' => 3,
        'backoff' => [1, 2, 5, 10, 15, 30, 60, 120],
        'start_to_close_timeout' => null,
        'non_retryable_error_types' => [],
    ]);
})->with([
    [['max_attempts' => 99]],
    ['legacy author metadata'],
    [['max_attempts' => 'not a policy']],
]);

it('rejects malformed or unsupported marked published snapshots', function (array $snapshot, string $message) {
    expect(fn () => NodeActivityPolicy::fromPublishedSnapshot($snapshot))
        ->toThrow(\InvalidArgumentException::class, $message);
})->with([
    [[
        'snapshot_version' => 1,
        'max_attempts' => 5,
        'backoff' => [1, 5],
        'start_to_close_timeout' => 90,
    ], 'non_retryable_error_types'],
    [[
        'snapshot_version' => 1,
        'max_attempts' => 5,
        'backoff' => [1, 5],
        'start_to_close_timeout' => 90,
        'non_retryable_error_types' => 'not-a-list',
    ], 'non_retryable_error_types'],
    [[
        'snapshot_version' => 2,
        'max_attempts' => 5,
        'backoff' => [1, 5],
        'start_to_close_timeout' => 90,
        'non_retryable_error_types' => [],
    ], 'snapshot_version'],
]);

it('decodes a marked published snapshot without consulting the live class table', function () {
    $suffix = str_replace('.', '', uniqid('', true));
    $exception = 'Nodeflow\\Tests\\RuntimePolicyNonThrowable'.$suffix;
    $snapshot = [
        'snapshot_version' => 1,
        'max_attempts' => 5,
        'backoff' => [1, 5],
        'start_to_close_timeout' => 90,
        'non_retryable_error_types' => [$exception],
    ];

    $before = NodeActivityPolicy::fromPublishedSnapshot($snapshot)->toArray();
    eval('namespace Nodeflow\\Tests; class RuntimePolicyNonThrowable'.$suffix.' {}');
    $after = NodeActivityPolicy::fromPublishedSnapshot($snapshot)->toArray();

    expect($before)->toBe($after);
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

it('rejects a loaded non-throwable non-retryable error type', function () {
    expect(fn () => NodeActivityPolicy::fromArray([
        'non_retryable_error_types' => [\stdClass::class],
    ]))->toThrow(\InvalidArgumentException::class, 'non_retryable_error_types');
});

it('rejects whitespace around non-retryable error type names during authoring validation', function () {
    expect(fn () => NodeActivityPolicy::fromArray([
        'non_retryable_error_types' => [' '.\stdClass::class.' '],
    ]))->toThrow(\InvalidArgumentException::class, 'non_retryable_error_types');
});

it('structurally rejects whitespace around published non-retryable type names', function () {
    expect(fn () => NodeActivityPolicy::fromPublishedSnapshot([
        'snapshot_version' => 1,
        'max_attempts' => 3,
        'backoff' => [1],
        'start_to_close_timeout' => null,
        'non_retryable_error_types' => [' Vendor\\Optional\\Exception '],
    ]))->toThrow(\InvalidArgumentException::class, 'non_retryable_error_types');
});

it('preserves an unavailable host exception class name for runtime matching', function () {
    $exception = 'Vendor\\OptionalHost\\UnavailableException';

    expect(NodeActivityPolicy::fromArray([
        'non_retryable_error_types' => [$exception],
    ])->toArray()['non_retryable_error_types'])->toBe([$exception]);
});

it('accepts a loaded throwable non-retryable error type', function () {
    expect(NodeActivityPolicy::fromArray([
        'non_retryable_error_types' => [\RuntimeException::class],
    ])->toArray()['non_retryable_error_types'])->toBe([\RuntimeException::class]);
});
