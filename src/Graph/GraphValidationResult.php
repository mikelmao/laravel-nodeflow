<?php

namespace Nodeflow\Graph;

class GraphValidationResult
{
    public function __construct(
        private array $errors = [],
        private array $warnings = [],
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function warnings(): array
    {
        return $this->warnings;
    }
}
