<?php

use Nodeflow\Nodes\NodeRegistry;

/**
 * Every test in this file exercises `nodeflow:extract-node`'s eight
 * read-only gates ONLY — this build never moves anything, so every
 * assertion that matters is really two: the exit code, AND that the host
 * tree is byte-identical before and after. A test that only checked the
 * exit code would miss a gate that "refuses" after already having written
 * something, which is exactly the bug class E45's own history warns about.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-extract-node-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0777, true);

    // Canonicalise once, up front, for the same reason every other test file
    // touching HostPath does: HostPath::root() resolves symlinks, and macOS
    // aliases /var to /private/var, which would otherwise make an assertion
    // about a resolved absolute path diverge from the command's own for a
    // reason that has nothing to do with the behaviour under test.
    $this->root = realpath($this->root);

    mkdir($this->root.'/app', 0777, true);

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
    ]));

    $this->app->setBasePath($this->root);
});

afterEach(function () {
    deleteTree($this->root);
    deleteTree($this->root.'-emptypath');
});

/** Recursively deletes $dir, tolerating symlinks (never following one into whatever it points at). */
function deleteTree(string $dir): void
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
            deleteTree($path);
        } else {
            unlink($path);
        }
    }

    rmdir($dir);
}

/**
 * A hash of every file's content and every symlink's own target, keyed by
 * path relative to $root, over the entire tree. This is what "byte-identical
 * before and after" is checked against — an exit-code assertion alone
 * cannot tell a genuine refusal from one that already wrote something before
 * refusing.
 */
function hostTreeHash(string $root): string
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
 * Writes a valid, minimal subject node under the conventional
 * App\Nodeflow\Nodes namespace, requires it, and returns its FQCN — standing
 * in for a host application whose node class already exists on disk, the
 * normal case this command is extracting FROM.
 *
 * $shortClass must be unique across this whole file: requiring two classes
 * that share an FQCN in one process fatals with "class already declared".
 */
function writeAppNode(string $root, string $shortClass, string $type): string
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
    }
    PHP);

    require $path;

    return 'App\Nodeflow\Nodes\\'.$shortClass;
}

// --- Happy path: nothing refuses, nothing is written -----------------------

it('passes all eight gates, prints a notice, and writes nothing', function () {
    $class = writeAppNode($this->root, 'HappyPathNode', 'happy.path');
    file_put_contents($this->root.'/composer.lock', '{}');

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('composer.lock: present')
        ->assertExitCode(0);

    expect(hostTreeHash($this->root))->toBe($before);
});

it('records composer.lock as absent when the host has none', function () {
    $class = writeAppNode($this->root, 'LockAbsentNode', 'lock.absent');

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('composer.lock: absent')
        ->assertExitCode(0);
});

it('refuses at exit code 1, not 0 (F-3 / handle(): int contract)', function () {
    // Counterfactual: return false from handle() for a refusal, and Laravel's
    // (int) cast on that turns it into exit code 0 -- indistinguishable from
    // success to any script or CI job that only checks $?.
    $exitCode = $this->artisan('nodeflow:extract-node', [
        'class' => 'Totally\Nonexistent\ClassXyz',
        '--package' => 'acme/widgets',
    ])->run();

    expect($exitCode)->toBe(1);
});

// --- G1: class_exists, is_a(Node), cardinality ------------------------------

