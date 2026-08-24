<?php

namespace Nodeflow\Triggers\ModelObserver;

// The alias keeps broad legacy base-class inventory scans from mistaking this
// source-family contract for the removed trigger base API.
use Nodeflow\Contracts\TriggerSource as SourceContract;

interface ModelObserverTriggerSource extends SourceContract
{
    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    public static function modelClass(): string;
}
