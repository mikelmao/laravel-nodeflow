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

    public function compile(array $config): TriggerActivationDescriptor;
}
