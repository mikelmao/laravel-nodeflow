<?php

namespace Nodeflow\Console;

use Illuminate\Filesystem\Filesystem;

/**
 * Appends a node class to the host's NodeflowServiceProvider.
 *
 * Separate from MakeNodeCommand because editing someone else's file is the
 * riskiest thing the generator does and deserves tests that do not involve
 * generating anything. The rule it exists to enforce: assert the anchor is
 * present and unique before writing, and change nothing at all otherwise. An
 * edit that applies cleanly and silently matches nothing has cost this project
 * time twice already.
 */
class NodeRegistrationWriter
{
    public const ANCHOR = 'protected array $nodes = [';

    public const TRIGGER_ANCHOR = 'protected array $triggers = [';

    /**
     * A method signature, not an array opening, because a SubjectAttribute carries
     * a Closure and PHP refuses a closure in a property default: `protected array
     * $x = [fn () => 1];` is "Constant expression contains invalid operations".
     */
    public const ATTRIBUTE_ANCHOR = 'protected function subjectAttributes(): array';

    /**
     * How far past a method-signature anchor the writer will look for the
     * `return [` it appends into. The generated method puts it 20-odd characters
     * away; anything further means the body is not the bare return array this
     * writer knows how to edit, and refusing beats appending into whatever other
     * array it found next.
     */
    private const METHOD_BODY_WINDOW = 120;

    /**
     * Token IDs a class-reference element's NAME may be built from. PHP 8's
     * tokeniser usually emits one of these per name (T_NAME_QUALIFIED for
     * `App\Foo`, T_NAME_FULLY_QUALIFIED for `\App\Foo`, plain T_STRING for
     * `Foo`); T_NS_SEPARATOR and T_NAME_RELATIVE are accepted too so an
     * older-style token stream (alternating T_STRING/T_NS_SEPARATOR) is still
     * recognised.
     */
    private const NAME_TOKEN_IDS = [
        T_STRING,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
        T_NS_SEPARATOR,
    ];

    /** Tokens that are legal ANYWHERE between a class-reference element's parts. */
    private const INSIGNIFICANT_TOKEN_IDS = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    public function __construct(private Filesystem $files) {}

    public function register(string $providerPath, string $nodeClass): NodeRegistrationOutcome
    {
        $entry = '\\'.ltrim($nodeClass, '\\').'::class';

        return $this->appendTo($providerPath, self::ANCHOR, ltrim($entry, '\\'), $entry);
    }

    /**
     * @param  string  $presenceNeedle  What "already registered" looks like. Not the
     *                                  whole entry: a node is matched on `Foo::class`
     *                                  so an entry written without the leading
     *                                  backslash still counts — the backslash is
     *                                  optional in PHP and only the entries this
     *                                  writer wrote itself carry one, and the
     *                                  `::class` suffix keeps `SendSms::class` from
     *                                  matching a longer name like
     *                                  `SendSmsExtra::class`. An attribute is matched
     *                                  on its key alone so a re-run with a different
     *                                  label does not append a second entry under one
     *                                  key — SubjectAttributeRegistry keys by
     *                                  attribute key and the second would silently
     *                                  replace the first.
     * @param  string  $indent  Entries in a property array sit at 8 spaces; entries
     *                          inside subjectAttributes()'s return array sit at 12.
     */
    public function appendTo(
        string $providerPath,
        string $anchor,
        string $presenceNeedle,
        string $entry,
        string $indent = '        ',
    ): NodeRegistrationOutcome {
        if (! $this->files->exists($providerPath)) {
            return NodeRegistrationOutcome::ProviderMissing;
        }

        $contents = $this->files->get($providerPath);

        // Anchor counts stay on the RAW bytes deliberately: a commented-out
        // anchor (e.g. `// protected array $triggers = [`) must still count
        // towards ambiguity, because the insertion offset computed below is a
        // RAW byte offset and a second, commented copy of the anchor text is
        // exactly the situation that makes "which one?" unanswerable.
        $occurrences = substr_count($contents, $anchor);

        if ($occurrences === 0) {
            return NodeRegistrationOutcome::AnchorMissing;
        }

        if ($occurrences > 1) {
            return NodeRegistrationOutcome::AnchorAmbiguous;
        }

        // E50: scoped to the anchor's own array span, not the whole file — a
        // mention anywhere else (a docblock example, a string literal in an
        // unrelated method) must not read as already registered.
        if ($this->isAlreadyPresent($contents, $anchor, $presenceNeedle)) {
            return NodeRegistrationOutcome::AlreadyPresent;
        }

        $position = $this->insertionPoint($contents, $anchor);

        if ($position === null) {
            return NodeRegistrationOutcome::AnchorMissing;
        }

        $updated = substr_replace(
            $contents,
            PHP_EOL.$indent.$entry.',',
            $position,
            0,
        );

        $this->files->put($providerPath, $updated);

        // E11: a position that passed every check above can still sit inside a
        // comment — a `$nodes` home whose declaration line is itself commented
        // out ("// protected array $nodes = [") still matches ANCHOR once, raw,
        // and the insertion looks clean right up until the result is read back.
        // Re-verify rather than trust the write: it must still parse, AND the
        // entry must be visible outside any comment, or this restores the
        // original bytes and refuses instead of reporting a success that
        // produced broken PHP.
        $written = $this->files->get($providerPath);

        if (! $this->parses($written)
            || ! str_contains(SourceText::withoutPhpComments($written), $presenceNeedle)) {
            $this->files->put($providerPath, $contents);

            return NodeRegistrationOutcome::WriteFailed;
        }

        return NodeRegistrationOutcome::Appended;
    }

