<?php

namespace Nodeflow\Console;

/**
 * One place in a host file that names a node class, found by
 * NodeReferenceScanner.
 *
 * WHY A BYTE RANGE, NOT JUST A FILE (E45). The first design draft exempted
 * whole FILES the extraction command rewrites. The host provider IS such a
 * file — the rewrite only touches the `$nodes` entry and the `use` import —
 * so a legacy `Nodeflow::register([SendMessage::class])` call living in that
 * SAME file was exempted rather than refused: the exact case the scan exists
 * to catch. Carrying `byteStart`/`byteEnd` per reference, rather than only a
 * file path, is what lets a caller exempt one proven span (the entry it just
 * rewrote) while still refusing on every other span in the same file.
 *
 * `kind` is one of `class_constant`, `string_literal`, `import`, `extends`.
 * There is no `class_alias` kind: a `class_alias(...)` call's first argument
 * is a string literal, already caught by the `string_literal` kind, so a
 * dedicated kind for it would be metadata nothing distinguishes.
 */
final readonly class NodeReference
{
    public function __construct(
        public string $file,
        public int $line,
        public int $byteStart,
        public int $byteEnd,
        public string $kind,
    ) {}
}
