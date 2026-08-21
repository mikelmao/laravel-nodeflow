<?php

namespace Nodeflow\Console;

/**
 * Finds every place in a set of host roots that names a given node class, so
 * `nodeflow:extract-node` can refuse a move instead of leaving the host
 * fatal (E34, as amended by E45 and E46).
 *
 * WHY THIS GATE EXISTS. `NodeRegistry::register()` autoloads a node class
 * through `is_a()`. Once a class moves into a package, any reference to its
 * OLD name that survives the move throws in the host's provider `boot()` —
 * on every request. This scanner is what makes extraction refuse rather than
 * ship that fatal.
 *
 * BYTE RANGES, NOT FILES (E45). Every NodeReference carries a byte span, not
 * merely a file, because exemption must be checked per span, never per file
 * — see NodeReference's own docblock for the counterfactual that falsified
 * the file-level design.
 *
 * MULTIPLE NAMESPACES ARE REFUSED, NOT GUESSED AT. PhpNameResolver reads
 * only a file's FIRST `namespace` block and merges `imports()` into one flat
 * map across blocks — correct for the overwhelmingly common one-namespace
 * file, wrong for a file with more than one. Rather than resolve names
 * against the wrong block's imports, this scanner refuses such a file
 * outright, naming it, before the resolver is ever asked to answer for it.
 *
 * WHAT "import" MEANS HERE. A `use` statement — plain, aliased, or a group
 * member — is its own `import` reference whenever its FQCN equals the
 * target, in addition to whatever separate `class_constant`/`extends`
 * reference its later usage produces. This is deliberately NOT deduplicated
 * against a same-file usage: the Step 4 counterfactual test on this class
 * asserts a provider carrying both a plain `use App\Nodeflow\Nodes\
 * SendMessage;` import AND two later `SendMessage::class` usages reports
 * THREE references, not two — "the import, the $nodes entry, and the
 * legacy register() entry: three distinct spans in ONE file." Collapsing
 * the plain import into its later usage would silently drop a span E45
 * exists to keep separate.
 *
 * A `class_alias(...)` call is not a separate kind: its first argument is a
 * string literal, so it is already caught by the `string_literal` case in
 * scanStringLiterals() — asserted by a persisted test rather than special-
 * cased, per the instruction not to keep a kind nothing distinguishes.
 *
 * KNOWN LIMIT (E46), stated rather than silently missed: a class name built
 * or stored dynamically — string concatenation, a database column, a config
 * value read at runtime — is out of reach of a token-based scan. This
 * scanner only ever finds a class name that is spelled out, literally, in
 * PHP source.
 */
final class NodeReferenceScanner
{
    /**
     * Token IDs a class-reference element's NAME may be built from. PHP 8.3's
     * tokeniser normally emits ONE such token per qualified name
     * (T_NAME_QUALIFIED for `App\Foo`, T_NAME_FULLY_QUALIFIED for `\App\Foo`,
     * T_NAME_RELATIVE for `namespace\Foo`, plain T_STRING for `Foo`);
     * T_NS_SEPARATOR is accepted too so an older-style alternating
     * T_STRING/T_NS_SEPARATOR stream is still recognised.
     */
    private const NAME_TOKEN_IDS = [
        T_STRING,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
        T_NS_SEPARATOR,
    ];

