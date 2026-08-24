<?php

namespace Tests\Support;

use Closure;
use InvalidArgumentException;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\LaravelEvent\LaravelEventOccurrence;
use Nodeflow\Triggers\LaravelEvent\LaravelEventTriggerDriver;
use Nodeflow\Triggers\LaravelEvent\LaravelEventTriggerSource;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;

final class OrderPlacedAcrossTenants
{
    /**
     * @param  array<string, array{users: string[], total: int}>  $deliveries
     */
    public function __construct(
        public string $eventId,
        public array $deliveries,
        public mixed $unsafeState = null,
    ) {}
}

class OrderPlacedEventSource implements LaravelEventTriggerSource
{
    public static ?Closure $resolver = null;

    public static ?Closure $snapshotter = null;

    /** @var LaravelEventOccurrence[] */
    public static array $occurrences = [];

    public static int $snapshots = 0;

    public static function key(): string
    {
        return 'test.order_placed';
    }

    public static function driver(): string
    {
        return LaravelEventTriggerDriver::key();
    }

    public static function eventClass(): string
    {
        return OrderPlacedAcrossTenants::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Order placed')
            ->fields([
                Field::number('minimum_total')->default(0),
            ]);
    }

    public function snapshot(object $event): LaravelEventOccurrence
    {
        if (! $event instanceof OrderPlacedAcrossTenants) {
            throw new InvalidArgumentException('Expected an order-placed event.');
        }

        self::$snapshots++;

        if (self::$snapshotter !== null) {
            return (self::$snapshotter)($event);
        }

        return new LaravelEventOccurrence(
            eventClass: $event::class,
            data: [
                'event_id' => $event->eventId,
                'deliveries' => $event->deliveries,
            ],
        );
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        if (! $occurrence->payload instanceof LaravelEventOccurrence) {
            throw new InvalidArgumentException('Expected a Laravel event occurrence.');
        }

        self::$occurrences[] = $occurrence->payload;

        if (self::$resolver !== null) {
            return (self::$resolver)($occurrence->payload, $config);
        }

        $match = TriggerMatch::make();

        foreach ($occurrence->payload->data['deliveries'] as $tenantId => $delivery) {
            if ($delivery['total'] < ($config['minimum_total'] ?? 0)) {
                continue;
            }

            $match = $match->forTenant(
                (string) $tenantId,
                'user',
                $delivery['users'],
                ['total' => $delivery['total']],
                (string) $occurrence->payload->data['event_id'],
            );
        }

        return $match;
    }

    public static function reset(): void
    {
        self::$resolver = null;
        self::$snapshotter = null;
        self::$occurrences = [];
        self::$snapshots = 0;
    }
}
