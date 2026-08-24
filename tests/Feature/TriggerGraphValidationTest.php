<?php

use Nodeflow\Contracts\TriggerDriver;
use Nodeflow\Contracts\TriggerNode;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Graph\GraphValidator;
use Nodeflow\Models\Flow;
use Nodeflow\Nodeflow;
use Nodeflow\Publishing\GraphInvalidException;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\AbstractTriggerNode;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;
use Nodeflow\Triggers\TriggerSourceRegistry;

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

class SourceFieldCompilerGraphTriggerNode extends AbstractTriggerNode
{
    public static int $compilations = 0;

    public static function type(): string
    {
        return 'test.source-field-compiler';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Source field compiler')->fields([
            Field::select('source')->required(),
        ]);
    }

    public function driver(): string
    {
        return 'test.fake';
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        self::$compilations++;

        return new TriggerActivationDescriptor(
            'test.fake',
            (string) $config['source'],
            null,
            ['account' => $config['account']],
        );
    }
}

class AccumulatingGraphTriggerNode extends AbstractTriggerNode
{
    public static int $compilations = 0;

    public static function type(): string
    {
        return 'test.accumulating-trigger-errors';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Accumulating trigger errors')->fields([
            Field::select('source')->required(),
            Field::text('mode')->required(),
        ]);
    }

    public function driver(): string
    {
        return 'test.fake';
    }

    public function validate(array $config, TriggerSourceRegistry $sources): array
    {
        return array_merge_recursive(parent::validate($config, $sources), [
            'node_rule' => ['The node rule field is required.'],
        ]);
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        self::$compilations++;

        return new TriggerActivationDescriptor('test.fake', (string) $config['source'], null, []);
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

class DescriptorGraphTriggerDriver implements TriggerDriver
{
    public static function key(): string
    {
        return 'test.descriptor';
    }

    public function sourceRegistered(TriggerSource $source): void
    {
    }

    public function validate(TriggerActivationDescriptor $descriptor): array
    {
        return $descriptor->qualifier === 'invalid'
            ? ['qualifier' => ['The qualifier is invalid.']]
            : [];
    }
}

class BoundaryFailureGraphTriggerDriver implements TriggerDriver
{
    public static bool $throwDuringValidation = false;

    public static function key(): string
    {
        return 'test.boundary';
    }

    public function sourceRegistered(TriggerSource $source): void
    {
    }

    public function validate(TriggerActivationDescriptor $descriptor): array
    {
        if (self::$throwDuringValidation) {
            throw new RuntimeException('secret driver validation detail');
        }

        return [];
    }
}

class DescriptorGraphTriggerSource implements TriggerSource
{
    public static function key(): string
    {
        return 'test.hard-coded-orders';
    }

    public static function driver(): string
    {
        return 'test.descriptor';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Hard-coded orders')
            ->fields([Field::text('account')->required()]);
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        return TriggerMatch::make();
    }
}

class CollidingDescriptorGraphTriggerSource implements TriggerSource
{
    public static function key(): string
    {
        return 'test.colliding-descriptor';
    }

    public static function driver(): string
    {
        return 'test.descriptor';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Colliding descriptor source')
            ->fields([Field::text('mode')->required()]);
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        return TriggerMatch::make();
    }
}

class BoundaryFailureGraphTriggerSource implements TriggerSource
{
    public static bool $throwDuringDefinition = false;

    public static function key(): string
    {
        return 'test.boundary-source';
    }

    public static function driver(): string
    {
        return 'test.boundary';
    }

    public function definition(): TriggerDefinition
    {
        if (self::$throwDuringDefinition) {
            throw new RuntimeException('secret source definition detail');
        }

        return TriggerDefinition::make('Boundary source');
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        return TriggerMatch::make();
    }
}

trait DirectGraphTriggerSourceSelection
{
    public function supportsSource(TriggerSource $source): bool
    {
        return $source::driver() === $this->driver() && $source::key() === $this->source([]);
    }
}

class MissingDriverGraphTriggerNode implements TriggerNode
{
    use DirectGraphTriggerSourceSelection;

    public static function type(): string
    {
        return 'test.missing-driver-trigger';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Missing driver');
    }

    public function driver(): string
    {
        return 'test.missing-driver';
    }

    public function defaultConfig(): array
    {
        return [];
    }

    public function source(array $config): string
    {
        return 'test.orders';
    }

    public function validate(array $config, TriggerSourceRegistry $sources): array
    {
        return [];
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        return new TriggerActivationDescriptor('test.missing-driver', 'test.orders', null, []);
    }
}

class UnknownSourceGraphTriggerNode implements TriggerNode
{
    use DirectGraphTriggerSourceSelection;

    public static function type(): string
    {
        return 'test.unknown-source-trigger';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Unknown source');
    }

    public function driver(): string
    {
        return 'test.descriptor';
    }

    public function defaultConfig(): array
    {
        return [];
    }

    public function source(array $config): string
    {
        return 'test.missing-source';
    }

    public function validate(array $config, TriggerSourceRegistry $sources): array
    {
        return [];
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        return new TriggerActivationDescriptor('test.descriptor', 'test.missing-source', null, []);
    }
}

class HardCodedSourceGraphTriggerNode implements TriggerNode
{
    use DirectGraphTriggerSourceSelection;

    public static function type(): string
    {
        return 'test.hard-coded-source-trigger';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Hard-coded source')
            ->fields([Field::text('mode')->required()]);
    }

    public function driver(): string
    {
        return 'test.descriptor';
    }

    public function defaultConfig(): array
    {
        return [];
    }

    public function source(array $config): string
    {
        return 'test.hard-coded-orders';
    }

    public function validate(array $config, TriggerSourceRegistry $sources): array
    {
        return ($config['custom'] ?? null) === 'valid'
            ? []
            : ['custom' => ['The custom configuration is invalid.']];
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        return new TriggerActivationDescriptor(
            'test.descriptor',
            'test.hard-coded-orders',
            $config['qualifier'] ?? null,
            [],
        );
    }
}

class MismatchedDescriptorGraphTriggerNode implements TriggerNode
{
    use DirectGraphTriggerSourceSelection;

    public static function type(): string
    {
        return 'test.mismatched-descriptor-trigger';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Mismatched descriptor');
    }

    public function driver(): string
    {
        return 'test.descriptor';
    }

    public function defaultConfig(): array
    {
        return [];
    }

    public function source(array $config): string
    {
        return 'test.hard-coded-orders';
    }

    public function validate(array $config, TriggerSourceRegistry $sources): array
    {
        return [];
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        return new TriggerActivationDescriptor('test.fake', 'test.orders', null, []);
    }
}

class CollidingDescriptorGraphTriggerNode implements TriggerNode
{
    use DirectGraphTriggerSourceSelection;

    public static function type(): string
    {
        return 'test.colliding-descriptor-trigger';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Colliding descriptor')
            ->fields([Field::text('mode')->required()]);
    }

    public function driver(): string
    {
        return 'test.descriptor';
    }

    public function defaultConfig(): array
    {
        return [];
    }

    public function source(array $config): string
    {
        return 'test.colliding-descriptor';
    }

    public function validate(array $config, TriggerSourceRegistry $sources): array
    {
        return [];
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        return new TriggerActivationDescriptor('test.descriptor', 'test.colliding-descriptor', null, []);
    }
}

class BoundaryFailureGraphTriggerNode implements TriggerNode
{
    use DirectGraphTriggerSourceSelection;

    public static ?string $failure = null;

    public static int $compilations = 0;

    public static function type(): string
    {
        return 'test.boundary-failure-trigger';
    }

    public function definition(): TriggerDefinition
    {
        $this->throwAt('definition');

        return TriggerDefinition::make('Boundary failure')
            ->fields([Field::text('mode')->required()]);
    }

    public function driver(): string
    {
        $this->throwAt('driver');

        return self::$failure === 'unregistered-driver'
            ? 'test.missing-boundary'
            : 'test.boundary';
    }

    public function defaultConfig(): array
    {
        return [];
    }

    public function source(array $config): string
    {
        return 'test.boundary-source';
    }

    public function validate(array $config, TriggerSourceRegistry $sources): array
    {
        $this->throwAt('custom-validation');

        return ($config['custom'] ?? null) === 'valid'
            ? []
            : ['custom' => ['The custom configuration is invalid.']];
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        self::$compilations++;
        $this->throwAt('compile');

        return new TriggerActivationDescriptor('test.boundary', 'test.boundary-source', null, []);
    }

    private function throwAt(string $boundary): void
    {
        if (self::$failure === $boundary) {
            throw new RuntimeException("secret {$boundary} detail");
        }
    }
}

class ThrowingConstructionGraphTriggerNode implements TriggerNode
{
    use DirectGraphTriggerSourceSelection;

    public function __construct()
    {
        throw new RuntimeException('secret constructor detail');
    }

    public static function type(): string
    {
        return 'test.throwing-construction-trigger';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Unreachable');
    }

    public function driver(): string
    {
        return 'test.boundary';
    }

    public function defaultConfig(): array
    {
        return [];
    }

    public function source(array $config): string
    {
        return 'test.boundary-source';
    }

    public function validate(array $config, TriggerSourceRegistry $sources): array
    {
        return [];
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        return new TriggerActivationDescriptor('test.boundary', 'test.boundary-source', null, []);
    }
}

function triggerGraphErrors(array $graph): array
{
    return app(GraphValidator::class)->validate(Graph::fromArray($graph))->errors();
}

function directTriggerGraph(string $type, array $config = []): array
{
    $graph = triggeredExitGraph();
    $graph['nodes'][0] = ['id' => 'trigger', 'type' => $type, 'config' => $config];

    return $graph;
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
    $result = app(GraphValidator::class)->validate(Graph::fromArray([
        'start' => 'first-action',
        'nodes' => [['id' => 'first-action', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]));

    expect($result->errors())->toBe([
        'The graph must contain exactly one trigger node.',
        'The graph start must be its trigger node.',
    ])->and($result->nodeErrors())->toBe([
        ['node' => null, 'field' => null, 'message' => 'The graph must contain exactly one trigger node.'],
        ['node' => null, 'field' => null, 'message' => 'The graph start must be its trigger node.'],
    ]);
});

it('rejects multiple triggers', function () {
    $graph = triggeredExitGraph();
    $graph['nodes'][] = ['id' => 'second-trigger', 'type' => 'test.fake_trigger', 'config' => ['source' => 'test.orders']];
    $graph['edges'][] = ['from' => 'second-trigger', 'output' => 'started', 'to' => 'first-action'];

    $result = app(GraphValidator::class)->validate(Graph::fromArray($graph));

    expect($result->errors())->toBe(['The graph must contain exactly one trigger node.'])
        ->and($result->nodeErrors())->toBe([[
            'node' => null,
            'field' => null,
            'message' => 'The graph must contain exactly one trigger node.',
        ]]);
});

it('attributes a multiple-trigger start mismatch to the graph', function () {
    $graph = triggeredExitGraph();
    $graph['nodes'][] = ['id' => 'second-trigger', 'type' => 'test.fake_trigger', 'config' => ['source' => 'test.orders']];
    $graph['edges'][] = ['from' => 'second-trigger', 'output' => 'started', 'to' => 'first-action'];
    $graph['start'] = 'first-action';

    $result = app(GraphValidator::class)->validate(Graph::fromArray($graph));

    expect($result->errors())->toBe([
        'The graph must contain exactly one trigger node.',
        'The graph start must be its trigger node.',
    ])->and($result->nodeErrors())->toBe([
        ['node' => null, 'field' => null, 'message' => 'The graph must contain exactly one trigger node.'],
        ['node' => null, 'field' => null, 'message' => 'The graph start must be its trigger node.'],
    ]);
});

it('rejects a start that does not name the sole trigger', function () {
    $graph = triggeredExitGraph();
    $graph['start'] = 'first-action';

    $result = app(GraphValidator::class)->validate(Graph::fromArray($graph));

    expect($result->errors())->toBe(['The graph start must be its trigger node.'])
        ->and($result->nodeErrors())->toBe([[
            'node' => 'trigger',
            'field' => null,
            'message' => 'The graph start must be its trigger node.',
        ]]);
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

it('validates combined source config before compiling and leaves publication untouched', function () {
    Nodeflow::registerTriggerNodes([SourceFieldCompilerGraphTriggerNode::class]);
    Nodeflow::registerTriggerSources([
        ConfiguredGraphTriggerSource::class,
        CollidingGraphTriggerSource::class,
    ]);
    SourceFieldCompilerGraphTriggerNode::$compilations = 0;

    $missingGraph = directTriggerGraph(SourceFieldCompilerGraphTriggerNode::type(), [
        'source' => ConfiguredGraphTriggerSource::key(),
    ]);
    $missing = app(GraphValidator::class)->validate(Graph::fromArray($missingGraph));

    expect($missing->nodeErrors())->toContain([
        'node' => 'trigger',
        'field' => 'account',
        'message' => 'The account field is required.',
    ])->and($missing->errors())->not->toContain(
        'Trigger node [trigger] could not compile its activation descriptor.',
    )->and(SourceFieldCompilerGraphTriggerNode::$compilations)->toBe(0);

    $collision = app(GraphValidator::class)->validate(Graph::fromArray(directTriggerGraph(
        SourceFieldCompilerGraphTriggerNode::type(),
        ['source' => CollidingGraphTriggerSource::key()],
    )));

    expect($collision->nodeErrors())->toContain([
        'node' => 'trigger',
        'field' => 'source',
        'message' => 'The source field [source] collides with a reserved trigger field.',
    ])->and(SourceFieldCompilerGraphTriggerNode::$compilations)->toBe(0);

    $flow = Flow::create([
        'tenant_id' => 'org-1',
        'name' => 'No partial source-field publication',
        'status' => 'draft',
    ]);

    expect(fn () => app(PublishFlow::class)->publish($flow, $missingGraph))
        ->toThrow(GraphInvalidException::class)
        ->and(SourceFieldCompilerGraphTriggerNode::$compilations)->toBe(0)
        ->and($flow->fresh()->current_version_id)->toBeNull()
        ->and($flow->versions()->count())->toBe(0)
        ->and($flow->triggerActivation()->exists())->toBeFalse();

    $valid = app(GraphValidator::class)->validate(Graph::fromArray(directTriggerGraph(
        SourceFieldCompilerGraphTriggerNode::type(),
        ['source' => ConfiguredGraphTriggerSource::key(), 'account' => 'primary'],
    )));

    expect($valid->passes())->toBeTrue()
        ->and(SourceFieldCompilerGraphTriggerNode::$compilations)->toBe(1);
});

it('accumulates node and source field errors before skipping compilation', function () {
    Nodeflow::registerTriggerNodes([AccumulatingGraphTriggerNode::class]);
    Nodeflow::registerTriggerSources([ConfiguredGraphTriggerSource::class]);
    AccumulatingGraphTriggerNode::$compilations = 0;

    $result = app(GraphValidator::class)->validate(Graph::fromArray(directTriggerGraph(
        AccumulatingGraphTriggerNode::type(),
        ['source' => ConfiguredGraphTriggerSource::key()],
    )));

    expect(collect($result->nodeErrors())->pluck('field')->all())
        ->toContain('mode', 'node_rule', 'account')
        ->and(AccumulatingGraphTriggerNode::$compilations)->toBe(0);
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

it('validates definition and custom rules for direct trigger node implementations', function () {
    Nodeflow::registerTriggerDrivers([DescriptorGraphTriggerDriver::class]);
    Nodeflow::registerTriggerNodes([HardCodedSourceGraphTriggerNode::class]);

    $result = app(GraphValidator::class)->validate(Graph::fromArray(
        directTriggerGraph(HardCodedSourceGraphTriggerNode::type())
    ));

    expect($result->nodeErrors())->toContain(
        ['node' => 'trigger', 'field' => 'mode', 'message' => 'The mode field is required.'],
        ['node' => 'trigger', 'field' => 'custom', 'message' => 'The custom configuration is invalid.'],
    );
});

it('rejects a direct trigger node whose declared driver is not registered', function () {
    Nodeflow::registerTriggerNodes([MissingDriverGraphTriggerNode::class]);

    expect(triggerGraphErrors(directTriggerGraph(MissingDriverGraphTriggerNode::type())))
        ->toContain('Trigger node [trigger] uses unregistered driver [test.missing-driver].');
});

it('rejects a descriptor-selected source that is not registered', function () {
    Nodeflow::registerTriggerDrivers([DescriptorGraphTriggerDriver::class]);
    Nodeflow::registerTriggerNodes([UnknownSourceGraphTriggerNode::class]);

    expect(triggerGraphErrors(directTriggerGraph(UnknownSourceGraphTriggerNode::type())))
        ->toContain(
            'Trigger node [trigger] selected source [test.missing-source], which is not registered for driver [test.descriptor].'
        );
});

it('validates a hard-coded descriptor source without requiring config source', function () {
    Nodeflow::registerTriggerDrivers([DescriptorGraphTriggerDriver::class]);
    Nodeflow::registerTriggerSources([DescriptorGraphTriggerSource::class]);
    Nodeflow::registerTriggerNodes([HardCodedSourceGraphTriggerNode::class]);

    $missing = app(GraphValidator::class)->validate(Graph::fromArray(directTriggerGraph(
        HardCodedSourceGraphTriggerNode::type(),
        ['mode' => 'automatic', 'custom' => 'valid'],
    )));
    $valid = app(GraphValidator::class)->validate(Graph::fromArray(directTriggerGraph(
        HardCodedSourceGraphTriggerNode::type(),
        ['mode' => 'automatic', 'custom' => 'valid', 'account' => 'primary'],
    )));

    expect($missing->nodeErrors())->toContain([
        'node' => 'trigger',
        'field' => 'account',
        'message' => 'The account field is required.',
    ])->and($valid->passes())->toBeTrue();
});

it('merges descriptor driver validation errors', function () {
    Nodeflow::registerTriggerDrivers([DescriptorGraphTriggerDriver::class]);
    Nodeflow::registerTriggerSources([DescriptorGraphTriggerSource::class]);
    Nodeflow::registerTriggerNodes([HardCodedSourceGraphTriggerNode::class]);

    $result = app(GraphValidator::class)->validate(Graph::fromArray(directTriggerGraph(
        HardCodedSourceGraphTriggerNode::type(),
        ['mode' => 'automatic', 'custom' => 'valid', 'account' => 'primary', 'qualifier' => 'invalid'],
    )));

    expect($result->nodeErrors())->toContain([
        'node' => 'trigger',
        'field' => 'qualifier',
        'message' => 'The qualifier is invalid.',
    ]);
});

it('rejects a compiled descriptor whose driver differs from its trigger node', function () {
    Nodeflow::registerTriggerDrivers([DescriptorGraphTriggerDriver::class]);
    Nodeflow::registerTriggerSources([DescriptorGraphTriggerSource::class]);
    Nodeflow::registerTriggerNodes([MismatchedDescriptorGraphTriggerNode::class]);

    expect(triggerGraphErrors(directTriggerGraph(
        MismatchedDescriptorGraphTriggerNode::type(),
        ['account' => 'primary'],
    )))
        ->toContain(
            'Trigger node [trigger] compiled driver [test.fake] but declares driver [test.descriptor].'
        );
});

it('rejects descriptor-selected source fields that collide with direct trigger fields', function () {
    Nodeflow::registerTriggerDrivers([DescriptorGraphTriggerDriver::class]);
    Nodeflow::registerTriggerSources([CollidingDescriptorGraphTriggerSource::class]);
    Nodeflow::registerTriggerNodes([CollidingDescriptorGraphTriggerNode::class]);

    expect(implode(' ', triggerGraphErrors(directTriggerGraph(
        CollidingDescriptorGraphTriggerNode::type(),
        ['mode' => 'automatic'],
    ))))->toContain('field [mode] collides with a reserved trigger field');
});

it('converts extension exceptions into deterministic structured errors', function (
    string $boundary,
    string $message,
) {
    BoundaryFailureGraphTriggerNode::$failure = in_array($boundary, [
        'definition',
        'custom-validation',
        'driver',
        'compile',
    ], true) ? $boundary : null;
    BoundaryFailureGraphTriggerNode::$compilations = 0;
    BoundaryFailureGraphTriggerDriver::$throwDuringValidation = $boundary === 'driver-validation';
    BoundaryFailureGraphTriggerSource::$throwDuringDefinition = $boundary === 'source-definition';

    Nodeflow::registerTriggerDrivers([BoundaryFailureGraphTriggerDriver::class]);
    Nodeflow::registerTriggerSources([BoundaryFailureGraphTriggerSource::class]);
    Nodeflow::registerTriggerNodes([BoundaryFailureGraphTriggerNode::class]);

    $result = app(GraphValidator::class)->validate(Graph::fromArray(
        directTriggerGraph(BoundaryFailureGraphTriggerNode::type(), ['mode' => 'automatic', 'custom' => 'valid'])
    ));

    expect($result->errors())->toBe([$message])
        ->and($result->nodeErrors())->toBe([[
            'node' => 'trigger',
            'field' => null,
            'message' => $message,
        ]])
        ->and(implode(' ', $result->errors()))->not->toContain('secret');
})->with([
    'definition' => ['definition', 'Trigger node [trigger] definition could not be validated.'],
    'custom validation' => ['custom-validation', 'Trigger node [trigger] custom validation could not be completed.'],
    'driver declaration' => ['driver', 'Trigger node [trigger] could not declare its driver.'],
    'compilation' => ['compile', 'Trigger node [trigger] could not compile its activation descriptor.'],
    'driver validation' => ['driver-validation', 'Trigger node [trigger] driver validation could not be completed.'],
    'source definition' => ['source-definition', 'Trigger node [trigger] source definition could not be validated.'],
]);

it('converts trigger construction exceptions into deterministic structured errors', function () {
    Nodeflow::registerTriggerNodes([ThrowingConstructionGraphTriggerNode::class]);

    $result = app(GraphValidator::class)->validate(Graph::fromArray(
        directTriggerGraph(ThrowingConstructionGraphTriggerNode::type())
    ));

    expect($result->errors())->toBe(['Trigger node [trigger] validation could not be completed.'])
        ->and($result->nodeErrors())->toBe([[
            'node' => 'trigger',
            'field' => null,
            'message' => 'Trigger node [trigger] validation could not be completed.',
        ]])
        ->and(implode(' ', $result->errors()))->not->toContain('secret');
});

it('compiles only once base and custom validation and driver registration are safe', function () {
    Nodeflow::registerTriggerDrivers([BoundaryFailureGraphTriggerDriver::class]);
    Nodeflow::registerTriggerSources([BoundaryFailureGraphTriggerSource::class]);
    Nodeflow::registerTriggerNodes([BoundaryFailureGraphTriggerNode::class]);
    BoundaryFailureGraphTriggerDriver::$throwDuringValidation = false;
    BoundaryFailureGraphTriggerSource::$throwDuringDefinition = false;
    BoundaryFailureGraphTriggerNode::$compilations = 0;

    BoundaryFailureGraphTriggerNode::$failure = null;
    app(GraphValidator::class)->validate(Graph::fromArray(directTriggerGraph(
        BoundaryFailureGraphTriggerNode::type(),
        ['custom' => 'valid'],
    )));

    BoundaryFailureGraphTriggerNode::$failure = null;
    app(GraphValidator::class)->validate(Graph::fromArray(directTriggerGraph(
        BoundaryFailureGraphTriggerNode::type(),
        ['mode' => 'automatic'],
    )));

    BoundaryFailureGraphTriggerNode::$failure = 'unregistered-driver';
    app(GraphValidator::class)->validate(Graph::fromArray(directTriggerGraph(
        BoundaryFailureGraphTriggerNode::type(),
        ['mode' => 'automatic', 'custom' => 'valid'],
    )));

    expect(BoundaryFailureGraphTriggerNode::$compilations)->toBe(0);

    BoundaryFailureGraphTriggerNode::$failure = null;
    $valid = app(GraphValidator::class)->validate(Graph::fromArray(directTriggerGraph(
        BoundaryFailureGraphTriggerNode::type(),
        ['mode' => 'automatic', 'custom' => 'valid'],
    )));

    expect($valid->passes())->toBeTrue()
        ->and(BoundaryFailureGraphTriggerNode::$compilations)->toBe(1);
});
