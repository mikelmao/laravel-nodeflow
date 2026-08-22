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
    $this->app->instance(
        \Nodeflow\Console\Extract\ComposerRunner::class,
        new \Tests\Support\PassingComposerRunner,
    );

    $this->root = sys_get_temp_dir().'/nodeflow-extract-node-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0777, true);

    // Canonicalise once, up front, for the same reason every other test file
    // touching HostPath does: HostPath::root() resolves symlinks, and macOS
    // aliases /var to /private/var, which would otherwise make an assertion
    // about a resolved absolute path diverge from the command's own for a
    // reason that has nothing to do with the behaviour under test.
    $this->root = realpath($this->root);

    mkdir($this->root.'/app', 0777, true);

    // 'autoload' => ['psr-4' => ['App\\' => 'app/']] matches every real
    // Laravel host's own composer.json (Laravel's own skeleton ships exactly
    // this entry) -- and, since Important A, G2 now REQUIRES the node's own
    // file to sit under a directory the host's composer.json maps this way,
    // so every fixture in this file needs it present to reach any gate past
    // G2 at all.
    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
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

// --- Happy path: every gate passes, so Task 9's moves actually run --------

// Task 8's own build stopped here with a "gates passed, nothing moved yet"
// notice and asserted the host tree stayed byte-identical -- the correct
// assertion for a command that was, at the time, read-only by construction.
// Task 9 replaces that notice with the real moves (see
// tests/Feature/ExtractNodeMovesTest.php for full coverage of M1-M7 and
// M6a), so a host tree that changes -- the package now exists, the original
// is gone -- is the CORRECT outcome here, not a regression.
it('passes all eight gates and performs the extraction', function () {
    $class = writeAppNode($this->root, 'HappyPathNode', 'happy.path');
    file_put_contents($this->root.'/composer.lock', '{}');

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);

    expect($this->root.'/packages/acme/widgets/composer.json')->toBeFile();
    expect($this->root.'/packages/acme/widgets/src/Nodes/HappyPathNode.php')->toBeFile();
    expect($this->root.'/app/Nodeflow/Nodes/HappyPathNode.php')->not->toBeFile();
});

it('extracts successfully when the host has no composer.lock', function () {
    $class = writeAppNode($this->root, 'LockAbsentNode', 'lock.absent');

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
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
        ->expectsOutputToContain('lives under a [vendor/] segment')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('does not refuse a node living outside app/ but still inside a PSR-4 root the host itself maps, e.g. src/ (Important 5, refined by Important A)', function () {
    // G2's rule is "under a directory the host's OWN composer.json maps via
    // autoload/autoload-dev PSR-4, and not under vendor/ at any depth" --
    // NOT "inside app/ specifically", and (since Important A) NOT merely
    // "anywhere under the host root" either: reading the host's own PSR-4
    // map is what lets a host mapping its root namespace to src/ (a
    // legitimate, if less common, choice) still work, while a directory the
    // host's own composer.json does not claim (storage/, a sibling
    // package's own src/, ...) does not. This fixture's own composer.json
    // therefore maps App\ to src/ explicitly -- the DEFAULT fixture (App\
    // => app/) would otherwise correctly refuse this exact file, which is
    // the whole point.
    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => ['App\\' => 'src/']],
    ]));

    $directory = $this->root.'/src/Nodeflow/Nodes';
    mkdir($directory, 0777, true);
    $path = $directory.'/SrcRootNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class SrcRootNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'src.root.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('SrcRootNode')->outputs(['default']);
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            return $context->continue('default');
        }
    }
    PHP);
    require $path;

    $this->artisan('nodeflow:extract-node', [
        'class' => 'App\Nodeflow\Nodes\SrcRootNode',
        '--package' => 'acme/widgets',
    ])->assertExitCode(0);
});

// --- Important A: G2 must not admit ground G5 cannot scan ------------------

