<?php

namespace Nodeflow\Contracts;

use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;

interface TriggerSource
{
    /** Stable `[a-z][a-z0-9._-]*` routing key, at most 191 characters. */
    public static function key(): string;

    /** Registered driver key using the same stable-key grammar. */
    public static function driver(): string;

    public function definition(): TriggerDefinition;

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch;
}
