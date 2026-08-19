<?php

namespace Nodeflow\Schema;

use Closure;

class SubjectAttribute
{
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        private Closure $resolver,
    ) {}

    public static function make(string $key, string $label, string $type, callable $resolver): self
    {
        return new self($key, $label, $type, Closure::fromCallable($resolver));
    }

    public function value(mixed $subject): mixed
    {
        return ($this->resolver)($subject);
    }
}
