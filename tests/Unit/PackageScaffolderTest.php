<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\NodeRegistrationWriter;
use Nodeflow\Console\PackageScaffolder;
use Nodeflow\Console\PackageTarget;

function makePackageTarget(string $absolutePath, array $overrides = []): PackageTarget
{
    return new PackageTarget(
        composerName: $overrides['composerName'] ?? 'acme/widgets',
        namespace: $overrides['namespace'] ?? 'Acme\\Widgets',
        absolutePath: $absolutePath,
        relativePath: $overrides['relativePath'] ?? 'packages/acme/widgets',
        providerClass: $overrides['providerClass'] ?? 'Acme\\Widgets\\WidgetsServiceProvider',
        nodeflowConstraint: $overrides['nodeflowConstraint'] ?? '^2.0',
        withJs: $overrides['withJs'] ?? false,
    );
}

function deleteRecursively(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;
        is_dir($path) && ! is_link($path) ? deleteRecursively($path) : unlink($path);
    }

    rmdir($dir);
}

beforeEach(function () {
    $this->basePath = sys_get_temp_dir().'/nodeflow-pkgscaffold-'.bin2hex(random_bytes(6));
    mkdir($this->basePath, 0777, true);

    // Canonicalise once, up front, for the same reason HostPathTest does:
    // HostPath::root() resolves symlinks, and macOS aliases /var to
    // /private/var, which would otherwise make every assertion below
    // diverge from the scaffolder's own resolved paths for a reason that has
    // nothing to do with the behaviour under test.
    $this->basePath = realpath($this->basePath);
    $this->packageRoot = $this->basePath.'/packages/acme/widgets';

    $this->scaffolder = new PackageScaffolder(new Filesystem, $this->basePath);
});

afterEach(function () {
    deleteRecursively($this->basePath);
});

it('emits a package whose composer.json is valid JSON with the expected name and providers', function () {
    $this->scaffolder->scaffold(makePackageTarget($this->packageRoot));

    $decoded = json_decode(file_get_contents($this->packageRoot.'/composer.json'), true);

    expect($decoded)->toBeArray();
    expect($decoded['name'])->toBe('acme/widgets');
    expect($decoded['extra']['laravel']['providers'])->toBe(['Acme\\Widgets\\WidgetsServiceProvider']);

    // Structural, not substring (global constraint): the PSR-4 key itself,
    // not merely that the text "Acme\Widgets" appears somewhere in the file.
    expect($decoded['autoload']['psr-4'])->toBe(['Acme\\Widgets\\' => 'src/']);
    expect($decoded['autoload-dev']['psr-4'])->toBe(['Tests\\' => 'tests/']);
});

it('emits a provider carrying the writer\'s anchor exactly once', function () {
    // The same gate ProviderStepTest uses, and for the same reason: a
    // drifted stub ships a provider no generator can write into, and
    // nothing else would say so.
    $this->scaffolder->scaffold(makePackageTarget($this->packageRoot));

    $contents = file_get_contents($this->packageRoot.'/src/WidgetsServiceProvider.php');

    expect(substr_count($contents, NodeRegistrationWriter::ANCHOR))->toBe(1);
});

it('emits a provider that parses', function () {
    $this->scaffolder->scaffold(makePackageTarget($this->packageRoot));

    expectParseablePhp($this->packageRoot.'/src/WidgetsServiceProvider.php');
});

it('mirrors the host\'s nodeflow constraint', function () {
    $devRoot = $this->basePath.'/packages/acme/dev-widgets';
    $this->scaffolder->scaffold(makePackageTarget($devRoot, ['nodeflowConstraint' => '@dev']));
    $devComposer = json_decode(file_get_contents($devRoot.'/composer.json'), true);
    expect($devComposer['require']['atram/laravel-nodeflow'])->toBe('@dev');

    $pinnedRoot = $this->basePath.'/packages/acme/pinned-widgets';
    $this->scaffolder->scaffold(makePackageTarget($pinnedRoot, ['nodeflowConstraint' => '^2.0']));
    $pinnedComposer = json_decode(file_get_contents($pinnedRoot.'/composer.json'), true);
    expect($pinnedComposer['require']['atram/laravel-nodeflow'])->toBe('^2.0');
});

it('omits resources/js unless withJs', function () {
    $this->scaffolder->scaffold(makePackageTarget($this->packageRoot, ['withJs' => false]));

    expect(file_exists($this->packageRoot.'/resources/js/index.ts'))->toBeFalse();

    $jsRoot = $this->basePath.'/packages/acme/js-widgets';
    $this->scaffolder->scaffold(makePackageTarget($jsRoot, ['withJs' => true]));

    expect(file_exists($jsRoot.'/resources/js/index.ts'))->toBeTrue();
});

it('emits package.json and tsconfig.json alongside index.ts under --js', function () {
    $this->scaffolder->scaffold(makePackageTarget($this->packageRoot, ['withJs' => true]));

    expect(file_exists($this->packageRoot.'/package.json'))->toBeTrue();
    expect(file_exists($this->packageRoot.'/tsconfig.json'))->toBeTrue();
    expect(file_exists($this->packageRoot.'/resources/js/index.ts'))->toBeTrue();

    // Each JSON file is itself structurally valid, not merely present.
    $packageJson = json_decode(file_get_contents($this->packageRoot.'/package.json'), true);
    expect($packageJson)->toBeArray();
    expect($packageJson['name'])->toBe('acme/widgets');

    expect(json_decode(file_get_contents($this->packageRoot.'/tsconfig.json'), true))->toBeArray();

    // index.ts has no substitution logic to verify structurally, but a
    // dropped placeholder would otherwise leave literal "{{ name }}" text
    // behind rather than failing loudly — checked here rather than only by
    // existence.
    expect(file_get_contents($this->packageRoot.'/resources/js/index.ts'))
        ->not->toContain('{{')
        ->toContain('acme/widgets');
});

