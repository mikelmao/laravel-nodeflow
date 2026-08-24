<?php

namespace Nodeflow\Triggers\LaravelEvent;

use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use InvalidArgumentException;
use Nodeflow\Contracts\TriggerDriver;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Triggers\TriggerActivationRepository;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerActivationSnapshot;
use Nodeflow\Triggers\TriggerOccurrence;
use Nodeflow\Triggers\TriggerOccurrenceDispatcher;
use Nodeflow\Triggers\TriggerSourceRegistry;
use ReflectionClass;
use Throwable;

class LaravelEventTriggerDriver implements TriggerDriver
{
    /** @var array<class-string, true> */
    private array $listening = [];

    public function __construct(
        private readonly TriggerSourceRegistry $sources,
        private readonly TriggerActivationRepository $activations,
        private readonly TriggerOccurrenceDispatcher $occurrences,
        private readonly EventDispatcher $events,
    ) {}

    public static function key(): string
    {
        return 'event';
    }

    public function sourceRegistered(TriggerSource $source): void
    {
        if (! $source instanceof LaravelEventTriggerSource) {
            return;
        }

        $eventClass = $source::eventClass();

        if (trim($eventClass) === ''
            || ! class_exists($eventClass)
            || ! (new ReflectionClass($eventClass))->isInstantiable()) {
            throw new InvalidArgumentException(
                "Laravel event trigger source [{$source::key()}] declared invalid event class [{$eventClass}]."
            );
        }

        if (isset($this->listening[$eventClass])) {
            return;
        }

        $this->events->listen($eventClass, function (mixed $event) use ($eventClass): void {
            if (! is_object($event) || $event::class !== $eventClass) {
                return;
            }

            $this->eventDispatched($eventClass, $event);
        });

        $this->listening[$eventClass] = true;
    }

    public function validate(TriggerActivationDescriptor $descriptor): array
    {
        if ($descriptor->driver !== self::key()) {
            return ['driver' => ['The activation descriptor does not use the event driver.']];
        }

        try {
            $source = $this->sources->resolve(self::key(), $descriptor->source);
        } catch (\RuntimeException) {
            return ['source' => ['The Laravel event source is not registered.']];
        }

        if (! $source instanceof LaravelEventTriggerSource) {
            return ['source' => ['The registered source is not a Laravel event trigger source.']];
        }

        return [];
    }

    /** @param  class-string  $eventClass */
    private function eventDispatched(string $eventClass, object $event): void
    {
        /** @var array<int, array{source: LaravelEventTriggerSource, activations: TriggerActivationSnapshot[]}> $deliveries */
        $deliveries = [];

        // Snapshot every source's candidates before allowing extraction or
        // resolution code to run. A source may publish or deactivate flows;
        // those side effects must not move another delivery to a different
        // graph than the graph active at event emission.
        foreach ($this->sources->forDriver(self::key()) as $source) {
            if (! $source instanceof LaravelEventTriggerSource
                || $source::eventClass() !== $eventClass) {
                continue;
            }

            try {
                $candidates = $this->activations->forDriverSource(
                    self::key(),
                    $source::key(),
                );
            } catch (Throwable $e) {
                $this->reportSafely($e);

                continue;
            }

            if ($candidates !== []) {
                $deliveries[] = ['source' => $source, 'activations' => $candidates];
            }
        }

        foreach ($deliveries as $delivery) {
            $source = $delivery['source'];

            try {
                $payload = $source->snapshot($event);

                if ($payload->eventClass !== $eventClass) {
                    throw new InvalidArgumentException(
                        "Laravel event source [{$source::key()}] returned an occurrence for [{$payload->eventClass}] while handling [{$eventClass}]."
                    );
                }
            } catch (Throwable $e) {
                $this->reportSafely($e);

                continue;
            }

            try {
                $this->occurrences->dispatch(new TriggerOccurrence(
                    driver: self::key(),
                    source: $source::key(),
                    payload: $payload,
                    activations: $delivery['activations'],
                ));
            } catch (Throwable) {
                // Occurrence-level setup failures are already reported by the
                // shared dispatcher. One source cannot abort another source's
                // delivery or the host application's event dispatch.
            }
        }
    }

    private function reportSafely(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // Host reporting is outside event delivery and fan-out semantics.
        }
    }
}
