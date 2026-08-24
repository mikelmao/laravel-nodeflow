<?php

namespace Nodeflow\Triggers\ModelObserver;

final readonly class ModelOccurrence
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $original
     * @param  string[]  $changedFields
     */
    public function __construct(
        public string $modelClass,
        public string $modelKey,
        public string $connectionName,
        public string $event,
        public array $attributes,
        public array $original,
        public array $changedFields,
    ) {}
}
