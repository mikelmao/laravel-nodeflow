<?php

/**
 * M1-M7 and M6a -- the actual moves `nodeflow:extract-node` performs once
 * every one of Task 8's eight gates has passed. ExtractNodeGatesTest.php
 * covers the gates themselves; this file covers what happens after they
 * all pass: a package is scaffolded, the class and its test move into it,
 * the package's own provider gains the registration, the host's own
 * provider and composer.json are edited, a final rescan (M6a) proves
 * nothing still names the class by its old FQCN, and only then are the
 * originals deleted (M7).
 *
 * Every failure-injection test in this file asserts BOTH that the host
 * tree is byte-identical to what it was before the run (movesTreeHash) AND,
 * where the package directory did not exist beforehand, that it is
 * genuinely ABSENT afterwards -- not merely that its tracked files match,
 * which a first draft of this coverage got wrong by assuming absence
 * applied to every target state. The three E43 target states (absent, a
 * matching pre-existing package, and a foreign directory under --force)
 * are each covered by at least one failure-injection test.
 */
beforeEach(function () {
    $this->app->instance(
        \Nodeflow\Console\Extract\ComposerRunner::class,
        new \Tests\Support\PassingComposerRunner,
    );

    $this->root = sys_get_temp_dir().'/nodeflow-extract-node-moves-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0777, true);

    // Canonicalise once, up front, for the same reason ExtractNodeGatesTest.php
    // does: HostPath::root() resolves symlinks, and macOS aliases /var to
    // /private/var.
    $this->root = realpath($this->root);

    mkdir($this->root.'/app', 0777, true);

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));

    $this->app->setBasePath($this->root);
});

afterEach(function () {
    // Undo any permission changes a failure-injection test made, or the
    // recursive delete below cannot finish.
    @chmod($this->root.'/composer.json', 0644);
    @chmod($this->root.'/app/Nodeflow/Nodes', 0755);
    @chmod($this->root.'/packages/acme/widgets/tests', 0755);
    @chmod($this->root.'/packages/acme/widgets/src', 0755);
    @chmod($this->root.'/packages/acme', 0755);

    movesDeleteTree($this->root);
});

/** Recursively deletes $dir, tolerating symlinks (never following one into whatever it points at). */
function movesDeleteTree(string $dir): void
{
    if (! is_dir($dir) && ! is_link($dir)) {
        return;
    }

    if (is_link($dir)) {
        unlink($dir);

        return;
    }

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;

        if (is_link($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            movesDeleteTree($path);
        } else {
            @chmod($path, 0644);
            unlink($path);
        }
    }

    @chmod($dir, 0755);
    rmdir($dir);
}

/**
 * A hash of every file's content and every symlink's own target, keyed by
 * path relative to $root -- the same "byte-identical before and after"
 * measure ExtractNodeGatesTest.php's own hostTreeHash() uses, given its own
 * (distinct) name here because both test files load into the same process.
 */
function movesTreeHash(string $root): string
{
    $entries = [];

    $walk = function (string $dir) use (&$walk, &$entries, $root) {
        foreach (scandir($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir.'/'.$name;
            $relative = substr($path, strlen($root) + 1);

            if (is_link($path)) {
                $entries[$relative] = 'symlink:'.readlink($path);

                continue;
            }

            if (is_dir($path)) {
                $entries[$relative.'/'] = 'dir';
                $walk($path);

                continue;
            }

            $entries[$relative] = hash_file('sha256', $path);
        }
    };

    $walk($root);
    ksort($entries);

    return hash('sha256', json_encode($entries));
}

/**
 * Writes a valid, minimal subject node under App\Nodeflow\Nodes, requires
 * it, and returns its FQCN. $extraBody is spliced verbatim into the class
 * body (after forSubject()) -- used to plant a docblock or a self-reference
 * a test wants to assert on.
 */
function movesWriteNode(string $root, string $shortClass, string $type, string $extraBody = ''): string
{
    $directory = $root.'/app/Nodeflow/Nodes';

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = $directory.'/'.$shortClass.'.php';

    file_put_contents($path, <<<PHP
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class {$shortClass} extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return '{$type}';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('{$shortClass}')->outputs(['default']);
        }

        public function forSubject(SubjectContext \$context): NodeResult
        {
            return \$context->continue('default');
        }
    {$extraBody}
    }
    PHP);

    require $path;

    return 'App\Nodeflow\Nodes\\'.$shortClass;
}

/**
 * A node with NO namespace declaration at all -- G2 only requires the FILE
 * sit under a mapped PSR-4 directory, never that the class's OWN namespace
 * matches the mapped prefix, so this legitimately passes every gate while
 * giving M2 nothing to rewrite.
 */
function movesWriteGlobalNamespaceNode(string $root, string $shortClass, string $type): string
{
    $directory = $root.'/app';

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = $directory.'/'.$shortClass.'.php';

    file_put_contents($path, <<<PHP
    <?php

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class {$shortClass} extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return '{$type}';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('{$shortClass}')->outputs(['default']);
        }

        public function forSubject(SubjectContext \$context): NodeResult
        {
            return \$context->continue('default');
        }
    }
    PHP);

    require $path;

    return $shortClass;
}

/** Writes app/Providers/NodeflowServiceProvider.php with the given $nodesBody spliced into the $nodes array, plus optional $extraUses / $extraBody. */
function movesWriteProvider(string $root, string $nodesBody, string $extraUses = '', string $extraBody = ''): string
{
    $directory = $root.'/app/Providers';

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = $directory.'/NodeflowServiceProvider.php';

    file_put_contents($path, <<<PHP
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;
    use Nodeflow\Nodeflow;
    {$extraUses}

    class NodeflowServiceProvider extends ServiceProvider
    {
        protected array \$nodes = [
    {$nodesBody}
        ];
    {$extraBody}
        public function boot(): void
        {
            Nodeflow::register(\$this->nodes);
        }
    }
    PHP);

    return $path;
}

/** Writes a host test at the conventional path, in the exact stubs/node.test.stub shape: opens `<?php`, then `use {{ namespacedClass }};`, no namespace declaration. */
function movesWriteHostTest(string $root, string $shortClass, string $fqcn): void
{
    $directory = $root.'/tests/Feature/Nodeflow';

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($directory.'/'.$shortClass.'Test.php', <<<PHP
    <?php

    use {$fqcn};
    use Nodeflow\Nodes\NodeRegistry;
    use Nodeflow\Nodes\HandlesSubject;

    it('keeps its type stable', function () {
        expect({$shortClass}::type())->toBe('whatever');
    });
    PHP);
}

// --- M1-M4: the moves themselves, on the happy path -------------------------

it('moves the class and rewrites only its namespace declaration, leaving a docblock mentioning the old namespace unchanged', function () {
    // F-1: a global str_replace() of the old namespace text would ALSO
    // rewrite this docblock's own mention of it, since the raw text is
    // identical. NodeReferenceScanner's own comment-skipping rule is what
    // keeps M2's structural rewrite from touching it.
    $extraBody = <<<'PHP'

        /** @see \App\Nodeflow\Nodes\DocblockNode -- still the old location on purpose */
    PHP;

    $class = movesWriteNode($this->root, 'DocblockNode', 'docblock.node', $extraBody);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $moved = file_get_contents($this->root.'/packages/acme/widgets/src/Nodes/DocblockNode.php');

    expect($moved)->toContain('namespace Acme\Widgets\Nodes;');
    expect($moved)->toContain('@see \App\Nodeflow\Nodes\DocblockNode -- still the old location on purpose');
    expect($this->root.'/app/Nodeflow/Nodes/DocblockNode.php')->not->toBeFile();

    exec('php -l '.escapeshellarg($this->root.'/packages/acme/widgets/src/Nodes/DocblockNode.php').' 2>&1', $out, $exit);
    expect($exit)->toBe(0);
});