    /**
     * Removes every entry inside $anchor's array whose written name RESOLVES to
     * $nodeClass (E38, E39, E50).
     *
     * Matching is identity, not spelling: `<name>::class` is only a candidate,
     * and is only removed once PhpNameResolver::resolve() says its FQCN, under
     * THIS file's own namespace and imports, equals the target. A name that
     * merely looks right — a longer sibling, a qualified name the current
     * namespace turns into a different class, one sitting inside a comment —
     * is left alone.
     *
     * The array's body is parsed STRUCTURALLY (spanElements(), token-based),
     * not lexically: an earlier design matched `<name>::class` with a regex
     * over comment-stripped text, which (a) let a `]` inside a STRING LITERAL
     * close the span early — because a regex has no notion of "this character
     * is inside a string" — and (b) had no way to notice a class-string
     * literal, a class constant (`self::SMS`), or a spread (`...$more`) sitting
     * where a class reference belongs. Both silently read as NotPresent, which
     * authorises the caller to delete the class file the entry still, in fact,
     * names. If ANY element in the array is not exactly `<name>::class` (a
     * literal `class` keyword, not merely something ending in the text
     * "class"), the whole operation refuses as EntryUnsupported rather than
     * report on the elements it does understand — an unrecognised element
     * might resolve to the target opaquely (a class constant aliasing it, as
     * above), so partial understanding of the array is not enough to certify
     * an absence.
     *
     * A class-string literal (`'App\…\SendMessage'`) is a REAL registration —
     * it must never read NotPresent either — but this writer refuses it as
     * EntryUnsupported rather than remove it. Parsing what a PHP string
     * literal actually denotes (single- vs double-quoted escape rules,
     * `\\`, variable interpolation) is a correctness-sensitive job
     * PhpNameResolver was never built for, and refusing costs the host one
     * manual edit; guessing wrong costs a fatal boot().
     *
     * Every removal is line-scoped: a match is only deleted when its own
     * physical line contains nothing else but that entry (plus an optional
     * trailing comma and, harmlessly, a comment), or when the entry is the
     * array's entire content — in which case the whole span between the
     * brackets is cleared instead, because there is no "its own line" to
     * delete without touching the anchor or the closing bracket. Anything
     * else — most importantly, a match sharing its line with a sibling entry
     * — refuses as EntryAmbiguous rather than attempt character surgery on a
     * shared line, and leaves the file untouched.
     */
    public function removeFrom(string $providerPath, string $anchor, string $nodeClass): NodeRemovalOutcome
    {
        if (! $this->files->exists($providerPath)) {
            return NodeRemovalOutcome::ProviderMissing;
        }

        $contents = $this->files->get($providerPath);

        // Same reasoning as appendTo(): counts stay on RAW bytes, because a
        // commented-out second anchor is exactly the situation that makes
        // "which array?" unanswerable.
        $occurrences = substr_count($contents, $anchor);

        if ($occurrences === 0) {
            return NodeRemovalOutcome::AnchorMissing;
        }

        if ($occurrences > 1) {
            return NodeRemovalOutcome::AnchorAmbiguous;
        }

        $parsed = $this->spanElements($contents, $anchor);

        if ($parsed === null) {
            return NodeRemovalOutcome::AnchorMissing;
        }

        if ($parsed['unsupported']) {
            return NodeRemovalOutcome::EntryUnsupported;
        }

        $target = ltrim($nodeClass, '\\');
        $resolver = PhpNameResolver::forSource($contents);

        $matches = array_values(array_filter(
            $parsed['elements'],
            static fn (array $element) => $resolver->resolve($element['writtenName']) === $target,
        ));

        if ($matches === []) {
            return NodeRemovalOutcome::NotPresent;
        }

        $totalElementCount = count($parsed['elements']);
        $deletions = [];

        foreach ($matches as $element) {
            $deletion = $this->entryDeletionRange($contents, $parsed['tokens'], $element, $totalElementCount, $parsed);

            if ($deletion === null) {
                return NodeRemovalOutcome::EntryAmbiguous;
            }

            $deletions[] = $deletion;
        }

        // Last match backwards, so earlier byte offsets in $updated stay valid
        // as each substr_replace() below runs.
        usort($deletions, static fn (array $a, array $b) => $b['start'] <=> $a['start']);

        $updated = $contents;

        foreach ($deletions as $deletion) {
            $updated = substr_replace($updated, '', $deletion['start'], $deletion['end'] - $deletion['start']);
        }

        $this->files->put($providerPath, $updated);

        // Re-verify rather than trust the write (E11's rule, applied to a
        // deletion): the result must still parse, the array's remaining
        // content must still be fully understood (not EntryUnsupported), AND
        // no resolved reference to the target may remain anywhere in it.
        // Any failure restores the original bytes rather than report a
        // removal that left the host's provider still naming a class that
        // may no longer exist.
        $written = $this->files->get($providerPath);
        $remainingParsed = $this->spanElements($written, $anchor);

        $remaining = null;

        if ($remainingParsed !== null && ! $remainingParsed['unsupported']) {
            $remainingResolver = PhpNameResolver::forSource($written);

            $remaining = array_values(array_filter(
                $remainingParsed['elements'],
                static fn (array $element) => $remainingResolver->resolve($element['writtenName']) === $target,
            ));
        }

        if (! $this->parses($written) || $remaining === null || $remaining !== []) {
            $this->files->put($providerPath, $contents);

            return NodeRemovalOutcome::WriteFailed;
        }

        return NodeRemovalOutcome::Removed;
    }

