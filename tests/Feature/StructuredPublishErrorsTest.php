<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphValidator;
use Nodeflow\Models\Flow;
use Nodeflow\Publishing\GraphInvalidException;
use Nodeflow\Publishing\PublishFlow;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver
    {
        public function currentTenantId(): ?string
        {
            return 'org-1';
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return true;
        }
    });
});

it('names the node whose field failed validation', function () {
    // §5.3: the editor renders an error beside its node. Parsing it back out of
    // "Node [w1] field [duration]: ..." is brittle, so the structure is carried.
    // Counterfactual: return only strings and there is nothing to key on.
    $result = app(GraphValidator::class)->validate(Graph::fromArray(triggeredGraph([
        'start' => 'w1',
        'nodes' => [['id' => 'w1', 'type' => 'core.wait', 'config' => []]],
        'edges' => [],
    ])));

    expect($result->passes())->toBeFalse()
        ->and($result->nodeErrors())->toContain([
            'node' => 'w1',
            'field' => 'duration',
            'message' => 'The duration field is required.',
        ]);
});

it('keeps the flat strings byte-identical alongside the structure', function () {
    // The existing suite asserts on these. Counterfactual: reshape errors() and
    // GraphValidatorTest and PublishFlowTest break.
    $result = app(GraphValidator::class)->validate(Graph::fromArray(triggeredGraph([
        'start' => 'w1',
        'nodes' => [['id' => 'w1', 'type' => 'core.wait', 'config' => []]],
        'edges' => [],
    ])));

    expect($result->errors())->toContain('Node [w1] field [duration]: The duration field is required.');
});

it('names the node for an unknown type', function () {
    $result = app(GraphValidator::class)->validate(Graph::fromArray(triggeredGraph([
        'start' => 'x1',
        'nodes' => [['id' => 'x1', 'type' => 'nope.missing', 'config' => []]],
        'edges' => [],
    ])));

    expect($result->nodeErrors())->toContain([
        'node' => 'x1',
        'field' => null,
        'message' => 'Node [x1] uses unknown type [nope.missing].',
    ]);
});

it('leaves the node null for a graph-level failure', function () {
    // A cycle or a missing start belongs to no node, and the editor must not try
    // to pin it to one. Counterfactual: default node to the first id and a cycle
    // error lands on an innocent node's card.
    $result = app(GraphValidator::class)->validate(Graph::fromArray([
        'start' => '',
        'nodes' => [],
        'edges' => [],
    ]));

    expect($result->nodeErrors())->toContain([
        'node' => null,
        'field' => null,
        'message' => 'The flow has no start node set. Choose a starting node before publishing.',
    ]);
});

it('carries the structure through the publish exception', function () {
    $flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    try {
        app(PublishFlow::class)->publish($flow, triggeredGraph([
            'start' => 'w1',
            'nodes' => [['id' => 'w1', 'type' => 'core.wait', 'config' => []]],
            'edges' => [],
        ]));
        $this->fail('expected GraphInvalidException');
    } catch (GraphInvalidException $e) {
        expect($e->nodeErrors())->toContain([
            'node' => 'w1',
            'field' => 'duration',
            'message' => 'The duration field is required.',
        ])->and($e->errors())->toBeArray();
    }
});
