<?php

namespace Nodeflow\Execution;

use Closure;
use InvalidArgumentException;
use Iterator;
use IteratorAggregate;
use Traversable;

/**
 * A factory Closure and an IteratorAggregate implementation must return a
 * fresh iterable or iterator for every replay.
 *
 * @implements IteratorAggregate<int, string>
 */
final class ReplayableSubjectIds implements IteratorAggregate
{
    private function __construct(private readonly Closure $factory) {}

    /**
     * @param  iterable<mixed>|Closure(): iterable<mixed>  $subjectIds
     */
    public static function from(iterable|Closure $subjectIds): self
    {
        if ($subjectIds instanceof Closure) {
            return new self($subjectIds);
        }

        if (is_array($subjectIds)) {
            return new self(static fn (): array => $subjectIds);
        }

        if ($subjectIds instanceof IteratorAggregate) {
            return new self(static fn (): Traversable => $subjectIds->getIterator());
        }

        if ($subjectIds instanceof Iterator) {
            throw new InvalidArgumentException(
                'A directly supplied subject ID iterator may be one-shot; provide a factory Closure that returns a fresh iterable for every replay instead.'
            );
        }

        throw new InvalidArgumentException('Subject IDs must be replayable.');
    }

    /** @return Traversable<int, string> */
    public function getIterator(): Traversable
    {
        $subjectIds = ($this->factory)();

        if (! is_iterable($subjectIds)) {
            throw new InvalidArgumentException('A replayable subject ID factory must return an iterable.');
        }

        foreach ($subjectIds as $subjectId) {
            $subjectId = (string) $subjectId;

            if (trim($subjectId) === '') {
                throw new InvalidArgumentException('A trigger tenant match must not contain a blank subject ID.');
            }

            yield $subjectId;
        }
    }

    public function isEmpty(): bool
    {
        foreach ($this as $_) {
            return false;
        }

        return true;
    }
}