it('refuses a node inside ANOTHER local path-repository package, not mapped by the HOST\'s own composer.json (Important A, case a)', function () {
    // packages/acme/other/src/... is a DIFFERENT Composer package's own
    // source, mapped by THAT package's own composer.json, never the host's.
    // Before Important A (G2 widened to "host root minus a top-level
    // vendor/"), this exited 0: the file sits inside the host root and not
    // under vendor/, so it passed, even though moving it would rewrite a
    // namespace that other package's own src/Consumer.php still references
    // -- a reference G5 never scans, because packages/acme/other/src/ was
    // never one of G5's roots either.
    mkdir($this->root.'/packages/acme/other/src/Nodes', 0777, true);
    $path = $this->root.'/packages/acme/other/src/Nodes/OtherPackageNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace Acme\Other\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class OtherPackageNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'other.package.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('OtherPackageNode')->outputs(['default']);
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            return $context->continue('default');
        }
    }
    PHP);
    require $path;

    // The live reference G5 would never have reached, even before this fix
    // -- included so the fixture demonstrates the actual consequence, not
    // merely a contrived path.
    file_put_contents($this->root.'/packages/acme/other/src/Consumer.php', <<<'PHP'
    <?php

    namespace Acme\Other;

    use Acme\Other\Nodes\OtherPackageNode;

    class Consumer
    {
        public function make(): OtherPackageNode
        {
            return new OtherPackageNode();
        }
    }
    PHP);

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', [
        'class' => 'Acme\Other\Nodes\OtherPackageNode',
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('PSR-4')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses a node inside a NESTED vendor/ directory belonging to a different local package (Important A, case b)', function () {
    // packages/foo/vendor/bar/pkg/src/... -- a vendor/ segment two levels
    // deep, precisely what the any-depth exclusion exists to stop. A host
    // mapping a broad root like "packages/" would otherwise treat this as
    // "contained" and therefore fine under a PSR-4-only check with no
    // separate vendor exclusion.
    mkdir($this->root.'/packages/foo/vendor/bar/pkg/src', 0777, true);
    $path = $this->root.'/packages/foo/vendor/bar/pkg/src/NestedVendorNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace Bar\Pkg;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class NestedVendorNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'nested.vendor.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('NestedVendorNode')->outputs(['default']);
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
        'class' => 'Bar\Pkg\NestedVendorNode',
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('vendor/] segment')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses a node inside storage/framework/cache/, unmapped by any PSR-4 entry (Important A, case c)', function () {
    mkdir($this->root.'/storage/framework/cache', 0777, true);
    $path = $this->root.'/storage/framework/cache/CacheNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace Storage\Cache;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class CacheNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'cache.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('CacheNode')->outputs(['default']);
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
        'class' => 'Storage\Cache\CacheNode',
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('PSR-4')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses a node already inside the extraction\'s own TARGET package, unmapped by the host (Important A, case d)', function () {
    // packages/acme/widgets/src/... is exactly where THIS extraction's own
    // --package=acme/widgets would land -- a node already sitting there is
    // not the host's own source either, and the host's composer.json never
    // claims it via autoload/autoload-dev.
    mkdir($this->root.'/packages/acme/widgets/src', 0777, true);
    $path = $this->root.'/packages/acme/widgets/src/AlreadyInTargetNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace Acme\Widgets;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class AlreadyInTargetNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'already.in.target.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('AlreadyInTargetNode')->outputs(['default']);
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
        'class' => 'Acme\Widgets\AlreadyInTargetNode',
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('PSR-4')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('unions the host\'s own PSR-4 roots into G5\'s scan, agreeing with G2 by construction (Important A)', function () {
    // G2 requires the node's own file to sit under a host PSR-4 root; G5's
    // scan roots gain that SAME set via the SAME hostPsr4Directories() call
    // -- proven here by mapping App\ to src/ (NOT one of the seven NAMED
    // scan directories) and putting a live reference to the target only
    // inside that src/ tree, outside every named root.
    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => ['App\\' => 'src/']],
    ]));

    mkdir($this->root.'/src/Nodeflow/Nodes', 0777, true);
    $nodePath = $this->root.'/src/Nodeflow/Nodes/PsrRootScanNode.php';

    file_put_contents($nodePath, <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class PsrRootScanNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'psr.root.scan.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('PsrRootScanNode')->outputs(['default']);
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            return $context->continue('default');
        }
    }
    PHP);
    require $nodePath;

    // A reference living ONLY under src/ -- not app/, config/, database/,
    // resources/, routes/, bootstrap/, or tests/.
    mkdir($this->root.'/src/Other', 0777, true);
    file_put_contents($this->root.'/src/Other/Consumer.php', <<<'PHP'
    <?php

    namespace App\Other;

    use App\Nodeflow\Nodes\PsrRootScanNode;

    class Consumer
    {
        public function make(): PsrRootScanNode
        {
            return new PsrRootScanNode();
        }
    }
    PHP);

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', [
        'class' => 'App\Nodeflow\Nodes\PsrRootScanNode',
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('Consumer.php')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

// --- Important N2: a PSR-4 value must not map the whole root or escape it ---

it('refuses a node under storage/, even when composer.json maps a PSR-4 prefix to "./" (Important N2)', function () {
    // "./" normalises to zero path segments -- the same as "." or "/" --
    // and MUST NOT be accepted as a mapped root, or it maps the ENTIRE
    // host, reopening Important A's case (c): storage/framework/cache/
    // would then be "contained" in that root and pass G2. Counterfactual:
    // restore the old trim($directory, '/') derivation (no segment check)
    // and this test fails at exit 0.
    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => ['App\\' => './']],
    ]));

    mkdir($this->root.'/storage/framework/cache', 0777, true);
    $path = $this->root.'/storage/framework/cache/DotSlashNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace Storage\Cache;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class DotSlashNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'dot.slash.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('DotSlashNode')->outputs(['default']);
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
        'class' => 'Storage\Cache\DotSlashNode',
        '--package' => 'acme/widgets',
    ])->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses the same node when the PSR-4 value is the bare dot rather than dot-slash', function () {
    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => ['App\\' => '.']],
    ]));

    mkdir($this->root.'/storage/framework/cache', 0777, true);
    $path = $this->root.'/storage/framework/cache/BareDotNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace Storage\Cache;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class BareDotNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'bare.dot.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('BareDotNode')->outputs(['default']);
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            return $context->continue('default');
        }
    }
    PHP);
    require $path;

    $this->artisan('nodeflow:extract-node', [
        'class' => 'Storage\Cache\BareDotNode',
        '--package' => 'acme/widgets',
    ])->assertFailed();
});

