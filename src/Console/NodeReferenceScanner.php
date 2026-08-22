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
 *   - `string_literal`  — a quoted string literal's exact value, or a
 *                         bounded substring match inside Blade markup or a
 *                         heredoc/nowdoc body (see "THREE DIFFERENT
 *                         MATCHING RULES" below).
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
 * THREE DIFFERENT MATCHING RULES, by token kind — stated together because a
 * reader comparing two of these methods without this note would reasonably
 * expect one rule, not three:
 *   1. A NAME token (`T_STRING`, `T_NAME_QUALIFIED`, …) is resolved through
 *      `PhpNameResolver` and compared for FQCN equality — see "DETECTION IS
 *      UNIVERSAL" above.
 *   2. A quoted string literal (`T_CONSTANT_ENCAPSED_STRING`) is unquoted
 *      and compared for exact VALUE equality — see scanStringLiterals().
 *      The token unambiguously denotes one value and nothing else, so
 *      exact equality is both correct and precise.
 *   3. Blade markup (`T_INLINE_HTML`) and a heredoc/nowdoc body
 *      (`T_ENCAPSED_AND_WHITESPACE`) are scanned for the target as a
 *      BOUNDED SUBSTRING — see scanBoundedText(). Neither token
 *      unambiguously denotes the FQCN alone: both are commonly a LARGER
 *      block of text (HTML markup, a code sample) the FQCN appears inside,
 *      not the token's entire content.
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
 *   - A Blade reference written as a bare SHORT NAME (`{{ SendMessage::class
 *     }}`) is out of reach: Blade has no `use`/import mechanism this
 *     scanner could resolve a short name against the way it resolves one
 *     inside real PHP. Only a name that is already fully qualified in the
 *     Blade source (with or without a leading `\`) is found.
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
     * @param  list<string>  $absoluteRoots  Each entry is either a directory
     *  (walked recursively) or a single FILE (scanned directly, without a
     *  containing directory needing to be named at all — a loose root-level
     *  `*.php` file a directory-only root list could never reach).
     * @param  list<string>  $excludedTopLevelNames  Directory NAMES to skip
     *  recursing into, but ONLY when they sit directly inside one of
     *  $absoluteRoots itself — never at any deeper nesting, and never
     *  affecting a DIFFERENT root that happens to share a name. Added for
     *  ExtractNodeCommand's shared scan-root method (G5 and M6a both call
     *  it): `storage/framework/` and `bootstrap/cache/` hold COMPILED
     *  artifacts, not source a developer wrote, and admitting `storage/`
     *  or `bootstrap/` as real scan roots at all (E46 — `bootstrap/app.php`
     *  is Laravel 11's own provider registration site) must not mean a
     *  stale compiled Blade view or cached config file can abort a
     *  legitimate move. Excluding the whole `storage/`/`bootstrap/`
     *  directory would lose real, developer-authored siblings
     *  (`storage/app/`, `bootstrap/providers.php`); excluding by bare name
     *  at every depth (the way `vendor/` is excluded elsewhere) would risk
     *  hiding a genuine reference sitting under some OTHER, unrelated
     *  directory a developer happened to name `framework/` or `cache/` —
     *  the wrong direction for a scanner whose whole job is to err toward
     *  finding too much, never too little. Scoping the exclusion to
     *  "immediate child of THIS root" is what avoids both.
     * @return list<NodeReference>
     *
     * @throws \RuntimeException when a scanned file declares more than one
     *                            `namespace` block, when a directory entry
     *                            (ordinarily a symlink) cannot be resolved
     *                            to a real path, or when following one
     *                            would revisit a directory already scanned
     *                            in this same call (a symlink cycle)
     */
    public static function scan(string $fqcn, array $absoluteRoots, array $excludedTopLevelNames = []): array
    {
        $target = ltrim($fqcn, '\\');
        $references = [];
        $visitedRealPaths = [];

        foreach ($absoluteRoots as $root) {
            foreach (self::scannableFilesUnder($root, $excludedTopLevelNames, $root, $visitedRealPaths) as $file) {
                array_push($references, ...self::scanFile($file, $target));
            }
        }

        return $references;
    }

    /**
     * Every `*.php` (which already covers `*.blade.php`), `*.phtml`, and
     * `*.inc` file under $directory, found by a recursive directory walk
     * that FOLLOWS a symlinked directory rather than skipping it (round 4
     * ruling — see below for why), with $visitedRealPaths tracking every
     * directory's own canonical (`realpath()`) form across the WHOLE
     * `scan()` call so a symlink cycle is caught rather than recursed into
     * forever.
     *
     * WHY FOLLOW A SYMLINK NESTED INSIDE A SCAN ROOT AT ALL (round 4
     * ruling, replacing round-1's `HostPath::contains()` filter). A
     * top-level scan root that IS ITSELF an escaping symlink is refused
     * upstream, before it ever reaches this class — ExtractNodeCommand's
     * own `hostPsr4Directories()` and `sharedScanRoots()` already filter
     * that out (E51, Important N2). What this method now handles is
     * DIFFERENT and sharper: a symlink NESTED inside an otherwise
     * legitimate root — `app/Linked` symlinked to a directory outside the
     * host, itself declaring `App\Linked\Consumer` and referencing the
     * node under extraction. PSR-4 (`App\` → `app/`) makes that class
     * genuinely autoloadable by the host at runtime, but the OLD
     * `HostPath::contains()` filter made it invisible to this scanner —
     * meaning invisible to both G5 and M6a — so extraction would delete
     * the original and leave the host loading a class that no longer
     * exists, the exact failure this whole command exists to prevent. A
     * blanket refusal of any scan root containing an escaping symlink was
     * considered and rejected: a monorepo with symlinked shared source is
     * exactly where this is real, and refusing there would block the
     * users who most need this command. Scanning the target instead finds
     * the reference and refuses for the right reason, naming the file —
     * the SAME trade this class already makes everywhere else (E46: erring
     * toward finding too much is always the safe direction; the unsafe one
     * is silently finding too little).
     *
     * The COST of following symlinks is what $visitedRealPaths pays for:
     * an unbounded symlink cycle would otherwise recurse forever, and a
     * broken symlink's target cannot be scanned at all — both REFUSE
     * LOUDLY (a thrown exception naming the path) rather than being
     * silently skipped, because silently skipping either is the same
     * "found too little" failure this method exists to avoid, just
     * reached a different way.
     *
     * $excludedTopLevelNames is only ever tested against an entry when
     * `$directory === $scanRoot` — i.e. only for entries directly inside
     * the ORIGINAL root scan() was handed for this call, never for an
     * entry reached by recursing one or more levels deeper. That is what
     * keeps the exclusion scoped to (say) `storage/framework/` alone,
     * rather than ALSO matching a `framework/` directory nested somewhere
     * else entirely under the same root.
     *
     * @param  list<string>  $excludedTopLevelNames
     * @param  array<string, true>  $visitedRealPaths  passed by reference
     *  and shared across every root in the SAME scan() call, not reset per
     *  root — two different top-level roots resolving to the same real
     *  directory (an unusual symlink arrangement) is exactly as much a
     *  cycle risk as one root symlinking into itself.
     * @return \Generator<string>
     *
     * @throws \RuntimeException
     */
    private static function scannableFilesUnder(string $directory, array $excludedTopLevelNames, string $scanRoot, array &$visitedRealPaths): \Generator
    {
        if (is_file($directory)) {
            if (self::hasScannableExtension($directory)) {
                yield $directory;
            }

            return;
        }

        $real = realpath($directory);

        if ($real === false) {
            throw new \RuntimeException(
                "[{$directory}] could not be resolved to a real path — a broken symlink target would ".
                'otherwise be silently skipped here, hiding a reference this scan could not then prove '.
                'is absent.'
            );
        }

        if (isset($visitedRealPaths[$real])) {
            throw new \RuntimeException(
                "[{$directory}] resolves to [{$real}], a directory this scan has already visited — ".
                'following it would recurse into a symlink cycle forever.'
            );
        }

        $visitedRealPaths[$real] = true;

        $entries = @scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if ($directory === $scanRoot && in_array($entry, $excludedTopLevelNames, true)) {
                continue;
            }

            $path = $directory.'/'.$entry;

            // A broken symlink (target does not exist) is neither
            // is_dir() nor is_file() — PHP cannot stat a target that
            // is not there — so without this check it would silently
            // fall through the two branches below and be skipped
            // entirely, exactly the "found too little" failure this
            // whole method exists to avoid. Checked here, structurally
            // (is_link() plus a failed realpath()), rather than folded
            // into the is_dir() branch below, because a broken symlink
            // is neither a directory NOR a scannable file and must be
            // refused regardless of which one its OWN name might have
            // suggested.
            if (is_link($path) && realpath($path) === false) {
                throw new \RuntimeException(
                    "[{$path}] is a symlink whose target could not be resolved — silently skipping it ".
                    'would hide whatever it was meant to point at, which this scan could not then prove '.
                    'does not reference the class under extraction.'
                );
            }

            if (is_dir($path)) {
                yield from self::scannableFilesUnder($path, $excludedTopLevelNames, $scanRoot, $visitedRealPaths);

                continue;
            }

            if (self::hasScannableExtension($entry)) {
                yield $path;
            }
        }
    }

    private static function hasScannableExtension(string $name): bool
    {
        return str_ends_with($name, '.blade.php')
            || str_ends_with($name, '.php')
            || str_ends_with($name, '.phtml')
            || str_ends_with($name, '.inc');
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
        array_push($references, ...self::scanBoundedText($meaningful, $target, $file));

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
                // class body, neither an import.
                if (in_array('other', $braceKinds, true)) {
                    $i++;

                    continue;
                }

                $following = $meaningful[$i + 1] ?? null;

                // A closure's captured-variable list PRECEDES its own `{`,
                // so the brace-kind guard above cannot see it: at this exact
                // point we may not be inside any brace at all (we could be
                // inside a function call's parens instead, as in
                // `Route::get('/x', function () use ($router) { ... })`).
                // An import's `use` is always followed by a NAME; a capture
                // list's `use` is always followed by `(`. Without this
                // check, parseUseStatement() reads the captured variable as
                // an alias and matches it against $imports under that
                // variable's own (garbage) key -- proven by a top-level
                // `use App\Nodeflow\Nodes\SendMessage;` followed later by
                // `function () use ($router) { return new SendMessage(); }`
                // in routes/web.php, which returned 0 references before this
                // fix (the capture list consumed tokens all the way to the
                // closure body's own first `;`, past the real usage).
                if ($following !== null && $following['id'] === null && $following['text'] === '(') {
                    $i++;

                    continue;
                }

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
            if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $matches = false;

            foreach (self::unquote($token['text']) as $candidate) {
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
     * A `string_literal` reference for each BOUNDED SUBSTRING occurrence of
     * $target inside a `T_INLINE_HTML` region (Blade markup, or any plain
     * HTML mixed into a `.php` file outside `<?php ?>` tags) or a
     * `T_ENCAPSED_AND_WHITESPACE` token — a heredoc/nowdoc body, but also
     * the literal segments BETWEEN interpolated `$variables` in a
     * double-quoted string or a backtick shell-exec string (round-3
     * review, Critical 2, plus a round-4 correction to this docblock: an
     * earlier version of it described only the heredoc/nowdoc case).
     *
     * WHY A DIFFERENT MATCHING RULE THAN scanStringLiterals(). A quoted
     * literal's VALUE is compared for exact equality, because the token
     * unambiguously denotes one value and nothing else. Neither of these
     * token kinds works that way: `T_INLINE_HTML` is an entire block of
     * Blade/HTML markup the target FQCN might appear anywhere inside
     * (`{{ \App\…\SendMessage::class }}`, `@php(...)`, `@php … @endphp`),
     * and a heredoc/nowdoc body (or an interpolated string's literal
     * segment) is commonly a larger text — a code sample, a template — the
     * FQCN appears inside, not the body's entire content (e.g.
     * `<<<PHP\nuse App\…\SendMessage;\nPHP`, which `scanStringLiterals()`'s
     * old exact-equality rule for this token type returned zero references
     * for). So this scans for the target as a SUBSTRING instead — bounded
     * so `App\Foo\SendMessage` cannot match inside `App\Foo\SendMessageExtra`
     * or `App\Foo\SendMessage\Sub`: the character immediately after a
     * candidate match may not be an identifier character or a backslash,
     * or the match is rejected and scanning resumes one byte later. The
     * character BEFORE a match is deliberately NOT bounded the same way —
     * over-matching there costs a look, not a fatal, the same direction
     * this scanner always errs in, and it is what lets
     * `\App\…\SendMessage::class` (a leading backslash immediately before
     * the match) succeed without special-casing it.
     *
     * BOTH token kinds are also searched for the ESCAPED form of $target
     * (every `\` doubled to `\\`) as a second needle. `T_INLINE_HTML` is
     * NOT plain, unescaped output text the way raw HTML is — round-3's
     * docblock claimed otherwise, which was wrong: `{{ … }}` and a
     * `@php(...)`/`@php … @endphp` body ARE PHP, so a string literal
     * written inside either one (`@php $n = app('App\\Nodeflow\\Nodes\\
     * SendMessage'); @endphp`) carries ordinary PHP string escaping —
     * confirmed missed (0 references) before this fix, where the
     * identical line inside a real `<?php` block already found 1.
     * Doubling the NEEDLE rather than folding the HAYSTACK (matching
     * `foldEscapedBackslash()`'s own reasoning) is what lets the recorded
     * byte range stay a plain, un-mapped slice of the RAW source text.
     *
     * STATED LIMIT, alongside E46: a Blade reference written as a bare
     * SHORT NAME (`{{ SendMessage::class }}`) is out of reach. Blade has no
     * import mechanism this scanner could resolve a short name against —
     * unlike PHP, there is no `use` statement anywhere in a `.blade.php`
     * file to establish what "SendMessage" alone would mean.
     *
     * A SURPRISING ASYMMETRY, worth finding here rather than by
     * inference: inside a `.blade.php` file, a `{{-- … --}}` Blade
     * comment, an HTML `<!-- … -->` comment, or ordinary prose naming the
     * class WILL match and cause extraction to refuse — this scanner has
     * no notion of a "comment" inside `T_INLINE_HTML` the way
     * `IGNORED_TOKEN_IDS` gives it one inside real PHP (a `// …` or
     * `/** … *\/` comment there is invisible, per rule 5). That is the
     * SAFE direction — a spurious refusal costs a look, never a fatal —
     * but it is surprising enough to state rather than leave for an
     * author to discover. For calibration, in the SAME markup: a URL
     * using `/` separators, a JavaScript string escaping `\\`, and a
     * lowercase spelling of the class name all correctly do NOT match —
     * only the exact, case-sensitive, backslash-separated FQCN (or its
     * doubled-backslash form) does.
     *
     * @param  list<array{id: int|null, text: string, start: int, end: int, line: int}>  $meaningful
     * @return list<NodeReference>
     */
    private static function scanBoundedText(array $meaningful, string $target, string $file): array
    {
        $references = [];
        $escaped = str_replace('\\', '\\\\', $target);
        $needles = $escaped === $target ? [$target] : [$target, $escaped];

        foreach ($meaningful as $token) {
            if ($token['id'] !== T_INLINE_HTML && $token['id'] !== T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }

            array_push($references, ...self::findBoundedMatches($token, $needles, $file));
        }

        return $references;
    }

    /**
     * Every bounded-substring match of any of $needles inside $token's own
     * text, each as its own `string_literal` reference spanning exactly
     * the matched bytes (not the whole token).
     *
     * @param  array{id: int|null, text: string, start: int, end: int, line: int}  $token
     * @param  list<string>  $needles
     * @return list<NodeReference>
     */
    private static function findBoundedMatches(array $token, array $needles, string $file): array
    {
        $references = [];
        $text = $token['text'];
        $length = strlen($text);

        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }

            $needleLength = strlen($needle);
            $offset = 0;

            while (($pos = strpos($text, $needle, $offset)) !== false) {
                $after = $pos + $needleLength;
                $afterChar = $after < $length ? $text[$after] : null;

                if ($afterChar !== null && ($afterChar === '\\' || self::isIdentifierChar($afterChar))) {
                    // The candidate is a PREFIX of a longer name (an extra
                    // identifier character extends the last segment; a
                    // trailing backslash extends into a deeper or sibling
                    // symbol). Resume scanning one byte past where this
                    // candidate started, not past its end, so an
                    // overlapping real match starting inside the rejected
                    // span is never skipped.
                    $offset = $pos + 1;

                    continue;
                }

                $references[] = new NodeReference(
                    $file,
                    $token['line'] + substr_count(substr($text, 0, $pos), "\n"),
                    $token['start'] + $pos,
                    $token['start'] + $after,
                    'string_literal',
                );

                $offset = $after;
            }
        }

        return $references;
    }

    private static function isIdentifierChar(string $char): bool
    {
        return $char === '_' || ctype_alnum($char);
    }

    /**
     * Every value a single- or double-quoted T_CONSTANT_ENCAPSED_STRING
     * token could denote, as a list to check against — normally exactly
     * one candidate, empty only for a quote style this does not decode. A
     * heredoc or nowdoc body is a different token type —
     * T_ENCAPSED_AND_WHITESPACE — and is matched by scanBoundedText()
     * instead, via a substring rule rather than this method's exact-value
     * one; see that method's own docblock for why.
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