    /**
     * The RAW byte range to delete for one matched element, or null when the
     * element cannot be removed without touching a sibling's content
     * (EntryAmbiguous).
     *
     * A match's own physical line "belongs to it alone" when every non-
     * whitespace, non-comment token on that line is either one of the
     * element's own tokens or a single trailing comma — computed from the
     * TOKEN stream rather than a trimmed string comparison, so a same-line
     * trailing comment (whose text might coincidentally look like more
     * entry text) can never confuse the check.
     *
     * @param  list<array{id: int|null, text: string, start: int, end: int}>  $tokens
     * @param  array{writtenName: string, tokenIndexes: list<int>, rawStart: int, rawEnd: int}  $element
     * @param  array{tokens: list<mixed>, openRaw: int, closeRaw: int, bodyStart: int, bodyEnd: int}  $span
     * @return array{start: int, end: int}|null
     */
    private function entryDeletionRange(string $contents, array $tokens, array $element, int $totalElementCount, array $span): ?array
    {
        $rawStart = $element['rawStart'];
        $rawEnd = $element['rawEnd'];

        $previousNewline = strrpos(substr($contents, 0, $rawStart), "\n");
        $lineStart = $previousNewline === false ? 0 : $previousNewline + 1;

        $nextNewline = strpos($contents, "\n", $rawEnd);
        $lineEnd = $nextNewline === false ? strlen($contents) : $nextNewline;

        $meaningfulIndexes = [];

        foreach ($tokens as $index => $token) {
            if ($token['start'] >= $lineStart && $token['end'] <= $lineEnd
                && ! in_array($token['id'], self::INSIGNIFICANT_TOKEN_IDS, true)) {
                $meaningfulIndexes[] = $index;
            }
        }

        $elementIndexes = $element['tokenIndexes'];
        $matchesLine = $meaningfulIndexes === $elementIndexes;

        if (! $matchesLine && count($meaningfulIndexes) === count($elementIndexes) + 1) {
            $withoutLast = array_slice($meaningfulIndexes, 0, -1);
            $lastIndex = end($meaningfulIndexes);

            $matchesLine = $withoutLast === $elementIndexes
                && $tokens[$lastIndex]['id'] === null
                && $tokens[$lastIndex]['text'] === ',';
        }

        if ($matchesLine) {
            return [
                'start' => $lineStart,
                'end' => $nextNewline === false ? strlen($contents) : $nextNewline + 1,
            ];
        }

        // No "own line" to delete without touching a sibling — but if this
        // element is the array's ONLY element, there is no sibling, and the
        // whole span between the brackets can be cleared instead. This is the
        // path a single-line array (`$nodes = [Foo::class];`) takes, because
        // its "line" also contains the anchor and the closing bracket.
        if ($totalElementCount === 1) {
            return ['start' => $span['openRaw'] + 1, 'end' => $span['closeRaw']];
        }

        return null;
    }