it('refuses a class that does not exist, reusing NodeRegistry::register()\'s own message (G1)', function () {
    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', [
        'class' => 'Totally\Nonexistent\ClassXyz',
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('cannot be registered as a nodeflow node')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses a class that does not extend Node, reusing NodeRegistry::register()\'s own message (G1)', function () {
    $path = $this->root.'/app/NotANode.php';
    file_put_contents($path, <<<'PHP'
    <?php

    namespace App;

    class NotANode
    {
    }
    PHP);
    require $path;

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => 'App\NotANode', '--package' => 'acme/widgets'])
        ->expectsOutputToContain('cannot be registered as a nodeflow node')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses a Node with neither cardinality interface, reusing NodeRegistry::register()\'s own message (G1)', function () {
    $directory = $this->root.'/app/Nodeflow/Nodes';
    mkdir($directory, 0777, true);
    $path = $directory.'/NoCardinalityNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class NoCardinalityNode extends Node
    {
        public static function type(): string
        {
            return 'no.cardinality';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('NoCardinalityNode');
        }
    }
    PHP);
    require $path;

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', [
        'class' => 'App\Nodeflow\Nodes\NoCardinalityNode',
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('implements neither')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

// --- G2: file location (E51) and single top-level symbol (E47) -------------

it('refuses a node whose file lives under vendor/, outside the host application (G2, adversarial probe 1)', function () {
    $directory = $this->root.'/vendor/some-vendor/some-pkg/src';
    mkdir($directory, 0777, true);
    $path = $directory.'/VendorNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace SomeVendor\SomePkg;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class VendorNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'vendor.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('VendorNode')->outputs(['default']);
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            return $context->continue('default');
        }
    }
    PHP);
    require $path;

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', [
        'class' => 'SomeVendor\SomePkg\VendorNode',
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('not inside the host application')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses a node whose file also declares a trait, naming the trait', function () {
    // E47. M2 rewrites the file's namespace, which moves EVERY declaration in it,
    // while the scan only looks for references to the node. Without this gate the
    // node resolves, type() holds, verification passes, and a host class using the
    // trait dies with "Trait ... not found".
    $directory = $this->root.'/app/Nodeflow/Nodes';
    mkdir($directory, 0777, true);
    $path = $directory.'/CompanionNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    trait CompanionHelper
    {
        public function help(): void
        {
        }
    }

    class CompanionNode extends Node implements HandlesSubject
    {
        use CompanionHelper;

        public static function type(): string
        {
            return 'companion.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('CompanionNode')->outputs(['default']);
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            return $context->continue('default');
        }
    }
    PHP);
    require $path;

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', [
        'class' => 'App\Nodeflow\Nodes\CompanionNode',
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('CompanionHelper')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses a node whose file also declares a top-level function, naming it (E47)', function () {
    $directory = $this->root.'/app/Nodeflow/Nodes';
    mkdir($directory, 0777, true);
    $path = $directory.'/CompanionFunctionNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    function companion_helper_function(): void
    {
    }

    class CompanionFunctionNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'companion.function.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('CompanionFunctionNode')->outputs(['default']);
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            return $context->continue('default');
        }
    }
    PHP);
    require $path;

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', [
        'class' => 'App\Nodeflow\Nodes\CompanionFunctionNode',
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('companion_helper_function')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses a node whose file also declares a top-level const, naming it (E47)', function () {
    $directory = $this->root.'/app/Nodeflow/Nodes';
    mkdir($directory, 0777, true);
    $path = $directory.'/CompanionConstNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    const COMPANION_HELPER_CONST = 'x';

    class CompanionConstNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'companion.const.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('CompanionConstNode')->outputs(['default']);
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            return $context->continue('default');
        }
    }
    PHP);
    require $path;

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', [
        'class' => 'App\Nodeflow\Nodes\CompanionConstNode',
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('COMPANION_HELPER_CONST')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('does not refuse a node file that declares only itself, with an anonymous class expression inside a method (E47)', function () {
    // `new class { ... }` and a closure both introduce their own '{' without a
    // preceding class/interface/trait/enum/function/const keyword whose next
    // token is a name -- findCompanionSymbol() must not mistake either for a
    // companion, since neither is a NAMED top-level symbol.
    $directory = $this->root.'/app/Nodeflow/Nodes';
    mkdir($directory, 0777, true);
    $path = $directory.'/AnonymousInsideNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class AnonymousInsideNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'anonymous.inside.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('AnonymousInsideNode')->outputs(['default']);
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            $helper = new class
            {
                public function noop(): void
                {
                }
            };

            $closure = function () {
                return 1;
            };

            $helper->noop();
            $closure();

            return $context->continue('default');
        }
    }
    PHP);
    require $path;

    $this->artisan('nodeflow:extract-node', [
        'class' => 'App\Nodeflow\Nodes\AnonymousInsideNode',
        '--package' => 'acme/widgets',
    ])->assertExitCode(0);
});

