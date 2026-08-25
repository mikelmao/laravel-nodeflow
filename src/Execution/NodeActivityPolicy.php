<?php

namespace Nodeflow\Execution;

use InvalidArgumentException;
use Nodeflow\Nodes\Node;
use Workflow\V2\Support\ActivityOptions;

final readonly class NodeActivityPolicy
{
    public const SNAPSHOT_VERSION = 1;

    /**
     * @param int|list<int> $backoff
     * @param list<class-string<\Throwable>> $nonRetryableErrorTypes
     *
     * Loaded symbols must be Throwable types. Unresolved class names are kept
     * deliberately: optional host exception packages need not be installed at
     * publication time, and runtime matching uses the stored name exactly.
     */
    private function __construct(
        public int $maxAttempts,
        public int|array $backoff,
        public ?int $startToCloseTimeout,
        public array $nonRetryableErrorTypes,
    ) {}

    public static function fromNode(Node $node): self
    {
        return self::fromValues(
            maxAttempts: $node->tries,
            backoff: $node->backoff,
            startToCloseTimeout: $node->timeout,
            nonRetryableErrorTypes: $node->nonRetryableErrorTypes,
            validateLoadedThrowableTypes: true,
        );
    }

    public static function fromArray(array $policy): self
    {
        $maxAttempts = array_key_exists('max_attempts', $policy)
            ? $policy['max_attempts']
            : Node::DEFAULT_TRIES;
        $backoff = array_key_exists('backoff', $policy)
            ? $policy['backoff']
            : Node::DEFAULT_BACKOFF;
        $startToCloseTimeout = array_key_exists('start_to_close_timeout', $policy)
            ? $policy['start_to_close_timeout']
            : null;
        $nonRetryableErrorTypes = array_key_exists('non_retryable_error_types', $policy)
            ? $policy['non_retryable_error_types']
            : [];

        return self::fromValues(
            maxAttempts: $maxAttempts,
            backoff: $backoff,
            startToCloseTimeout: $startToCloseTimeout,
            nonRetryableErrorTypes: $nonRetryableErrorTypes,
            validateLoadedThrowableTypes: true,
        );
    }

    /**
     * Decode an activity policy frozen by this package at publication time.
     *
     * Unmarked values predate the reserved runtime snapshot and are author
     * metadata, not executable policy. This method intentionally performs
     * structural validation only: workflow replay must not depend on which
     * optional exception classes happen to be loaded by the current worker.
     */
    public static function fromPublishedSnapshot(mixed $snapshot): self
    {
        if (! is_array($snapshot) || ! array_key_exists('snapshot_version', $snapshot)) {
            return self::defaults();
        }

        if ($snapshot['snapshot_version'] !== self::SNAPSHOT_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported activity policy snapshot_version [%s].',
                is_scalar($snapshot['snapshot_version']) || $snapshot['snapshot_version'] === null
                    ? var_export($snapshot['snapshot_version'], true)
                    : get_debug_type($snapshot['snapshot_version']),
            ));
        }

        foreach ([
            'max_attempts',
            'backoff',
            'start_to_close_timeout',
            'non_retryable_error_types',
        ] as $field) {
            if (! array_key_exists($field, $snapshot)) {
                throw new InvalidArgumentException("Published activity policy snapshot is missing [{$field}].");
            }
        }

        return self::fromValues(
            maxAttempts: $snapshot['max_attempts'],
            backoff: $snapshot['backoff'],
            startToCloseTimeout: $snapshot['start_to_close_timeout'],
            nonRetryableErrorTypes: $snapshot['non_retryable_error_types'],
            validateLoadedThrowableTypes: false,
        );
    }

    /**
     * @return array{
     *     snapshot_version: int,
     *     max_attempts: int,
     *     backoff: int|list<int>,
     *     start_to_close_timeout: int|null,
     *     non_retryable_error_types: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'max_attempts' => $this->maxAttempts,
            'backoff' => $this->backoff,
            'start_to_close_timeout' => $this->startToCloseTimeout,
            'non_retryable_error_types' => $this->nonRetryableErrorTypes,
        ];
    }

    /**
     * @param int|list<int> $backoff
     * @param list<class-string<\Throwable>> $nonRetryableErrorTypes
     */
    private static function fromValues(
        mixed $maxAttempts,
        mixed $backoff,
        mixed $startToCloseTimeout,
        mixed $nonRetryableErrorTypes,
        bool $validateLoadedThrowableTypes,
    ): self {
        self::assertMaxAttempts($maxAttempts);
        self::assertBackoff($backoff);
        self::assertTimeout($startToCloseTimeout);
        self::assertNonRetryableErrorTypes($nonRetryableErrorTypes, $validateLoadedThrowableTypes);

        return new self(
            maxAttempts: $maxAttempts,
            backoff: $backoff,
            startToCloseTimeout: $startToCloseTimeout,
            nonRetryableErrorTypes: $nonRetryableErrorTypes,
        );
    }

    private static function defaults(): self
    {
        return self::fromValues(
            maxAttempts: Node::DEFAULT_TRIES,
            backoff: Node::DEFAULT_BACKOFF,
            startToCloseTimeout: null,
            nonRetryableErrorTypes: [],
            validateLoadedThrowableTypes: false,
        );
    }

    public function activityOptions(): ActivityOptions
    {
        return new ActivityOptions(
            maxAttempts: $this->maxAttempts,
            backoff: $this->backoff,
            startToCloseTimeout: $this->startToCloseTimeout,
            nonRetryableErrorTypes: $this->nonRetryableErrorTypes,
        );
    }

    private static function assertMaxAttempts(mixed $maxAttempts): void
    {
        if (! is_int($maxAttempts) || $maxAttempts < 1) {
            throw new InvalidArgumentException('Activity policy max_attempts must be an integer greater than or equal to 1.');
        }
    }

    private static function assertBackoff(mixed $backoff): void
    {
        if (is_int($backoff)) {
            if ($backoff >= 0) {
                return;
            }

            throw new InvalidArgumentException('Activity policy backoff must be a non-negative integer or a list of non-negative integers.');
        }

        if (! is_array($backoff) || ! array_is_list($backoff)) {
            throw new InvalidArgumentException('Activity policy backoff must be a non-negative integer or a list of non-negative integers.');
        }

        foreach ($backoff as $seconds) {
            if (! is_int($seconds) || $seconds < 0) {
                throw new InvalidArgumentException('Activity policy backoff must be a non-negative integer or a list of non-negative integers.');
            }
        }
    }

    private static function assertTimeout(mixed $timeout): void
    {
        if ($timeout !== null && (! is_int($timeout) || $timeout < 1)) {
            throw new InvalidArgumentException('Activity policy start_to_close_timeout must be null or an integer greater than or equal to 1.');
        }
    }

    private static function assertNonRetryableErrorTypes(
        mixed $errorTypes,
        bool $validateLoadedThrowableTypes,
    ): void
    {
        if (! is_array($errorTypes) || ! array_is_list($errorTypes)) {
            throw new InvalidArgumentException('Activity policy non_retryable_error_types must be a list of non-empty strings.');
        }

        foreach ($errorTypes as $errorType) {
            if (! is_string($errorType) || trim($errorType) === '') {
                throw new InvalidArgumentException('Activity policy non_retryable_error_types must be a list of non-empty strings.');
            }

            if ($validateLoadedThrowableTypes
                && (class_exists($errorType) || interface_exists($errorType))
                && ! is_a($errorType, \Throwable::class, true)) {
                throw new InvalidArgumentException('Activity policy non_retryable_error_types must contain Throwable class names.');
            }
        }
    }
}
