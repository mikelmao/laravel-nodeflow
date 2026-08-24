<?php

namespace Tests\Support;

use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;

class FakeTriggerSource implements TriggerSource
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
        return TriggerDefinition::make('Fake source');
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        $payload = $occurrence->payload;
        $occurrenceId = (string) $payload['occurrence_id'];

        return TriggerMatch::make()->forTenant(
            (string) $payload['tenant_id'],
            'user',
            [(string) $payload['subject_id']],
            ['occurrence' => $occurrenceId],
            $occurrenceId,
        );
    }
}
