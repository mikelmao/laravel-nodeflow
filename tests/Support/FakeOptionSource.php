<?php

namespace Tests\Support;

use Nodeflow\Schema\OptionSource;

class FakeOptionSource implements OptionSource
{
    public function options(): array
    {
        return ['welcome' => 'Welcome message', 'reminder' => 'Reminder'];
    }
}