it('refuses the same node when the PSR-4 value is the bare slash rather than dot-slash', function () {
    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => ['App\\' => '/']],
    ]));

    mkdir($this->root.'/storage/framework/cache', 0777, true);
    $path = $this->root.'/storage/framework/cache/BareSlashNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace Storage\Cache;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class BareSlashNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'bare.slash.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('BareSlashNode')->outputs(['default']);
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            return $context->continue('default');
        }
    }
    PHP);
    require $path;

    $this->artisan('nodeflow:extract-node', [
        'class' => 'Storage\Cache\BareSlashNode',
        '--package' => 'acme/widgets',
    ])->assertFailed();
});

it('does not let a "../" PSR-4 value make G5 scan outside the host root (Important N2)', function () {
    // A dedicated, isolated sandbox (not sys_get_temp_dir() itself, which
    // is a busy, shared directory unrelated files could live under) so
    // this test is deterministic: the ONLY thing directly inside the
    // sandbox, besides the host itself, is one planted file this test
    // controls. composer.json declares a SECOND (legitimate) PSR-4 entry
    // ("App\" => "app/") so G2 still passes for the node itself -- this
    // test is isolating whether the "../" entry becomes a G5 scan root,
    // not re-testing G2's own containment rule.
    $sandbox = sys_get_temp_dir().'/nodeflow-extract-node-n2sandbox-'.bin2hex(random_bytes(6));
    mkdir($sandbox, 0777, true);
    $sandbox = realpath($sandbox);

    $hostRoot = $sandbox.'/host';
    mkdir($hostRoot.'/app', 0777, true);

    file_put_contents($hostRoot.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => [
            'App\\' => 'app/',
            'Escaped\\' => '../',
        ]],
    ]));

    $this->app->setBasePath($hostRoot);

    $class = writeAppNode($hostRoot, 'ClimbOutNode', 'climb.out.node');

    // Planted directly inside the SANDBOX -- one level above the host root,
    // exactly where "../" resolves to. If G5 ever treated that resolved
    // path as a scan root, this reference would be found and extraction
    // would wrongly refuse.
    file_put_contents($sandbox.'/OutsideConsumer.php', <<<'PHP'
    <?php

    namespace Outside;

    use App\Nodeflow\Nodes\ClimbOutNode;

    class OutsideConsumer
    {
        public function make(): ClimbOutNode
        {
            return new ClimbOutNode();
        }
    }
    PHP);

    try {
        $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
            ->assertExitCode(0);
    } finally {
        deleteTree($sandbox);
        $this->app->setBasePath($this->root);
    }
});

it('refuses a node under storage/, when composer.json maps a PSR-4 prefix to "app/.." (a non-leading ".." segment)', function () {
    // "app/.." canonicalises straight back to the host root -- is_dir() is
    // true and $hostRoot->contains() is true for it, so ONLY the
    // in_array('..', $segments, true) check refuses it; the containment
    // check alone would let it through, because the escape-and-return
    // lands EXACTLY on legitimate ground. Every other Important N2 test
    // uses a value where '..' is the FIRST (or only) segment; this is the
    // one shape a purely positional check ("is the last/first segment
    // '..'?") would miss, and only a scan of every segment catches it.
    // Counterfactual: delete the in_array('..', $segments, true) line
    // (keep the emptiness and containment checks) and this test fails at
    // exit 0.
    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => ['App\\' => 'app/..']],
    ]));

    mkdir($this->root.'/storage/framework/cache', 0777, true);
    $path = $this->root.'/storage/framework/cache/NonLeadingDotDotNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace Storage\Cache;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class NonLeadingDotDotNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'non.leading.dot.dot.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('NonLeadingDotDotNode')->outputs(['default']);
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
        'class' => 'Storage\Cache\NonLeadingDotDotNode',
        '--package' => 'acme/widgets',
    ])->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('does not let a PSR-4 directory that is a SYMLINK escaping the host become a scan root (Important N2, symlink escape)', function () {
    // The syntactic segment check (empty, or containing '..') cannot see
    // this one at all: "linked/" is a perfectly ordinary-looking value.
    // Only resolving it -- $hostRoot->contains($absolute), which
    // canonicalises through the symlink via HostPath's own realpath() --
    // reveals that it points OUTSIDE the host. A second, legitimate PSR-4
    // entry ("App" => "app/") lets G2 pass normally for the node itself,
    // isolating what this test actually checks: whether the ESCAPING
    // entry becomes a G5 scan root. If it does, G5 finds a reference
    // planted only in the symlink's OUTSIDE target and wrongly refuses
    // citing a file that was never part of the host; if it does not
    // (the fix), extraction succeeds, because nothing outside the host is
    // ever scanned. Counterfactual: delete the
    // `! $hostRoot->contains($absolute)` half of the guard (keep only the
    // isDirectory() check) and this test fails -- exit 1, citing
    // OutsideConsumer.php, a file that was never part of the host tree.
    $outside = sys_get_temp_dir().'/nodeflow-extract-node-symlink-outside-'.bin2hex(random_bytes(6));
    mkdir($outside, 0777, true);
    $outside = realpath($outside);

    symlink($outside, $this->root.'/linked');

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => [
            'App\\' => 'app/',
            'Escaped\\' => 'linked/',
        ]],
    ]));

    $class = writeAppNode($this->root, 'SymlinkEscapeNode', 'symlink.escape.node');

    // Planted only inside the symlink's OUTSIDE target -- never part of
    // the host tree at all, reachable ONLY if the escaping "linked/" entry
    // were ever treated as a real scan root.
    file_put_contents($outside.'/OutsideConsumer.php', <<<'PHP'
    <?php

    namespace Outside;

    use App\Nodeflow\Nodes\SymlinkEscapeNode;

    class OutsideConsumer
    {
        public function make(): SymlinkEscapeNode
        {
            return new SymlinkEscapeNode();
        }
    }
    PHP);

    try {
        $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
            ->assertExitCode(0);
    } finally {
        unlink($this->root.'/linked');
        deleteTree($outside);
    }
});

