<?php

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Models\Run;
use Nodeflow\Nodes\Core\ConditionNode;
use Nodeflow\Nodes\Core\ExitNode;
use Nodeflow\Nodes\Core\WaitNode;
use Nodeflow\Schema\SubjectAttribute;
use Nodeflow\Schema\SubjectAttributeRegistry;

it('exit node declares no outputs', function () {
    expect(ExitNode::type())->toBe('core.exit')
        ->and((new ExitNode)->definition()->outputNames())->toBe([]);
});

it('wait node requires a duration and passes everyone through', function () {
    $node = new WaitNode;

    expect($node->validate([]))->toHaveKey('duration')
        ->and($node->validate(['duration' => '1 day']))->toBe([]);

    $run = new Run(['is_test' => false]);
    $context = new AudienceContext($run, 'w1', ['duration' => '1 day'], 'user', ['1', '2']);

    expect($node->forAudience($context)->outputs())->toBe(['default' => ['1', '2']]);
});

it('condition node branches per subject from registered attributes', function () {
    app(SubjectAttributeRegistry::class)->register(
        SubjectAttribute::make('clicked', 'Has clicked', 'boolean', fn ($s) => $s['clicked']),
    );

    $node = new ConditionNode;
    $run = new Run(['is_test' => false]);
    $config = ['attribute' => 'clicked', 'operator' => 'is_true', 'value' => null];

    $clicked = new SubjectContext($run, 'c1', $config, '1', ['clicked' => true]);
    $notClicked = new SubjectContext($run, 'c1', $config, '2', ['clicked' => false]);

    expect($node->forSubject($clicked)->outputs())->toBe(['yes' => ['1']])
        ->and($node->forSubject($notClicked)->outputs())->toBe(['no' => ['2']]);
});

it('condition node supports equals and in operators', function () {
    app(SubjectAttributeRegistry::class)->register(
        SubjectAttribute::make('severity', 'Severity', 'text', fn ($s) => $s['severity']),
    );

    $node = new ConditionNode;
    $run = new Run(['is_test' => false]);

    $equals = new SubjectContext($run, 'c1', ['attribute' => 'severity', 'operator' => 'equals', 'value' => 'red'], '1', ['severity' => 'red']);
    $in = new SubjectContext($run, 'c1', ['attribute' => 'severity', 'operator' => 'in', 'value' => ['orange', 'red']], '2', ['severity' => 'orange']);

    expect($node->forSubject($equals)->outputs())->toBe(['yes' => ['1']])
        ->and($node->forSubject($in)->outputs())->toBe(['yes' => ['2']]);
});

it('exposes registered attributes as editor options', function () {
    app(SubjectAttributeRegistry::class)->register(
        SubjectAttribute::make('clicked', 'Has clicked', 'boolean', fn ($s) => true),
    );

    expect(app(SubjectAttributeRegistry::class)->options())->toBe(['clicked' => 'Has clicked']);
});

it('condition node throws for unknown operator', function () {
    app(SubjectAttributeRegistry::class)->register(
        SubjectAttribute::make('field', 'Field', 'text', fn ($s) => 'value'),
    );

    $node = new ConditionNode;
    $run = new Run(['is_test' => false]);
    $context = new SubjectContext($run, 'c1', ['attribute' => 'field', 'operator' => 'unknown_op', 'value' => 'x'], '1', ['field' => 'value']);

    expect(fn () => $node->forSubject($context))
        ->toThrow(\RuntimeException::class, 'Unknown condition operator');
});

it('condition node rejects null actual on equals operator', function () {
    app(SubjectAttributeRegistry::class)->register(
        SubjectAttribute::make('field', 'Field', 'text', fn ($s) => null),
    );

    $node = new ConditionNode;
    $run = new Run(['is_test' => false]);
    $context = new SubjectContext($run, 'c1', ['attribute' => 'field', 'operator' => 'equals', 'value' => ''], '1', ['field' => null]);

    expect($node->forSubject($context)->outputs())->toBe(['no' => ['1']]);
});

it('condition node handles boolean string values correctly', function () {
    app(SubjectAttributeRegistry::class)->register(
        SubjectAttribute::make('active', 'Active', 'boolean', fn ($s) => $s['active']),
    );

    $node = new ConditionNode;
    $run = new Run(['is_test' => false]);

    $falseSubject = new SubjectContext($run, 'c1', ['attribute' => 'active', 'operator' => 'equals', 'value' => 'false'], '1', ['active' => false]);

    expect($node->forSubject($falseSubject)->outputs())->toBe(['yes' => ['1']]);
});

it('condition node supports in operator with comma-separated string', function () {
    app(SubjectAttributeRegistry::class)->register(
        SubjectAttribute::make('severity', 'Severity', 'text', fn ($s) => $s['severity']),
    );

    $node = new ConditionNode;
    $run = new Run(['is_test' => false]);

    // Test with comma-separated string (what the editor produces)
    $context = new SubjectContext(
        $run,
        'c1',
        ['attribute' => 'severity', 'operator' => 'in', 'value' => 'orange, red, yellow'],
        '1',
        ['severity' => 'orange']
    );

    expect($node->forSubject($context)->outputs())->toBe(['yes' => ['1']]);
});

it('condition node rejects null actual on in operator', function () {
    app(SubjectAttributeRegistry::class)->register(
        SubjectAttribute::make('field', 'Field', 'text', fn ($s) => null),
    );

    $node = new ConditionNode;
    $run = new Run(['is_test' => false]);
    $context = new SubjectContext($run, 'c1', ['attribute' => 'field', 'operator' => 'in', 'value' => 'a, b, c'], '1', ['field' => null]);

    expect($node->forSubject($context)->outputs())->toBe(['no' => ['1']]);
});