// --- G3: type() must be a proven literal (E36) ------------------------------

it('refuses a node whose type() is computed, and writes nothing', function () {
    // E36/E10. The one failure re-running cannot repair: type() derived from the
    // class name silently changes identity when the namespace moves, orphaning
    // every published version that references it.
    // Counterfactual: skip G3 entirely and this passes while the extraction
    // proceeds -- verify by commenting out the gate and re-running.
    $directory = $this->root.'/app/Nodeflow/Nodes';
    mkdir($directory, 0777, true);
    $path = $directory.'/ComputedTypeNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class ComputedTypeNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return strtolower(class_basename(static::class));
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('ComputedTypeNode')->outputs(['default']);
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            return $context->continue('default');
        }
    }
    PHP);
    require $path;

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', [
        'class' => 'App\Nodeflow\Nodes\ComputedTypeNode',
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('does not return a plain string literal')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

// --- G4: a DIFFERENT owner refuses; unregistered does not ------------------

it('refuses when the proven type is already registered to a different class (G4)', function () {
    $owner = writeAppNode($this->root, 'GateFourOwner', 'gate4.shared');
    app(NodeRegistry::class)->register($owner);

    $contender = writeAppNode($this->root, 'GateFourContender', 'gate4.shared');

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $contender, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('GateFourOwner')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('does not refuse when the proven type is registered to the class being extracted itself (G4)', function () {
    $class = writeAppNode($this->root, 'GateFourSelfOwned', 'gate4.self');
    app(NodeRegistry::class)->register($class);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);
});

it('does not refuse when the proven type is not registered at all (G4)', function () {
    // "Unregistered is NOT a refusal" -- a freshly generated node that has
    // never been wired into the host's provider is legitimately extractable.
    $class = writeAppNode($this->root, 'GateFourUnregistered', 'gate4.unregistered');

    expect(app(NodeRegistry::class)->has('gate4.unregistered'))->toBeFalse();

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);
});

// --- G5: NodeReferenceScanner minus rewritableSpans() (E45, E46) -----------

