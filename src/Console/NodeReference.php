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
 * `kind` is one of `class_constant`, `string_literal`, `import`, `extends`,
 * `reference`. Detection is universal (round-2 review, Critical 2): ANY
 * name-run that resolves to the target is a reference, whatever syntax
 * surrounds it, so `kind` is classification metadata on top of that one
 * rule, not the mechanism that decides whether something is a reference at
 * all. `reference` is the generic bucket for everything that is not one of
 * the other four specific shapes — `new Name()`, `Name::method()`,
 * `instanceof Name`, `catch (Name $e)`, a typed property/parameter/return,
 * `implements Name`, and so on. There is no `class_alias` kind: a
 * `class_alias(...)` call's first argument is a string literal, already
 * caught by the `string_literal` kind, so a dedicated kind for it would be
 * metadata nothing distinguishes. See NodeReferenceScanner's own docblock
 * for the full account of what each kind means and what universal detection
 * changed.
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
