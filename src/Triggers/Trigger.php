<?php

namespace Nodeflow\Triggers;

use Nodeflow\Schema\TriggerDefinition;

abstract class Trigger
{
    abstract public static function type(): string;

    /** The host event class this trigger listens to. */
    abstract public static function event(): string;

    abstract public function definition(): TriggerDefinition;

    abstract public function resolve(object $event): TriggerMatch;

    /**
     * A stable identity for one firing, used for run idempotency. Override when
     * the event carries a natural id, e.g. "alert-218".
     */
    public function idempotencyKey(object $event): ?string
    {
        return null;
    }

    /** Does this flow's trigger config match this event? */
    public function matchesConfig(object $event, array $config): bool
    {
        return true;
    }
}
