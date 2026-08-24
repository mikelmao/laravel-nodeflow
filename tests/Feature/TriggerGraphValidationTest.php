<?php

use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Graph\GraphValidator;
use Nodeflow\Nodeflow;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;

class ConfiguredGraphTriggerSource implements TriggerSource
{
    public static function key(): string
    {
        return 'test.configured-orders';
    }

    public static function driver(): string
    {
        return 'test.fake';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Configured orders')
            ->fields([Field::text('account')->required()]);
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        return TriggerMatch::make();
    }
}

class CollidingGraphTriggerSource implements TriggerSource
{
    public static function key(): string
    {
        return 'test.colliding-orders';
    }

    public static function driver(): string
    {
        return 'test.fake';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Colliding orders')
            ->fields([Field::text('source')->required()]);
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        return TriggerMatch::make();
    }
}

class WrongTypedEventGraphTriggerSource implements TriggerSource
{
    public static function key(): string
    {
        return 'test.wrong-event-type';
    }

    public static function driver(): string
    {
        return 'event';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Wrong event type');
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        return TriggerMatch::make();
    }
}

function triggerGraphErrors(array $graph): array
{
    return app(GraphValidator::class)->validate(Graph::fromArray($graph))->errors();
}

it('accepts one trigger whose started edge leads to an executable entry', function () {
    $graph = Graph::fromArray(triggeredExitGraph());
    $types = app(GraphTypeCatalog::class);

    expect(app(GraphValidator::class)->validate($graph)->passes())->toBeTrue()
        ->and($graph->triggerNodeIds($types))->toBe(['trigger'])
        ->and($graph->incomingEdges('first-action'))->toBe([
            ['from' => 'trigger', 'output' => 'started', 'to' => 'first-action'],
        ])
        ->and($graph->entryNodeId($types))->toBe('first-action');
});

it('rejects an executable graph start and a graph with no trigger', function () {
    $errors = triggerGraphErrors([
        'start' => 'first-action',
        'nodes' => [['id' => 'first-action', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);

    expect($errors)->toContain('The graph must contain exactly one trigger node.')
        ->and($errors)->toContain('The graph start must be its trigger node.');
});

it('rejects multiple triggers', function () {
    $graph = triggeredExitGraph();
    $graph['nodes'][] = ['id' => 'second-trigger', 'type' => 'test.fake_trigger', 'config' => ['source' => 'test.orders']];
    $graph['edges'][] = ['from' => 'second-trigger', 'output' => 'started', 'to' => 'first-action'];

    expect(triggerGraphErrors($graph))->toContain('The graph must contain exactly one trigger node.');
});

it('rejects a start that does not name the sole trigger', function () {
    $graph = triggeredExitGraph();
    $graph['start'] = 'first-action';

    expect(triggerGraphErrors($graph))->toContain('The graph start must be its trigger node.');
});

it('rejects a trigger with an incoming edge', function () {
    $graph = triggeredExitGraph();
    $graph['edges'][] = ['from' => 'first-action', 'output' => 'default', 'to' => 'trigger'];

    expect(triggerGraphErrors($graph))->toContain('Trigger node [trigger] cannot have incoming edges.');
});

it('requires exactly one started target', function (array $edges) {
    $graph = triggeredExitGraph();
    $graph['edges'] = $edges;

    expect(triggerGraphErrors($graph))->toContain(
        'Trigger node [trigger] must have exactly one [started] edge target.'
    );
})->with([
    'no target' => [[]],
    'multiple targets' => [[
        ['from' => 'trigger', 'output' => 'started', 'to' => 'first-action'],
        ['from' => 'trigger', 'output' => 'started', 'to' => 'first-action'],
    ]],
]);

it('requires the started target to be executable rather than another trigger', function () {
    $graph = triggeredExitGraph();
    $graph['nodes'][] = ['id' => 'second-trigger', 'type' => 'test.fake_trigger', 'config' => ['source' => 'test.orders']];
    $graph['edges'][0]['to'] = 'second-trigger';

    expect(triggerGraphErrors($graph))->toContain(
        'Trigger node [trigger] must start an executable node.'
    );
});

it('rejects outputs outside the trigger contract', function () {
    $graph = triggeredExitGraph();
    $graph['edges'][0]['output'] = 'sent';

    expect(implode(' ', triggerGraphErrors($graph)))->toContain('Node [trigger] has no output [sent].');
});

it('validates trigger source fields together with reserved trigger fields', function () {
    Nodeflow::registerTriggerSources([ConfiguredGraphTriggerSource::class]);

    $missing = triggerGraphErrors(triggeredExitGraph('test.configured-orders'));
    $valid = app(GraphValidator::class)->validate(Graph::fromArray(
        triggeredExitGraph('test.configured-orders', ['account' => 'primary'])
    ));

    expect(implode(' ', $missing))->toContain('field [account]')
        ->and($valid->passes())->toBeTrue();
});

it('rejects missing and wrongly typed source selections', function (mixed $source, string $message) {
    $errors = triggerGraphErrors(triggeredExitGraph(triggerConfig: ['source' => $source]));

    expect(implode(' ', $errors))->toContain($message);
})->with([
    'unregistered source' => ['test.missing', 'not registered for driver [test.fake]'],
    'non-string source' => [42, 'source field must be a string'],
]);

it('rejects a source registered for a different driver', function () {
    Nodeflow::registerTriggerSources([WrongTypedEventGraphTriggerSource::class]);

    expect(implode(' ', triggerGraphErrors(triggeredExitGraph('test.wrong-event-type'))))
        ->toContain('not registered for driver [test.fake]');
});

it('rejects a registered source of the wrong trigger source contract', function () {
    Nodeflow::registerTriggerSources([WrongTypedEventGraphTriggerSource::class]);
    $graph = triggeredExitGraph('test.wrong-event-type');
    $graph['nodes'][0]['type'] = 'core.trigger.laravel_event';

    expect(implode(' ', triggerGraphErrors($graph)))
        ->toContain('not compatible with driver [event]');
});

it('rejects source fields that collide with trigger reserved fields', function () {
    Nodeflow::registerTriggerSources([CollidingGraphTriggerSource::class]);

    expect(implode(' ', triggerGraphErrors(triggeredExitGraph('test.colliding-orders'))))
        ->toContain('field [source] collides with a reserved trigger field');
});