it('rewrites a self-referencing class-string literal to the new FQCN, properly re-quoted', function () {
    // A string literal's VALUE does not resolve through PHP's own namespace
    // rules the way a name token does, so it must be re-spelled with the
    // full new FQCN -- requote()'s own reason for existing, rather than
    // rewritten to the bare short name the way every other self-reference
    // kind is.
    $extraBody = <<<'PHP'

        public function legacyAlias(): string
        {
            return 'App\Nodeflow\Nodes\StringRefNode';
        }
    PHP;

    $class = movesWriteNode($this->root, 'StringRefNode', 'stringref.node', $extraBody);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $movedPath = $this->root.'/packages/acme/widgets/src/Nodes/StringRefNode.php';
    $moved = file_get_contents($movedPath);

    // Semantic, not textual: requote() is free to escape a backslash it did
    // not strictly need to (PHP's own single-quote rule folds `\\` back to
    // one backslash regardless), so the correct check is the VALUE the
    // rewritten literal evaluates to, not its exact source spelling.
    expect($moved)->not->toContain('App\Nodeflow\Nodes\StringRefNode');

    exec('php -l '.escapeshellarg($movedPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0);

    require $movedPath;
    expect((new \Acme\Widgets\Nodes\StringRefNode())->legacyAlias())->toBe('Acme\Widgets\Nodes\StringRefNode');
});

it('rewrites the namespace declaration and a later self-reference correctly when their replacement lengths differ', function () {
    // applySpanReplacements() must apply replacements in DESCENDING byte-
    // offset order. The namespace span sits BEFORE the self-reference in
    // the file, and here the two replacement texts are deliberately
    // different LENGTHS ("Acme\Pkg\Nodes" is shorter than
    // "App\Nodeflow\Nodes") -- ascending order would rewrite the namespace
    // span FIRST, shift every byte after it, and then apply the
    // self-reference's OWN already-computed (now stale) offsets to the
    // wrong bytes. Same-length replacements (this file's other self-
    // reference tests) cannot discriminate this, since a same-length
    // substr_replace() never shifts anything after it regardless of order.
    $extraBody = <<<'PHP'

        public function legacyAlias(): string
        {
            return 'App\Nodeflow\Nodes\ShorterNamespaceNode';
        }
    PHP;

    $class = movesWriteNode($this->root, 'ShorterNamespaceNode', 'shorter.namespace.node', $extraBody);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/pkg'])
        ->assertExitCode(0);

    $movedPath = $this->root.'/packages/acme/pkg/src/Nodes/ShorterNamespaceNode.php';

    expect($movedPath)->toBeFile();
    expect(file_get_contents($movedPath))->toContain('namespace Acme\Pkg\Nodes;');

    exec('php -l '.escapeshellarg($movedPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0);

    require $movedPath;
    expect((new \Acme\Pkg\Nodes\ShorterNamespaceNode())->legacyAlias())->toBe('Acme\Pkg\Nodes\ShorterNamespaceNode');
});

it('rewrites a self-referencing DOUBLE-quoted class-string literal to the new FQCN, properly re-quoted', function () {
    // requote() branches on the ORIGINAL token's own quote style; this
    // exercises the double-quoted half of that branch, which the
    // single-quoted test above does not.
    $extraBody = <<<'PHP'

        public function legacyAlias(): string
        {
            return "App\Nodeflow\Nodes\DoubleQuotedRefNode";
        }
    PHP;

    $class = movesWriteNode($this->root, 'DoubleQuotedRefNode', 'doublequotedref.node', $extraBody);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $movedPath = $this->root.'/packages/acme/widgets/src/Nodes/DoubleQuotedRefNode.php';

    exec('php -l '.escapeshellarg($movedPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0);

    require $movedPath;
    expect((new \Acme\Widgets\Nodes\DoubleQuotedRefNode())->legacyAlias())->toBe('Acme\Widgets\Nodes\DoubleQuotedRefNode');
});

it('preserves the ESCAPED (doubled-backslash) spelling inside a NOWDOC, which processes no escapes at all (persisted probe, review round 3)', function () {
    // A nowdoc (<<<'TEXT') is unlike EVERY other string form in this
    // file: it processes NO escapes whatsoever, not even the \\ -> \
    // collapse a single-quoted string does. NodeReferenceScanner's own
    // scanBoundedText() searches for BOTH the plain FQCN and its every-
    // backslash-doubled spelling as two separate needles specifically
    // because either one can appear verbatim in such a body. The fixture
    // below writes the OLD FQCN with every backslash ALREADY doubled --
    // matching the ESCAPED needle -- so stringLiteralReplacement() must
    // preserve that doubling in the replacement: writing the PLAIN
    // spelling instead would change the RUNTIME VALUE (a nowdoc's value is
    // its source text, byte for byte), not merely its cosmetic form.
    $extraBody = <<<'PHP'

        public function legacyAlias(): string
        {
            return <<<'TEXT'
            App\\Nodeflow\\Nodes\\NowdocEscapedNode
            TEXT;
        }
    PHP;

    $class = movesWriteNode($this->root, 'NowdocEscapedNode', 'nowdocescaped.node', $extraBody);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $movedPath = $this->root.'/packages/acme/widgets/src/Nodes/NowdocEscapedNode.php';

    exec('php -l '.escapeshellarg($movedPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0);

    require $movedPath;

    // The RUNTIME VALUE of a nowdoc is its source text verbatim: the
    // doubled backslashes the fixture was written with must survive as
    // doubled backslashes in the value, not collapse to single ones.
    $value = (new \Acme\Widgets\Nodes\NowdocEscapedNode())->legacyAlias();
    expect($value)->toBe('Acme\\\\Widgets\\\\Nodes\\\\NowdocEscapedNode');
    expect(substr_count($value, '\\'))->toBe(6);
});

it('rewrites a self-referencing HEREDOC body to the new FQCN without splicing in literal quote characters', function () {
    // IMPORTANT review finding. NodeReferenceScanner::scanBoundedText()
    // matches a heredoc/nowdoc body (T_ENCAPSED_AND_WHITESPACE) as a
    // BOUNDED SUBSTRING -- its span covers ONLY the matched bytes, with NO
    // surrounding quotes at all, unlike a quoted T_CONSTANT_ENCAPSED_STRING
    // token. Calling requote() on a span like that would splice literal
    // quote characters into the middle of the heredoc body -- valid PHP
    // (php -l still passes) but a corrupted VALUE at runtime. This proves
    // the fix by actually calling the method and checking the value, not
    // just parsing the file.
    //
    // A REAL heredoc (this one -- <<<TEXT, no quotes around the label)
    // processes escapes like a double-quoted string, so the rewritten
    // SOURCE now carries doubled backslashes (needsHeredocEscaping()'s own
    // reason for existing) -- what matters is that the RUNTIME VALUE,
    // after PHP's own escape processing collapses them back down, is the
    // correct single-backslash FQCN.
    $extraBody = <<<'PHP'

        public function legacyAlias(): string
        {
            return <<<TEXT
            App\Nodeflow\Nodes\HeredocRefNode
            TEXT;
        }
    PHP;

    $class = movesWriteNode($this->root, 'HeredocRefNode', 'heredocref.node', $extraBody);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $movedPath = $this->root.'/packages/acme/widgets/src/Nodes/HeredocRefNode.php';
    $moved = file_get_contents($movedPath);

    // No quote character was spliced into the heredoc body, and every
    // backslash is doubled in the SOURCE (heredoc escape rules) -- a
    // single-quoted PHP literal here, so every '\\\\' pair is exactly two
    // literal backslash characters, matching the doubled SOURCE bytes
    // needsHeredocEscaping() must have produced.
    expect($moved)->toContain('Acme\\\\Widgets\\\\Nodes\\\\HeredocRefNode');
    expect($moved)->not->toContain("'Acme\Widgets\Nodes\HeredocRefNode'");
    expect($moved)->not->toContain('"Acme\Widgets\Nodes\HeredocRefNode"');

    exec('php -l '.escapeshellarg($movedPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0);

    require $movedPath;
    expect((new \Acme\Widgets\Nodes\HeredocRefNode())->legacyAlias())->toBe('Acme\Widgets\Nodes\HeredocRefNode');
});

it('escapes the new FQCN for heredoc rules when a namespace segment would otherwise form a real escape sequence (promoted finding, review round 3)', function () {
    // The exact failure the reviewer constructed: --namespace=acme\things
    // is a VALID Composer/PHP identifier pair (assertValidNamespaceSegments()
    // only checks each segment is a legal PHP identifier; a lowercase
    // first letter is legal), so the new FQCN's own text contains the two
    // characters "\t" immediately after "acme" -- and "\t" IS a
    // recognised heredoc escape (a literal tab) unless the replacement
    // text is written with a DOUBLED backslash. Before this fix, the
    // moved file would parse cleanly (php -l passes) while its heredoc
    // silently evaluated to a value containing a tab character instead of
    // "\t".
    $extraBody = <<<'PHP'

        public function legacyAlias(): string
        {
            return <<<TEXT
            App\Nodeflow\Nodes\EscapeDangerNode
            TEXT;
        }
    PHP;

    $class = movesWriteNode($this->root, 'EscapeDangerNode', 'escapedanger.node', $extraBody);

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/things',
        '--namespace' => 'acme\things',
    ])->assertExitCode(0);

    $movedPath = $this->root.'/packages/acme/things/src/Nodes/EscapeDangerNode.php';

    exec('php -l '.escapeshellarg($movedPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0);

    require $movedPath;

    // The runtime value must be the literal FQCN text -- "acme", a
    // backslash, "things", ... -- and must NOT contain an actual tab
    // character where the "t" of "things" belongs.
    $value = (new \acme\things\Nodes\EscapeDangerNode())->legacyAlias();
    expect($value)->toBe('acme\things\Nodes\EscapeDangerNode');
    expect($value)->not->toContain("\t");
});

it('escapes an INTERPOLATED double-quoted string reference too, not only a heredoc (Important N1, review round 4)', function () {
    // The general class N1 named: EVERY T_ENCAPSED_AND_WHITESPACE chunk
    // except a nowdoc's processes escapes -- an ordinary double-quoted
    // string with a $variable interpolation is tokenised into exactly
    // such chunks around the interpolation point, and the literal chunk
    // containing our class reference is just as vulnerable to a \t-shaped
    // corruption as a heredoc body is.
    $extraBody = <<<'PHP'

        public function legacyAlias(): string
        {
            $suffix = '!';

            return "App\Nodeflow\Nodes\InterpNode$suffix";
        }
    PHP;

    $class = movesWriteNode($this->root, 'InterpNode', 'interp.node', $extraBody);

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/things',
        '--namespace' => 'acme\things',
    ])->assertExitCode(0);

    $movedPath = $this->root.'/packages/acme/things/src/Nodes/InterpNode.php';

    exec('php -l '.escapeshellarg($movedPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0);

    require $movedPath;

    $value = (new \acme\things\Nodes\InterpNode())->legacyAlias();
    expect($value)->toBe('acme\things\Nodes\InterpNode!');
    expect($value)->not->toContain("\t");
});

it('BACKTICK shell-exec strings process escapes the same way and need the same protection', function () {
    $extraBody = <<<'PHP'

        public function legacyAlias(): string
        {
            return `printf '%s' 'App\Nodeflow\Nodes\BacktickNode'`;
        }
    PHP;

    $class = movesWriteNode($this->root, 'BacktickNode', 'backtick.node', $extraBody);

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/things',
        '--namespace' => 'acme\things',
    ])->assertExitCode(0);

    $movedPath = $this->root.'/packages/acme/things/src/Nodes/BacktickNode.php';

    exec('php -l '.escapeshellarg($movedPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0);

    require $movedPath;

    // Execute a fixed, deterministic printf command and inspect the returned
    // bytes. Source text alone cannot distinguish the correct runtime "\\t"
    // (hex 5c74) from the tab byte (09) PHP would produce if the replacement
    // were not escaped before landing in this backtick token.
    $value = (new \acme\things\Nodes\BacktickNode())->legacyAlias();
    expect($value)->toBe('acme\things\Nodes\BacktickNode');
    expect(bin2hex($value))->toContain('5c74')->not->toContain('09');
});

it('preserves the PLAIN spelling inside a NOWDOC when the original text was plain, never doubling it (mutation survivors 1 and 2, review round 4)', function () {
    // The committed nowdoc probe used the ESCAPED spelling, whose correct
    // output happens to COINCIDE with what "always escape" would also
    // produce -- so it could not tell "needsEscaping() always returns
    // true" or "isNowdoc always computed as false" apart from the real
    // fix. A PLAIN-spelled nowdoc is the missing counterexample: the
    // correct replacement must stay PLAIN (single backslash), and either
    // mutation above would wrongly double it.
    $extraBody = <<<'PHP'

        public function legacyAlias(): string
        {
            return <<<'TEXT'
            App\Nodeflow\Nodes\PlainNowdocNode
            TEXT;
        }
    PHP;

    $class = movesWriteNode($this->root, 'PlainNowdocNode', 'plainnowdoc.node', $extraBody);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $movedPath = $this->root.'/packages/acme/widgets/src/Nodes/PlainNowdocNode.php';

    exec('php -l '.escapeshellarg($movedPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0);

    require $movedPath;

    $value = (new \Acme\Widgets\Nodes\PlainNowdocNode())->legacyAlias();
    expect($value)->toBe('Acme\Widgets\Nodes\PlainNowdocNode');
    expect(substr_count($value, '\\'))->toBe(3);
});

it('resets nowdoc state after T_END_HEREDOC, so an UNRELATED nowdoc earlier in the file cannot suppress escaping for a later interpolated string (mutation survivor 3, review round 4)', function () {
    // Deleting the T_END_HEREDOC reset survived the whole suite: nothing
    // exercised a file where a nowdoc closes and is FOLLOWED, with no
    // fresh T_START_HEREDOC in between, by a plain double-quoted string
    // that also needs escaping. Without the reset, the stale "we are in
    // a nowdoc" state bleeds forward and wrongly skips escaping there.
    $extraBody = <<<'PHP'

        public function unrelatedNowdoc(): string
        {
            return <<<'NOWDOC'
            just some literal text, no class reference here
            NOWDOC;
        }

        public function legacyAlias(): string
        {
            $suffix = '!';

            return "App\Nodeflow\Nodes\ResetProbeNode$suffix";
        }
    PHP;

    $class = movesWriteNode($this->root, 'ResetProbeNode', 'resetprobe.node', $extraBody);

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/things',
        '--namespace' => 'acme\things',
    ])->assertExitCode(0);

    $movedPath = $this->root.'/packages/acme/things/src/Nodes/ResetProbeNode.php';

    exec('php -l '.escapeshellarg($movedPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0);

    require $movedPath;

    $value = (new \acme\things\Nodes\ResetProbeNode())->legacyAlias();
    expect($value)->toBe('acme\things\Nodes\ResetProbeNode!');
    expect($value)->not->toContain("\t");
});

it('never escapes a Blade/inline-HTML reference, even when the new namespace would form a dangerous escape sequence (mutation survivor 4, review round 4)', function () {
    // T_INLINE_HTML processes NO escapes at all -- dropping the
    // "$id === T_ENCAPSED_AND_WHITESPACE" half of needsEscaping()'s own
    // check (checking only $isNowdoc, which defaults to null and would
    // read as "needs escaping" here too) would wrongly double the
    // backslash in literal markup text, which is never correct: markup is
    // echoed byte-for-byte, so a doubled backslash in the SOURCE means a
    // doubled backslash in the OUTPUT -- a real, visible regression.
    $extraBody = <<<'PHP'

        public function bladeProbe(): void
        {
            ?>
            App\Nodeflow\Nodes\BladeProbeNode
            <?php
        }
    PHP;

    $class = movesWriteNode($this->root, 'BladeProbeNode', 'bladeprobe.node', $extraBody);

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/things',
        '--namespace' => 'acme\things',
    ])->assertExitCode(0);

    $movedPath = $this->root.'/packages/acme/things/src/Nodes/BladeProbeNode.php';

    exec('php -l '.escapeshellarg($movedPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0);

    $moved = file_get_contents($movedPath);

    // The inline-HTML text is echoed byte-for-byte, so checking the
    // SOURCE text directly is equivalent to checking the runtime OUTPUT
    // for this token kind -- unlike a heredoc/double-quoted string, there
    // is no separate escape-processing step to distinguish source from
    // value.
    expect($moved)->toContain("acme\\things\\Nodes\\BladeProbeNode\n");
    expect($moved)->not->toContain('acme\\\\things\\\\Nodes\\\\BladeProbeNode');
});

it("moves the class's test and rewrites its import, adding no namespace declaration", function () {
    $class = movesWriteNode($this->root, 'TestMoveNode', 'test.move.node');
    movesWriteHostTest($this->root, 'TestMoveNode', $class);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $movedTestPath = $this->root.'/packages/acme/widgets/tests/TestMoveNodeTest.php';
    expect($movedTestPath)->toBeFile();

    $moved = file_get_contents($movedTestPath);
    expect($moved)->toContain('use Acme\Widgets\Nodes\TestMoveNode;');

    // Structural, not textual: token_get_all() must find no T_NAMESPACE
    // token at all -- stubs/node.test.stub declares none, so a
    // namespace-declaration rewrite is a verified no-op on this exact file.
    $hasNamespace = false;
    foreach (token_get_all($moved) as $token) {
        if (is_array($token) && $token[0] === T_NAMESPACE) {
            $hasNamespace = true;
        }
    }
    expect($hasNamespace)->toBeFalse();

    expect($this->root.'/tests/Feature/Nodeflow/TestMoveNodeTest.php')->not->toBeFile();

    exec('php -l '.escapeshellarg($movedTestPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0);
});

it('registers the class in the package provider and removes it from the host provider, both files still parsing', function () {
    $class = movesWriteNode($this->root, 'RegistrationNode', 'registration.node');
    movesWriteProvider($this->root, '        \App\Nodeflow\Nodes\RegistrationNode::class,', 'use App\Nodeflow\Nodes\RegistrationNode;');

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $packageProvider = file_get_contents($this->root.'/packages/acme/widgets/src/WidgetsServiceProvider.php');
    expect($packageProvider)->toContain('Acme\Widgets\Nodes\RegistrationNode::class');

    $hostProvider = file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php');
    expect($hostProvider)->not->toContain('RegistrationNode');

    exec('php -l '.escapeshellarg($this->root.'/packages/acme/widgets/src/WidgetsServiceProvider.php').' 2>&1', $out1, $exit1);
    exec('php -l '.escapeshellarg($this->root.'/app/Providers/NodeflowServiceProvider.php').' 2>&1', $out2, $exit2);
    expect($exit1)->toBe(0);
    expect($exit2)->toBe(0);
});

// --- M5: the host provider's own `use` import -------------------------------

it('removes the now-unused host import once its array entry is gone', function () {
    $class = movesWriteNode($this->root, 'ImportCleanupNode', 'import.cleanup.node');
    movesWriteProvider($this->root, '        \App\Nodeflow\Nodes\ImportCleanupNode::class,', 'use App\Nodeflow\Nodes\ImportCleanupNode;');

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $hostProvider = file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php');
    expect($hostProvider)->not->toContain('use App\Nodeflow\Nodes\ImportCleanupNode;');
    expect($hostProvider)->not->toContain('ImportCleanupNode');
});

it('keeps a multi-member use statement when only one imported member becomes unused', function () {
    $class = movesWriteNode($this->root, 'MultiImportNode', 'multi.import.node');
    movesWriteProvider(
        $this->root,
        '        MultiImportNode::class,',
        'use App\Nodeflow\Nodes\MultiImportNode, App\Support\Keep;',
        '    public const OTHER = Keep::class;'.PHP_EOL,
    );

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $provider = file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php');
    expect($provider)->toContain('use App\Nodeflow\Nodes\MultiImportNode, App\Support\Keep;')
        ->and($provider)->toContain('public const OTHER = Keep::class;');
});

it('preserves earlier registrations across successive extractions into the same package', function () {
    $first = movesWriteNode($this->root, 'FirstPackagedNode', 'first.packaged.node');

    $this->artisan('nodeflow:extract-node', ['class' => $first, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $second = movesWriteNode($this->root, 'SecondPackagedNode', 'second.packaged.node');

    $this->artisan('nodeflow:extract-node', ['class' => $second, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $provider = file_get_contents($this->root.'/packages/acme/widgets/src/WidgetsServiceProvider.php');
    expect($provider)->toContain('Acme\Widgets\Nodes\FirstPackagedNode::class')
        ->and($provider)->toContain('Acme\Widgets\Nodes\SecondPackagedNode::class');
});

it('keeps the host import when its short name appears in a second place', function () {
    // A DIFFERENT, fully-qualified reference to a class that merely SHARES
    // ImportCleanupNode's own short name, elsewhere in the same provider --
    // not a reference to $class at all (a different FQCN, so G5 does not
    // refuse), but identifierAppearsOutside() is deliberately a plain
    // identifier check, not a resolution check, so it errs toward keeping
    // an import rather than risk breaking something that still spells the
    // same short name.
    $class = movesWriteNode($this->root, 'SharedShortNameNode', 'shared.shortname.node');
    movesWriteProvider(
        $this->root,
        '        \App\Nodeflow\Nodes\SharedShortNameNode::class,',
        'use App\Nodeflow\Nodes\SharedShortNameNode;',
        '    public const OTHER = \Other\Vendor\SharedShortNameNode::class;'.PHP_EOL,
    );

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $hostProvider = file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php');
    expect($hostProvider)->toContain('use App\Nodeflow\Nodes\SharedShortNameNode;');

    exec('php -l '.escapeshellarg($this->root.'/app/Providers/NodeflowServiceProvider.php').' 2>&1', $out, $exit);
    expect($exit)->toBe(0);
});

it('continues extraction under the default error handler when only the unused-import write fails', function () {
    $class = movesWriteNode($this->root, 'DefaultHandlerImportNode', 'defaulthandlerimport.node');
    movesWriteProvider($this->root, '', 'use App\Nodeflow\Nodes\DefaultHandlerImportNode;');

    $providerPath = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $originalProvider = file_get_contents($providerPath);
    chmod($providerPath, 0444);

    try {
        $exit = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
            'class' => $class,
            '--package' => 'acme/widgets',
        ]);
    } finally {
        chmod($providerPath, 0644);
    }

    expect($exit)->toBe(0);
    expect(file_get_contents($providerPath))->toBe($originalProvider);
    expect($this->root.'/packages/acme/widgets/src/Nodes/DefaultHandlerImportNode.php')->toBeFile();
    expect($this->root.'/app/Nodeflow/Nodes/DefaultHandlerImportNode.php')->not->toBeFile();
});

it('leaves the import in place, and the extraction still succeeds, when removeUnusedImportIfSafe() cannot write the host provider (review round 4, Minor N3)', function () {
    // A production error handler may swallow file_put_contents()'s warning
    // and let Filesystem::put() return false instead of throwing. Keep this
    // separate from the default-handler test above so both failure shapes
    // remain explicit.
    $class = movesWriteNode($this->root, 'UnwritableImportNode', 'unwritableimport.node');
    movesWriteProvider($this->root, '', 'use App\Nodeflow\Nodes\UnwritableImportNode;');

    $providerPath = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $originalProvider = file_get_contents($providerPath);
    chmod($providerPath, 0444);

    set_error_handler(static fn (): bool => true, E_WARNING);

    try {
        $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
            ->assertExitCode(0);
    } finally {
        restore_error_handler();
        chmod($providerPath, 0644);
    }

    // chmod makes the write fail before changing any byte, so this proves
    // only the portable silent-false branch: the original is retained and
    // extraction continues. It deliberately does NOT claim to manufacture
    // or cover a partial write; the production guard restores one if a real
    // filesystem ever exposes that state.
    expect(file_get_contents($providerPath))->toBe($originalProvider);

    // And the rest of the extraction still completed -- this is a
    // cosmetic cleanup failure, never a reason to abort or restore
    // everything else.
    expect($this->root.'/packages/acme/widgets/src/Nodes/UnwritableImportNode.php')->toBeFile();
    expect($this->root.'/app/Nodeflow/Nodes/UnwritableImportNode.php')->not->toBeFile();
});

// --- M6: the host composer.json -------------------------------------------

it('adds a RELATIVE path repository and require entry, never an absolute path', function () {
    $class = movesWriteNode($this->root, 'ComposerJsonNode', 'composerjson.node');

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $decoded = json_decode(file_get_contents($this->root.'/composer.json'), true);

    $pathRepo = null;
    foreach ($decoded['repositories'] as $repository) {
        if (($repository['type'] ?? null) === 'path' && ($repository['url'] ?? null) === 'packages/acme/widgets') {
            $pathRepo = $repository;
        }
    }

    expect($pathRepo)->not->toBeNull();
    expect($pathRepo['url'])->toBe('packages/acme/widgets');
    expect($pathRepo['url'][0])->not->toBe('/');
    expect($pathRepo['options']['versions']['acme/widgets'])->toBe('1.0.0');
    expect($decoded['require']['acme/widgets'])->not->toBeNull();
});

it('adds the stable version alias to an existing matching path repository without duplicating it', function () {
    $class = movesWriteNode($this->root, 'ExistingPathAliasNode', 'existing.path.alias');
    mkdir($this->root.'/packages/acme/widgets', 0777, true);
    file_put_contents($this->root.'/packages/acme/widgets/composer.json', json_encode([
        'name' => 'acme/widgets',
    ]));
    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0', 'acme/widgets' => '*'],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
        'repositories' => [['type' => 'path', 'url' => 'packages/acme/widgets']],
    ]));

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertSuccessful();

    $decoded = json_decode(file_get_contents($this->root.'/composer.json'), true);
    $matching = array_values(array_filter(
        $decoded['repositories'],
        fn (array $repository): bool => ($repository['url'] ?? null) === 'packages/acme/widgets',
    ));

    expect($matching)->toHaveCount(1);
    expect($matching[0]['options']['versions']['acme/widgets'])->toBe('1.0.0');
});

// --- Building the package target: refusals before anything is touched -----

it('refuses an invalid --namespace before touching anything (E52)', function () {
    $class = movesWriteNode($this->root, 'BadNamespaceNode', 'badnamespace.node');

    $before = movesTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
        '--namespace' => '123Invalid',
    ])
        ->expectsOutputToContain('not a valid PHP identifier')
        ->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/packages')->not->toBeDirectory();
});