// --- Mutation survivors from the PSR-4 derivation itself --------------------

it('refuses cleanly, not with an uncaught exception, when the host has no composer.json at all (mutation survivor 1)', function () {
    unlink($this->root.'/composer.json');

    $class = writeAppNode($this->root, 'NoComposerJsonAtAllNode', 'no.composer.json.at.all');

    $exitCode = $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])->run();

    expect($exitCode)->toBe(1);
});

it('reads a PSR-4 mapping declared under autoload-dev, not just autoload (mutation survivor 2)', function () {
    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload-dev' => ['psr-4' => ['App\\' => 'app/']],
    ]));

    $class = writeAppNode($this->root, 'AutoloadDevNode', 'autoload.dev.node');

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);
});

it('reads the ARRAY form of a single PSR-4 mapping (mutation survivor 3)', function () {
    // Composer allows one namespace prefix to map to SEVERAL directories:
    // "App\\": ["app/", "app2/"]. Both must be accepted as candidate roots.
    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => ['App\\' => ['app2/', 'app/']]],
    ]));

    $class = writeAppNode($this->root, 'ArrayFormPsr4Node', 'array.form.psr4.node');

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);
});

it('drops a PSR-4 entry pointing at a directory that does not exist on disk, rather than crashing (mutation survivor 4)', function () {
    // A directory declared in composer.json but never created must never
    // become a G5 scan root: NodeReferenceScanner::scan() calls
    // HostPath::root() on every root it is given, which throws
    // InvalidArgumentException for a non-existent path -- and gate5() only
    // catches RuntimeException, so an unfiltered entry here would crash the
    // command instead of refusing (or succeeding) cleanly.
    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => [
            'App\\' => 'app/',
            'Missing\\' => 'does-not-exist/',
        ]],
    ]));

    $class = writeAppNode($this->root, 'MissingMappedDirNode', 'missing.mapped.dir.node');

    $exitCode = $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])->run();

    expect($exitCode)->toBe(0);
});

it('does not treat an ancestor directory literally named "vendor", ABOVE the host root, as containment (mutation survivor 5)', function () {
    // underVendorAtAnyDepth() only inspects the path SEGMENTS strictly
    // BELOW the host root (array_slice($fileSegments, count($rootSegments))).
    // Without that slice, a host root whose own ANCESTOR happens to be
    // named "vendor" would false-positive on every single node it has.
    $ancestor = sys_get_temp_dir().'/vendor';

    if (! is_dir($ancestor)) {
        mkdir($ancestor, 0777, true);
    }

    $ancestor = realpath($ancestor);

    $hostRoot = $ancestor.'/nodeflow-extract-node-'.bin2hex(random_bytes(6));
    mkdir($hostRoot.'/app', 0777, true);

    file_put_contents($hostRoot.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));

    $this->app->setBasePath($hostRoot);

    $class = writeAppNode($hostRoot, 'AncestorVendorNode', 'ancestor.vendor.node');

    try {
        $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
            ->assertExitCode(0);
    } finally {
        deleteTree($hostRoot);
        $this->app->setBasePath($this->root);
    }
});

it('does not report the same survivor twice when a named scan root and a PSR-4 root are the same directory (mutation survivor 6)', function () {
    // The default fixture maps App\ to app/, which is ALSO one of the seven
    // NAMED scan directories -- without array_unique() on gate5()'s merged
    // roots list, app/ would be scanned TWICE, and every unexempted
    // reference inside it would be reported twice over.
    $class = writeAppNode($this->root, 'DuplicateRootNode', 'duplicate.root.node');

    // Deliberately no `use` import here -- one bare, fully-qualified
    // reference is exactly ONE NodeReference, so this test's count is not
    // confounded by a SEPARATE, legitimate second reference (an import
    // plus a later short-name usage would be two REAL references in the
    // same file, muddying what this test is actually isolating).
    file_put_contents($this->root.'/app/UsesIt.php', <<<'PHP'
    <?php

    namespace App;

    class UsesIt
    {
        public function make(): void
        {
            new \App\Nodeflow\Nodes\DuplicateRootNode();
        }
    }
    PHP);

    // Bypassing $this->artisan() deliberately: its own testing helper mocks
    // the console's BufferedOutput (so expectsOutputToContain() can make its
    // own assertions on individual writes), which means the buffer
    // Artisan::output() would normally read is never actually populated.
    // Artisan::call() runs the command through a REAL buffer instead, which
    // is what this test needs to count occurrences rather than merely
    // check presence.
    $exitCode = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ]);
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->toBe(1);
    expect(substr_count($output, 'UsesIt.php'))->toBe(1);
});

