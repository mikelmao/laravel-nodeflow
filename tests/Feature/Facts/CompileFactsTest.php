<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Facts\CompiledFactPredicate;
use Nodeflow\Facts\FactCatalogue;
use Nodeflow\Facts\FactCatalogueContext;
use Nodeflow\Facts\FactDefinition;
use Nodeflow\Facts\FactProvider;
use Nodeflow\Facts\FactProviderRegistry;
use Nodeflow\Facts\FactResolutionContext;
use Nodeflow\Facts\FactValueType;
use Nodeflow\Models\Flow;
use Nodeflow\Nodeflow;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Publishing\GraphInvalidException;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

class FactCompilationTestNode extends Node implements HandlesAudience
{
    public static function type(): string { return 'test.fact-compilation'; }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Fact compiler')
            ->outputs(['yes', 'no'])
            ->fields([Field::factPredicate('predicate', 'runtime_condition')->required()]);
    }

    public function forAudience(AudienceContext $context): NodeResult
    {
        return $context->all('yes');
    }
}

final class FactCompilationTestProvider implements FactProvider
{
    public int $catalogueCalls = 0;
    public ?Throwable $catalogueFailure = null;
    /** @var list<string> */
    public array $runtimeOperators = ['equals', 'in'];

    public function key(): string { return 'crm'; }

    public function catalogue(FactCatalogueContext $context): FactCatalogue
    {
        $this->catalogueCalls++;
        if ($this->catalogueFailure !== null) {
            throw $this->catalogueFailure;
        }

        return new FactCatalogue('crm', 'revision-42', [
            new FactDefinition(
                'profile.segment', 1, 'Segment', FactValueType::Text,
                ['runtime_condition'], ['runtime_condition' => $this->runtimeOperators],
            ),
        ]);
    }

    public function resolve(FactResolutionContext $context, CompiledFactPredicate $predicate, array $subjectIds): iterable
    {
        return [];
    }
}

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool { return true; }
    });
    Nodeflow::register([FactCompilationTestNode::class]);
    $this->provider = new FactCompilationTestProvider;
    app(FactProviderRegistry::class)->register($this->provider);
    $this->flow = Flow::create(['name' => 'Fact flow', 'status' => 'draft']);
});

it('pins a fact predicate from the current provider catalogue during publication', function () {
    $version = app(PublishFlow::class)->publish($this->flow, factCompilationGraph([
        'provider' => 'crm',
        'key' => 'profile.segment',
        'version' => 1,
        'operator' => 'in',
        'value' => ['retail', 'agriculture', 'retail'],
    ]))->version;

    $node = collect($version->graph['nodes'])->firstWhere('id', 'condition');

    expect($node['config']['predicate'])->toBe([
        'provider' => 'crm',
        'key' => 'profile.segment',
        'version' => 1,
        'type' => 'text',
        'operator' => 'in',
        'value' => ['agriculture', 'retail'],
        'missing_behavior' => 'route_no',
        'catalogue_revision' => 'revision-42',
    ])->and($this->provider->catalogueCalls)->toBe(1);
});

it('returns a field-scoped publication error for unavailable facts', function () {
    try {
        app(PublishFlow::class)->publish($this->flow, factCompilationGraph([
            'provider' => 'crm',
            'key' => 'profile.unknown',
            'version' => 1,
            'operator' => 'equals',
            'value' => 'agriculture',
        ]));
        $error = null;
    } catch (GraphInvalidException $exception) {
        $error = $exception;
    }

    expect($error)->toBeInstanceOf(GraphInvalidException::class)
        ->and($error->nodeErrors())->toBe([[
            'node' => 'condition',
            'field' => 'predicate',
            'message' => 'The selected fact is unavailable or invalid.',
        ]])
        ->and($this->flow->fresh()->current_version_id)->toBeNull();
});

it('does not disguise provider outages as an invalid user selection', function () {
    $this->provider->catalogueFailure = new RuntimeException('Provider unavailable.');

    expect(fn () => app(PublishFlow::class)->publish($this->flow, factCompilationGraph([
        'provider' => 'crm',
        'key' => 'profile.segment',
        'version' => 1,
        'operator' => 'equals',
        'value' => 'agriculture',
    ])))->toThrow(RuntimeException::class, 'Provider unavailable.');
});

it('rejects provider operators that the built-in condition cannot execute', function () {
    $this->provider->runtimeOperators = ['starts_with'];

    expect(fn () => app(PublishFlow::class)->publish($this->flow, factCompilationGraph([
        'provider' => 'crm',
        'key' => 'profile.segment',
        'version' => 1,
        'operator' => 'starts_with',
        'value' => 'agriculture',
    ], 'core.fact_condition')))->toThrow(GraphInvalidException::class, 'selected fact is unavailable or invalid');
});

function factCompilationGraph(array $predicate, string $nodeType = 'test.fact-compilation'): array
{
    return triggeredGraph([
        'start' => 'condition',
        'nodes' => [
            ['id' => 'condition', 'type' => $nodeType, 'config' => ['predicate' => $predicate]],
            ['id' => 'exit-yes', 'type' => 'core.exit', 'config' => []],
            ['id' => 'exit-no', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [
            ['from' => 'condition', 'output' => 'yes', 'to' => 'exit-yes'],
            ['from' => 'condition', 'output' => 'no', 'to' => 'exit-no'],
        ],
    ]);
}