it("refuses when the host's own composer.json does not require atram/laravel-nodeflow (E33)", function () {
    $class = movesWriteNode($this->root, 'MissingConstraintNode', 'missingconstraint.node');

    file_put_contents($this->root.'/composer.json', json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));

    $before = movesTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('E33')
        ->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/packages')->not->toBeDirectory();
});

// --- G5 and M6a share ONE root set (Important 4, review round) -------------

it('G5 itself now refuses a reference sitting in an ordinary top-level directory, for free -- no moves attempted at all', function () {
    // Review-round finding: G5 and M6a used to derive their own scan roots
    // independently, and G5's OLD allowlist (REFERENCE_SCAN_DIRS) missed a
    // reference in an ordinary top-level directory like "scripts/" that
    // was neither a conventional name nor PSR-4-mapped. That meant a
    // refusal which should be FREE was instead paid for with six moves and
    // a rollback (M6a caught it only after M1-M6 had already run). Now
    // that gate5() and rescanPostMoveTree() both call the SAME
    // scanSharedRoots(), this refuses immediately, at G5, before
    // buildPackageTarget() or performMoves() ever runs -- provably, by
    // asserting the package directory was never even attempted.
    $class = movesWriteNode($this->root, 'BlindSpotNode', 'blindspot.node');

    mkdir($this->root.'/scripts', 0777, true);
    file_put_contents($this->root.'/scripts/legacy.php', <<<'PHP'
    <?php

    class LegacyScript
    {
        public function make(): \App\Nodeflow\Nodes\BlindSpotNode
        {
            return new \App\Nodeflow\Nodes\BlindSpotNode();
        }
    }
    PHP);

    $before = movesTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('scripts/legacy.php')
        ->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
});

