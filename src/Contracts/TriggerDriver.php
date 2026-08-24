<?php

namespace Nodeflow\Contracts;

use Nodeflow\Triggers\TriggerActivationDescriptor;

interface TriggerDriver
{
    /** Stable `[a-z][a-z0-9._-]*` routing key, at most 191 characters. */
    public static function key(): string;

    public function sourceRegistered(TriggerSource $source): void;

    public function validate(TriggerActivationDescriptor $descriptor): array;
}
