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

        // Comment-stripped (E22): a needle that only matches raw text treats a
        // debugged-out entry, or one sitting inside an unrelated docblock
        // example, as already registered.
        if (str_contains(SourceText::withoutPhpComments($contents), $presenceNeedle)) {
            return NodeRegistrationOutcome::AlreadyPresent;
        }

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