it('refuses a node still registered through a legacy Nodeflow::register() call', function () {
    // E45, and the finding that falsified the first design draft. The provider is
    // a file M5 rewrites, so a file-level exemption let this through; a span-level
    // one refuses it. After the move, NodeRegistry::register() autoloads through
    // is_a(), so the surviving entry is a fatal in boot() on every request.
    // The fixture is the demo's real shape.
    $class = writeAppNode($this->root, 'LegacyNode', 'legacy.node');

    $providerDirectory = $this->root.'/app/Providers';
    mkdir($providerDirectory, 0777, true);

    file_put_contents($providerDirectory.'/NodeflowServiceProvider.php', <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Nodeflow\Nodes\LegacyNode;
    use Nodeflow\Nodeflow;

    class NodeflowServiceProvider
    {
        protected array $nodes = [
            LegacyNode::class,
        ];

        public function boot(): void
        {
            Nodeflow::register([
                LegacyNode::class,
            ]);
        }
    }
    PHP);

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('NodeflowServiceProvider.php')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('does not refuse when the host provider carries only the exempted use import and $nodes entry', function () {
    // The other half of the legacy-register test above: rewritableSpans() must
    // actually exempt the provider's own `use` import and `$nodes` entry, or
    // every host that registers its nodes the RECOMMENDED way (no legacy
    // Nodeflow::register() call at all) would be refused by G5 regardless.
    $class = writeAppNode($this->root, 'GateFiveCleanNode', 'gate5.clean');

    $providerDirectory = $this->root.'/app/Providers';
    mkdir($providerDirectory, 0777, true);

    file_put_contents($providerDirectory.'/NodeflowServiceProvider.php', <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Nodeflow\Nodes\GateFiveCleanNode;

    class NodeflowServiceProvider
    {
        protected array $nodes = [
            GateFiveCleanNode::class,
        ];

        public function boot(): void
        {
        }
    }
    PHP);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);
});

it('does not refuse a node file that names its own FQCN inside itself, not just via self/static', function () {
    // Exercises RewritableSpan::wholeFile()'s own [0, filesize) range against a
    // REAL found reference at a byte offset greater than zero: the node's own
    // declaration is excluded from scanning by NodeReferenceScanner itself
    // (a declaration is not a reference), so an explicit `new
    // \App\Nodeflow\Nodes\{class}()` written elsewhere in the same file is the
    // only realistic way this file's own wholeFile exemption is ever tested
    // against a genuine NodeReference rather than an empty one.
    $directory = $this->root.'/app/Nodeflow/Nodes';
    mkdir($directory, 0777, true);
    $path = $directory.'/SelfReferencingNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class SelfReferencingNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'self.referencing.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('SelfReferencingNode')->outputs(['default']);
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            $other = new \App\Nodeflow\Nodes\SelfReferencingNode();

            return $context->continue('default');
        }
    }
    PHP);
    require $path;

    $this->artisan('nodeflow:extract-node', [
        'class' => 'App\Nodeflow\Nodes\SelfReferencingNode',
        '--package' => 'acme/widgets',
    ])->assertExitCode(0);
});

it('bracket-matches the $nodes array past a nested array literal, not just to the first "]"', function () {
    // nodesArrayBody() must find the OUTER array's own closing bracket, not
    // whatever ']' appears first -- a nested array value sitting before the
    // real entry would otherwise truncate the body early, and a genuinely
    // exempt entry sitting AFTER that point would be read as outside the
    // $nodes property and left unexempted.
    $class = writeAppNode($this->root, 'NestedArrayCompanionNode', 'nested.array.companion');

    $providerDirectory = $this->root.'/app/Providers';
    mkdir($providerDirectory, 0777, true);

    file_put_contents($providerDirectory.'/NodeflowServiceProvider.php', <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Nodeflow\Nodes\NestedArrayCompanionNode;

    class NodeflowServiceProvider
    {
        protected array $nodes = [
            ['decoy'],
            NestedArrayCompanionNode::class,
        ];

        public function boot(): void
        {
        }
    }
    PHP);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);
});

