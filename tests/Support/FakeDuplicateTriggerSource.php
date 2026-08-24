<?php

namespace Tests\Support;

use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;

class FakeDuplicateTriggerSource implements TriggerSource
{
    public static function key(): string
    {
        return 'test.orders';
    }

    public static function driver(): string
    {
        return 'test.fake';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Duplicate fake source');
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        return TriggerMatch::make();
    }
}
