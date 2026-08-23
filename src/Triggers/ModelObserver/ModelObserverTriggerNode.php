<?php

namespace Nodeflow\Triggers\ModelObserver;

use Nodeflow\Schema\Field;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\AbstractTriggerNode;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerSourceRegistry;

class ModelObserverTriggerNode extends AbstractTriggerNode
{
    public static function type(): string
    {
        return 'core.trigger.model_observer';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Model observer')
            ->description('Starts after an allowlisted Eloquent model lifecycle event.')
            ->icon('database')
            ->fields([
                Field::select('source')->required(),
                Field::select('event')->required()->options([
                    'created' => 'Created',
                    'updated' => 'Updated',
                    'deleted' => 'Deleted',
                    'restored' => 'Restored',
                ]),
                Field::multiselect('changed_fields'),
            ]);
    }

    public function driver(): string
    {
        return 'model';
    }

    public function validate(array $config, TriggerSourceRegistry $sources): array
    {
        $errors = $this->validateForSourceType(
            $config,
            $sources,
            ModelObserverTriggerSource::class,
            ['changed_fields.*' => ['string']],
        );

        if (array_key_exists('changed_fields', $config)
            && $config['changed_fields'] !== null
            && ($config['event'] ?? null) !== 'updated') {
            $errors['changed_fields'][] = 'Changed fields may only be configured for the updated event.';
        }

        return $errors;
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        return new TriggerActivationDescriptor(
            driver: 'model',
            source: (string) $config['source'],
            qualifier: (string) $config['event'],
            metadata: ['changed_fields' => array_values($config['changed_fields'] ?? [])],
        );
    }
}
