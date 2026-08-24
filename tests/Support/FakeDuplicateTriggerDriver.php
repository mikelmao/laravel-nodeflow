<?php

namespace Tests\Support;

use Nodeflow\Contracts\TriggerDriver;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Triggers\TriggerActivationDescriptor;

class FakeDuplicateTriggerDriver implements TriggerDriver
{
    public static function key(): string
    {
        return 'test.fake';
    }

    public function sourceRegistered(TriggerSource $source): void {}

    public function validate(TriggerActivationDescriptor $descriptor): array
    {
        return [];
    }
}