it('drops an empty-string PSR-4 mapping value (mutation survivor 7)', function () {
    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => ['App\\' => '', 'Real\\' => 'app/']],
    ]));

    $class = writeAppNode($this->root, 'EmptyStringPsr4Node', 'empty.string.psr4.node');

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);
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

it('refuses a node whose file also declares a top-level BY-REF function, naming it (C1)', function () {
    // Critical 1. On PHP 8.1+, the '&' in `function &foo()` is NOT the bare
    // string token nextFunctionName()'s old code checked for -- token_get_all()
    // emits T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, an ARRAY token, since the
    // '&' is followed by an identifier rather than a $variable or '...'. The old
    // check `! is_array($token) && $token === '&'` can never match that array
    // form, so a by-ref top-level function was read as having no name at all and
    // silently passed G2. Counterfactual: change nextFunctionName()'s skip list
    // back to the bare-string check and this test fails (exit 0, and the
    // function's own name never appears in the refusal at all because the loop
    // returns null for it instead of the function's real name).
    $directory = $this->root.'/app/Nodeflow/Nodes';
    mkdir($directory, 0777, true);
    $path = $directory.'/ByRefCompanionNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    function &byRefCompanion(array &$a): array
    {
        return $a;
    }

    class ByRefCompanionNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'byref.companion.node';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('ByRefCompanionNode')->outputs(['default']);
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
        'class' => 'App\Nodeflow\Nodes\ByRefCompanionNode',
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('byRefCompanion')
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

it('refuses a node inside a BRACED namespace block that also declares a companion trait, naming it (C3)', function () {
    // Critical 3. `namespace App\Nodeflow\Nodes { ... }` (the braced form) is
    // valid PHP, and everything it contains sits at brace depth 1 under a
    // NAIVE depth counter -- so a plain '{'/'}' counter never sees the class
    // OR the trait at "depth 0" and the whole companion check finds nothing.
    // findCompanionSymbol() must skip the brace that OPENS a namespace
    // statement specifically (the same distinction PhpNameResolver and
    // NodeReferenceScanner already make for their own, different reasons),
    // not merely brace-count everything uniformly. Counterfactual: revert
    // findCompanionSymbol() to a bare $depth counter (no brace-KIND stack) and
    // this test fails at exit 0, with the trait moving silently.
    $directory = $this->root.'/app/Nodeflow/Nodes';
    mkdir($directory, 0777, true);
    $path = $directory.'/BracedNode.php';

    file_put_contents($path, <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes {

        use Nodeflow\Execution\NodeResult;
        use Nodeflow\Execution\SubjectContext;
        use Nodeflow\Nodes\HandlesSubject;
        use Nodeflow\Nodes\Node;
        use Nodeflow\Schema\NodeDefinition;

        trait BracedCompanionTrait
        {
            public function help(): void
            {
            }
        }

        class BracedNode extends Node implements HandlesSubject
        {
            use BracedCompanionTrait;

            public static function type(): string
            {
                return 'braced.node';
            }

            public function definition(): NodeDefinition
            {
                return NodeDefinition::make('BracedNode')->outputs(['default']);
            }

            public function forSubject(SubjectContext $context): NodeResult
            {
                return $context->continue('default');
            }
        }
    }
    PHP);
    require $path;

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', [
        'class' => 'App\Nodeflow\Nodes\BracedNode',
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('BracedCompanionTrait')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
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

it('refuses a same-named import sitting in a SIBLING provider file, not just the host NodeflowServiceProvider (Minor 1)', function () {
    // providerSpans() scans app/Providers/ as a whole (NodeReferenceScanner::
    // scan() only accepts a directory), so a sibling file living right next
    // to the real provider -- an AppServiceProvider, say -- is scanned too.
    // Its own `use` import of the class being extracted must NOT be folded
    // into the exemption set: AppServiceProvider.php is not one of the files
    // Task 9 rewrites, so a reference living there is a genuine survivor, and
    // exempting it here would silently certify a rewrite that never happens
    // to it. Counterfactual: drop the canonical same-file filter from
    // providerSpans() (fold every reference found anywhere in the directory
    // into the exemption set) and this test fails at exit 0.
    $class = writeAppNode($this->root, 'MinorOneNode', 'minor.one.node');

    $providerDirectory = $this->root.'/app/Providers';
    mkdir($providerDirectory, 0777, true);

    file_put_contents($providerDirectory.'/NodeflowServiceProvider.php', <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Nodeflow\Nodes\MinorOneNode;

    class NodeflowServiceProvider
    {
        protected array $nodes = [
            MinorOneNode::class,
        ];

        public function boot(): void
        {
        }
    }
    PHP);

    file_put_contents($providerDirectory.'/AppServiceProvider.php', <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Nodeflow\Nodes\MinorOneNode;

    class AppServiceProvider
    {
    }
    PHP);

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('AppServiceProvider.php')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses (safe, over-refusal) rather than mis-locate the $nodes array when the anchor text appears more than once in the provider file (Minor 2)', function () {
    // NodeRegistrationWriter::findClassEntrySpans() guards against the SAME
    // ambiguity appendTo()/removeFrom() already guard against: a SECOND,
    // real occurrence of the anchor text 'protected array $nodes = [' --
    // here, a second class left in the same file -- makes WHICH array a raw
    // strpos() would find genuinely ambiguous. This is the fixture that
    // actually discriminates the guard: a string-literal repro (e.g. `const
    // HINT = 'protected array $nodes = [';`) turned out NOT to, because
    // arraySpan()'s own token-alignment check (openIndex must land on a REAL
    // '[' token) already rejects an anchor position landing inside a string
    // token regardless of this guard -- confirmed by executing that fixture
    // against a build with the guard removed: it still refused safely, for a
    // DIFFERENT reason. Two REAL property declarations don't have that
    // accidental protection: raw strpos() finds the FIRST one, which parses
    // as a perfectly valid (if wrong) array either way. Counterfactual:
    // delete the substr_count($contents, $anchor) !== 1 guard from
    // findClassEntrySpans() and this test fails at exit 0 -- the FIRST
    // class's $nodes entry is silently exempted, its own ambiguity with the
    // second occurrence never detected.
    //
    // ORDERING IN THIS FIXTURE IS LOAD-BEARING -- do not "tidy" it (e.g. by
    // alphabetising the two classes, which would put LeftoverDuplicateProvider
    // first). Raw strpos() always finds the FIRST occurrence of the anchor
    // text. Putting the DUPLICATE (empty-array) class first would refuse
    // either way, with or without the guard: without it, strpos() finds the
    // empty array, parses it successfully, and correctly finds zero matching
    // elements in it -- the exact same [] result the guard itself would
    // produce, so that ordering cannot tell the two apart. Only with the
    // REAL, non-empty class's own array occurring FIRST does removing the
    // guard actually change the observable outcome (it silently succeeds by
    // accident instead of refusing on principle), which is why the real
    // class must stay first for this test to mean anything.
    $class = writeAppNode($this->root, 'MinorTwoNode', 'minor.two.node');

    $providerDirectory = $this->root.'/app/Providers';
    mkdir($providerDirectory, 0777, true);

    // NodeflowServiceProvider (the REAL, non-empty $nodes array) MUST come
    // FIRST in this file -- see the ordering note above.
    file_put_contents($providerDirectory.'/NodeflowServiceProvider.php', <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Nodeflow\Nodes\MinorTwoNode;

    class NodeflowServiceProvider
    {
        protected array $nodes = [
            MinorTwoNode::class,
        ];

        public function boot(): void
        {
        }
    }

    class LeftoverDuplicateProvider
    {
        protected array $nodes = [
        ];
    }
    PHP);

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('NodeflowServiceProvider.php')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
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

it('does not claim a same-short-name test file that tests a DIFFERENT class (Important 3)', function () {
    // rewritableSpans() locates a candidate test file by SHORT CLASS NAME
    // alone (the only convention MakeNodeCommand::writeTest() gives it to go
    // by) -- but two classes in different namespaces can share a short name,
    // and both would collide on the exact same conventional test path. If the
    // candidate were trusted unconditionally, extracting App\Nodeflow\Nodes\
    // CollideNode would claim tests/Feature/Nodeflow/CollideNodeTest.php even
    // though that file actually tests the UNRELATED App\Other\CollideNode --
    // handing Task 9's moves a file to move that does not belong to the class
    // being extracted at all. Counterfactual: drop the
    // fileReferencesClass() check from rewritableSpans() (trust the
    // conventional path unconditionally) and this test's assertion fails --
    // the wrong-owner test file is included as a span regardless of what it
    // actually contains.
    $target = writeAppNode($this->root, 'CollideNode', 'collide.target');

    $decoyDirectory = $this->root.'/app/Other';
    mkdir($decoyDirectory, 0777, true);
    file_put_contents($decoyDirectory.'/CollideNode.php', <<<'PHP'
    <?php

    namespace App\Other;

    class CollideNode
    {
    }
    PHP);
    require $decoyDirectory.'/CollideNode.php';

    $testDirectory = $this->root.'/tests/Feature/Nodeflow';
    mkdir($testDirectory, 0777, true);
    $testFile = $testDirectory.'/CollideNodeTest.php';

    file_put_contents($testFile, <<<'PHP'
    <?php

    namespace Tests\Feature\Nodeflow;

    use App\Other\CollideNode;

    class CollideNodeTest
    {
        public function it_exercises_the_OTHER_collide_node(): void
        {
            new CollideNode();
        }
    }
    PHP);

    $command = app(\Nodeflow\Console\ExtractNodeCommand::class);
    $spans = $command->rewritableSpans($target, $this->root);

    $canonicalTestFile = realpath($testFile);
    $matching = array_filter($spans, fn ($span) => (realpath($span->file) ?: $span->file) === $canonicalTestFile);

    expect($matching)->toBeEmpty();
});

it('does not claim the conventional test file merely because a SIBLING file in the same directory references the target (Important B)', function () {
    // fileReferencesClass()'s canonical same-file filter -- Finding 6's own
    // filter, re-entering through this new helper -- was untested. Its
    // sibling copy inside providerSpans() (Minor 1, Finding 9) already had
    // a discriminating test; this one did not, even though the exact same
    // hazard applies: NodeReferenceScanner::scan() is handed the candidate
    // FILE'S OWN DIRECTORY (it only accepts a directory), so every sibling
    // living alongside the conventional {Short}Test.php is scanned too.
    // Replacing the canonical filter with `return $references !== []`
    // leaves the suite green UNTIL a sibling in that same directory
    // references the target while the conventional candidate itself does
    // not -- exactly this fixture. Counterfactual: make that replacement
    // and the wrong-owner span count goes from 0 to 1.
    $target = writeAppNode($this->root, 'SiblingRefNode', 'sibling.ref.node');

    $testDirectory = $this->root.'/tests/Feature/Nodeflow';
    mkdir($testDirectory, 0777, true);

    // The CONVENTIONAL candidate path -- deliberately references nothing.
    $conventionalTestFile = $testDirectory.'/SiblingRefNodeTest.php';
    file_put_contents($conventionalTestFile, <<<'PHP'
    <?php

    namespace Tests\Feature\Nodeflow;

    class SiblingRefNodeTest
    {
        public function it_asserts_nothing_about_the_node_at_all(): void
        {
        }
    }
    PHP);

    // A SIBLING file, in the SAME directory, that DOES reference the target.
    file_put_contents($testDirectory.'/UnrelatedOtherTest.php', <<<'PHP'
    <?php

    namespace Tests\Feature\Nodeflow;

    use App\Nodeflow\Nodes\SiblingRefNode;

    class UnrelatedOtherTest
    {
        public function it_uses_the_node_for_something_else_entirely(): void
        {
            new SiblingRefNode();
        }
    }
    PHP);

    $command = app(\Nodeflow\Console\ExtractNodeCommand::class);
    $spans = $command->rewritableSpans($target, $this->root);

    $canonicalConventionalFile = realpath($conventionalTestFile);
    $matching = array_filter(
        $spans,
        fn ($span) => (realpath($span->file) ?: $span->file) === $canonicalConventionalFile,
    );

    expect($matching)->toBeEmpty();
});

it('refuses when the $nodes array also carries an element the writer cannot classify, rather than exempting the whole array (Important 2)', function () {
    // rewritableSpans() now reuses NodeRegistrationWriter::findClassEntrySpans(),
    // which returns [] whenever ANY element in the array is not a plain
    // `<name>::class` -- exactly mirroring removeFrom()'s own EntryUnsupported
    // refusal. A nested array literal sitting alongside the real entry means
    // the LATER move can never safely touch this array at all, so G5 must NOT
    // exempt the real entry either: exempting it here while removeFrom()
    // refuses to touch it later is precisely the "gate and moves disagree"
    // defect this method exists to prevent.
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

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('NodeflowServiceProvider.php')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
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

it('refuses a node whose FQCN appears only in bootstrap/app.php, Laravel 11\'s own registration site (G5, Important 1)', function () {
    // Without bootstrap/ in the scanned roots, a reference sitting in exactly
    // the file Laravel 11 itself uses to register providers/bindings would go
    // completely undetected -- the widened-roots probe (E46) only proved
    // config/ was reached, not this file specifically.
    $class = writeAppNode($this->root, 'BootstrapOnlyNode', 'bootstrap.only');

    mkdir($this->root.'/bootstrap', 0777, true);
    file_put_contents($this->root.'/bootstrap/app.php', <<<'PHP'
    <?php

    return [
        'node' => 'App\Nodeflow\Nodes\BootstrapOnlyNode',
    ];
    PHP);

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('bootstrap/app.php')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses a node whose FQCN appears only in tests/Unit/, proving the test suite itself is scanned (G5, Important 1)', function () {
    // Symmetric with rewritableSpans() exempting the conventional TEST FILE
    // (tests/Feature/Nodeflow/{Class}Test.php): if G5 never scans tests/ at
    // all, a reference sitting in some OTHER test file under tests/Unit/ --
    // one Task 9 will not move -- would silently survive undetected.
    $class = writeAppNode($this->root, 'TestsOnlyNode', 'tests.only');

    mkdir($this->root.'/tests/Unit', 0777, true);
    file_put_contents($this->root.'/tests/Unit/SomeOtherTest.php', <<<'PHP'
    <?php

    namespace Tests\Unit;

    use App\Nodeflow\Nodes\TestsOnlyNode;

    class SomeOtherTest
    {
        public function it_uses_the_node(): void
        {
            new TestsOnlyNode();
        }
    }
    PHP);

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('SomeOtherTest.php')
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
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
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
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
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
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
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
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
        'require' => ['atram/laravel-nodeflow' => '^2.0', 'acme/widgets' => '^1.0'],
        'repositories' => [
            ['type' => 'path', 'url' => 'packages/*/*'],
        ],
    ]));

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);
});

it('refuses when a single-segment glob does not cross a "/" to cover a nested target (C2)', function () {
    // Critical 2. "packages/*" is Composer's own idiomatic monorepo form and
    // covers exactly one path SEGMENT under packages/ (e.g. packages/foo) --
    // it does NOT cover packages/acme/widgets, a TWO-segment target, the same
    // way Composer's own path repository resolution would not treat it as a
    // match. The old code used a bare fnmatch() with no FNM_PATHNAME flag,
    // under which '*' crosses '/' freely and "packages/*" wrongly matches ANY
    // path nested arbitrarily deep under packages/ -- a segment-wise glob
    // doing a "starts with this prefix" job, the substring-shaped mistake
    // this codebase's own HostPath docblock names as its most recent
    // recurrence. Counterfactual: drop FNM_PATHNAME from the fnmatch() call
    // and this test fails (exit 0) because "packages/*" wrongly matches
    // "packages/acme/widgets".
    $class = writeAppNode($this->root, 'GateSixGlobCrossSlashNode', 'gate6.globcrossslash');

    file_put_contents($this->root.'/composer.json', json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
        'require' => ['atram/laravel-nodeflow' => '^2.0', 'acme/widgets' => '^1.0'],
        'repositories' => [
            ['type' => 'path', 'url' => 'packages/*'],
        ],
    ]));

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('acme/widgets')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses when extra.laravel.dont-discover covers the new package with a "*" entry (G6, adversarial probe 3)', function () {
    $class = writeAppNode($this->root, 'GateSixStarNode', 'gate6.star');

    file_put_contents($this->root.'/composer.json', json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
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
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
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
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
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
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'extra' => ['laravel' => ['dont-discover' => ['someone/else']]],
    ]));

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->assertExitCode(0);
});