it('refuses a node whose FQCN appears only in config/, proving the widened scan roots reach it (G5, adversarial probe 4)', function () {
    $class = writeAppNode($this->root, 'ConfigOnlyNode', 'config.only');

    mkdir($this->root.'/config', 0777, true);
    file_put_contents($this->root.'/config/nodeflow_custom.php', <<<'PHP'
    <?php

    return [
        'node' => 'App\Nodeflow\Nodes\ConfigOnlyNode',
    ];
    PHP);

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('nodeflow_custom.php')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('does not refuse when a different class merely shares the same short name in app/ (G5, adversarial probe 5)', function () {
    $class = writeAppNode($this->root, 'ProbeFiveNode', 'probe.five');

    // A DIFFERENT class, different namespace, same short name -- must not be
    // mistaken for a reference to $class. NodeReferenceScanner resolves by
    // FQCN, not by spelling, so this must not block legitimate work.
    $decoyDirectory = $this->root.'/app/Other';
    mkdir($decoyDirectory, 0777, true);
    file_put_contents($decoyDirectory.'/ProbeFiveNode.php', <<<'PHP'
    <?php

    namespace App\Other;

    class ProbeFiveNode
    {
    }
    PHP);
    require $decoyDirectory.'/ProbeFiveNode.php';

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);
});

it('refuses cleanly, naming the file, when a scanned host file declares more than one namespace (G5)', function () {
    $class = writeAppNode($this->root, 'MultiNsSiblingNode', 'multi.ns.sibling');

    file_put_contents($this->root.'/app/Weird.php', <<<'PHP'
    <?php

    namespace App\One;

    namespace App\Two;

    class Weird
    {
    }
    PHP);

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('more than one namespace')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

// --- G6: composer.json parses, no naming conflict, no dont-discover cover (E49) ---

it('refuses when --package is already required from a path repository pointing elsewhere (G6, adversarial probe 2)', function () {
    $class = writeAppNode($this->root, 'GateSixElsewhereNode', 'gate6.elsewhere');

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0', 'acme/widgets' => '^1.0'],
        'repositories' => [
            ['type' => 'path', 'url' => 'packages/somewhere-else'],
        ],
    ]));

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('packages/acme/widgets')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses when --package is already required in require-dev, not just require, from a different source', function () {
    $class = writeAppNode($this->root, 'GateSixRequireDevNode', 'gate6.requiredev');

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'require-dev' => ['acme/widgets' => '^1.0'],
    ]));

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('acme/widgets')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('does not refuse when --package is required from a matching path repository', function () {
    $class = writeAppNode($this->root, 'GateSixMatchingNode', 'gate6.matching');

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0', 'acme/widgets' => '^1.0'],
        'repositories' => [
            ['type' => 'path', 'url' => 'packages/acme/widgets'],
        ],
    ]));

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);
});

it('does not refuse when the matching path repository url is a glob, not a literal path', function () {
    // Composer's own path repositories may be a glob (e.g. "packages/*/*").
    // requiredFromMatchingPathRepository() matches via fnmatch() rather than
    // a plain equality check specifically so this case is covered.
    $class = writeAppNode($this->root, 'GateSixGlobNode', 'gate6.glob');

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0', 'acme/widgets' => '^1.0'],
        'repositories' => [
            ['type' => 'path', 'url' => 'packages/*/*'],
        ],
    ]));

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);
});

it('refuses when extra.laravel.dont-discover covers the new package with a "*" entry (G6, adversarial probe 3)', function () {
    $class = writeAppNode($this->root, 'GateSixStarNode', 'gate6.star');

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'extra' => ['laravel' => ['dont-discover' => ['*']]],
    ]));

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('dont-discover')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses when dont-discover is written as the bare string "*" rather than an array', function () {
    $class = writeAppNode($this->root, 'GateSixBareStarNode', 'gate6.barestar');

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'extra' => ['laravel' => ['dont-discover' => '*']],
    ]));

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('dont-discover')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses when dont-discover names the new package specifically, not just "*"', function () {
    $class = writeAppNode($this->root, 'GateSixNamedNode', 'gate6.named');

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'extra' => ['laravel' => ['dont-discover' => ['acme/widgets']]],
    ]));

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('acme/widgets')
        ->assertFailed();
});

it('does not refuse when dont-discover lists only unrelated packages', function () {
    $class = writeAppNode($this->root, 'GateSixUnrelatedNode', 'gate6.unrelated');

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'extra' => ['laravel' => ['dont-discover' => ['someone/else']]],
    ]));

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);
});

it('refuses when the host composer.json does not parse as JSON (G6)', function () {
    $class = writeAppNode($this->root, 'GateSixBadJsonNode', 'gate6.badjson');

    file_put_contents($this->root.'/composer.json', '{not valid json');

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('does not parse as JSON')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

// --- G7: target path absent, empty, or already this package (E43) ---------

it('refuses an occupied target path that is not the package being extracted, and succeeds with --force (E43)', function () {
    $class = writeAppNode($this->root, 'GateSevenNode', 'gate7.node');

    mkdir($this->root.'/packages/acme/widgets', 0777, true);
    file_put_contents($this->root.'/packages/acme/widgets/composer.json', json_encode(['name' => 'someone/else']));

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('E43')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
        '--force' => true,
    ])->assertExitCode(0);

    expect(hostTreeHash($this->root))->toBe($before);
});

