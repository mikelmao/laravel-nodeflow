<?php

namespace Nodeflow\Triggers\LaravelEvent;

use Nodeflow\Schema\Field;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\AbstractTriggerNode;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerSourceRegistry;

class LaravelEventTriggerNode extends AbstractTriggerNode
{
    public static function type(): string
    {
        return 'core.trigger.laravel_event';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Laravel event')
            ->description('Starts when an allowlisted Laravel event is dispatched.')
            ->icon('bolt')
            ->fields([
                Field::select('source')->required(),
            ]);
    }

    public function driver(): string
    {
        return 'event';
    }

    public function validate(array $config, TriggerSourceRegistry $sources): array
    {
        return $this->validateForSourceType($config, $sources, LaravelEventTriggerSource::class);
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        return new TriggerActivationDescriptor(
            driver: 'event',
            source: (string) $config['source'],
            qualifier: null,
            metadata: [],
        );
    }
}
