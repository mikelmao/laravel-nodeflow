<?php

namespace Nodeflow\Triggers\ModelObserver;

use Nodeflow\Contracts\TriggerSource;

interface ModelObserverTriggerSource extends TriggerSource
{
    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    public static function modelClass(): string;
}
