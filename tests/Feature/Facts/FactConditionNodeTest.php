<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\NodeRunner;
use Nodeflow\Facts\CompiledFactPredicate;
use Nodeflow\Facts\FactCatalogue;
use Nodeflow\Facts\FactCatalogueContext;
use Nodeflow\Facts\FactProvider;
use Nodeflow\Facts\FactProviderRegistry;
use Nodeflow\Facts\FactResolution;
use Nodeflow\Facts\FactResolutionContext;
use Nodeflow\Facts\Exceptions\FactContractException;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;
use Nodeflow\Nodes\Core\FactConditionNode;
use Nodeflow\Nodes\NodeRegistry;

final class FactConditionTestProvider implements FactProvider
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var list<list<string>> */
    public array $batches = [];

    public function key(): string { return 'crm'; }

    public function catalogue(FactCatalogueContext $context): FactCatalogue
    {
        return new FactCatalogue('crm', 'revision', []);
    }

    public function resolve(FactResolutionContext $context, CompiledFactPredicate $predicate, array $subjectIds): iterable
    {
        $this->batches[] = $subjectIds;

        return array_map(
            fn (string $id): FactResolution => array_key_exists($id, $this->values)
                ? FactResolution::present($id, $this->values[$id])
                : FactResolution::missing($id),
            $subjectIds,
        );
    }
}

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool { return true; }
    });

    $this->provider = new FactConditionTestProvider;
    app(FactProviderRegistry::class)->register($this->provider);
    config()->set('nodeflow.limits.audience_chunk', 2);
});

it('registers a built-in fact condition with a runtime predicate field', function () {
    $node = app(NodeRegistry::class)->resolve('core.fact_condition');
    $definition = $node->definition()->toArray();

    expect($node)->toBeInstanceOf(FactConditionNode::class)
        ->and($definition['outputs'])->toBe(['yes', 'no'])
        ->and($definition['fields'][0]['type'])->toBe('fact_predicate')
        ->and($definition['fields'][0]['fact_capability'])->toBe('runtime_condition');
});

it('bulk resolves each engine chunk and routes every subject exactly once', function () {
    $this->provider->values = [
        '1' => 'agriculture',
        '2' => 'retail',
        '3' => 'agriculture',
        '4' => 'retail',
        '5' => 'agriculture',
    ];
    [$run, $graph] = factConditionRun(['missing_behavior' => 'route_no'], ['1', '2', '3', '4', '5']);

    app(NodeRunner::class)->run($run, $graph, 'condition');

    expect($this->provider->batches)->toBe([['1', '2'], ['3', '4'], ['5']])
        ->and(RunSubject::where('run_id', $run->id)->where('current_node_id', 'yes')->pluck('subject_id')->all())
        ->toBe(['1', '3', '5'])
        ->and(RunSubject::where('run_id', $run->id)->where('current_node_id', 'no')->pluck('subject_id')->all())
        ->toBe(['2', '4']);
});

it('routes missing values according to the pinned behavior', function () {
    $this->provider->values = ['1' => 'agriculture'];
    [$run, $graph] = factConditionRun(['missing_behavior' => 'route_yes'], ['1', '2']);

    app(NodeRunner::class)->run($run, $graph, 'condition');

    expect(RunSubject::where('run_id', $run->id)->where('current_node_id', 'yes')->pluck('subject_id')->all())
        ->toBe(['1', '2']);
});

it('fails closed when a provider omits a requested subject result', function () {
    $provider = new class implements FactProvider {
        public function key(): string { return 'broken'; }
        public function catalogue(FactCatalogueContext $context): FactCatalogue { return new FactCatalogue('broken', 'r', []); }
        public function resolve(FactResolutionContext $context, CompiledFactPredicate $predicate, array $subjectIds): iterable
        {
            return [FactResolution::present($subjectIds[0], 'agriculture')];
        }
    };
    app(FactProviderRegistry::class)->register($provider);
    [$run, $graph] = factConditionRun(['provider' => 'broken'], ['1', '2']);

    expect(fn () => app(NodeRunner::class)->run($run, $graph, 'condition'))
        ->toThrow(FactContractException::class, 'exactly one result');
});

/** @return array{Run, Graph} */
function factConditionRun(array $predicateOverrides, array $subjectIds): array
{
    $predicate = [
        'provider' => 'crm',
        'key' => 'profile.segment',
        'version' => 1,
        'type' => 'text',
        'operator' => 'equals',
        'value' => 'agriculture',
        'missing_behavior' => 'route_no',
        'catalogue_revision' => 'revision',
        ...$predicateOverrides,
    ];
    $graph = Graph::fromArray([
        'start' => 'condition',
        'nodes' => [
            ['id' => 'condition', 'type' => 'core.fact_condition', 'config' => ['predicate' => $predicate]],
            ['id' => 'yes', 'type' => 'core.exit', 'config' => []],
            ['id' => 'no', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [
            ['from' => 'condition', 'output' => 'yes', 'to' => 'yes'],
            ['from' => 'condition', 'output' => 'no', 'to' => 'no'],
        ],
    ]);
    $flow = Flow::create(['name' => 'Facts', 'status' => 'active']);
    $version = FlowVersion::create([
        'flow_id' => $flow->id,
        'version' => 1,
        'graph' => $graph->toArray(),
        'content_hash' => 'facts',
    ]);
    $run = Run::create([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'trigger_data' => ['snapshot_id' => 'snapshot-1'],
        'is_test' => false,
        'strategy' => 'cohort',
        'status' => 'running',
    ]);
    foreach ($subjectIds as $id) {
        RunSubject::create([
            'run_id' => $run->id,
            'subject_type' => 'user',
            'subject_id' => $id,
            'current_node_id' => 'condition',
            'status' => 'active',
        ]);
    }

    return [$run, $graph];
}
