<?php

namespace Nodeflow\Contracts;

use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerSourceRegistry;

interface TriggerNode
{
    public static function type(): string;

    public function definition(): TriggerDefinition;

    public function driver(): string;

    public function defaultConfig(): array;

    public function validate(array $config, TriggerSourceRegistry $sources): array;

    /**
     * Compile stable activation routing metadata from node configuration.
     *
     * Implementations must be pure and deterministic for the same configuration.
     * Nodeflow invokes this during publication and again when validating a pinned
     * occurrence snapshot before extension or audience code is allowed to run.
     */
    public function compile(array $config): TriggerActivationDescriptor;
}
