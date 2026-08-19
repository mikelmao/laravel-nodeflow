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