    /**
     * Parses $anchor's array body into its class-reference elements —
     * `<name> :: class`, tolerating whitespace and comments between the
     * three parts — or reports that at least one element is not that shape.
     *
     * Elements are found by splitting the body's tokens on top-level commas
     * (bracket/paren/brace depth tracked so a nested array's or a function
     * call's own commas are never mistaken for one), which is what keeps a
     * class-string literal's `]` (absorbed whole into ONE
     * T_CONSTANT_ENCAPSED_STRING token, never a bracket token in its own
     * right) from ever being mistaken for the array's own closing bracket —
     * the exact defect a character-by-character scan had.
     *
     * Any element failing classifyElement() makes the WHOLE result
     * `unsupported`, not just that element: an element this parser cannot
     * read (a class constant, a spread, a nested array, a string literal, a
     * call) might resolve, opaquely, to the very class a caller is searching
     * for, so reporting on the elements that DO parse and staying silent
     * about the rest would risk the same false "not registered" this replaces.
     *
     * @return array{
     *     unsupported: bool,
     *     elements: list<array{writtenName: string, tokenIndexes: list<int>, rawStart: int, rawEnd: int}>,
     *     tokens: list<array{id: int|null, text: string, start: int, end: int}>,
     *     openRaw: int,
     *     closeRaw: int,
     *     bodyStart: int,
     *     bodyEnd: int,
     * }|null
     */
    private function spanElements(string $contents, string $anchor): ?array
    {
        $span = $this->arraySpan($contents, $anchor);

        if ($span === null) {
            return null;
        }

        $tokens = $span['tokens'];
        $groups = [];
        $current = [];
        $depth = 0;

        for ($i = $span['bodyStart']; $i < $span['bodyEnd']; $i++) {
            $token = $tokens[$i];
            $isBoundary = $token['id'] === null;

            if ($isBoundary && in_array($token['text'], ['(', '[', '{'], true)) {
                $depth++;
            } elseif ($isBoundary && in_array($token['text'], [')', ']', '}'], true)) {
                $depth--;
            } elseif ($isBoundary && $token['text'] === ',' && $depth === 0) {
                $groups[] = $current;
                $current = [];

                continue;
            }

            $current[] = $i;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        $elements = [];

        foreach ($groups as $group) {
            $significant = array_values(array_filter(
                $group,
                static fn (int $index) => ! in_array($tokens[$index]['id'], self::INSIGNIFICANT_TOKEN_IDS, true),
            ));

            if ($significant === []) {
                continue; // A trailing comma before the closing bracket — not an element.
            }

            $classified = $this->classifyElement($tokens, $significant);

            if ($classified === null) {
                return ['unsupported' => true, 'elements' => [], 'tokens' => $tokens] + $span;
            }

            $elements[] = $classified;
        }

        return ['unsupported' => false, 'elements' => $elements, 'tokens' => $tokens] + $span;
    }

    /**
     * Whether $significant (a comma-delimited element's tokens, comments and
     * whitespace already excluded) is exactly a name followed by `::`
     * followed by the literal `class` keyword — PHP's own class-constant-
     * fetch syntax, not merely text that happens to contain the word "class".
     *
     * @param  list<array{id: int|null, text: string, start: int, end: int}>  $tokens
     * @param  list<int>  $significant  token indexes into $tokens, in order
     * @return array{writtenName: string, tokenIndexes: list<int>, rawStart: int, rawEnd: int}|null
     */
    private function classifyElement(array $tokens, array $significant): ?array
    {
        $count = count($significant);

        if ($count < 3) {
            return null;
        }

        $classIndex = $significant[$count - 1];
        $colonIndex = $significant[$count - 2];

        if ($tokens[$classIndex]['id'] !== T_CLASS || $tokens[$colonIndex]['id'] !== T_DOUBLE_COLON) {
            return null;
        }

        // Always non-empty: $count >= 3 here, so $count - 2 >= 1.
        $nameIndexes = array_slice($significant, 0, $count - 2);

        foreach ($nameIndexes as $index) {
            if (! in_array($tokens[$index]['id'], self::NAME_TOKEN_IDS, true)) {
                return null;
            }
        }

        $writtenName = implode('', array_map(static fn (int $index) => $tokens[$index]['text'], $nameIndexes));

        return [
            'writtenName' => $writtenName,
            'tokenIndexes' => $significant,
            'rawStart' => $tokens[$significant[0]]['start'],
            'rawEnd' => $tokens[$classIndex]['end'],
        ];
    }

    /**
     * Whether $presenceNeedle already appears inside $anchor's own array span
     * (E50) — never the whole file, so a mention anywhere else (a docblock
     * example, a string literal in an unrelated method) cannot read as already
     * registered.
     *
     * A needle ending in `::class` is a class reference and is matched by
     * resolved identity, the same structural parser removeFrom() uses. If that
     * parser finds an element it cannot classify, this reports "present" —
     * the conservative direction, since an unrecognised element (a class
     * constant, say) might itself resolve to the target, and a false
     * "not present" here risks appendTo() adding a duplicate registration.
     *
     * Any other needle (the `SubjectAttribute::make('key'` case) is not a
     * class reference at all, so it is matched as a literal substring of the
     * span's comment-stripped text.
     */
    private function isAlreadyPresent(string $contents, string $anchor, string $presenceNeedle): bool
    {
        if (str_ends_with($presenceNeedle, '::class')) {
            $target = ltrim(substr($presenceNeedle, 0, -strlen('::class')), '\\');
            $parsed = $this->spanElements($contents, $anchor);

            if ($parsed === null) {
                return false;
            }

            if ($parsed['unsupported']) {
                return true;
            }

            $resolver = PhpNameResolver::forSource($contents);

            foreach ($parsed['elements'] as $element) {
                if ($resolver->resolve($element['writtenName']) === $target) {
                    return true;
                }
            }

            return false;
        }

        $span = $this->arraySpan($contents, $anchor);

        if ($span === null) {
            return false;
        }

        $body = '';

        for ($i = $span['bodyStart']; $i < $span['bodyEnd']; $i++) {
            $token = $span['tokens'][$i];

            if ($token['id'] === T_COMMENT || $token['id'] === T_DOC_COMMENT) {
                continue;
            }

            $body .= $token['text'];
        }

        return str_contains($body, $presenceNeedle);
    }

    /**
     * The array span $anchor opens: every PHP token in the file alongside its
     * own raw byte range (shared, so callers do not each re-tokenise the
     * file), the token-index bounds of the array's BODY (between the
     * brackets, exclusive), and the RAW byte offsets of the brackets
     * themselves.
     *
     * Found by brace/bracket matching from the anchor's own `[` to its
     * partner — not by searching for the next `]`, which a nested array
     * would close early — walked over the TOKEN stream, not raw characters:
     * a comment or a string literal is exactly ONE token regardless of what
     * `[` or `]` characters its own text contains, so a bracket sitting
     * inside either can never be mistaken for a real one. A character-based
     * scan over comment-STRIPPED text (this method's previous shape) caught
     * the comment case but not the string-literal one — a class-string entry
     * placed before the real target inside a nested array, such as
     * `['x]'],`, closed the span at that literal's OWN internal `]`.
     *
     * @return array{tokens: list<array{id: int|null, text: string, start: int, end: int}>, bodyStart: int, bodyEnd: int, openRaw: int, closeRaw: int}|null
     */
    private function arraySpan(string $contents, string $anchor): ?array
    {
        $openRaw = $this->openBracketPosition($contents, $anchor);

        if ($openRaw === null) {
            return null;
        }

        $tokens = $this->tokens($contents);
        $openIndex = null;

        foreach ($tokens as $index => $token) {
            if ($token['start'] === $openRaw && $token['id'] === null && $token['text'] === '[') {
                $openIndex = $index;

                break;
            }
        }

        if ($openIndex === null) {
            return null;
        }

        $depth = 0;
        $closeIndex = null;

        for ($i = $openIndex, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token['id'] !== null) {
                continue; // A named token (identifier, keyword, STRING, comment...) is never a bracket character itself.
            }

            if ($token['text'] === '[') {
                $depth++;
            } elseif ($token['text'] === ']') {
                $depth--;

                if ($depth === 0) {
                    $closeIndex = $i;

                    break;
                }
            }
        }

        if ($closeIndex === null) {
            return null;
        }

        return [
            'tokens' => $tokens,
            'bodyStart' => $openIndex + 1,
            'bodyEnd' => $closeIndex,
            'openRaw' => $openRaw,
            'closeRaw' => $tokens[$closeIndex]['start'],
        ];
    }

