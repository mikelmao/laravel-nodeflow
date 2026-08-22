<?php

namespace Nodeflow\Console;

/**
 * One byte range that `ExtractNodeCommand::rewritableSpans()` has proven the
 * extraction itself will transform: the node's own file, its test file, or
 * one specific edited range inside the host's own NodeflowServiceProvider
 * (the `$nodes` entry, or the `use` import).
 *
 * WHY THIS EXISTS AS ITS OWN CLASS, given it carries no behaviour beyond its
 * own construction — the same reason `NodeReference` does. G5 subtracts
 * exactly the set `rewritableSpans()` returns from what
 * `NodeReferenceScanner::scan()` finds; a `NodeReference` describes something
 * FOUND, a `RewritableSpan` describes something that will be TRANSFORMED,
 * and conflating the two types would blur a distinction G5's own docblock
 * depends on: a survivor is a `NodeReference` not covered by any
 * `RewritableSpan`.
 */
final readonly class RewritableSpan
{
    public function __construct(
        public string $file,
        public int $byteStart,
        public int $byteEnd,
    ) {}

    /**
     * The entire byte range of $file. Used for the node's own file and its
     * test file: moving either rewrites the file's namespace, which moves
     * EVERY declaration in it (E47's own reasoning, from the other
     * direction) — so exempting anything less than the whole file would
     * itself be wrong, not merely incomplete.
     */
    public static function wholeFile(string $file): self
    {
        $size = filesize($file);

        return new self($file, 0, $size === false ? PHP_INT_MAX : $size);
    }

    /** Whether $file/[$byteStart, $byteEnd) together fully contain $otherByteStart/$otherByteEnd, in the same file. */
    public function covers(string $file, int $otherByteStart, int $otherByteEnd): bool
    {
        return $this->sameFile($file)
            && $otherByteStart >= $this->byteStart
            && $otherByteEnd <= $this->byteEnd;
    }

    /**
     * Canonical (realpath-resolved) comparison, not a raw string one:
     * NodeReferenceScanner and this class can reach the same file through
     * different (but equal) raw path spellings — one via a root handed to
     * scan(), the other via ReflectionClass::getFileName() or a path this
     * class built itself — and a symlink or a doubled slash would make an
     * ordinary string compare miss a match that is, on disk, the exact same
     * file.
     */
    private function sameFile(string $file): bool
    {
        return (realpath($this->file) ?: $this->file) === (realpath($file) ?: $file);
    }
}