it('does not refuse over a reference sitting in storage/framework/ -- a compiled artifact, not source', function () {
    // Review-round finding, second direction: a NAIVE widening of G5 to
    // "every top-level directory" would ALSO admit storage/ as a whole,
    // and storage/framework/ holds a COMPILED Blade view -- not source a
    // developer wrote. A stale compiled artifact naming the class must
    // never be able to refuse (G5) or abort (M6a) a legitimate move.
    // storage/ itself (e.g. storage/app/) is still real ground and stays
    // covered; only the framework/ subdirectory specifically is excluded.
    $class = movesWriteNode($this->root, 'CompiledViewNode', 'compiledview.node');

    mkdir($this->root.'/storage/framework/views', 0777, true);
    file_put_contents($this->root.'/storage/framework/views/0123456789abcdef.php', <<<'PHP'
    <?php $x = new \App\Nodeflow\Nodes\CompiledViewNode(); ?>
    PHP);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    expect($this->root.'/packages/acme/widgets/src/Nodes/CompiledViewNode.php')->toBeFile();
});

it('does not refuse over a reference sitting in bootstrap/cache/ -- a compiled artifact, not source', function () {
    // Same reasoning as storage/framework/, for bootstrap/cache/ (Laravel's
    // own compiled config/routes/services cache). bootstrap/ itself stays
    // a real scan root (E46: bootstrap/app.php is Laravel 11's own
    // provider registration site) -- only cache/ is excluded.
    $class = movesWriteNode($this->root, 'CompiledConfigNode', 'compiledconfig.node');

    mkdir($this->root.'/bootstrap/cache', 0777, true);
    file_put_contents($this->root.'/bootstrap/cache/config.php', <<<'PHP'
    <?php return ['binding' => \App\Nodeflow\Nodes\CompiledConfigNode::class];
    PHP);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    expect($this->root.'/packages/acme/widgets/src/Nodes/CompiledConfigNode.php')->toBeFile();
});

