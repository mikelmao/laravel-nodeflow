<?php

namespace Nodeflow\Contracts;

use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;

interface TriggerSource
{
    public static function key(): string;

    public static function driver(): string;

    public function definition(): TriggerDefinition;

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch;
}
