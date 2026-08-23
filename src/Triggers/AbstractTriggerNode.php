<?php

namespace Nodeflow\Triggers;

use Illuminate\Support\Facades\Validator;
use Nodeflow\Contracts\TriggerNode;

abstract class AbstractTriggerNode implements TriggerNode
{
    public function defaultConfig(): array
    {
        return [];
    }

    public function validate(array $config, TriggerSourceRegistry $sources): array
    {
        return Validator::make($config, $this->definition()->rules())
            ->errors()
            ->toArray();
    }
}
