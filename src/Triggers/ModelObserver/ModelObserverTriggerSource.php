<?php

namespace Nodeflow\Triggers\ModelObserver;

use Nodeflow\Contracts\TriggerSource as SourceContract;

interface ModelObserverTriggerSource extends SourceContract
{
    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    public static function modelClass(): string;
}