it('refuses when the host composer.json does not parse as JSON, now via G2 rather than G6 (Important A ripple)', function () {
    // This refusal moved gates. Before Important A widened G2 to require
    // the node's own file sit under a host PSR-4 root, an unparseable
    // composer.json was only ever caught by G6's OWN existence/parse check
    // below. Now G2's hostPsr4Directories() reads and decodes the SAME file
    // FIRST, gets [] (an unparseable file maps nothing), and refuses before
    // G6 ever runs -- so G6's own "does not parse as JSON" message
    // (deliberately left in place; see gate6()'s own docblock for why) is
    // provably unreachable through any valid call path today. This test
    // asserts what ACTUALLY happens now -- refusal via G2's message, not
    // G6's -- rather than keeping a stale assertion that would silently
    // start failing for the wrong reason.
    $class = writeAppNode($this->root, 'GateSixBadJsonNode', 'gate6.badjson');

    file_put_contents($this->root.'/composer.json', '{not valid json');

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', ['class' => $class, '--package' => 'acme/widgets'])
        ->expectsOutputToContain('composer.json maps via autoload or autoload-dev PSR-4')
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

    // --force overrides the foreign occupant and lets extraction actually
    // run (Task 9) -- the host tree is NOT byte-identical afterwards: the
    // foreign composer.json is gone, replaced by the scaffolded package's
    // own, and the original class file has moved. See
    // ExtractNodeMovesTest.php for the full "foreign directory under
    // --force" journal/restore coverage this one line of this file is not
    // meant to duplicate.
    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
        '--force' => true,
    ])->assertExitCode(0);

    $decoded = json_decode(file_get_contents($this->root.'/packages/acme/widgets/composer.json'), true);
    expect($decoded['name'])->toBe('acme/widgets');
    expect($this->root.'/app/Nodeflow/Nodes/GateSevenNode.php')->not->toBeFile();
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

