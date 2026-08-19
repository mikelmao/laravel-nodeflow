<?php

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;

/**
 * The node context is the package's public API for every host node ever written.
 * Handing node authors the full mutable Run model made `$c->run()->delete()`
 * inside a node body legal, and narrowing it later would break every host node
 * written in the meantime. So it is narrowed now, while there are none.
 */
beforeEach(function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);
    $version = FlowVersion::create(['flow_id' => $flow->id, 'version' => 1, 'graph' => ['nodes' => [], 'edges' => []], 'content_hash' => 'h']);

    $this->run = Run::create([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'correlation_id' => 'alert-77',
        'strategy' => 'cohort',
        'status' => 'running',
        'is_test' => true,
    ]);
});

it('does not hand node authors the mutable Run model', function (string $class) {
    expect(method_exists($class, 'run'))->toBeFalse(
        "{$class}::run() would let a node body call \$c->run()->delete()"
    );

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $type = $method->getReturnType();

        expect($type instanceof ReflectionNamedType ? $type->getName() : '')
            ->not->toBe(Run::class, "{$class}::{$method->getName()}() returns the Run model");
    }
})->with([SubjectContext::class, AudienceContext::class]);

it('exposes the run identity a node legitimately needs, per subject', function () {
    $context = new SubjectContext($this->run, 'n1', ['a' => 1], '7', ['id' => '7']);

    expect($context->runId())->toBe($this->run->id)
        ->and($context->correlationId())->toBe('alert-77')
        ->and($context->isTest())->toBeTrue()
        ->and($context->nodeId())->toBe('n1')
        ->and($context->subjectId())->toBe('7')
        ->and($context->config('a'))->toBe(1);
});

it('exposes the run identity a node legitimately needs, per audience', function () {
    $context = new AudienceContext($this->run, 'n1', [], 'user', ['1', '2']);

    expect($context->runId())->toBe($this->run->id)
        ->and($context->correlationId())->toBe('alert-77')
        ->and($context->isTest())->toBeTrue()
        ->and($context->subjectType())->toBe('user')
        ->and($context->subjectIds())->toBe(['1', '2']);
});
