<?php

namespace Nodeflow\Console;

use InvalidArgumentException;

final readonly class RecoveryIdentityResult
{
    private function __construct(
        public RecoveryIdentityStatus $status,
        public ?string $path = null,
        public ?string $reason = null,
    ) {}

    public static function found(string $path): self
    {
        return new self(RecoveryIdentityStatus::Found, path: $path);
    }

    public static function absent(): self
    {
        return new self(RecoveryIdentityStatus::Absent);
    }

    public static function inconclusive(string $reason): self
    {
        return new self(RecoveryIdentityStatus::Inconclusive, reason: $reason);
    }

    public function foundPath(): string
    {
        if ($this->status !== RecoveryIdentityStatus::Found || $this->path === null) {
            throw new InvalidArgumentException('A recovery identity result does not contain a found path.');
        }

        return $this->path;
    }
}
