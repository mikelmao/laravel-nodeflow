<?php

namespace Nodeflow\Triggers\Webhook;

use Nodeflow\Schema\Field;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\AbstractTriggerNode;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerSourceRegistry;

class WebhookTriggerNode extends AbstractTriggerNode
{
    protected function sourceType(): string
    {
        return WebhookTriggerSource::class;
    }

    public static function type(): string
    {
        return 'core.trigger.webhook';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Webhook')
            ->description('Starts when an allowlisted webhook source receives a request.')
            ->icon('webhook')
            ->fields([
                Field::select('source')->required(),
            ]);
    }

    public function driver(): string
    {
        return WebhookTriggerDriver::key();
    }

    public function validate(array $config, TriggerSourceRegistry $sources): array
    {
        return $this->validateForSourceType($config, $sources);
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        $metadata = $config;
        unset($metadata['source']);

        return new TriggerActivationDescriptor(
            driver: $this->driver(),
            source: (string) $config['source'],
            qualifier: null,
            metadata: $metadata,
        );
    }
}
