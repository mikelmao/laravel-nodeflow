<?php

use Nodeflow\Execution\ReplayableSubjectIds;

it('defers factory invocation and replays normalized subject IDs', function () {
    $calls = 0;
    $subjectIds = ReplayableSubjectIds::from(function () use (&$calls): array {
        $calls++;

        return [10, 20];
    });

    expect($calls)->toBe(0)
        ->and(iterator_to_array($subjectIds, false))->toBe(['10', '20'])
        ->and($calls)->toBe(1)
        ->and(iterator_to_array($subjectIds, false))->toBe(['10', '20'])
        ->and($calls)->toBe(2);
});

it('replays by requesting a new iterator from an iterator aggregate', function () {
    $subjectIds = new class implements IteratorAggregate
    {
        public int $calls = 0;

        public function getIterator(): Traversable
        {
            $this->calls++;

            return new ArrayIterator([10, 20]);
        }
    };
    $replayable = ReplayableSubjectIds::from($subjectIds);

    expect(iterator_to_array($replayable, false))->toBe(['10', '20'])
        ->and(iterator_to_array($replayable, false))->toBe(['10', '20'])
        ->and($subjectIds->calls)->toBe(2);
});

it('rejects a directly supplied one-shot generator', function () {
    $generator = (function (): Generator {
        yield 10;
    })();

    expect(fn () => ReplayableSubjectIds::from($generator))
        ->toThrow(\InvalidArgumentException::class, 'one-shot');
});

it('rejects non-iterable factory results when iterated', function () {
    $subjectIds = ReplayableSubjectIds::from(fn (): string => 'not an audience');

    expect(fn () => iterator_to_array($subjectIds, false))
        ->toThrow(\InvalidArgumentException::class, 'iterable');
});

it('rejects blank subject IDs when iterated', function () {
    $subjectIds = ReplayableSubjectIds::from([10, '  ']);

    expect(fn () => iterator_to_array($subjectIds, false))
        ->toThrow(\InvalidArgumentException::class, 'blank subject ID');
});

it('checks replayable factories for emptiness without consuming their next replay', function () {
    $calls = 0;
    $nonempty = ReplayableSubjectIds::from(function () use (&$calls): array {
        $calls++;

        return [10];
    });
    $empty = ReplayableSubjectIds::from(fn (): array => []);

    expect($empty->isEmpty())->toBeTrue()
        ->and($nonempty->isEmpty())->toBeFalse()
        ->and($calls)->toBe(1)
        ->and(iterator_to_array($nonempty, false))->toBe(['10'])
        ->and($calls)->toBe(2);
});
