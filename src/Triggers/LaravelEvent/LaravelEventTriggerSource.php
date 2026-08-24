<?php

namespace Nodeflow\Triggers\LaravelEvent;

use Nodeflow\Contracts\TriggerSource as SourceContract;

interface LaravelEventTriggerSource extends SourceContract
{
    /** @return class-string */
    public static function eventClass(): string;

    /**
     * Extract an immutable, value-only snapshot from the allowlisted event.
     * Nodeflow deliberately does not inspect or serialize the event object.
     */
    public function snapshot(object $event): LaravelEventOccurrence;
}