it('DOES still refuse over a reference sitting in bootstrap/app.php -- E46, Laravel 11\'s own provider registration site', function () {
    // The counterfactual proving the bootstrap/cache/ exclusion is scoped
    // correctly: bootstrap/ itself must still be scanned, or this would
    // silently regress E46.
    $class = movesWriteNode($this->root, 'BootstrapAppNode', 'bootstrapapp.node');

    mkdir($this->root.'/bootstrap', 0777, true);
    file_put_contents($this->root.'/bootstrap/app.php', <<<'PHP'
    <?php $x = \App\Nodeflow\Nodes\BootstrapAppNode::class;
    PHP);

    $before = movesTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('bootstrap/app.php')
        ->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
});

it('does not scan a top-level vendor/ directory, so extraction succeeds even though a reference sits there', function () {
    // A reference planted only inside vendor/ is exactly the ground E51
    // says is not the host's own source. postMoveScanRoots() deliberately
    // excludes it: without that exclusion, this fixture's own reference
    // would abort the run.
    $class = movesWriteNode($this->root, 'VendorBlindNode', 'vendor.blind.node');

    mkdir($this->root.'/vendor/some-package', 0777, true);
    file_put_contents($this->root.'/vendor/some-package/Consumer.php', <<<'PHP'
    <?php

    class Consumer
    {
        public function make(): \App\Nodeflow\Nodes\VendorBlindNode
        {
            return new \App\Nodeflow\Nodes\VendorBlindNode();
        }
    }
    PHP);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    expect($this->root.'/packages/acme/widgets/src/Nodes/VendorBlindNode.php')->toBeFile();
});

it('does not scan a top-level node_modules/ directory, so extraction succeeds even though a reference sits there (mutation survivor 3)', function () {
    // NODE_MODULES_DIR is a new constant with no dedicated test of its
    // own -- mirroring the vendor/ test above, since a JS dependency tree
    // is never the host's own source either.
    $class = movesWriteNode($this->root, 'NodeModulesBlindNode', 'nodemodulesblind.node');

    mkdir($this->root.'/node_modules/some-package', 0777, true);
    file_put_contents($this->root.'/node_modules/some-package/consumer.php', <<<'PHP'
    <?php

    class Consumer
    {
        public function make(): \App\Nodeflow\Nodes\NodeModulesBlindNode
        {
            return new \App\Nodeflow\Nodes\NodeModulesBlindNode();
        }
    }
    PHP);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    expect($this->root.'/packages/acme/widgets/src/Nodes/NodeModulesBlindNode.php')->toBeFile();
});

