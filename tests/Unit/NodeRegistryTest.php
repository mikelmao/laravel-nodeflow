<?php

use Nodeflow\Nodes\NodeRegistry;
use Tests\Support\FakeSendNode;

it('resolves a node by its stable type string', function () {
    $registry = new NodeRegistry;
    $registry->register(FakeSendNode::class);

    expect($registry->resolve('test.send'))->toBeInstanceOf(FakeSendNode::class)
        ->and($registry->has('test.send'))->toBeTrue();
});

it('resolves a renamed type through an alias', function () {
    $registry = new NodeRegistry;
    $registry->register(FakeSendNode::class);
    $registry->alias('test.old_send', 'test.send');

    expect($registry->resolve('test.old_send'))->toBeInstanceOf(FakeSendNode::class);
});

it('throws a typed error for an unknown type', function () {
    $registry = new NodeRegistry;

    expect(fn () => $registry->resolve('test.missing'))
        ->toThrow(Nodeflow\Nodes\UnknownNodeTypeException::class, 'test.missing');
});

it('validates config against the definition', function () {
    $node = new FakeSendNode;

    expect($node->validate(['channel' => 'sms']))->toBe([])
        ->and($node->validate([]))->toHaveKey('channel')
        ->and($node->validate(['channel' => 'carrier-pigeon']))->toHaveKey('channel');
});

it('builds a palette grouped for the editor', function () {
    $registry = new NodeRegistry;
    $registry->register(FakeSendNode::class);

    $palette = $registry->palette();

    expect($palette)->toHaveCount(1)
        ->and($palette[0]['type'])->toBe('test.send')
        ->and($palette[0]['cardinality'])->toBe(['subject']);
});

it('refuses to register a node implementing neither cardinality interface', function () {
    // Spec section 5's example, written verbatim, used to register, validate,
    // publish, start a run and only then throw at NodeRunner.php:66 the first
    // time a subject reached it. Failing here puts the error in front of the
    // author who can fix it.
    $registry = new NodeRegistry;

    expect(fn () => $registry->register(Tests\Support\FakeNoCardinalityNode::class))
        ->toThrow(
            Nodeflow\Nodes\InvalidNodeException::class,
            Tests\Support\FakeNoCardinalityNode::class,
        );

    expect($registry->has('test.no-cardinality'))->toBeFalse();
});

it('names both interfaces in the registration error so the author knows the fix', function () {
    $registry = new NodeRegistry;

    try {
        $registry->register(Tests\Support\FakeNoCardinalityNode::class);
        $message = '';
    } catch (Nodeflow\Nodes\InvalidNodeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('HandlesSubject')
        ->and($message)->toContain('HandlesAudience');
});

it('registers a node implementing only HandlesAudience', function () {
    $registry = new NodeRegistry;
    $registry->register(Nodeflow\Nodes\Core\ExitNode::class);

    expect($registry->has('core.exit'))->toBeTrue()
        ->and($registry->palette()[0]['cardinality'])->toBe(['audience']);
});

it('does not ship a core fan-out node', function () {
    // core.split returned the same subject list under two outputs. advance() then
    // issued two sequential UPDATEs keyed on (run_id, subject_type, subject_id,
    // status='active') and the second overwrote the first: measured with three
    // subjects, all three ended at branch 'b' and none at 'a', while
    // node_executions recorded subject_count=3 for *both* outputs. It is not
    // fixable in this schema — nodeflow_run_subjects has
    // unique(run_id, subject_type, subject_id) and one current_node_id, so a
    // subject cannot be in two places. Removed from v1 rather than shipped broken.
    expect(app(NodeRegistry::class)->has('core.split'))->toBeFalse()
        ->and(class_exists('Nodeflow\Nodes\Core\SplitNode'))->toBeFalse();

    expect(collect(app(NodeRegistry::class)->palette())->pluck('type')->all())
        ->not->toContain('core.split');
});
