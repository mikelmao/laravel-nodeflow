<?php

use Nodeflow\Execution\NodeResult;

it('records one subject taking one output', function () {
    expect(NodeResult::forSubject('42', 'sent')->outputs())->toBe(['sent' => ['42']]);
});

it('merges many single-subject results into a partition', function () {
    $merged = NodeResult::merge(
        NodeResult::forSubject('1', 'yes'),
        NodeResult::forSubject('2', 'no'),
        NodeResult::forSubject('3', 'yes'),
    );

    expect($merged->outputs())->toBe(['yes' => ['1', '3'], 'no' => ['2']]);
});

it('accepts a bulk partition directly', function () {
    $result = NodeResult::partition(['sent' => ['1', '2'], 'skipped' => ['3']]);

    expect($result->outputs())->toBe(['sent' => ['1', '2'], 'skipped' => ['3']])
        ->and($result->subjectCount())->toBe(3);
});

it('records failures separately from outputs', function () {
    $result = NodeResult::failed('7', 'gateway timeout');

    expect($result->outputs())->toBe([])
        ->and($result->failures())->toBe(['7' => 'gateway timeout']);
});

it('merges failures alongside outputs', function () {
    $merged = NodeResult::merge(
        NodeResult::forSubject('1', 'sent'),
        NodeResult::failed('2', 'no channel'),
    );

    expect($merged->outputs())->toBe(['sent' => ['1']])
        ->and($merged->failures())->toBe(['2' => 'no channel']);
});

it('keeps the first failure message when the same subject fails twice', function () {
    $merged = NodeResult::merge(
        NodeResult::failed('5', 'first error'),
        NodeResult::failed('5', 'second error'),
    );

    expect($merged->failures())->toBe(['5' => 'first error']);
});

it('excludes failed subjects from subjectCount', function () {
    $merged = NodeResult::merge(
        NodeResult::forSubject('1', 'sent'),
        NodeResult::forSubject('2', 'sent'),
        NodeResult::failed('3', 'gateway timeout'),
    );

    expect($merged->subjectCount())->toBe(2)
        ->and($merged->outputs())->toBe(['sent' => ['1', '2']])
        ->and($merged->failures())->toBe(['3' => 'gateway timeout']);
});