it('does not apply the storage/framework exclusion to a PSR-4 directory that merely happens to be BASENAMED "storage" (mutation survivor 2)', function () {
    // scanSharedRoots()'s own guard: $root !== $hostBasePath.'/'.basename($root).
    // A PSR-4 mapping onto a nested "storage/" directory produces a scan
    // root whose basename is "storage" but which is NOT the top-level
    // host storage/ directory ARTIFACT_SUBDIRECTORIES means. The
    // intermediate directory (".libhidden") is dot-prefixed specifically
    // so the top-level walk itself never reaches this root by any OTHER
    // path -- isolating the guard's own effect. Deleting the guard would
    // wrongly apply the "framework" exclusion here too, hiding a REAL
    // reference sitting in .libhidden/storage/framework/.
    $class = movesWriteNode($this->root, 'FakeStorageNode', 'fakestorage.node');

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => [
            'App\\' => 'app/',
            'Lib\\' => '.libhidden/storage/',
        ]],
    ]));

    mkdir($this->root.'/.libhidden/storage/framework', 0777, true);
    file_put_contents($this->root.'/.libhidden/storage/framework/Consumer.php', <<<'PHP'
    <?php

    namespace Lib;

    class Consumer
    {
        public function make(): \App\Nodeflow\Nodes\FakeStorageNode
        {
            return new \App\Nodeflow\Nodes\FakeStorageNode();
        }
    }
    PHP);

    $before = movesTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('.libhidden/storage/framework/Consumer.php')
        ->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
});

it('still scans a PSR-4 directory that is dot-prefixed, ground the top-level walk alone would skip (mutation survivor 6)', function () {
    // hostPsr4Directories() unioned into sharedScanRoots() is not fully
    // redundant with the top-level walk: a host mapping its own
    // namespace onto a dot-prefixed directory (unusual, but nothing in
    // Composer forbids it) is exactly the ground the top-level walk
    // itself excludes (".git and similar") -- so only the PSR-4 union
    // reaches it.
    $class = movesWriteNode($this->root, 'DotPsr4Node', 'dotpsr4.node');

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => [
            'App\\' => 'app/',
            'Hidden\\' => '.hidden-src/',
        ]],
    ]));

    mkdir($this->root.'/.hidden-src', 0777, true);
    file_put_contents($this->root.'/.hidden-src/Consumer.php', <<<'PHP'
    <?php

    namespace Hidden;

    class Consumer
    {
        public function make(): \App\Nodeflow\Nodes\DotPsr4Node
        {
            return new \App\Nodeflow\Nodes\DotPsr4Node();
        }
    }
    PHP);

    $before = movesTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('.hidden-src/Consumer.php')
        ->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
});

it('refuses over a reference in a loose root-level .php file, end to end (review round 4, item A)', function () {
    // rector.php was the reviewer's own example: a loose *.php file
    // sitting directly at the host root, with no containing directory of
    // its own. Before this fix, sharedScanRoots() returned directories
    // only, so nothing here was ever scanned by G5 or M6a — extraction
    // would delete the original and leave this file's own reference
    // pointing at a class that no longer exists.
    $class = movesWriteNode($this->root, 'RootFileNode', 'rootfile.node');

    file_put_contents($this->root.'/rector.php', <<<'PHP'
    <?php

    return \App\Nodeflow\Nodes\RootFileNode::class;
    PHP);

    $before = movesTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('rector.php')
        ->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
});

it('refuses over a reference in a loose root-level PHP-family file with a non-php extension', function (string $shortClass, string $extension) {
    $class = movesWriteNode($this->root, $shortClass, strtolower($shortClass).'.node');
    $rootFile = $this->root.'/loose.'.$extension;
    file_put_contents($rootFile, "<?php\n\nreturn \\{$class}::class;\n");

    $before = movesTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('loose.'.$extension)
        ->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/app/Nodeflow/Nodes/'.$shortClass.'.php')->toBeFile();
    expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
})->with([
    'phtml' => ['RootPhtmlNode', 'phtml'],
    'inc' => ['RootIncNode', 'inc'],
    'uppercase php' => ['RootUppercasePhpNode', 'PHP'],
]);

it('refuses before moving when a loose root-level PHP-family file cannot be read', function () {
    $class = movesWriteNode($this->root, 'UnreadableRootFileNode', 'unreadablerootfile.node');
    $unreadableFile = $this->root.'/unreadable.phtml';
    file_put_contents($unreadableFile, '<?php');

    $before = movesTreeHash($this->root);

    try {
        chmod($unreadableFile, 0000);

        $exit = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
            'class' => $class,
            '--package' => 'acme/widgets',
        ]);

        expect($exit)->not->toBe(0);
        expect(\Illuminate\Support\Facades\Artisan::output())
            ->toContain('unreadable.phtml')
            ->toContain('could not be read');
    } finally {
        chmod($unreadableFile, 0644);
    }

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/app/Nodeflow/Nodes/UnreadableRootFileNode.php')->toBeFile();
    expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
});

it('refuses over a reference in a scannable root-level dotfile', function () {
    $class = movesWriteNode($this->root, 'DotConfigNode', 'dotconfig.node');
    file_put_contents(
        $this->root.'/.php-cs-fixer.php',
        "<?php\n\nreturn \\App\\Nodeflow\\Nodes\\DotConfigNode::class;\n",
    );

    $before = movesTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('.php-cs-fixer.php')
        ->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/app/Nodeflow/Nodes/DotConfigNode.php')->toBeFile();
    expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
});

it('continues to exclude an ordinary root-level dot-directory from the shared scan', function () {
    $class = movesWriteNode($this->root, 'HiddenDirectoryNode', 'hiddendirectory.node');
    mkdir($this->root.'/.hidden', 0777, true);
    file_put_contents(
        $this->root.'/.hidden/Consumer.php',
        "<?php\n\nreturn \\App\\Nodeflow\\Nodes\\HiddenDirectoryNode::class;\n",
    );

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    expect($this->root.'/packages/acme/widgets/src/Nodes/HiddenDirectoryNode.php')->toBeFile();
    expect($this->root.'/app/Nodeflow/Nodes/HiddenDirectoryNode.php')->not->toBeFile();
});

it('refuses over a reference reached through a symlink NESTED inside a scan root, rather than silently deleting the original (review round 4, item B)', function () {
    // The sharper of the two: app/Linked symlinked to a directory OUTSIDE
    // the host, declaring App\Linked\Consumer and referencing the node.
    // PSR-4 (App\ -> app/) makes this genuinely autoloadable by the host
    // at runtime -- the old HostPath::contains() filter INSIDE
    // NodeReferenceScanner made it invisible to both G5 and M6a, so
    // extraction would delete the original and leave the host loading a
    // class that no longer exists: the exact failure this whole command
    // exists to prevent. A top-level scan root that IS an escaping
    // symlink is still refused upstream (Important N2's own test covers
    // that); this is about a symlink NESTED inside an otherwise
    // legitimate root, which the scanner must now follow.
    $class = movesWriteNode($this->root, 'SymlinkedConsumerNode', 'symlinkedconsumer.node');

    $outside = sys_get_temp_dir().'/nodeflow-extract-node-symlink-target-'.bin2hex(random_bytes(6));
    mkdir($outside, 0777, true);
    $outside = realpath($outside);

    expect(dirname($outside))->toBe(dirname($this->root));

    file_put_contents($outside.'/Consumer.php', <<<'PHP'
    <?php

    namespace App\Linked;

    class Consumer
    {
        public function make(): \App\Nodeflow\Nodes\SymlinkedConsumerNode
        {
            return new \App\Nodeflow\Nodes\SymlinkedConsumerNode();
        }
    }
    PHP);

    symlink($outside, $this->root.'/app/Linked');

    $before = movesTreeHash($this->root);

    try {
        $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
            ->expectsOutputToContain('Consumer.php')
            ->assertFailed();

        expect(movesTreeHash($this->root))->toBe($before);
        expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
        expect($this->root.'/app/Nodeflow/Nodes/SymlinkedConsumerNode.php')->toBeFile();
    } finally {
        unlink($this->root.'/app/Linked');
        movesDeleteTree($outside);
    }
});

