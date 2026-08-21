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
     * Matches a written class-constant reference such as `Foo::class`,
     * `\App\Foo::class`, or `App\Nodeflow\Nodes\Foo::class`. Captures the whole
     * text including the `::class` suffix; callers strip that suffix themselves
     * before handing the remainder to PhpNameResolver.
     */
    private const CLASS_REFERENCE_PATTERN = '/\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*'
        .'(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*::class/';

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
     * $nodeClass (E38, E39).
     *
     * Matching is identity, not spelling: `<name>::class` is only a candidate,
     * and is only removed once PhpNameResolver::resolve() says its FQCN, under
     * THIS file's own namespace and imports, equals the target. A name that
     * merely looks right — a longer sibling, a qualified name the current
     * namespace turns into a different class, one sitting inside a comment —
     * is left alone.
     *
     * Every removal is line-scoped: a match is only deleted when its own
     * comment-stripped line is exactly that entry (plus an optional trailing
     * comma), or when the entry is the array's entire content — in which case
     * the whole span between the brackets is cleared instead, because there is
     * no "its own line" to delete without touching the anchor or the closing
     * bracket. Anything else — most importantly, a match sharing its line with
     * a sibling entry — refuses as EntryAmbiguous rather than attempt character
     * surgery on a shared line, and leaves the file untouched.
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

        $span = $this->arraySpan($contents, $anchor);

        if ($span === null) {
            return NodeRemovalOutcome::AnchorMissing;
        }

        $target = ltrim($nodeClass, '\\');
        $matches = $this->targetEntriesInSpan($contents, $anchor, $target);

        if ($matches === null || $matches === []) {
            return NodeRemovalOutcome::NotPresent;
        }

        $bodyStrippedTrimmed = trim(substr(
            $span['code'],
            $span['bodyStart'],
            $span['bodyEnd'] - $span['bodyStart'],
        ));

        $deletions = [];

        foreach ($matches as $match) {
            $deletion = $this->entryDeletionRange($contents, $span, $match, $bodyStrippedTrimmed);

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
        // deletion): the result must still parse, AND no resolved reference to
        // the target may remain anywhere in the anchor's array. Either failure
        // restores the original bytes rather than report a removal that left
        // the host's provider still naming a class that may no longer exist.
        $written = $this->files->get($providerPath);
        $remaining = $this->targetEntriesInSpan($written, $anchor, $target);

        if (! $this->parses($written) || $remaining === null || $remaining !== []) {
            $this->files->put($providerPath, $contents);

            return NodeRemovalOutcome::WriteFailed;
        }

        return NodeRemovalOutcome::Removed;
    }

    /**
     * The RAW byte range to delete for one matched entry, or null when the
     * entry cannot be removed without touching a sibling's content
     * (EntryAmbiguous).
     *
     * @param  array{text: string, codeStart: int, codeEnd: int}  $match
     * @param  array{code: string, offsets: list<int>, bodyStart: int, bodyEnd: int, openRaw: int, closeRaw: int}  $span
     * @return array{start: int, end: int}|null
     */
    private function entryDeletionRange(string $contents, array $span, array $match, string $bodyStrippedTrimmed): ?array
    {
        $code = $span['code'];
        $offsets = $span['offsets'];
        $entryText = $match['text'];
        $withComma = $entryText.',';

        $previousNewline = strrpos(substr($code, 0, $match['codeStart']), "\n");
        $lineStartCode = $previousNewline === false ? 0 : $previousNewline + 1;

        $nextNewline = strpos($code, "\n", $match['codeEnd']);
        $lineEndCode = $nextNewline === false ? strlen($code) : $nextNewline;

        $line = trim(substr($code, $lineStartCode, $lineEndCode - $lineStartCode));

        if ($line === $entryText || $line === $withComma) {
            $rawStart = $offsets[$lineStartCode];
            $rawEnd = $nextNewline === false
                ? strlen($contents)
                : $offsets[$lineEndCode] + 1;

            return ['start' => $rawStart, 'end' => $rawEnd];
        }

        // No "own line" to delete without touching a sibling — but if this
        // entry IS the array's entire content, there is no sibling, and the
        // whole span between the brackets can be cleared instead. This is the
        // path a single-line array (`$nodes = [Foo::class];`) takes, because
        // its "line" also contains the anchor and the closing bracket.
        if ($bodyStrippedTrimmed === $entryText || $bodyStrippedTrimmed === $withComma) {
            return ['start' => $span['openRaw'] + 1, 'end' => $span['closeRaw']];
        }

        return null;
    }

    /**
     * Every `<name>::class` occurrence inside $anchor's array span whose
     * resolved FQCN equals $target. Null means the anchor's array span could
     * not be located at all (callers treat that as "could not verify" rather
     * than "definitely absent").
     *
     * @return list<array{text: string, codeStart: int, codeEnd: int}>|null
     */
    private function targetEntriesInSpan(string $contents, string $anchor, string $target): ?array
    {
        $span = $this->arraySpan($contents, $anchor);

        if ($span === null) {
            return null;
        }

        $body = substr($span['code'], $span['bodyStart'], $span['bodyEnd'] - $span['bodyStart']);

        if (! preg_match_all(self::CLASS_REFERENCE_PATTERN, $body, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $resolver = PhpNameResolver::forSource($contents);
        $found = [];

        foreach ($matches[0] as [$text, $offsetInBody]) {
            $writtenName = substr($text, 0, -strlen('::class'));

            if ($resolver->resolve($writtenName) !== $target) {
                continue;
            }

            $codeStart = $span['bodyStart'] + $offsetInBody;

            $found[] = [
                'text' => $text,
                'codeStart' => $codeStart,
                'codeEnd' => $codeStart + strlen($text),
            ];
        }

        return $found;
    }

    /**
     * Whether $presenceNeedle already appears inside $anchor's own array span
     * (E50) — never the whole file, so a mention anywhere else (a docblock
     * example, a string literal in an unrelated method) cannot read as already
     * registered.
     *
     * A needle ending in `::class` is a class reference and is matched by
     * resolved identity, the same rule removeFrom() uses. Any other needle (the
     * `SubjectAttribute::make('key'` case) is not a class reference at all, so
     * it is matched as a literal substring of the span's comment-stripped text.
     */
    private function isAlreadyPresent(string $contents, string $anchor, string $presenceNeedle): bool
    {
        if (str_ends_with($presenceNeedle, '::class')) {
            $target = ltrim(substr($presenceNeedle, 0, -strlen('::class')), '\\');
            $matches = $this->targetEntriesInSpan($contents, $anchor, $target);

            return $matches !== null && $matches !== [];
        }

        $span = $this->arraySpan($contents, $anchor);

        if ($span === null) {
            return false;
        }

        $body = substr($span['code'], $span['bodyStart'], $span['bodyEnd'] - $span['bodyStart']);

        return str_contains($body, $presenceNeedle);
    }

    /**
     * The array span $anchor opens: comment-stripped code and its raw-offset
     * map (shared, so callers do not each re-tokenise the file), the code-index
     * bounds of the array's BODY (between the brackets, exclusive), and the RAW
     * byte offsets of the brackets themselves.
     *
     * Found by brace/bracket matching from the anchor's own `[` to its partner
     * — not by searching for the next `]`, which a nested array would close
     * early — walked over the comment-stripped text so a `[` or `]` sitting
     * inside a comment cannot shift the count.
     *
     * @return array{code: string, offsets: list<int>, bodyStart: int, bodyEnd: int, openRaw: int, closeRaw: int}|null
     */
    private function arraySpan(string $contents, string $anchor): ?array
    {
        $openRaw = $this->openBracketPosition($contents, $anchor);

        if ($openRaw === null) {
            return null;
        }

        [$code, $offsets] = $this->codeWithOffsets($contents);

        $openCode = array_search($openRaw, $offsets, true);

        if ($openCode === false) {
            return null;
        }

        $depth = 0;
        $closeCode = null;

        for ($i = $openCode, $length = strlen($code); $i < $length; $i++) {
            if ($code[$i] === '[') {
                $depth++;
            } elseif ($code[$i] === ']') {
                $depth--;

                if ($depth === 0) {
                    $closeCode = $i;

                    break;
                }
            }
        }

        if ($closeCode === null) {
            return null;
        }

        return [
            'code' => $code,
            'offsets' => $offsets,
            'bodyStart' => $openCode + 1,
            'bodyEnd' => $closeCode,
            'openRaw' => $openRaw,
            'closeRaw' => $offsets[$closeCode],
        ];
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