it('refuses a --package that is not a valid Composer name, before ever reaching G6 or G7 (Important 4)', function () {
    // Without this check, an invalid --package (bad characters, no vendor/name
    // separator, uppercase) flowed through all eight gates and reported
    // success -- G6/G7 only ever compare the string as given, they never
    // validate its SHAPE.
    $class = writeAppNode($this->root, 'InvalidPackageNameNode', 'invalid.package.name');

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'Not A Valid Name!!',
    ])
        ->expectsOutputToContain('not a valid Composer package name')
        ->assertFailed();

    expect(hostTreeHash($this->root))->toBe($before);
});

it('refuses a --package with no vendor/name separator', function () {
    $class = writeAppNode($this->root, 'NoSuffixPackageNode', 'no.suffix.package');

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'nosuffix',
    ])
        ->expectsOutputToContain('not a valid Composer package name')
        ->assertFailed();
});

it('refuses an uppercase --package, closing a real G6 case-sensitivity bypass (Important 4)', function () {
    // The exact reported repro: dont-discover: ["acme/widgets"] with
    // --package=ACME/Widgets used to PASS G6, because that check compares
    // with an exact `===` and "ACME/Widgets" !== "acme/widgets" byte-for-byte
    // -- an uppercase spelling of the very same package silently defeated
    // E49's own refusal. Composer's own package name pattern is
    // lowercase-only, so validating --package against it BEFORE G6 ever runs
    // closes this as a side effect of a single, reused check rather than a
    // second, bespoke case-folding rule bolted onto G6 alone.
    $class = writeAppNode($this->root, 'UppercasePackageBypassNode', 'uppercase.package.bypass');

    file_put_contents($this->root.'/composer.json', json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'extra' => ['laravel' => ['dont-discover' => ['acme/widgets']]],
    ]));

    $before = hostTreeHash($this->root);

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'ACME/Widgets',
    ])
        ->expectsOutputToContain('not a valid Composer package name')
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
