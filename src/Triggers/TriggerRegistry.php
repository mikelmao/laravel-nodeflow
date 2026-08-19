<?php

namespace Nodeflow\Triggers;

use Illuminate\Support\Facades\Event;
use RuntimeException;

class TriggerRegistry
{
    /** @var array<string, class-string<Trigger>> */
    private array $types = [];

    /** @var array<string, true> event class => a listener has been attached */
    private array $listenedEvents = [];

    /**
     * Registering a trigger attaches its event listener immediately, rather than
     * waiting for NodeflowServiceProvider::boot(). Triggers are often registered
     * from a host provider's own boot() method, which can run after this
     * package's boot() — attaching lazily here means registration order between
     * providers no longer matters. At most one listener is attached per distinct
     * event class: EventTriggerListener::handle() already fans out to every
     * trigger matching the fired event, so two triggers sharing an event() must
     * not each get their own Event::listen — that would run handle() twice per
     * firing and, combined with a null idempotency key, create duplicate runs.
     */
    public function register(string ...$classes): self
    {
        foreach ($classes as $class) {
            $this->types[$class::type()] = $class;

            $eventClass = $class::event();

            if (! isset($this->listenedEvents[$eventClass])) {
                $this->listenedEvents[$eventClass] = true;

                Event::listen($eventClass, fn (object $event) => app(EventTriggerListener::class)->handle($event));
            }
        }

        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function resolve(string $type): Trigger
    {
        if (! isset($this->types[$type])) {
            throw new RuntimeException("Unknown nodeflow trigger type [{$type}].");
        }

        return app($this->types[$type]);
    }

    /** @return array<string, class-string<Trigger>> */
    public function all(): array
    {
        return $this->types;
    }

    /** @return Trigger[] */
    public function forEvent(string $eventClass): array
    {
        return array_values(array_map(
            fn (string $class) => app($class),
            array_filter($this->types, fn (string $class) => $class::event() === $eventClass),
        ));
    }

    public function palette(): array
    {
        return array_values(array_map(function (string $class, string $type) {
            return array_merge(app($class)->definition()->toArray(), ['type' => $type]);
        }, $this->types, array_keys($this->types)));
    }
}
