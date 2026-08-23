<?php

namespace Nodeflow\Contracts;

use Nodeflow\Triggers\TriggerActivationDescriptor;

interface TriggerDriver
{
    public static function key(): string;

    public function sourceRegistered(TriggerSource $source): void;

    public function validate(TriggerActivationDescriptor $descriptor): array;
}
