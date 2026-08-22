<?php

namespace Nodeflow\Console;

/**
 * Answers "what class does this written name mean in this file?" using PHP's own
 * name-resolution rule.
 *
 * WHY THIS EXISTS, given ProviderRegistrationStep deliberately declined to parse
 * use statements. The stakes inverted (E35). There, a false positive was harmless
 * and the shape unseen. Here a false NEGATIVE leaves the host fatal after a move —
 * NodeRegistry::register() autoloads through is_a(), so a stale FQCN throws in the
 * host's provider boot() on every request — and a false POSITIVE refuses
 * legitimate work in any codebase that happens to contain another SendMessage.
 *
 * The rule implemented is the language's, not a heuristic:
 *   \A\B\C          -> A\B\C, always
 *   Alias           -> the import's target, when Alias is imported
 *   Alias\D\E       -> the import's target for Alias, then \D\E
 *   A\B\C           -> <current namespace>\A\B\C, when A is not imported
 *
 * That last line is the one the first draft of this plan's design got wrong, and
 * it is why removeFrom() must resolve rather than string-match. Verified:
 * inside `namespace App\Providers;`, `App\Nodeflow\Nodes\SendMessage::class`
 * evaluates to `App\Providers\App\Nodeflow\Nodes\SendMessage`.
 *
 * Stated limit (Step 5 probe 4): a file with more than one `namespace` block is
 * not supported — this reads only the first and does not attempt to scope
 * imports or resolution per-block. NodeReferenceScanner must refuse such a file
 * outright rather than rely on this resolver to handle it correctly.
 *
 * A braced namespace (`namespace App\Providers { ... }`) IS supported: its
 * brace is not a scope that hides imports. readImports() tracks a stack of
 * brace *kinds* rather than a bare depth counter, so a `use` nested only
 * inside namespace braces is still a top-level import, while one nested
 * inside any other brace (class body, closure) is not.
 *
 * Known characteristic, not a bug: imports() is a single flat map, not one
 * scoped per namespace block. A file with two braced namespace blocks each
 * declaring their own `use` statements has both sets merged into the same
 * map, which is wrong for such a file in isolation — but the stated
 * multi-namespace limit above means NodeReferenceScanner refuses that file
 * before this resolver ever sees it, so the flat map never has to answer for
 * more than one namespace's imports in practice.
 */
final class PhpNameResolver
{
    /** @param array<string, string> $imports alias (lowercased) => FQCN */
    private function __construct(
        private readonly string $namespace,
        private readonly array $imports,
    ) {}