    /**
     * Tokens dropped entirely before any pattern is matched: whitespace has
     * no meaning here, and rule 5 requires a comment or docblock to be
     * invisible to every scan, not merely skipped as a "reference" once
     * found — `token_get_all()` already swallows an ENTIRE comment into one
     * T_COMMENT/T_DOC_COMMENT token, so dropping that one token is enough;
     * nothing inside it is ever re-tokenised as a name, `::`, or string.
     */
    private const IGNORED_TOKEN_IDS = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /**
     * @param  list<string>  $absoluteRoots
     * @return list<NodeReference>
     *
     * @throws \RuntimeException when a scanned file declares more than one
     *                            `namespace` block
     */
    public static function scan(string $fqcn, array $absoluteRoots): array
    {
        $target = ltrim($fqcn, '\\');
        $references = [];

        foreach ($absoluteRoots as $root) {
            $hostPath = HostPath::root($root);

            foreach (self::phpFilesUnder($root) as $file) {
                // A file reached only through a symlink whose target escapes
                // this root is not "inside" it (HostPath::contains() is
                // canonical, per its own docblock) — skipping it here is
                // what keeps this scanner from following a symlink out of
                // the tree it was told to scan.
                if (! $hostPath->contains($file)) {
                    continue;
                }

                array_push($references, ...self::scanFile($file, $target));
            }
        }

        return $references;
    }

