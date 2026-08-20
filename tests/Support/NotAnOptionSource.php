<?php

namespace Tests\Support;

/**
 * Deliberately implements nothing. Used to prove a field declaring a class that is
 * not an OptionSource fails loudly rather than yielding an empty select.
 */
class NotAnOptionSource
{
    public function options(): array
    {
        return ['sneaky' => 'Should never be reached'];
    }
}
