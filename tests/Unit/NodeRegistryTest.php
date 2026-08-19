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
