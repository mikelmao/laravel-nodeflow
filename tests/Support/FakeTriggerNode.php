<?php

namespace Tests\Support;

use Nodeflow\Schema\Field;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\AbstractTriggerNode;
use Nodeflow\Triggers\TriggerActivationDescriptor;

class FakeTriggerNode extends AbstractTriggerNode
{
    public static function type(): string
    {
        return 'test.trigger';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Fake trigger')
            ->icon('bolt')
            ->fields([Field::text('source')->required()]);
    }

    public function driver(): string
    {
        return 'test.fake';
    }

    public function defaultConfig(): array
    {
        return ['source' => 'test.source'];
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        $source = (string) $config['source'];
        unset($config['source']);

        return new TriggerActivationDescriptor(
            driver: 'test.fake',
            source: $source,
            qualifier: null,
            metadata: $config,
        );
    }
}