it('refuses and leaves the extraction tree unchanged when a nested symlink target is unreadable', function () {
    $class = movesWriteNode($this->root, 'UnreadableLinkedNode', 'unreadablelinked.node');

    $outside = sys_get_temp_dir().'/nodeflow-extract-node-unreadable-target-'.bin2hex(random_bytes(6));
    mkdir($outside, 0777, true);
    $outside = realpath($outside);
    symlink($outside, $this->root.'/app/Unreadable');

    $before = movesTreeHash($this->root);
    try {
        chmod($outside, 0000);

        try {
            $exit = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
                'class' => $class,
                '--package' => 'acme/widgets',
            ]);

            expect($exit)->not->toBe(0);
            expect(\Illuminate\Support\Facades\Artisan::output())
                ->toContain('app/Unreadable')
                ->toContain('could not be read');
        } finally {
            chmod($outside, 0755);
        }

        expect(movesTreeHash($this->root))->toBe($before);
        expect($this->root.'/app/Nodeflow/Nodes/UnreadableLinkedNode.php')->toBeFile();
        expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
    } finally {
        @chmod($outside, 0755);

        if (is_link($this->root.'/app/Unreadable')) {
            unlink($this->root.'/app/Unreadable');
        }

        movesDeleteTree($outside);
    }
});

it('refuses a nested symlink to the host parent before traversing sibling projects', function () {
    $class = movesWriteNode($this->root, 'HostParentLinkedNode', 'hostparentlinked.node');
    $sourceLink = $this->root.'/app/HostParent';
    $resolvedAncestor = dirname($this->root);
    symlink($resolvedAncestor, $sourceLink);

    $before = movesTreeHash($this->root);
    $exit = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ]);

    expect($exit)->not->toBe(0);
    expect(\Illuminate\Support\Facades\Artisan::output())
        ->toContain('app/HostParent')
        ->toContain($resolvedAncestor)
        ->toContain('ancestor of the original scan root');
    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/app/Nodeflow/Nodes/HostParentLinkedNode.php')->toBeFile();
    expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
});

// --- Restores byte-identically on failure injected at each step ------------

it('restores byte-identically when M1 (scaffold) fails, leaving no package directory (target ABSENT)', function () {
    $class = movesWriteNode($this->root, 'M1FailNode', 'm1.fail.node');

    // A host stub override that is not valid PHP -- PackageScaffolder
    // validates every rendered .php file BEFORE writing anything, so this
    // fails cleanly with nothing yet on disk.
    mkdir($this->root.'/stubs/package', 0777, true);
    file_put_contents($this->root.'/stubs/package/provider.stub', '<?php class {{ not valid php');

    $before = movesTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
});

it('restores byte-identically when M1 fails partway through its own write loop (target ABSENT)', function () {
    // CRITICAL review finding: PackageScaffolder::scaffold() writes its
    // files one at a time via a BARE file_put_contents() (Filesystem::put()
    // applies no `@` suppression), so a write that fails does not return
    // false for scaffold() to notice -- it throws straight out of
    // scaffold() itself, before scaffoldPackage() ever reaches its OWN
    // provider-existence check. Blocking `packages/acme/` itself (chmod
    // 0555) makes the very FIRST mkdir() scaffold() attempts fail, so
    // nothing is written at all -- proving the fix holds even when
    // scaffold() fails at its earliest possible point, not merely when a
    // LATER check (the provider-existence one) is what throws.
    $class = movesWriteNode($this->root, 'M1LoopFailNode', 'm1.loopfail.node');

    mkdir($this->root.'/packages/acme', 0777, true);
    chmod($this->root.'/packages/acme', 0555);

    $before = movesTreeHash($this->root);

    try {
        $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
            ->assertFailed();
    } finally {
        chmod($this->root.'/packages/acme', 0755);
    }

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
});

it('restores byte-identically when M1 writes composer.json and README.md but the provider write fails, over a FOREIGN directory under --force', function () {
    // The reviewer's own constructed counterexample: a foreign occupant
    // (E43, --force) whose own src/ is read-only. src/Nodes/ is
    // pre-created so ensureDirectoryExists('src/Nodes') is a no-op (it
    // never needs to WRITE into the read-only src/ at all) -- so
    // scaffold()'s write loop successfully writes composer.json and
    // README.md (both directly in the package root, writable) BEFORE
    // reaching src/{ShortClass}.php, which fails because CREATING that new
    // file needs write permission on its read-only parent. That failure
    // throws OUT of scaffold() itself rather than returning normally, so
    // the provider-existence check inside scaffoldPackage() is never even
    // reached. The finally-wrapped journaling is what still records those
    // two successful writes.
    $class = movesWriteNode($this->root, 'M1PartialWriteNode', 'm1.partialwrite.node');

    mkdir($this->root.'/packages/acme/widgets', 0777, true);
    file_put_contents($this->root.'/packages/acme/widgets/composer.json', json_encode(['name' => 'someone/else']));
    mkdir($this->root.'/packages/acme/widgets/src/Nodes', 0777, true);
    file_put_contents($this->root.'/packages/acme/widgets/src/NOTES.txt', 'do not touch');
    chmod($this->root.'/packages/acme/widgets/src', 0555);

    $before = movesTreeHash($this->root);

    try {
        $this->artisan('nodeflow:extract-node', [
            'class' => $class,
            '--package' => 'acme/widgets',
            '--force' => true,
        ])->assertFailed();
    } finally {
        chmod($this->root.'/packages/acme/widgets/src', 0755);
    }

    expect(movesTreeHash($this->root))->toBe($before);

    $decoded = json_decode(file_get_contents($this->root.'/packages/acme/widgets/composer.json'), true);
    expect($decoded['name'])->toBe('someone/else');
    expect($this->root.'/packages/acme/widgets/src/NOTES.txt')->toBeFile();
});

it('restores byte-identically when M2 (moving the class) fails, leaving no package directory (target ABSENT)', function () {
    // A node with NO namespace declaration at all passes every gate (G2
    // only requires the FILE sit under a mapped PSR-4 directory) but gives
    // M2 nothing to rewrite -- M1 has already scaffolded the package by the
    // time this is discovered, so restoring it is the whole point.
    $class = movesWriteGlobalNamespaceNode($this->root, 'M2FailNode', 'm2.fail.node');

    $before = movesTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
});

