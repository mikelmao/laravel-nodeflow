<?php

namespace Nodeflow\Console;

use Closure;
use InvalidArgumentException;

/** A structurally validated, non-mutating provider registration proposal. */
final readonly class NodeRegistrationPlan
{
    /** @param Closure(string, string): void|null $validator */
    public function __construct(
        public NodeRegistrationOutcome $outcome,
        public ?string $path = null,
        public ?string $contents = null,
        private ?Closure $validator = null,
        public ?string $originalContents = null,
    ) {}

    public function validate(string $contents, string $path): void
    {
        if ($this->validator === null || $this->path !== $path) {
            throw new InvalidArgumentException('This registration plan cannot validate the requested provider.');
        }

        ($this->validator)($contents, $path);
    }

    public function requiresManualRegistration(): bool
    {
        return in_array($this->outcome, [
            NodeRegistrationOutcome::ProviderMissing,
            NodeRegistrationOutcome::AnchorMissing,
            NodeRegistrationOutcome::AnchorAmbiguous,
        ], true);
    }
}
