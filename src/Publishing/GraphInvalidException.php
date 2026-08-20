<?php

namespace Nodeflow\Publishing;

use RuntimeException;

class GraphInvalidException extends RuntimeException
{
    /**
     * @param  string[]  $errors
     * @param  array<int, array{node: ?string, field: ?string, message: string}>  $nodeErrors
     */
    public function __construct(
        private array $errors,
        private array $nodeErrors = [],
    ) {
        parent::__construct('The flow could not be published: '.implode(' ', $errors));
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
}