it('does not refuse an empty, pre-existing target directory (G7)', function () {
    $class = writeAppNode($this->root, 'GateSevenEmptyDirNode', 'gate7.emptydir');
    mkdir($this->root.'/packages/acme/widgets', 0777, true);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);
});

it('does not refuse when the target already holds exactly the package being extracted (G7)', function () {
    $class = writeAppNode($this->root, 'GateSevenMatchNode', 'gate7.match');
    mkdir($this->root.'/packages/acme/widgets', 0777, true);
    file_put_contents($this->root.'/packages/acme/widgets/composer.json', json_encode(['name' => 'acme/widgets']));

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);
});

it('refuses an occupied target with no composer.json at all, distinct from a foreign one (G7)', function () {
    $class = writeAppNode($this->root, 'GateSevenNoComposerJsonNode', 'gate7.nocomposerjson');
    mkdir($this->root.'/packages/acme/widgets', 0777, true);
    file_put_contents($this->root.'/packages/acme/widgets/.gitkeep', '');

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('no composer.json')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('resolves the target path from --path rather than the default when it is given (G7)', function () {
    $class = writeAppNode($this->root, 'GateSevenCustomPathNode', 'gate7.custompath');
    mkdir($this->root.'/custom/location', 0777, true);
    file_put_contents($this->root.'/custom/location/composer.json', json_encode(['name' => 'someone/else']));

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
        '--path' => 'custom/location',
    ])
        ->expectsOutputToContain('custom/location')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

// --- G8: composer invocable; composer.lock existence recorded (E48) -------

it('refuses when composer is not invocable (G8)', function () {
    $class = writeAppNode($this->root, 'GateEightNode', 'gate8.node');

    $emptyPathDirectory = $this->root.'-emptypath';
    mkdir($emptyPathDirectory, 0777, true);

    $originalPath = getenv('PATH');
    putenv('PATH='.$emptyPathDirectory);

    try {
        $before = hostTreeHash($this->root);

        $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
            ->expectsOutputToContain('composer')
            ->assertFailed();

        expect(hostTreeHash($this->root))->toBe($before);
    } finally {
        putenv($originalPath === false ? 'PATH' : 'PATH='.$originalPath);
    }
});

// --- Missing --package: not one of the eight gates, but a precondition they need ---

it('refuses with no --package given, before ever touching composer.json or the target path', function () {
    $class = writeAppNode($this->root, 'NoPackageOptionNode', 'no.package.option');

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class])
        ->expectsOutputToContain('--package')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

// --- F-3: reset instance-cached state at the top of handle() --------------

it('does not leak a stale provenType or composerLockExisted from an earlier successful call into a later refused one (F-3)', function () {
    // This exact bug shipped twice already in this codebase, against different
    // cached properties on different commands (MakeNodeCommand::nodeType(),
    // MakeNodePackageCommand::target()). Counterfactual: delete the two reset
    // lines at the top of handle() and this test's second pair of assertions
    // fails -- provenType()/composerLockExisted() still report the FIRST
    // call's values after a SECOND call that never got far enough to compute
    // either one itself.
    $classA = writeAppNode($this->root, 'ResetNodeA', 'reset.a');
    file_put_contents($this->root.'/composer.lock', '{}');

    $this->artisan('nodeflow:extract-node', ['class' => $classA, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    $command = $this->app[\Illuminate\Contracts\Console\Kernel::class]->all()['nodeflow:extract-node'];

    expect($command->provenType())->toBe('reset.a');
    expect($command->composerLockExisted())->toBeTrue();

    $this->artisan('nodeflow:extract-node', [
        'class' => 'Totally\Nonexistent\ClassXyz',
        '--package' => 'acme/widgets',
    ])->assertFailed();

    expect($command->provenType())->toBeNull();
    expect($command->composerLockExisted())->toBeNull();
});
