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
 * DETECTION IS UNIVERSAL, NOT PER-CONSTRUCT (round-2 review, Critical 2).
 * The first cut of this class detected exactly four syntactic shapes —
 * `<name>::class`, `extends <name>`, a `use` import, a string literal — and
 * missed every other way a class name can appear as a bare identifier:
 * `new Name()`, `Name::method()`, `instanceof Name`, `catch (Name $e)`, a
 * typed property/parameter/return, `implements Name`, and more. A host
 * whose ONLY reference to the moved class was `$p = new SendMessage();
 * SendMessage::warmUp();` sailed through undetected. The rule is now: every
 * maximal run of name tokens (`T_STRING`, `T_NAME_QUALIFIED`,
 * `T_NAME_FULLY_QUALIFIED`, `T_NAME_RELATIVE`) is resolved through
 * `PhpNameResolver`, and ANY run whose resolution equals the target is a
 * reference, regardless of what syntax surrounds it. `kind` survives as
 * classification metadata on TOP of that one rule, not as the mechanism
 * that decides whether something is a reference at all:
 *   - `class_constant` — immediately followed by `::class`.
 *   - `extends`         — inside an `extends` clause (walks every
 *                         comma-separated name, not just the first, so a
 *                         `interface I extends \Other\T, Target` reference
 *                         is not missed).
 *   - `import`          — a `use` statement member (see below).
 *   - `string_literal`  — a quoted, heredoc, or nowdoc string body.
 *   - `reference`       — everything else this rule now also catches:
 *                         `new`, a static call, `instanceof`, `catch`, a
 *                         typed property/parameter/return, `implements`,
 *                         and any other bare use of the name.
 *
 * A DECLARATION IS NOT A REFERENCE. Universal detection means the scanner
 * would otherwise flag the target class's OWN declaration — `class
 * SendMessage extends Base { ... }` — every time it scans the very file the
 * class lives in (a near-certainty: that file normally sits under `app/`,
 * one of the roots this scanner is handed). `class`/`interface`/`trait`/
 * `enum`'s own declared name, and a `namespace` declaration's own name, are
 * both skipped outright — they define a symbol, they do not use one.
 *
 * A bare identifier that happens to spell the target's short name in some
 * OTHER declaration position — a constant, a function, a method — is NOT
 * excluded the same way. Matching it requires that position's file to sit
 * in the exact same namespace as the target AND reuse its exact short name,
 * which is far less likely than "this file simply IS the target's own
 * declaration" (the one case excluded above). Left as a stated, accepted
 * trade-off of universal detection: the cost of a false positive here is a
 * look, never a fatal, so the risk runs in the safe direction.
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
 * target, ALWAYS, with no deduplication against a later usage the universal
 * rule above separately catches in the same file. The Step 4 counterfactual
 * test on this class asserts a provider carrying both a plain `use
 * App\Nodeflow\Nodes\SendMessage;` import AND two later `SendMessage::class`
 * usages reports THREE references, not two — "the import, the $nodes entry,
 * and the legacy register() entry: three distinct spans in ONE file."
 * Collapsing the plain import into its later usage would silently drop a
 * span E45 exists to keep separate. An import's FQCN is looked up through
 * `PhpNameResolver::imports()` (built once per file, by the same code every
 * OTHER lookup in this codebase trusts) rather than re-derived here — this
 * class only walks the `use` statement's own tokens far enough to find each
 * member's byte range and its alias, if any; PhpNameResolver decides what it
 * actually imports.
 *
 * A `class_alias(...)` call is not a separate kind: its first argument is a
 * string literal, so it is already caught by the `string_literal` case —
 * asserted by a persisted test rather than special-cased, per the
 * instruction not to keep a kind nothing distinguishes.
 *
 * KNOWN LIMITS, stated rather than silently missed:
 *   - E46: a class name built or stored dynamically — string concatenation,
 *     a database column, a config value read at runtime — is out of reach
 *     of a token-based scan.
 *   - Only `.php`, `.blade.php` (itself ending `.php`), `.phtml`, and `.inc`
 *     files are scanned. A reference spelled out only inside a file with
 *     some other extension is out of reach.
 *   - A heredoc/nowdoc body or a double-quoted literal is unescaped only
 *     for `\\` (folded to one literal backslash); every other backslash
 *     sequence is left exactly as written, matching real PHP — see
 *     foldEscapedBackslash()'s docblock for the bug this fixed.
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
     * Keywords that declare a new class-like symbol. The name immediately
     * following one of these is that symbol's OWN name — a definition, not
     * a reference — and is skipped rather than resolved.
     */
    private const DECLARATION_KEYWORD_IDS = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];

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

            foreach (self::scannableFilesUnder($root) as $file) {
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
     * Every `*.php` (which already covers `*.blade.php`), `*.phtml`, and
     * `*.inc` file under $root, found by a plain recursive directory walk.
     * `is_dir()` follows a symlinked directory (so a file reached only
     * through one is still found and handed to `scan()`'s own `contains()`
     * filter above, which is what actually decides whether it counts) —
     * this method itself does no filtering.
     *
     * @return \Generator<string>
     */
    private static function scannableFilesUnder(string $root): \Generator
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
                yield from self::scannableFilesUnder($path);

                continue;
            }

            if (str_ends_with($entry, '.blade.php')
                || str_ends_with($entry, '.php')
                || str_ends_with($entry, '.phtml')
                || str_ends_with($entry, '.inc')) {
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

        $references = self::scanTokens($meaningful, $resolver, $target, $file);
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
     * The single left-to-right walk that produces every `import`,
     * `class_constant`, `extends`, and generic `reference` hit in one pass —
     * see this class's own docblock ("DETECTION IS UNIVERSAL") for why a
     * name-run's resolution, not its syntactic position, decides whether it
     * is a reference at all.
     *
     * @param  list<array{id: int|null, text: string, start: int, end: int, line: int}>  $meaningful
     * @return list<NodeReference>
     */
    private static function scanTokens(array $meaningful, PhpNameResolver $resolver, string $target, string $file): array
    {
        $count = count($meaningful);
        $namespaceBraces = self::namespaceBraceIndexes($meaningful);
        $braceKinds = [];
        $clauseKind = null;
        $imports = $resolver->imports();
        $references = [];
        $i = 0;

        // A `while` loop, deliberately, not `for (...; $i++)`: several
        // branches below already set $i precisely themselves (a run's own
        // `nextIndex`, a `use` statement's own terminating `;`), and a
        // `for` header's own `$i++` would ALSO fire on top of that after
        // every `continue` — a double-increment that silently drops the
        // very next token in the stream. That defect shipped once already:
        // it skipped straight from a `::class` fetch's `class` keyword into
        // treating it as a fresh `class` DECLARATION keyword, which then
        // skipped ITS following token too, and the drift compounded for the
        // rest of the file — `new SendMessage()` and `SendMessage::warmUp()`
        // later in the same file were never reached. See the mutation-tested
        // regression test naming this exact failure.
        while ($i < $count) {
            $token = $meaningful[$i];

            if ($token['id'] === null) {
                if ($token['text'] === '{') {
                    $braceKinds[] = isset($namespaceBraces[$i]) ? 'namespace' : 'other';
                } elseif ($token['text'] === '}') {
                    array_pop($braceKinds);
                }

                $i++;

                continue;
            }

            if ($token['id'] === T_NAMESPACE) {
                // A namespace declares itself; its own name is not a use of
                // anything.
                $i++;

                while ($i < $count && in_array($meaningful[$i]['id'], self::NAME_TOKEN_IDS, true)) {
                    $i++;
                }

                continue;
            }

            if (in_array($token['id'], self::DECLARATION_KEYWORD_IDS, true)) {
                // The class/interface/trait/enum's OWN name — a definition,
                // never a reference. Absent for an anonymous `new class {}`.
                $i++;

                while ($i < $count && in_array($meaningful[$i]['id'], self::NAME_TOKEN_IDS, true)) {
                    $i++;
                }

                continue;
            }

            if ($token['id'] === T_EXTENDS) {
                $clauseKind = 'extends';
                $i++;

                continue;
            }

            if ($token['id'] === T_IMPLEMENTS) {
                // Not its own kind (round-2 ruling): an implemented
                // interface is caught by the universal rule below and
                // labelled the generic `reference` kind, same as `new` or a
                // static call.
                $clauseKind = null;
                $i++;

                continue;
            }

            if ($token['id'] === T_USE) {
                // A `use` inside a non-namespace brace is a trait use in a
                // class body or a closure's captured-variable list, neither
                // an import.
                if (in_array('other', $braceKinds, true)) {
                    $i++;

                    continue;
                }

                $following = $meaningful[$i + 1] ?? null;

                if ($following !== null && $following['id'] !== null && in_array($following['id'], [T_FUNCTION, T_CONST], true)) {
                    $i = self::indexOfSemicolon($meaningful, $i + 1) + 1;

                    continue;
                }

                [$semicolon, $found] = self::parseUseStatement($meaningful, $i + 1, $imports, $target, $file);
                array_push($references, ...$found);
                $i = $semicolon + 1;

                continue;
            }

            if (in_array($token['id'], self::NAME_TOKEN_IDS, true)) {
                $run = self::consumeNameRun($meaningful, $i);
                $i = $run['nextIndex'];

                if ($resolver->resolve($run['text']) === $target) {
                    $kind = self::classify($meaningful, $run, $clauseKind);
                    $references[] = new NodeReference($file, $run['line'], $run['byteStart'], $run['byteEnd'], $kind);
                }

                // The clause a comma-separated `extends`/`implements` list is
                // reading survives past the comma into the NEXT name-run;
                // anything else — the list's own end (`{`), a switch to
                // `implements`, or plain code — ends it.
                $next = $meaningful[$run['nextIndex']] ?? null;

                if ($clauseKind !== null && ($next === null || $next['id'] !== null || $next['text'] !== ',')) {
                    $clauseKind = null;
                }

                continue;
            }

            $i++;
        }

        return $references;
    }

    /**
     * Consumes the maximal run of NAME_TOKEN_IDS tokens starting at $start
     * and returns its text, byte range, starting line, and the index just
     * past it.
     *
     * @param  list<array{id: int|null, text: string, start: int, end: int, line: int}>  $meaningful
     * @return array{text: string, byteStart: int, byteEnd: int, line: int, nextIndex: int}
     */
    private static function consumeNameRun(array $meaningful, int $start): array
    {
        $count = count($meaningful);
        $text = '';
        $byteStart = $meaningful[$start]['start'];
        $byteEnd = $byteStart;
        $line = $meaningful[$start]['line'];
        $i = $start;

        while ($i < $count && in_array($meaningful[$i]['id'], self::NAME_TOKEN_IDS, true)) {
            $text .= $meaningful[$i]['text'];
            $byteEnd = $meaningful[$i]['end'];
            $i++;
        }

        return ['text' => $text, 'byteStart' => $byteStart, 'byteEnd' => $byteEnd, 'line' => $line, 'nextIndex' => $i];
    }

    /**
     * Which `kind` a matched name-run gets, given what immediately follows
     * it (`::class`) or the `extends`/`implements` clause it is reading (if
     * any). Everything else — `new`, a static call, `instanceof`, `catch`,
     * a typed declaration, `implements`, or plain code — is the generic
     * `reference` kind.
     *
     * @param  list<array{id: int|null, text: string, start: int, end: int, line: int}>  $meaningful
     * @param  array{nextIndex: int}  $run
     */
    private static function classify(array $meaningful, array $run, ?string $clauseKind): string
    {
        $colon = $meaningful[$run['nextIndex']] ?? null;
        $class = $meaningful[$run['nextIndex'] + 1] ?? null;

        if ($colon !== null && $colon['id'] === T_DOUBLE_COLON && $class !== null && $class['id'] === T_CLASS) {
            return 'class_constant';
        }

        return $clauseKind === 'extends' ? 'extends' : 'reference';
    }

    /**
     * Token index of every `{` opening a namespace's braced body
     * (`namespace App\Providers { ... }`, or the bare global
     * `namespace { ... }`), so scanTokens() can tell that brace apart from a
     * class body's or closure's the same way PhpNameResolver's own private
     * equivalent does.
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

            // round-2 review, Critical 1: `??` yields its right-hand side
            // when the LEFT is null — and a `{` token's own `id` IS null, so
            // `($meaningful[$j]['id'] ?? false) === null` can never be true.
            // That earlier form made this method always return `[]`, which
            // made scanTokens() classify EVERY namespace brace as `'other'`
            // and silently discard every `use` inside one. Existence must be
            // checked explicitly instead of leaning on `??`'s null-coalesce.
            if (array_key_exists($j, $meaningful) && $meaningful[$j]['id'] === null && $meaningful[$j]['text'] === '{') {
                $indexes[$j] = true;
            }
        }

        return $indexes;
    }

    /**
     * Consumes one `use` statement starting just after the `use` keyword,
     * handling the plain, aliased, and group forms, and returns the index of
     * its terminating `;` alongside every matching member's reference.
     *
     * Each member's FQCN is decided by looking its key (its alias, or its
     * own short name) up in $imports — the SAME map `PhpNameResolver`
     * already built for this file — rather than re-deriving it from a
     * hand-tracked group prefix. This walk only needs to find each member's
     * own byte range and alias; PhpNameResolver, not a second copy of its
     * logic, decides what the statement actually imports.
     *
     * @param  list<array{id: int|null, text: string, start: int, end: int, line: int}>  $meaningful
     * @param  array<string, string>  $imports  alias (lowercased) => FQCN, from PhpNameResolver::imports()
     * @return array{0: int, 1: list<NodeReference>}
     */
    private static function parseUseStatement(array $meaningful, int $start, array $imports, string $target, string $file): array
    {
        $count = count($meaningful);
        $currentText = '';
        $currentStart = null;
        $lastNameEnd = null;
        $lastNameLine = null;
        $alias = null;
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
                    $alias = $token['text'];
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
                // Whatever text accumulated before a group's `{` is the
                // shared prefix, not a member in its own right — discard it
                // and start fresh for the first real member.
                $currentText = '';
                $currentStart = null;

                continue;
            }

            if ($token['text'] === ',' || $token['text'] === '}' || $token['text'] === ';') {
                if ($currentText !== '') {
                    $key = strtolower($alias ?? self::lastSegment($currentText));

                    if (($imports[$key] ?? null) === $target) {
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
                $alias = null;

                if ($token['text'] === ';') {
                    return [$i, $references];
                }
            }
        }

        return [$count - 1, $references];
    }

    private static function lastSegment(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
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

    /**
     * A `string_literal` reference for each quoted, heredoc, or nowdoc
     * string whose unquoted value, with leading backslashes trimmed, equals
     * $target. This is also how a `class_alias('Old\Fqcn', 'Legacy')` call
     * is caught — its first argument is exactly such a literal, so no
     * special-cased `class_alias` handling is needed.
     *
     * @param  list<array{id: int|null, text: string, start: int, end: int, line: int}>  $meaningful
     * @return list<NodeReference>
     */
    private static function scanStringLiterals(array $meaningful, string $target, string $file): array
    {
        $references = [];

        foreach ($meaningful as $token) {
            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
                $candidates = self::unquote($token['text']);
            } elseif ($token['id'] === T_ENCAPSED_AND_WHITESPACE) {
                $candidates = self::heredocCandidates($token['text']);
            } else {
                continue;
            }

            $matches = false;

            foreach ($candidates as $candidate) {
                if (ltrim($candidate, '\\') === $target) {
                    $matches = true;

                    break;
                }
            }

            if (! $matches) {
                continue;
            }

            $references[] = new NodeReference($file, $token['line'], $token['start'], $token['end'], 'string_literal');
        }

        return $references;
    }

    /**
     * Every value a single- or double-quoted T_CONSTANT_ENCAPSED_STRING
     * token could denote, as a list to check against — normally exactly
     * one candidate, empty only for a quote style this does not decode (a
     * heredoc or nowdoc body is a different token type —
     * T_ENCAPSED_AND_WHITESPACE — and never reaches this method; see
     * heredocCandidates()).
     *
     * Single-quoted strings only ever escape `\'` and `\\`; every other
     * backslash is literal — which is exactly what leaves a class name's own
     * separators (`\`) untouched. The double-quoted case is folded through
     * foldEscapedBackslash() rather than `stripcslashes()` — see that
     * method's docblock for the real bug that choice fixes.
     *
     * @return list<string>
     */
    private static function unquote(string $raw): array
    {
        if ($raw === '' || ($raw[0] !== "'" && $raw[0] !== '"')) {
            return [];
        }

        $inner = substr($raw, 1, -1);

        if ($raw[0] === "'") {
            return [str_replace(["\\'", '\\\\'], ["'", '\\'], $inner)];
        }

        return [self::foldEscapedBackslash($inner)];
    }

    /**
     * Every value a T_ENCAPSED_AND_WHITESPACE token (a heredoc or nowdoc
     * body's literal content, with no interpolated variable) could denote.
     *
     * The token's own text always carries exactly one trailing newline —
     * PHP's grammar requires the closing identifier to start its own line —
     * which is stripped before comparison. A nowdoc body is entirely
     * literal; a heredoc body escapes like a double-quoted string. Which
     * one this token came from depends on the OPENING `<<<` marker, several
     * tokens earlier, which this method does not have access to — rather
     * than thread that context through the whole walk, both the raw and
     * the folded form are returned as candidates, so either a nowdoc's
     * literal backslash or a heredoc's escaped one is checked.
     *
     * @return list<string>
     */
    private static function heredocCandidates(string $raw): array
    {
        $trimmed = rtrim($raw, "\r\n");

        if ($trimmed === '') {
            return [];
        }

        $folded = self::foldEscapedBackslash($trimmed);

        return $folded === $trimmed ? [$trimmed] : [$trimmed, $folded];
    }

    /**
     * Folds every ESCAPED backslash (`\\`, two source characters) down to
     * one literal backslash, and leaves every other backslash — in
     * particular one immediately followed by a letter, exactly the shape a
     * class name's own namespace separator takes — untouched.
     *
     * Fixes a real bug, not merely a mutation-testing gap: `stripcslashes()`
     * (this method's first-round implementation) strips the backslash from
     * ANY unrecognised escape sequence, not only recognised ones —
     * `stripcslashes('App\Nodeflow\Nodes\SendMessage')` returns
     * `'AppNodeflowNodesSendMessage'`, deleting every namespace separator.
     * Real PHP does the opposite: inside a double-quoted string or a
     * heredoc body, `\N` is not a recognised escape, so PHP leaves it as
     * the two literal characters `\` and `N` — confirmed by executing
     * `$x = "App\Nodeflow\Nodes\SendMessage"; var_dump($x);`, which prints
     * the string completely unchanged. `stripcslashes()`'s over-eager
     * stripping meant a double-quoted class-name literal written with
     * plain, unescaped backslashes (the more natural way to write one, and
     * the ONLY way this class's own single-quoted fixtures write theirs)
     * was silently never found at all — confirmed by executing the scanner
     * against exactly such a fixture before this fix, which returned zero
     * references.
     *
     * A class name never legitimately contains any OTHER double-quoted
     * escape (`\n`, `\t`, a hex or unicode escape, …), so folding only
     * `\\` — never delegating to a general escape decoder — is enough to
     * match every real double-quoted or heredoc form of a class name
     * without stripcslashes()'s false negatives.
     */
    private static function foldEscapedBackslash(string $s): string
    {
        return str_replace('\\\\', '\\', $s);
    }
}
