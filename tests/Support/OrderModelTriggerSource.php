<?php

namespace Tests\Support;

use Closure;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\ModelObserver\ModelObserverTriggerDriver;
use Nodeflow\Triggers\ModelObserver\ModelObserverTriggerSource;
use Nodeflow\Triggers\ModelObserver\ModelOccurrence;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;
use Tests\Support\Models\ObservedOrder;

class OrderModelTriggerSource implements ModelObserverTriggerSource
{
    public static ?Closure $resolver = null;

    /** @var ModelOccurrence[] */
    public static array $occurrences = [];

    public static function key(): string
    {
        return 'test.observed_orders';
    }

    public static function driver(): string
    {
        return ModelObserverTriggerDriver::key();
    }

    public static function modelClass(): string
    {
        return ObservedOrder::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Observed orders');
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        if (! $occurrence->payload instanceof ModelOccurrence) {
            throw new \InvalidArgumentException('Expected a model occurrence.');
        }

        self::$occurrences[] = $occurrence->payload;

        if (self::$resolver !== null) {
            return (self::$resolver)($occurrence->payload, $config);
        }

        $payload = $occurrence->payload;
        $occurrenceId = hash('sha256', serialize([
            $payload->modelClass,
            $payload->modelKey,
            $payload->event,
            $payload->attributes,
            $payload->original,
            $payload->changedFields,
        ]));

        return TriggerMatch::make()->forTenant(
            (string) $payload->attributes['tenant_id'],
            'user',
            [(string) $payload->attributes['user_id']],
            [
                'event' => $payload->event,
                'status' => $payload->attributes['status'] ?? null,
                'original_status' => $payload->original['status'] ?? null,
                'changed_fields' => $payload->changedFields,
            ],
            $occurrenceId,
        );
    }
}
