<?php

namespace Tests\Support;

use Closure;
use Nodeflow\Contracts\TriggerDriver;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Triggers\TriggerActivationDescriptor;

class FakeTriggerDriver implements TriggerDriver
{
    public static ?Closure $onSourceRegistered = null;

    public int $registeredSources = 0;

    public static function key(): string
    {
        return 'test.fake';
    }

    public function sourceRegistered(TriggerSource $source): void
    {
        $this->registeredSources++;

        if (self::$onSourceRegistered !== null) {
            (self::$onSourceRegistered)($source, $this);
        }
    }

    public function validate(TriggerActivationDescriptor $descriptor): array
    {
        return [];
    }
}
