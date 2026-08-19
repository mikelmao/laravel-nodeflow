<?php

namespace Nodeflow\Publishing;

use RuntimeException;

class GraphInvalidException extends RuntimeException
{
    public function __construct(private array $errors)
    {
        parent::__construct('The flow could not be published: '.implode(' ', $errors));
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