    public static function forSource(string $source): self
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t) => ! is_array($t)
                || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
        ));

        return new self(
            self::readNamespace($tokens),
            self::readImports($tokens),
        );
    }

    public function namespaceName(): string
    {
        return $this->namespace;
    }

    /** @return array<string, string> */
    public function imports(): array
    {
        return $this->imports;
    }

    public function resolve(string $writtenName): string
    {
        $name = ltrim($writtenName, '\\');

        // A leading backslash is a fully-qualified name and resolves to itself.
        if (str_starts_with($writtenName, '\\')) {
            return $name;
        }

        $segments = explode('\\', $name);
        $first = strtolower($segments[0]);

        if (isset($this->imports[$first])) {
            $rest = array_slice($segments, 1);

            return $rest === []
                ? $this->imports[$first]
                : $this->imports[$first].'\\'.implode('\\', $rest);
        }

        return $this->namespace === '' ? $name : $this->namespace.'\\'.$name;
    }

    /** @param list<array{0:int,1:string}|string> $tokens */
    private static function readNamespace(array $tokens): string
    {
        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            $parts = [];

            for ($i = $index + 1; $i < count($tokens); $i++) {
                $next = $tokens[$i];

                if (! is_array($next)) {
                    break;
                }

                // PHP 8 emits T_NAME_QUALIFIED for A\B; older shapes emit
                // T_STRING + T_NS_SEPARATOR. Accept both so this does not depend
                // on the tokeniser's version-specific grouping.
                if (in_array($next[0], [T_NAME_QUALIFIED, T_STRING, T_NS_SEPARATOR], true)) {
                    $parts[] = $next[1];

                    continue;
                }

                break;
            }

            return trim(implode('', $parts), '\\');
        }

        return '';
    }

    /** @return array<string, string> */
    private static function readImports(array $tokens): array
    {
        $imports = [];
        $count = count($tokens);

        // A braced namespace's `{` is not the kind of brace that hides a `use`
        // (a namespace's own use statements are still top-level imports); a
        // class body's or closure's `{` is. Track which opening braces belong
        // to a namespace declaration up front, then walk a stack of brace
        // *kinds* — not a bare depth counter — so nesting is judged correctly:
        // a `use` is a top-level import only while every open brace on the
        // stack is a namespace brace.
        $namespaceBraces = self::namespaceBraceIndexes($tokens);
        $braceKinds = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                if ($token === '{') {
                    $braceKinds[] = isset($namespaceBraces[$i]) ? 'namespace' : 'other';
                }

                if ($token === '}') {
                    array_pop($braceKinds);
                }

                continue;
            }

            if ($token[0] !== T_USE) {
                continue;
            }

            // A `use` inside a non-namespace brace is either a trait use in a
            // class body or a closure's captured-variable list. Neither is an
            // import, and reading one as an import produces a garbage alias.
            if (in_array('other', $braceKinds, true)) {
                continue;
            }

            // A closure's captured-variable list precedes its OWN `{`, so the
            // brace-kind guard above cannot see it: at this exact point we may
            // not be inside any brace at all (we could be inside a function
            // call's parens instead, as in `Route::get('/x', function () use
            // ($router) { ... })`). The decisive rule is the token immediately
            // after `use`: an import's `use` is always followed by a NAME; a
            // capture list's `use` is always followed by `(`. Without this
            // check, readOneUseStatement() reads the captured variable as an
            // alias and OVERWRITES a real import sharing that short name --
            // proven by a top-level `use App\Nodeflow\Nodes\SendMessage;`
            // followed later by `function () use ($router) { ... }`, which
            // left imports() empty instead of containing the real import.
            $following = $tokens[$i + 1] ?? null;

            if ($following === '(') {
                continue;
            }

            // `use function …` and `use const …` import symbols, not classes.
            if (is_array($following) && in_array($following[0], [T_FUNCTION, T_CONST], true)) {
                continue;
            }

            $i = self::readOneUseStatement($tokens, $i + 1, $imports);
        }

        return $imports;
    }

    /**
     * Finds the token index of every `{` that opens a namespace's braced body
     * (`namespace App\Providers { ... }`, or the bare global form
     * `namespace { ... }`), so readImports() can tell that brace apart from a
     * class body's or closure's.
     *
     * @param  list<array{0:int,1:string}|string>  $tokens
     * @return array<int, true> token index => true
     */
    private static function namespaceBraceIndexes(array $tokens): array
    {
        $indexes = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            $j = $i + 1;

            while (
                $j < $count
                && is_array($tokens[$j])
                && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING, T_NS_SEPARATOR], true)
            ) {
                $j++;
            }

            if (($tokens[$j] ?? null) === '{') {
                $indexes[$j] = true;
            }
        }

        return $indexes;
    }

    /**
     * Consumes one `use` statement, handling both the plain and group forms, and
     * returns the index of its terminating ';'.
     *
     * @param  array<string, string>  $imports
     */
    private static function readOneUseStatement(array $tokens, int $start, array &$imports): int
    {
        $count = count($tokens);
        $prefix = '';
        $current = '';
        $alias = null;
        $inGroup = false;
        $expectAlias = false;

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if ($token[0] === T_AS) {
                    $expectAlias = true;

                    continue;
                }

                if ($expectAlias) {
                    $alias = $token[1];
                    $expectAlias = false;

                    continue;
                }

                if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING, T_NS_SEPARATOR], true)) {
                    $current .= $token[1];
                }

                continue;
            }

            if ($token === '{') {
                $inGroup = true;
                $prefix = rtrim($current, '\\').'\\';
                $current = '';

                continue;
            }

            if ($token === ',' || $token === '}' || $token === ';') {
                if ($current !== '') {
                    $fqcn = trim(($inGroup ? $prefix : '').$current, '\\');
                    $short = $alias ?? self::lastSegment($fqcn);
                    $imports[strtolower($short)] = $fqcn;
                }

                $current = '';
                $alias = null;

                if ($token === ';') {
                    return $i;
                }

                if ($token === '}') {
                    $inGroup = false;
                }
            }
        }

        return $count - 1;
    }

    private static function lastSegment(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