    /**
     * Every PHP token in $contents, alongside its own raw byte range.
     * token_get_all() is a lossless lexer — concatenating every token's text
     * in order reproduces $contents exactly — so each token's raw start is
     * just the running length of every token before it.
     *
     * @return list<array{id: int|null, text: string, start: int, end: int}>
     */
    private function tokens(string $contents): array
    {
        $tokens = [];
        $raw = 0;

        foreach (token_get_all($contents) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $id = is_array($token) ? $token[0] : null;
            $length = strlen($text);

            $tokens[] = ['id' => $id, 'text' => $text, 'start' => $raw, 'end' => $raw + $length];

            $raw += $length;
        }

        return $tokens;
    }

    /**
     * The RAW byte offset of the array's own opening `[` — one character before
     * insertionPoint()'s result, which is always the position immediately
     * after that bracket, whether the anchor ends in `[` itself or is a
     * method signature whose `return [` insertionPoint() locates.
     */
    private function openBracketPosition(string $contents, string $anchor): ?int
    {
        $afterBracket = $this->insertionPoint($contents, $anchor);

        return $afterBracket === null ? null : $afterBracket - 1;
    }

    /**
     * An anchor ending in `[` *is* the array opening, so the insertion point is
     * its end. A method-signature anchor is not, so the `return [` that follows
     * has to be found — and bounded, because an unbounded search would append
     * into whatever unrelated array appeared next in the file.
     *
     * The search runs over COMMENT-STRIPPED text, not the raw window: a
     * docblock example such as `// e.g. return [ SubjectAttribute::make(...) ];`
     * placed before the real `return [` would otherwise be found first, and the
     * entry would land inside the comment. Positions found in the stripped text
     * are mapped back to RAW byte offsets before returning, because
     * substr_replace() in appendTo() always writes into the raw bytes.
     */
    private function insertionPoint(string $contents, string $anchor): ?int
    {
        $anchorPos = strpos($contents, $anchor);

        if ($anchorPos === false) {
            return null;
        }

        $anchorEnd = $anchorPos + strlen($anchor);

        if (str_ends_with($anchor, '[')) {
            return $anchorEnd;
        }

        [$code, $offsets] = $this->codeWithOffsets($contents);

        $start = null;

        foreach ($offsets as $index => $rawOffset) {
            if ($rawOffset >= $anchorEnd) {
                $start = $index;

                break;
            }
        }

        if ($start === null) {
            return null;
        }

        $window = substr($code, $start, self::METHOD_BODY_WINDOW);
        $offset = strpos($window, 'return [');

        if ($offset === false) {
            return null;
        }

        $lastMatchedCodeIndex = $start + $offset + strlen('return [') - 1;

        return array_key_exists($lastMatchedCodeIndex, $offsets)
            ? $offsets[$lastMatchedCodeIndex] + 1
            : null;
    }

