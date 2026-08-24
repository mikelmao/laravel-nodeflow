<?php

namespace Nodeflow\Triggers\ModelObserver;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonSerializable;
use Nodeflow\Contracts\TriggerDriver;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Triggers\TriggerActivationRepository;
use Nodeflow\Triggers\TriggerActivationSnapshot;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerOccurrence;
use Nodeflow\Triggers\TriggerOccurrenceDispatcher;
use Nodeflow\Triggers\TriggerSourceRegistry;
use Stringable;
use Throwable;

class ModelObserverTriggerDriver implements TriggerDriver
{
    private const EVENTS = ['created', 'updated', 'deleted', 'restored'];

    /** @var array<class-string<Model>, true> */
    private array $listening = [];

    public function __construct(
        private readonly TriggerSourceRegistry $sources,
        private readonly TriggerActivationRepository $activations,
        private readonly TriggerOccurrenceDispatcher $occurrences,
        private readonly EventDispatcher $events,
    ) {}

    public static function key(): string
    {
        return 'model';
    }

    public function sourceRegistered(TriggerSource $source): void
    {
        if (! $source instanceof ModelObserverTriggerSource) {
            // Compatibility is reported by node/descriptor validation. An
            // incompatible extension must never install a model listener.
            return;
        }

        $modelClass = $source::modelClass();

        if (! is_a($modelClass, Model::class, true)) {
            throw new InvalidArgumentException(
                "Model trigger source [{$source::key()}] declared [{$modelClass}], which is not an Eloquent model."
            );
        }

        if (isset($this->listening[$modelClass])) {
            return;
        }

        foreach (self::EVENTS as $event) {
            $this->events->listen(
                "eloquent.{$event}: {$modelClass}",
                function (mixed $model) use ($modelClass, $event): void {
                    if (! $model instanceof $modelClass) {
                        return;
                    }

                    $this->modelEvent($model, $event);
                },
            );
        }

        $this->listening[$modelClass] = true;
    }

    public function validate(TriggerActivationDescriptor $descriptor): array
    {
        if ($descriptor->driver !== self::key()) {
            return ['driver' => ['The activation descriptor does not use the model driver.']];
        }

        try {
            $source = $this->sources->resolve(self::key(), $descriptor->source);
        } catch (\RuntimeException) {
            return ['source' => ['The model source is not registered.']];
        }

        if (! $source instanceof ModelObserverTriggerSource) {
            return ['source' => ['The registered source is not a model observer trigger source.']];
        }

        return [];
    }

    private function modelEvent(Model $model, string $event): void
    {
        try {
            $occurrence = $this->snapshot($model, $event);
            $deliveries = $this->deliveries($occurrence);

            if ($deliveries === []) {
                return;
            }

            $model->getConnection()->afterCommit(function () use ($occurrence, $deliveries): void {
                foreach ($deliveries as $source => $activations) {
                    try {
                        $this->occurrences->dispatch(new TriggerOccurrence(
                            driver: self::key(),
                            source: $source,
                            payload: $occurrence,
                            qualifier: $occurrence->event,
                            activations: $activations,
                        ));
                    } catch (Throwable) {
                        // The shared dispatcher isolates activation failures. A
                        // setup-level failure has already been reported there;
                        // swallowing it here lets another source continue and
                        // keeps it outside model persistence.
                    }
                }
            });
        } catch (Throwable $e) {
            // Trigger infrastructure is observational. A registration, query,
            // snapshot or transaction callback failure cannot veto persistence
            // in the host application's model lifecycle.
            $this->reportSafely($e);
        }
    }

    /**
     * @return array<string, TriggerActivationSnapshot[]>
     */
    private function deliveries(ModelOccurrence $occurrence): array
    {
        $deliveries = [];

        foreach ($this->sources->forDriver(self::key()) as $source) {
            if (! $source instanceof ModelObserverTriggerSource
                || $source::modelClass() !== $occurrence->modelClass) {
                continue;
            }

            $activations = $this->activations->forDriverSource(
                self::key(),
                $source::key(),
                $occurrence->event,
            );

            if ($occurrence->event === 'updated') {
                $activations = array_values(array_filter(
                    $activations,
                    fn (TriggerActivationSnapshot $activation): bool => $this->changedFieldsMatch(
                        $activation,
                        $occurrence,
                    ),
                ));
            }

            if ($activations !== []) {
                $deliveries[$source::key()] = $activations;
            }
        }

        return $deliveries;
    }

    private function changedFieldsMatch(
        TriggerActivationSnapshot $activation,
        ModelOccurrence $occurrence,
    ): bool {
        $configured = $activation->descriptor['changed_fields'] ?? [];

        if (! is_array($configured) || $configured === []) {
            return true;
        }

        foreach ($configured as $field) {
            // Malformed trusted state must reach the pinned-activation
            // validator in the dispatcher rather than being silently skipped
            // (and must not emit array-to-string warnings here).
            if (! is_string($field)
                || in_array($field, $occurrence->changedFields, true)) {
                return true;
            }
        }

        return false;
    }

    private function snapshot(Model $model, string $event): ModelOccurrence
    {
        return new ModelOccurrence(
            modelClass: $model::class,
            modelKey: (string) $model->getKey(),
            connectionName: (string) $model->getConnection()->getName(),
            event: $event,
            attributes: $this->snapshotValues($model->getAttributes()),
            original: $this->snapshotValues($model->getRawOriginal()),
            changedFields: array_values(array_map(
                static fn (mixed $field): string => (string) $field,
                array_keys($model->getChanges()),
            )),
        );
    }

    private function snapshotValues(array $values): array
    {
        $snapshot = [];

        foreach ($values as $key => $value) {
            $snapshot[$key] = $this->snapshotValue($value);
        }

        return $snapshot;
    }

    private function snapshotValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $this->snapshotValues($value);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof JsonSerializable) {
            return $this->snapshotValue($value->jsonSerialize());
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException(
            'Model trigger occurrences may contain only immutable value data.'
        );
    }

    private function reportSafely(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // A host reporter is not part of the model persistence contract.
        }
    }
}