it('carries no triggers or subjectAttributes anchor', function () {
    $this->scaffolder->scaffold(makePackageTarget($this->packageRoot));

    $contents = file_get_contents($this->packageRoot.'/src/WidgetsServiceProvider.php');

    expect($contents)->not->toContain(NodeRegistrationWriter::TRIGGER_ANCHOR);
    expect($contents)->not->toContain(NodeRegistrationWriter::ATTRIBUTE_ANCHOR);
});

it('prefers a host stub override', function () {
    mkdir($this->basePath.'/stubs/package', 0777, true);
    file_put_contents($this->basePath.'/stubs/package/provider.stub', <<<'PHP'
    <?php

    namespace {{ namespace }};

    use Illuminate\Support\ServiceProvider;

    // HOST-OVERRIDE-MARKER
    class {{ shortClass }} extends ServiceProvider
    {
        protected array $nodes = [
        ];

        public function boot(): void
        {
            \Nodeflow\Nodeflow::register($this->nodes);
        }
    }
    PHP);

    $this->scaffolder->scaffold(makePackageTarget($this->packageRoot));

    $contents = file_get_contents($this->packageRoot.'/src/WidgetsServiceProvider.php');

    expect($contents)->toContain('HOST-OVERRIDE-MARKER');
});

it('emits a README with every placeholder substituted and no claim that make-node runs inside the package', function () {
    $this->scaffolder->scaffold(makePackageTarget($this->packageRoot));

    $readme = file_get_contents($this->packageRoot.'/README.md');

    expect($readme)->not->toContain('{{');
    expect($readme)->toContain('acme/widgets');
    expect($readme)->toContain('Acme\\Widgets\\WidgetsServiceProvider');

    // Requirement 5: an earlier design draft claimed make-node could run
    // inside a scaffolded package; that is false, and the README must not
    // repeat the claim.
    expect($readme)->not->toContain('You can run');
    expect($readme)->toContain('You cannot run `php artisan nodeflow:make-node` inside this package');
});

it('creates an empty src/Nodes directory alongside the provider', function () {
    $this->scaffolder->scaffold(makePackageTarget($this->packageRoot));

    expect(is_dir($this->packageRoot.'/src/Nodes'))->toBeTrue();
});

it('emits the package\'s own example test, which parses', function () {
    $this->scaffolder->scaffold(makePackageTarget($this->packageRoot));

    expect(file_exists($this->packageRoot.'/tests/ExampleTest.php'))->toBeTrue();

    expectParseablePhp($this->packageRoot.'/tests/ExampleTest.php');

    // Both placeholders land inside a string literal (the it() description
    // and the class-reference respectively), so a dropped substitution
    // would NOT fail to parse — only a content check catches it.
    $contents = file_get_contents($this->packageRoot.'/tests/ExampleTest.php');
    expect($contents)->not->toContain('{{');
    expect($contents)->toContain('WidgetsServiceProvider');
});

it('does not double the trailing namespace separator when the namespace already ends in one', function () {
    // Counterfactual: drop the rtrim() in renderComposerJson() and this
    // fails — the PSR-4 key ends up "Acme\\Widgets\\\\" (a literal double
    // backslash before "src/"), which is not the key Composer's own
    // autoloader expects.
    $this->scaffolder->scaffold(makePackageTarget($this->packageRoot, [
        'namespace' => 'Acme\\Widgets\\',
    ]));

    $decoded = json_decode(file_get_contents($this->packageRoot.'/composer.json'), true);

    expect($decoded['autoload']['psr-4'])->toBe(['Acme\\Widgets\\' => 'src/']);
});

it('refuses a provider short class name that would otherwise escape the package root, and writes nothing', function () {
    // A provider class whose short name is not a valid PHP identifier can
    // never be written: it fails to parse as a class declaration before
    // this class's own path-arithmetic is ever consulted, which is why a
    // literal '/'-embedding escape attempt is refused here rather than by
    // HostPath's own guard.
    $target = makePackageTarget($this->packageRoot, [
        'providerClass' => 'Acme\\Widgets\\Evil/../../Escaped',
    ]);

    expect(fn () => $this->scaffolder->scaffold($target))->toThrow(RuntimeException::class);

    expect(file_exists($this->packageRoot))->toBeFalse();
    expect(file_exists(dirname($this->packageRoot, 2).'/Escaped.php'))->toBeFalse();
});

it('throws when a rendered stub does not parse, and leaves nothing behind', function () {
    // E52, F-2: this guard needs a persisted test, not merely a proved claim.
    mkdir($this->basePath.'/stubs/package', 0777, true);
    file_put_contents($this->basePath.'/stubs/package/provider.stub', <<<'PHP'
    <?php

    namespace {{ namespace }};

    class {{ shortClass }} extends {{{{
    PHP);

    expect(fn () => $this->scaffolder->scaffold(makePackageTarget($this->packageRoot)))
        ->toThrow(RuntimeException::class);

    // Nothing was written at all — not the package directory, not any
    // sibling file that rendered cleanly before the broken one was checked.
    expect(file_exists($this->packageRoot))->toBeFalse();
});