    /**
     * Comment-stripped code alongside a map from each kept character's index in
     * that stripped string back to its RAW byte offset in $contents.
     *
     * PHP's tokeniser is a lossless lexer: concatenating every token's own text
     * in order, comments included, reproduces $contents exactly. That is what
     * makes tracking each surviving character's raw offset by running length
     * possible without a second, separate pass over the file.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function codeWithOffsets(string $contents): array
    {
        $tokens = token_get_all($contents);
        $code = '';
        $offsets = [];
        $raw = 0;

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $isComment = is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT);

            if (! $isComment) {
                for ($i = 0, $length = strlen($text); $i < $length; $i++) {
                    $offsets[] = $raw + $i;
                }

                $code .= $text;
            }

            $raw += strlen($text);
        }

        return [$code, $offsets];
    }

    /**
     * Whether $source is valid PHP, using the TOKEN_PARSE flag rather than
     * shelling out to `php -l`: token_get_all() with that flag throws a
     * catchable \ParseError on invalid grammar (e.g. a bare expression
     * statement at class-body level, which is exactly what an insertion into a
     * commented-out property declaration produces), and staying in-process
     * avoids a second `php` invocation for every write this writer performs.
     */
    private function parses(string $source): bool
    {
        try {
            token_get_all($source, TOKEN_PARSE);

            return true;
        } catch (\ParseError) {
            return false;
        }
    }
}
