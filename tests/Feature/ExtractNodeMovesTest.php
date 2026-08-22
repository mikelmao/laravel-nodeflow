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
    expect($decoded['require']['acme/widgets'])->not->toBeNull();
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

// --- M6a: the post-move rescan (E45) ----------------------------------------

it('aborts at M6a when a reference survives in a location the earlier gates could not see, restoring the host byte-identically', function () {
    // "scripts/" is neither one of G5's REFERENCE_SCAN_DIRS nor mapped by
    // the host's own PSR-4 ("App\\" => "app/" only) -- G5 cannot see this
    // reference and passes every gate. M6a's own rescan is deliberately
    // WIDER (every top-level directory except vendor/), so it is the one
    // that catches it -- and it must abort BEFORE M7 deletes the original.
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
        ->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
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

it('restores byte-identically when M3 (moving the test) fails, leaving no package directory (target ABSENT)', function () {
    // References the class only through a FULLY QUALIFIED name, never a
    // `use` import -- rewritableSpans()'s own fileReferencesClass() still
    // proves this file references $class (so M3 is attempted), but
    // importSpanFor() finds no import span to rewrite.
    $class = movesWriteNode($this->root, 'M3FailNode', 'm3.fail.node');

    $directory = $this->root.'/tests/Feature/Nodeflow';
    mkdir($directory, 0777, true);
    file_put_contents($directory.'/M3FailNodeTest.php', <<<'PHP'
    <?php

    it('keeps its type stable', function () {
        expect(\App\Nodeflow\Nodes\M3FailNode::type())->toBe('m3.fail.node');
    });
    PHP);

    $before = movesTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertFailed();

    expect(movesTreeHash($this->root))->toBe($before);
    expect($this->root.'/packages/acme/widgets')->not->toBeDirectory();
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
