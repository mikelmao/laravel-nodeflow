<?php

namespace Nodeflow\Triggers\LaravelEvent;

use Nodeflow\Contracts\TriggerSource;

interface LaravelEventTriggerSource extends TriggerSource
{
    /** @return class-string */
    public static function eventClass(): string;
}
