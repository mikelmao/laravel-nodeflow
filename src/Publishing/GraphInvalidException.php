<?php

namespace Nodeflow\Publishing;

use RuntimeException;

class GraphInvalidException extends RuntimeException
{
    private const INVALID_UTF8_MESSAGE = 'The flow validation produced text that is not valid UTF-8.';

    /** @var string[] */
    private array $errors;

    /** @var array<int, array{node: ?string, field: ?string, message: string}> */
    private array $nodeErrors;

    /**
     * @param  string[]  $errors
     * @param  array<int, array{node: ?string, field: ?string, message: string}>  $nodeErrors
     */
    public function __construct(
        array $errors,
        array $nodeErrors = [],
    ) {
        // Extension-owned routing keys and validation messages can reach the
        // semantic validator before activation compilation. Normalize the
        // exception boundary so a malformed extension string cannot make the
        // controller's structured 422 fail JSON encoding and become a 500.
        $this->errors = array_map(
            fn (mixed $error): string => $this->safeMessage($error),
            $errors,
        );
        $this->nodeErrors = array_map(fn (mixed $entry): array => [
            'node' => $this->safeNullableString(is_array($entry) ? ($entry['node'] ?? null) : null),
            'field' => $this->safeNullableString(is_array($entry) ? ($entry['field'] ?? null) : null),
            'message' => $this->safeMessage(is_array($entry) ? ($entry['message'] ?? null) : null),
        ], $nodeErrors);

        parent::__construct('The flow could not be published: '.implode(' ', $this->errors));
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The same failures, each pinned to its node where one is known, so an editor
     * can render them on the canvas instead of as one wall of text.
     *
     * @return array<int, array{node: ?string, field: ?string, message: string}>
     */
    public function nodeErrors(): array
    {
        return $this->nodeErrors;
    }

    private function safeMessage(mixed $value): string
    {
        return is_string($value) && preg_match('//u', $value) === 1
            ? $value
            : self::INVALID_UTF8_MESSAGE;
    }

    private function safeNullableString(mixed $value): ?string
    {
        return is_string($value) && preg_match('//u', $value) === 1
            ? $value
            : null;
    }
}