it("moves a test that references the class only by a FULLY QUALIFIED name, with no `use` import at all", function () {
    // CRITICAL review finding: rewritableSpans() exempts this test file
    // WHOLE, but the first draft of M3 only ever rewrote the import span
    // -- leaving a fully-qualified reference (or any reference not routed
    // through an import) stale under the old FQCN, caught only later by
    // M6a, which refused otherwise-valid work with no workaround but
    // hand-editing. M3 must rewrite EVERY recorded reference span, mirror-
    // ing M2, which is exactly what makes this file — with no `use`
    // import to rewrite at all — still move successfully.
    $class = movesWriteNode($this->root, 'NoImportRefNode', 'noimportref.node');

    $directory = $this->root.'/tests/Feature/Nodeflow';
    mkdir($directory, 0777, true);
    file_put_contents($directory.'/NoImportRefNodeTest.php', <<<'PHP'
    <?php

    it('keeps its type stable', function () {
        expect(\App\Nodeflow\Nodes\NoImportRefNode::type())->toBe('noimportref.node');
    });
    PHP);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $movedPath = $this->root.'/packages/acme/widgets/tests/NoImportRefNodeTest.php';
    $moved = file_get_contents($movedPath);

    expect($moved)->toContain('\Acme\Widgets\Nodes\NoImportRefNode::type()');
    expect($moved)->not->toContain('App\Nodeflow\Nodes\NoImportRefNode');
    expect($this->root.'/tests/Feature/Nodeflow/NoImportRefNodeTest.php')->not->toBeFile();

    exec('php -l '.escapeshellarg($movedPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0);
});

it("moves a test with BOTH a `use` import and a fully-qualified reference, rewriting every span (M6a never needs to catch a leftover)", function () {
    // The reviewer's own constructed counterexample: an ordinary host test
    // containing both `use App\Nodeflow\Nodes\...;` AND a fully-qualified
    // `\App\Nodeflow\Nodes\...::type()` call. Before this fix, M3 rewrote
    // only the import, M6a found the untouched fully-qualified reference
    // in the copy it had just written, and refused valid work.
    $class = movesWriteNode($this->root, 'BothFormsNode', 'bothforms.node');

    $directory = $this->root.'/tests/Feature/Nodeflow';
    mkdir($directory, 0777, true);
    file_put_contents($directory.'/BothFormsNodeTest.php', <<<'PHP'
    <?php

    use App\Nodeflow\Nodes\BothFormsNode;

    it('keeps its type stable', function () {
        expect(BothFormsNode::type())->toBe('bothforms.node');
        expect(\App\Nodeflow\Nodes\BothFormsNode::type())->toBe('bothforms.node');
    });
    PHP);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $movedPath = $this->root.'/packages/acme/widgets/tests/BothFormsNodeTest.php';
    $moved = file_get_contents($movedPath);

    expect($moved)->toContain('use Acme\Widgets\Nodes\BothFormsNode;');
    expect($moved)->toContain('\Acme\Widgets\Nodes\BothFormsNode::type()');
    expect($moved)->not->toContain('App\Nodeflow\Nodes\BothFormsNode');

    exec('php -l '.escapeshellarg($movedPath).' 2>&1', $out, $exit);
    expect($exit)->toBe(0);
});

it('restores byte-identically when M3 (moving the test) fails, over an ALREADY-MATCHING pre-existing package', function () {
    // A pre-existing package (E43's "matching existing" target state)
    // whose OWN tests/ directory is read-only: M1's scaffold() writes
    // tests/ExampleTest.php successfully (that directory already exists,
    // so no mkdir is even attempted), M2 succeeds, but M3's own write into
    // the SAME read-only tests/ directory fails.
    $class = movesWriteNode($this->root, 'M3FailNode', 'm3.fail.node');

    $directory = $this->root.'/tests/Feature/Nodeflow';
    mkdir($directory, 0777, true);
    file_put_contents($directory.'/M3FailNodeTest.php', <<<'PHP'
    <?php

    use App\Nodeflow\Nodes\M3FailNode;

    it('keeps its type stable', function () {
        expect(M3FailNode::type())->toBe('m3.fail.node');
    });
    PHP);

    mkdir($this->root.'/packages/acme/widgets', 0777, true);
    file_put_contents($this->root.'/packages/acme/widgets/composer.json', json_encode(['name' => 'acme/widgets']));
    mkdir($this->root.'/packages/acme/widgets/tests', 0777, true);
    file_put_contents($this->root.'/packages/acme/widgets/tests/DUMMY.txt', 'from an earlier run');
    chmod($this->root.'/packages/acme/widgets/tests', 0555);

    $before = movesTreeHash($this->root);

    try {
        $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
            ->assertFailed();
    } finally {
        chmod($this->root.'/packages/acme/widgets/tests', 0755);
    }

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/packages/acme/widgets/tests/DUMMY.txt')->toBeFile();
});

it('restores byte-identically when M4 (registering in the package provider) fails, over a FOREIGN directory taken with --force', function () {
    $class = movesWriteNode($this->root, 'M4FailNode', 'm4.fail.node');

    // Foreign occupant (E43): a directory that already exists, holds a
    // composer.json naming a DIFFERENT package, and is only usable at all
    // because --force is passed.
    mkdir($this->root.'/packages/acme/widgets', 0777, true);
    file_put_contents($this->root.'/packages/acme/widgets/composer.json', json_encode(['name' => 'someone/else']));
    file_put_contents($this->root.'/packages/acme/widgets/NOTES.txt', 'do not touch');

    // Valid PHP, so M1's own scaffold succeeds and OVERWRITES the foreign
    // composer.json -- but with no `protected array $nodes = [` anchor at
    // all, so M4's register() call reports AnchorMissing.
    mkdir($this->root.'/stubs/package', 0777, true);
    file_put_contents($this->root.'/stubs/package/provider.stub', <<<'PHP'
    <?php

    namespace {{ namespace }};

    use Illuminate\Support\ServiceProvider;

    class {{ shortClass }} extends ServiceProvider
    {
        public function boot(): void
        {
        }
    }
    PHP);

    $before = movesTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
        '--force' => true,
    ])->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/packages/acme/widgets/composer.json')->toBeFile();

    $decoded = json_decode(file_get_contents($this->root.'/packages/acme/widgets/composer.json'), true);
    expect($decoded['name'])->toBe('someone/else');
    expect($this->root.'/packages/acme/widgets/NOTES.txt')->toBeFile();
});

it('restores byte-identically when M5 (deregistering from the host) fails, over an ALREADY-MATCHING pre-existing package', function () {
    $class = movesWriteNode($this->root, 'M5FailNode', 'm5.fail.node');

    // A pre-existing package the host already required from a matching
    // path repository -- E43's "matching existing" target state, standing
    // in for a legitimate re-run of a PREVIOUS extraction.
    mkdir($this->root.'/packages/acme/widgets', 0777, true);
    file_put_contents($this->root.'/packages/acme/widgets/composer.json', json_encode(['name' => 'acme/widgets']));
    file_put_contents($this->root.'/packages/acme/widgets/DUMMY.txt', 'from an earlier run');

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0', 'acme/widgets' => '*'],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
        'repositories' => [['type' => 'path', 'url' => 'packages/acme/widgets']],
    ]));

    // EntryAmbiguous (E39): the target shares its own physical line with a
    // sibling entry, so removeFrom() cannot delete it without touching that
    // sibling's own content. G5 already accepted this exact array (every
    // element classifies as a plain `<name>::class`), so this is a genuine
    // M5-time failure, not one the gates should have caught first.
    movesWriteProvider(
        $this->root,
        '        \App\Nodeflow\Nodes\M5FailNode::class, \App\Nodeflow\Nodes\M5FailNodeSibling::class,',
        'use App\Nodeflow\Nodes\M5FailNode;',
    );

    $before = movesTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/packages/acme/widgets/DUMMY.txt')->toBeFile();

    $decoded = json_decode(file_get_contents($this->root.'/packages/acme/widgets/composer.json'), true);
    expect($decoded['name'])->toBe('acme/widgets');
});

it('treats M5 EntryUnsupported as a failure, never as "nothing to do"', function () {
    // EntryUnsupported can fire even when $class is NOT itself present in
    // the array at all: removeFrom() refuses the moment it finds ANY
    // element it cannot classify (a spread here), before it ever checks
    // whether $class resolves to anything in the elements it CAN read. G4
    // already allows an unregistered class through ("unregistered is
    // explicitly NOT a refusal"), and G5 finds no reference to $class in
    // this file at all (it genuinely is not there) -- so this reaches M5
    // clean, and removeFrom() reporting EntryUnsupported must still abort
    // the whole extraction rather than be read as "nothing registered,
    // nothing to remove".
    $class = movesWriteNode($this->root, 'M5UnsupportedNode', 'm5.unsupported.node');

    movesWriteProvider($this->root, "        ...config('extra_nodes', []),");

    $before = movesTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
});

it('restores byte-identically when M6 (composer.json update) fails, leaving no package directory (target ABSENT)', function () {
    $class = movesWriteNode($this->root, 'M6FailNode', 'm6.fail.node');

    $before = movesTreeHash($this->root);

    // Read-only: put() cannot write the updated composer.json.
    chmod($this->root.'/composer.json', 0444);

    try {
        // ExtractJournal::putBack() must recognise that composer.json's
        // bytes were NEVER actually changed (the failing write never took)
        // and skip re-attempting an identical write, rather than trying
        // again and hitting the exact same permission failure a second
        // time -- a clean "restored to its original state" message, not an
        // alarming "restoring the host also failed" one, is the
        // observable proof restore() recognised nothing needed undoing.
        $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
            ->expectsOutputToContain('the host has been restored to its original state')
            ->assertFailed();
    } finally {
        chmod($this->root.'/composer.json', 0644);
    }

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
});

it('restores byte-identically when M7 (deleting the originals) fails, leaving no package directory (target ABSENT)', function () {
    $class = movesWriteNode($this->root, 'M7FailNode', 'm7.fail.node');

    $before = movesTreeHash($this->root);

    // No write permission on the CONTAINING directory: unlink() of the
    // original class file fails.
    chmod($this->root.'/app/Nodeflow/Nodes', 0555);

    try {
        $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
            ->assertFailed();
    } finally {
        chmod($this->root.'/app/Nodeflow/Nodes', 0755);
    }

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
    expect($this->root.'/app/Nodeflow/Nodes/M7FailNode.php')->toBeFile();
});