    /**
     * Every `*.php` and `*.blade.php` file under $root, found by a plain
     * recursive directory walk. `is_dir()` follows a symlinked directory (so
     * a file reached only through one is still found and handed to
     * `scan()`'s own `contains()` filter above, which is what actually
     * decides whether it counts) — this method itself does no filtering.
     *
     * @return \Generator<string>
     */
    private static function phpFilesUnder(string $root): \Generator
    {
        $entries = @scandir($root);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $root.'/'.$entry;

            if (is_dir($path)) {
                yield from self::phpFilesUnder($path);

                continue;
            }

            if (str_ends_with($entry, '.blade.php') || str_ends_with($entry, '.php')) {
                yield $path;
            }
        }
    }

    /** @return list<NodeReference> */
    private static function scanFile(string $file, string $target): array
    {
        $source = file_get_contents($file);

        if ($source === false) {
            return [];
        }

        $tokens = self::tokenise($source);

        $namespaceCount = 0;

        foreach ($tokens as $token) {
            if ($token['id'] === T_NAMESPACE) {
                $namespaceCount++;
            }
        }

        if ($namespaceCount > 1) {
            throw new \RuntimeException(
                "File [{$file}] declares more than one namespace; NodeReferenceScanner refuses ".
                'it rather than resolve names against PhpNameResolver\'s flat, first-block-only imports.'
            );
        }

        $meaningful = array_values(array_filter(
            $tokens,
            static fn (array $token) => ! in_array($token['id'], self::IGNORED_TOKEN_IDS, true),
        ));

        $resolver = PhpNameResolver::forSource($source);
        $runs = self::nameRuns($meaningful);

        $references = [];

        array_push($references, ...self::scanImports($meaningful, $target, $file));
        array_push($references, ...self::scanClassConstants($meaningful, $runs, $resolver, $target, $file));
        array_push($references, ...self::scanExtends($meaningful, $runs, $resolver, $target, $file));
        array_push($references, ...self::scanStringLiterals($meaningful, $target, $file));

        return $references;
    }

    /**
     * Every PHP token in $source, alongside its own raw byte range and line
     * number. token_get_all() is a lossless lexer — concatenating every
     * token's text in order reproduces $source exactly — so each token's raw
     * start is just the running length of every token before it, the same
     * property NodeRegistrationWriter::codeWithOffsets() relies on.
     *
     * @return list<array{id: int|null, text: string, start: int, end: int, line: int}>
     */
    private static function tokenise(string $source): array
    {
        $tokens = [];
        $raw = 0;
        $line = 1;

        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $id = is_array($token) ? $token[0] : null;
            $length = strlen($text);

            $tokens[] = ['id' => $id, 'text' => $text, 'start' => $raw, 'end' => $raw + $length, 'line' => $line];

            $line += substr_count($text, "\n");
            $raw += $length;
        }

        return $tokens;
    }

    /**
     * Every maximal run of consecutive NAME_TOKEN_IDS tokens in $meaningful —
     * a "written name" as PHP's own grammar would group it, whatever
     * construct (`::class`, `extends`, a bare expression) surrounds it.
     * Byte range and line come from the run's own first/last token, never
     * from neighbouring tokens.
     *
     * @param  list<array{id: int|null, text: string, start: int, end: int, line: int}>  $meaningful
     * @return list<array{startIndex: int, endIndex: int, text: string, byteStart: int, byteEnd: int, line: int}>
     */
    private static function nameRuns(array $meaningful): array
    {
        $runs = [];
        $count = count($meaningful);
        $i = 0;

        while ($i < $count) {
            if (! in_array($meaningful[$i]['id'], self::NAME_TOKEN_IDS, true)) {
                $i++;

                continue;
            }

            $startIndex = $i;
            $text = '';
            $byteStart = $meaningful[$i]['start'];
            $line = $meaningful[$i]['line'];
            $byteEnd = $byteStart;

            while ($i < $count && in_array($meaningful[$i]['id'], self::NAME_TOKEN_IDS, true)) {
                $text .= $meaningful[$i]['text'];
                $byteEnd = $meaningful[$i]['end'];
                $i++;
            }

            $runs[] = [
                'startIndex' => $startIndex,
                'endIndex' => $i - 1,
                'text' => $text,
                'byteStart' => $byteStart,
                'byteEnd' => $byteEnd,
                'line' => $line,
            ];
        }

        return $runs;
    }

    /**
     * A `class_constant` reference for each name-run immediately followed by
     * `::class` (T_DOUBLE_COLON then T_CLASS, both already adjacent in the
     * comment/whitespace-stripped $meaningful stream) whose PhpNameResolver
     * resolution equals $target. The byte range covers only the NAME — not
     * the `::class` suffix — so it isolates exactly the text a rewrite would
     * need to replace.
     *
     * @param  list<array{id: int|null, text: string, start: int, end: int, line: int}>  $meaningful
     * @param  list<array{startIndex: int, endIndex: int, text: string, byteStart: int, byteEnd: int, line: int}>  $runs
     * @return list<NodeReference>
     */
    private static function scanClassConstants(array $meaningful, array $runs, PhpNameResolver $resolver, string $target, string $file): array
    {
        $references = [];

        foreach ($runs as $run) {
            $colon = $meaningful[$run['endIndex'] + 1] ?? null;
            $class = $meaningful[$run['endIndex'] + 2] ?? null;

            if ($colon === null || $colon['id'] !== T_DOUBLE_COLON || $class === null || $class['id'] !== T_CLASS) {
                continue;
            }

            if ($resolver->resolve($run['text']) !== $target) {
                continue;
            }

            $references[] = new NodeReference($file, $run['line'], $run['byteStart'], $run['byteEnd'], 'class_constant');
        }

        return $references;
    }

    /**
     * An `extends` reference for each name-run in an `extends` clause whose
     * PhpNameResolver resolution equals $target. Walks every comma-separated
     * name after the `extends` keyword (an interface may extend several;
     * a class extends exactly one) rather than only the first, so a match
     * further along such a list is never silently skipped.
     *
     * @param  list<array{id: int|null, text: string, start: int, end: int, line: int}>  $meaningful
     * @param  list<array{startIndex: int, endIndex: int, text: string, byteStart: int, byteEnd: int, line: int}>  $runs
     * @return list<NodeReference>
     */
    private static function scanExtends(array $meaningful, array $runs, PhpNameResolver $resolver, string $target, string $file): array
    {
        $references = [];
        $runsByStart = [];

        foreach ($runs as $run) {
            $runsByStart[$run['startIndex']] = $run;
        }

        foreach ($meaningful as $index => $token) {
            if ($token['id'] !== T_EXTENDS) {
                continue;
            }

            $expected = $index + 1;

            while (isset($runsByStart[$expected])) {
                $run = $runsByStart[$expected];

                if ($resolver->resolve($run['text']) === $target) {
                    $references[] = new NodeReference($file, $run['line'], $run['byteStart'], $run['byteEnd'], 'extends');
                }

                $next = $meaningful[$run['endIndex'] + 1] ?? null;

                if ($next === null || $next['id'] !== null || $next['text'] !== ',') {
                    break;
                }

                $expected = $run['endIndex'] + 2;
            }
        }

        return $references;
    }

    /**
     * A `string_literal` reference for each T_CONSTANT_ENCAPSED_STRING whose
     * unquoted value, with leading backslashes trimmed, equals $target. This
     * is also how a `class_alias('Old\Fqcn', 'Legacy')` call is caught —
     * its first argument is exactly such a literal, so no special-cased
     * `class_alias` handling is needed.
     *
     * Heredoc/nowdoc string bodies are not T_CONSTANT_ENCAPSED_STRING tokens
     * and are not unquoted here; a class name spelled out only inside one
     * would not be found. Not exercised by this scanner's fixtures, and
     * consistent with E46: a name reachable only through a construct this
     * scanner does not parse is a stated limit, not a silent guess.
     *
     * @param  list<array{id: int|null, text: string, start: int, end: int, line: int}>  $meaningful
     * @return list<NodeReference>
     */
    private static function scanStringLiterals(array $meaningful, string $target, string $file): array
    {
        $references = [];

        foreach ($meaningful as $token) {
            if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = self::unquote($token['text']);

            if ($value === null) {
                continue;
            }

            if (ltrim($value, '\\') !== $target) {
                continue;
            }

            $references[] = new NodeReference($file, $token['line'], $token['start'], $token['end'], 'string_literal');
        }

        return $references;
    }

    /**
     * The value a single- or double-quoted T_CONSTANT_ENCAPSED_STRING token
     * denotes, or null for a quote style this does not decode (a heredoc or
     * nowdoc body is a different token type and never reaches this method).
     *
     * Single-quoted strings only ever escape `\'` and `\\`; every other
     * backslash is literal — which is exactly what leaves a class name's own
     * separators (`\`) untouched. `stripcslashes()` is used for the
     * double-quoted case as a close approximation; it is not a byte-perfect
     * implementation of PHP's own double-quoted escape grammar, but a class
     * name contains no character whose escape sequence the two disagree on.
     */
    private static function unquote(string $raw): ?string
    {
        if ($raw === '' || ($raw[0] !== "'" && $raw[0] !== '"')) {
            return null;
        }

        $inner = substr($raw, 1, -1);

        if ($raw[0] === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }

        return stripcslashes($inner);
    }

    /**
     * An `import` reference for each `use` statement member whose FQCN
     * equals $target, EXCEPT a plain, unaliased, ungrouped `use
     * Fully\Qualified\Name;` — see this class's own docblock for why that
     * one form is deliberately not reported. Byte range covers only the
     * name text before any `as Alias`.
     *
     * @param  list<array{id: int|null, text: string, start: int, end: int, line: int}>  $meaningful
     * @return list<NodeReference>
     */
    private static function scanImports(array $meaningful, string $target, string $file): array
    {
        $count = count($meaningful);
        $namespaceBraces = self::namespaceBraceIndexes($meaningful);
        $braceKinds = [];
        $references = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $meaningful[$i];

            if ($token['id'] === null) {
                if ($token['text'] === '{') {
                    $braceKinds[] = isset($namespaceBraces[$i]) ? 'namespace' : 'other';
                } elseif ($token['text'] === '}') {
                    array_pop($braceKinds);
                }

                continue;
            }

            if ($token['id'] !== T_USE) {
                continue;
            }

            // A `use` inside a non-namespace brace is a trait use in a class
            // body or a closure's captured-variable list, neither an import.
            if (in_array('other', $braceKinds, true)) {
                continue;
            }

            $following = $meaningful[$i + 1] ?? null;

            if ($following !== null && $following['id'] !== null && in_array($following['id'], [T_FUNCTION, T_CONST], true)) {
                $i = self::indexOfSemicolon($meaningful, $i + 1);

                continue;
            }

            [$i, $found] = self::parseUseStatement($meaningful, $i + 1, $target, $file);
            array_push($references, ...$found);
        }

        return $references;
    }

    /**
     * Consumes one `use` statement starting just after the `use` keyword,
     * handling the plain, aliased, and group forms, and returns the index of
     * its terminating `;` alongside every matching member's reference.
     *
     * @param  list<array{id: int|null, text: string, start: int, end: int, line: int}>  $meaningful
     * @return array{0: int, 1: list<NodeReference>}
     */
    private static function parseUseStatement(array $meaningful, int $start, string $target, string $file): array
    {
        $count = count($meaningful);
        $prefix = '';
        $currentText = '';
        $currentStart = null;
        $lastNameEnd = null;
        $lastNameLine = null;
        $inGroup = false;
        $expectAlias = false;
        $references = [];

        for ($i = $start; $i < $count; $i++) {
            $token = $meaningful[$i];

            if ($token['id'] !== null) {
                if ($token['id'] === T_AS) {
                    $expectAlias = true;

                    continue;
                }

                if ($expectAlias) {
                    // Exactly one alias token follows `as`; consumed and
                    // discarded — an import reference's byte range is the
                    // IMPORTED name, never the alias it is given.
                    $expectAlias = false;

                    continue;
                }

                if (in_array($token['id'], self::NAME_TOKEN_IDS, true)) {
                    if ($currentStart === null) {
                        $currentStart = $i;
                        $lastNameLine = $token['line'];
                    }

                    $currentText .= $token['text'];
                    $lastNameEnd = $token['end'];
                }

                continue;
            }

            if ($token['text'] === '{') {
                $inGroup = true;
                $prefix = rtrim($currentText, '\\').'\\';
                $currentText = '';
                $currentStart = null;

                continue;
            }

            if ($token['text'] === ',' || $token['text'] === '}' || $token['text'] === ';') {
                if ($currentText !== '') {
                    $fqcn = trim(($inGroup ? $prefix : '').$currentText, '\\');

                    if ($fqcn === $target) {
                        $references[] = new NodeReference(
                            $file,
                            $lastNameLine,
                            $meaningful[$currentStart]['start'],
                            $lastNameEnd,
                            'import',
                        );
                    }
                }

                $currentText = '';
                $currentStart = null;

                if ($token['text'] === ';') {
                    return [$i, $references];
                }

                if ($token['text'] === '}') {
                    $inGroup = false;
                }
            }
        }

        return [$count - 1, $references];
    }

    /**
     * Token index of every `{` opening a namespace's braced body
     * (`namespace App\Providers { ... }`, or the bare global
     * `namespace { ... }`) — ported from PhpNameResolver's identical
     * private helper, so scanImports() can tell that brace apart from a
     * class body's or closure's the same way PhpNameResolver does.
     *
     * @param  list<array{id: int|null, text: string, start: int, end: int, line: int}>  $meaningful
     * @return array<int, true>
     */
    private static function namespaceBraceIndexes(array $meaningful): array
    {
        $indexes = [];
        $count = count($meaningful);

        for ($i = 0; $i < $count; $i++) {
            if ($meaningful[$i]['id'] !== T_NAMESPACE) {
                continue;
            }

            $j = $i + 1;

            while ($j < $count && in_array($meaningful[$j]['id'], self::NAME_TOKEN_IDS, true)) {
                $j++;
            }

            if (($meaningful[$j]['id'] ?? false) === null && ($meaningful[$j]['text'] ?? null) === '{') {
                $indexes[$j] = true;
            }
        }

        return $indexes;
    }

    /**
     * @param  list<array{id: int|null, text: string, start: int, end: int, line: int}>  $meaningful
     */
    private static function indexOfSemicolon(array $meaningful, int $start): int
    {
        $count = count($meaningful);

        for ($i = $start; $i < $count; $i++) {
            if ($meaningful[$i]['id'] === null && $meaningful[$i]['text'] === ';') {
                return $i;
            }
        }

        return $count - 1;
    }
}
