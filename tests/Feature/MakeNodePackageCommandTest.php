<?php

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-make-node-package-'.bin2hex(random_bytes(6));

    mkdir($this->root, 0777, true);

    // Canonicalise once, up front, for the same reason HostPathTest and
    // PackageScaffolderTest do: HostPath::root() resolves symlinks, and macOS
    // aliases /var to /private/var, which would otherwise make an assertion
    // about the resolved absolute path diverge from the command's own for a
    // reason that has nothing to do with the behaviour under test.
    $this->root = realpath($this->root);

    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
    ]));

    $this->app->setBasePath($this->root);
});

afterEach(function () {
    $delete = function (string $dir) use (&$delete) {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $delete($path) : unlink($path);
        }

        rmdir($dir);
    };

    $delete($this->root);
    $delete($this->root.'-outside');
});

it('scaffolds into packages/vendor/name by default', function () {
    $this->artisan('nodeflow:make-node-package', ['name' => 'acme/widgets'])->assertExitCode(0);

    expect($this->root.'/packages/acme/widgets/composer.json')->toBeFile();
    expect($this->root.'/packages/acme/widgets/src/WidgetsServiceProvider.php')->toBeFile();

    $decoded = json_decode(file_get_contents($this->root.'/packages/acme/widgets/composer.json'), true);
    expect($decoded['name'])->toBe('acme/widgets');
});

it('StudlyCases a hyphenated, multi-word package name into the default namespace', function () {
    // Every other test in this file uses a lowercase single-word vendor and
    // package, so `Str::studly($vendor).'\\'.Str::studly($package)` and the
    // naive `$vendor.'\\'.$package` happen to produce identical output for
    // all of them. Counterfactual (verified): drop Str::studly() from the
    // default-namespace derivation and this input is refused outright — a
    // bare "my-cool-nodes" segment contains a hyphen, which is not a legal
    // PHP identifier character — instead of correctly StudlyCasing to
    // "MyCoolNodes".
    $this->artisan('nodeflow:make-node-package', ['name' => 'acme/my-cool-nodes'])
        ->assertExitCode(0);

    expect($this->root.'/packages/acme/my-cool-nodes/src/MyCoolNodesServiceProvider.php')->toBeFile();

    $provider = file_get_contents($this->root.'/packages/acme/my-cool-nodes/src/MyCoolNodesServiceProvider.php');
    expect($provider)->toContain('namespace Acme\\MyCoolNodes;');
    expect($provider)->toContain('class MyCoolNodesServiceProvider');

    $composer = json_decode(file_get_contents($this->root.'/packages/acme/my-cool-nodes/composer.json'), true);
    expect($composer['autoload']['psr-4'])->toBe(['Acme\\MyCoolNodes\\' => 'src/']);
    expect($composer['extra']['laravel']['providers'])->toBe(['Acme\\MyCoolNodes\\MyCoolNodesServiceProvider']);
});

it('refuses an invalid Composer name, naming the pattern', function () {
    // A single expectsOutputToContain() call here, deliberately: the whole
    // refusal is emitted as one console line, and two substring checks
    // against the very same line contend for the same underlying mocked
    // write — Mockery hands that one write to whichever expectation was
    // registered first and leaves the other permanently unsatisfied, which
    // is a harness quirk rather than anything about the command's output.
    $this->artisan('nodeflow:make-node-package', ['name' => 'Not A Valid Name!'])
        ->expectsOutputToContain('a-z0-9')
        ->assertFailed();

    expect($this->root.'/packages')->not->toBeDirectory();
});

it('refuses a Composer-valid name that is not a valid PHP namespace, and writes nothing', function () {
    // E52: 123vendor/456pkg satisfies Composer's own pattern but StudlyCase of
    // either segment still starts with a digit, which is not a legal PHP
    // identifier. Composer's pattern alone would let this through to render
    // `namespace 123Vendor\456Pkg;` — a parse error.
    $this->artisan('nodeflow:make-node-package', ['name' => '123vendor/456pkg'])
        ->expectsOutputToContain('not a valid PHP identifier')
        ->assertFailed();

    expect($this->root.'/packages')->not->toBeDirectory();
});

it('honours --namespace, and the emitted provider declares it', function () {
    // The provider's short class name is derived from the GIVEN namespace's
    // own last segment ('Space'), not from the package name ('widgets'),
    // whenever --namespace overrides the default — see the fix note above
    // resolveTarget()'s $shortClassBase for why: deriving from the package
    // name regardless of an explicit --namespace is exactly what made
    // `2captcha/2captcha-php --namespace=Acme\Captcha` unfixable.
    $this->artisan('nodeflow:make-node-package', [
        'name' => 'acme/widgets',
        '--namespace' => 'Custom\\Space',
    ])->assertExitCode(0);

    $provider = file_get_contents($this->root.'/packages/acme/widgets/src/SpaceServiceProvider.php');

    expect($provider)->toContain('namespace Custom\\Space;');
    expect($provider)->toContain('class SpaceServiceProvider');

    $composer = json_decode(file_get_contents($this->root.'/packages/acme/widgets/composer.json'), true);
    expect($composer['autoload']['psr-4'])->toBe(['Custom\\Space\\' => 'src/']);
    expect($composer['extra']['laravel']['providers'])->toBe(['Custom\\Space\\SpaceServiceProvider']);
});

it('derives the provider short class from the package name when --namespace is absent', function () {
    // The other half of the same fix: with no --namespace override, the
    // short class still comes from the package segment, matching every
    // other test in this file that doesn't pass --namespace.
    $this->artisan('nodeflow:make-node-package', ['name' => 'acme/widgets'])->assertExitCode(0);

    expect($this->root.'/packages/acme/widgets/src/WidgetsServiceProvider.php')->toBeFile();

    $composer = json_decode(file_get_contents($this->root.'/packages/acme/widgets/composer.json'), true);
    expect($composer['extra']['laravel']['providers'])->toBe(['Acme\\Widgets\\WidgetsServiceProvider']);
});

it('lets --namespace rescue a package name that cannot studly-case into a valid PHP identifier', function () {
    // 2captcha/2captcha-php is a real Packagist name. Its package segment
    // studly-cases to "2captchaPhp", an invalid PHP identifier (leading
    // digit) — so deriving the short class from the package name would
    // fail even with a perfectly valid --namespace, and the refusal
    // message's own advice ("pass --namespace") would be advice that
    // provably cannot work. Deriving from --namespace's own last segment
    // instead means this now succeeds.
    $this->artisan('nodeflow:make-node-package', [
        'name' => '2captcha/2captcha-php',
        '--namespace' => 'Acme\\Captcha',
    ])->assertExitCode(0);

    expect($this->root.'/packages/2captcha/2captcha-php/src/CaptchaServiceProvider.php')->toBeFile();

    $composer = json_decode(file_get_contents($this->root.'/packages/2captcha/2captcha-php/composer.json'), true);
    expect($composer['name'])->toBe('2captcha/2captcha-php');
    expect($composer['extra']['laravel']['providers'])->toBe(['Acme\\Captcha\\CaptchaServiceProvider']);
});

it('StudlyCases an underscored --namespace segment before appending ServiceProvider', function () {
    // Str::studly() is applied to $namespace's own last segment on the way
    // to $shortClassBase — not just end(explode(...)) verbatim. Both
    // "MyCoolServiceProvider" (with Str::studly) and "my_coolServiceProvider"
    // (without it — an underscore is a legal, if unconventional, PHP
    // identifier character, so the un-studlied form is ALSO valid PHP) parse
    // fine, so nothing else backstops this: the scaffolder's own pre-write
    // parse check passes either way, and only this test's exact filename and
    // content assertions can tell the two apart.
    $this->artisan('nodeflow:make-node-package', [
        'name' => 'acme/widgets',
        '--namespace' => 'Acme\\my_cool',
    ])->assertExitCode(0);

    expect($this->root.'/packages/acme/widgets/src/MyCoolServiceProvider.php')->toBeFile();
    expect($this->root.'/packages/acme/widgets/src/my_coolServiceProvider.php')->not->toBeFile();

    $provider = file_get_contents($this->root.'/packages/acme/widgets/src/MyCoolServiceProvider.php');
    expect($provider)->toContain('class MyCoolServiceProvider');
});

it('refuses a namespace whose last segment studly-cases into an invalid identifier, even though the namespace itself is valid', function () {
    // The "incomparable, not redundant" case: validating $providerClass (the
    // FULL fully-qualified class, short class included) catches this;
    // validating $namespace alone would not. A leading underscore is a
    // legal first character for a PHP namespace segment, so "_2captcha"
    // passes as $namespace's own last segment on its own — but
    // Str::studly('_2captcha') strips that very underscore, exposing the
    // leading digit and producing "2captchaServiceProvider", an invalid
    // identifier. Str::studly() does not preserve validity in either
    // direction, which is exactly why the two checks are incomparable
    // rather than one subsuming the other (see resolveTarget()'s own
    // comment above assertValidNamespaceSegments($providerClass) for the
    // full writeup, including the converse case).
    $this->artisan('nodeflow:make-node-package', [
        'name' => 'acme/widgets',
        '--namespace' => 'Acme\\_2captcha',
    ])
        ->expectsOutputToContain('[2captchaServiceProvider]')
        ->assertFailed();

    expect($this->root.'/packages')->not->toBeDirectory();
});

it('strips a --namespace value carrying stray leading/trailing backslashes', function () {
    // Counterfactual: drop the trim($namespaceOption, '\\') and the provider
    // renders `namespace \Custom\Space;` — a leading namespace separator is a
    // PHP parse error. PackageScaffolder's own pre-write parse check would
    // catch that and refuse loudly rather than write it, but this command's
    // own trim() is what avoids relying on that fallback at all.
    $this->artisan('nodeflow:make-node-package', [
        'name' => 'acme/widgets',
        '--namespace' => '\\Custom\\Space\\',
    ])->assertExitCode(0);

    $provider = file_get_contents($this->root.'/packages/acme/widgets/src/SpaceServiceProvider.php');

    expect($provider)->toContain('namespace Custom\\Space;');
    expect($provider)->not->toContain('namespace \\Custom');
});

it('refuses an absolute --path rather than silently treating it as relative', function () {
    // HostPath::resolveWithin() deliberately refuses a leading '/' — it is
    // not "a relative path inside the project". Counterfactual: trim a
    // leading '/' off --path before handing it to resolveWithin() (the way
    // an earlier draft of this command did) and this test fails, because an
    // absolute-looking --path would be silently reinterpreted as relative
    // instead of refused.
    $this->artisan('nodeflow:make-node-package', [
        'name' => 'acme/widgets',
        '--path' => '/etc/acme-widgets',
    ])
        ->expectsOutputToContain('must be a relative path')
        ->assertFailed();
});

it('refuses a --path that escapes the host through a symlink, and writes nothing', function () {
    $outside = $this->root.'-outside';
    mkdir($outside, 0777, true);
    mkdir($this->root.'/packages', 0777, true);
    symlink($outside, $this->root.'/packages/escape');

    $this->artisan('nodeflow:make-node-package', [
        'name' => 'acme/widgets',
        '--path' => 'packages/escape/widgets',
    ])
        ->expectsOutputToContain('resolves outside the project root')
        ->assertFailed();

    expect(glob($outside.'/*'))->toBe([]);
});

it('refuses --path=. rather than overwriting the host application itself, even with --force', function () {
    // The most serious finding on this task: targetIsAvailable()'s
    // inference — "this directory's composer.json names this package,
    // therefore this directory IS that package" — is false for the project
    // root. Reproduced exactly as found: a host composer.json that happens
    // to be named after the very package being scaffolded, plus --path=.,
    // used to pass targetIsAvailable() and let the package template
    // overwrite the host's own composer.json (autoload, require, and all),
    // dropping README.md, src/, and tests/ at the project root, at exit 0
    // with no --force needed.
    file_put_contents($this->root.'/composer.json', json_encode([
        'name' => 'acme/widgets',
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));

    $hostComposerBefore = file_get_contents($this->root.'/composer.json');

    $this->artisan('nodeflow:make-node-package', [
        'name' => 'acme/widgets',
        '--path' => '.',
    ])
        ->expectsOutputToContain('resolves to the host application')
        ->assertFailed();

    expect(file_get_contents($this->root.'/composer.json'))->toBe($hostComposerBefore);
    expect($this->root.'/README.md')->not->toBeFile();
    expect($this->root.'/src')->not->toBeDirectory();

    // Independent of --force (the host root is never a legitimate scaffold
    // target, no matter what flag is passed):
    $this->artisan('nodeflow:make-node-package', [
        'name' => 'acme/widgets',
        '--path' => '.',
        '--force' => true,
    ])
        ->expectsOutputToContain('resolves to the host application')
        ->assertFailed();

    expect(file_get_contents($this->root.'/composer.json'))->toBe($hostComposerBefore);
});

it('refuses --path resolving to the host root through an in-host symlink, not just a literal "."', function () {
    // A stricter version of the fix above, found while verifying it: a bare
    // string comparison between $absolutePath and $host->basePath() (even
    // after stripping a trailing slash) misses a --path that lands on an
    // in-host SYMLINK whose own target is the host root — resolveWithin()
    // returns the raw, non-canonicalised "packages/self" form, which never
    // string-equals the canonical root, even though they are the exact same
    // directory on disk. The guard uses HostPath::relativeDepth(), which
    // canonicalises before comparing, specifically to close this.
    file_put_contents($this->root.'/composer.json', json_encode([
        'name' => 'acme/widgets',
        'require' => ['atram/laravel-nodeflow' => '^2.0'],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));
    mkdir($this->root.'/packages', 0777, true);
    symlink($this->root, $this->root.'/packages/self');

    $hostComposerBefore = file_get_contents($this->root.'/composer.json');

    $this->artisan('nodeflow:make-node-package', [
        'name' => 'acme/widgets',
        '--path' => 'packages/self',
    ])
        ->expectsOutputToContain('resolves to the host application')
        ->assertFailed();

    expect(file_get_contents($this->root.'/composer.json'))->toBe($hostComposerBefore);
});

it('maps a RuntimeException from the scaffolder to exit code 1, not 0', function () {
    // Reachable in production through a host stubs/package/provider.stub
    // override that renders unparseable PHP — PackageScaffolder's own
    // pre-write parse check throws RuntimeException in exactly that case
    // (see PackageScaffolderTest's "throws when a rendered stub does not
    // parse" test for the mechanism). handle() must map that to
    // self::FAILURE the same way it maps every InvalidArgumentException
    // refusal, or a broken host stub override would silently exit 0.
    mkdir($this->root.'/stubs/package', 0777, true);
    file_put_contents($this->root.'/stubs/package/provider.stub', <<<'PHP'
    <?php

    namespace {{ namespace }};

    class {{ shortClass }} extends {{{{
    PHP);

    $exitCode = $this->artisan('nodeflow:make-node-package', ['name' => 'acme/widgets'])
        ->expectsOutputToContain('does not produce valid PHP')
        ->run();

    expect($exitCode)->toBe(1);
    expect($this->root.'/packages')->not->toBeDirectory();
});

it('refuses when the default-derived namespace is not a valid PHP identifier, naming it precisely', function () {
    // Validating the FULL provider class (not just the base namespace)
    // matters here: without --namespace, the provider's short class is
    // Str::studly($package).'ServiceProvider', and its validity is what
    // this guard actually exists to check. See the report's mutation-testing
    // section for why an explicit --namespace no longer creates daylight
    // between "validate the full provider class" and "validate the base
    // namespace alone" — the short class is now always derived from one of
    // $namespace's own segments, so the two became provably equivalent once
    // that derivation changed. This default-derivation path still isn't
    // equivalent to skipping validation ENTIRELY: without it, the scaffolder's
    // own pre-write parse check would still refuse (RuntimeException, exit 1),
    // but with a generic message rather than naming the actual bad identifier.
    $this->artisan('nodeflow:make-node-package', ['name' => 'acme/456pkg'])
        ->expectsOutputToContain('not a valid PHP identifier')
        ->assertFailed();

    expect($this->root.'/packages')->not->toBeDirectory();
});

it('refuses when the host does not require the package, naming the fix', function () {
    file_put_contents($this->root.'/composer.json', json_encode(['require' => []]));

    $this->artisan('nodeflow:make-node-package', ['name' => 'acme/widgets'])
        ->expectsOutputToContain('composer require atram/laravel-nodeflow')
        ->assertFailed();

    expect($this->root.'/packages')->not->toBeDirectory();
});

it('treats a non-string require constraint as absent rather than passing it through', function () {
    // Composer itself only ever writes a string constraint, but a
    // hand-edited composer.json is not guaranteed to. Counterfactual: drop
    // is_string($constraint) from hostNodeflowConstraint() and this array
    // value flows into PackageTarget's `string $nodeflowConstraint`
    // constructor parameter, which is a TypeError — a hard crash instead of
    // the same graceful refusal an absent constraint gets.
    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => ['^2.0']],
    ]));

    $this->artisan('nodeflow:make-node-package', ['name' => 'acme/widgets'])
        ->expectsOutputToContain('composer require atram/laravel-nodeflow')
        ->assertFailed();

    expect($this->root.'/packages')->not->toBeDirectory();
});

it('treats an empty-string require constraint as absent', function () {
    file_put_contents($this->root.'/composer.json', json_encode([
        'require' => ['atram/laravel-nodeflow' => ''],
    ]));

    $this->artisan('nodeflow:make-node-package', ['name' => 'acme/widgets'])
        ->expectsOutputToContain('composer require atram/laravel-nodeflow')
        ->assertFailed();
});

it('refuses an occupied directory with no composer.json at all, without --force', function () {
    // Distinct from the "foreign composer.json" case below: this exercises
    // targetIsAvailable()'s ! $this->files->exists($composerJsonPath) branch,
    // which an occupied-but-foreign-composer.json fixture never reaches.
    mkdir($this->root.'/packages/acme/widgets', 0777, true);
    file_put_contents($this->root.'/packages/acme/widgets/.gitkeep', '');

    $this->artisan('nodeflow:make-node-package', ['name' => 'acme/widgets'])
        ->expectsOutputToContain('--force')
        ->assertFailed();

    $this->artisan('nodeflow:make-node-package', [
        'name' => 'acme/widgets',
        '--force' => true,
    ])->assertExitCode(0);
});

it('accepts an existing package directory whose composer.json name matches, and does not duplicate', function () {
    $this->artisan('nodeflow:make-node-package', ['name' => 'acme/widgets'])->assertExitCode(0);

    $before = glob($this->root.'/packages/acme/*');

    $this->artisan('nodeflow:make-node-package', ['name' => 'acme/widgets'])->assertExitCode(0);

    expect(glob($this->root.'/packages/acme/*'))->toBe($before);
});

it('refuses a foreign occupied directory without --force, and succeeds with it', function () {
    mkdir($this->root.'/packages/acme/widgets', 0777, true);
    file_put_contents($this->root.'/packages/acme/widgets/composer.json', json_encode(['name' => 'someone/else']));

    $this->artisan('nodeflow:make-node-package', ['name' => 'acme/widgets'])
        ->expectsOutputToContain('--force')
        ->assertFailed();

    $this->artisan('nodeflow:make-node-package', [
        'name' => 'acme/widgets',
        '--force' => true,
    ])->assertExitCode(0);

    $decoded = json_decode(file_get_contents($this->root.'/packages/acme/widgets/composer.json'), true);
    expect($decoded['name'])->toBe('acme/widgets');
});

it('prints but does not write host Vite/tsconfig wiring under --js', function () {
    // Ordered with the rarer substring first, deliberately: 'compilerOptions'
    // appears only in the tsconfig snippet, but '@nodeflow/editor' appears in
    // BOTH snippets, including the very same line as 'compilerOptions'. If
    // '@nodeflow/editor' were registered first, Mockery would let it claim
    // that shared line before the 'compilerOptions' expectation ever saw it
    // — a harness quirk, not a claim about the command's actual output,
    // which contains both independently (proven by the two toBeFile()
    // assertions below and by manual inspection of Artisan::output()).
    $this->artisan('nodeflow:make-node-package', [
        'name' => 'acme/widgets',
        '--js' => true,
    ])
        ->expectsOutputToContain('compilerOptions')
        ->expectsOutputToContain('@nodeflow/editor')
        ->assertExitCode(0);

    expect($this->root.'/vite.config.ts')->not->toBeFile();
    expect($this->root.'/tsconfig.json')->not->toBeFile();

    expect($this->root.'/packages/acme/widgets/package.json')->toBeFile();
    expect($this->root.'/packages/acme/widgets/resources/js/index.ts')->toBeFile();
});

it('prints no host wiring reminder under --js when the host is already wired', function () {
    // Covers printJsWiring()'s `if ($snippet === null) continue;` guard:
    // ViteAliasStep::snippet() and TsconfigPathsStep::snippet() both return
    // null once their own check() reports AlreadyPresent. Counterfactual:
    // delete that guard and this test's first expectsOutputToContain(),
    // called with a null snippet, is what a un-guarded call would hand to
    // $this->line() — this pins the already-wired host to printing nothing
    // extra rather than exercising that path.
    file_put_contents($this->root.'/vite.config.ts', <<<'TS'
    import path from 'node:path'
    export default defineConfig({
        resolve: {
            alias: {
                '@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js'),
            },
        },
    })
    TS);

    file_put_contents($this->root.'/tsconfig.json', json_encode(['compilerOptions' => ['paths' => [
        '@nodeflow/editor' => ['./vendor/atram/laravel-nodeflow/resources/js'],
        '@nodeflow/editor/*' => ['./vendor/atram/laravel-nodeflow/resources/js/*'],
    ]]]));

    $viteBefore = file_get_contents($this->root.'/vite.config.ts');
    $tsconfigBefore = file_get_contents($this->root.'/tsconfig.json');

    $this->artisan('nodeflow:make-node-package', [
        'name' => 'acme/widgets',
        '--js' => true,
    ])
        ->doesntExpectOutputToContain('add this to the host yourself')
        ->assertExitCode(0);

    expect(file_get_contents($this->root.'/vite.config.ts'))->toBe($viteBefore);
    expect(file_get_contents($this->root.'/tsconfig.json'))->toBe($tsconfigBefore);
});

it('refuses at exit code 1, not 0', function () {
    // The F-3 / handle(): int contract. Counterfactual: return false from
    // handle() for a refusal, and Laravel's (int) cast on that turns it into
    // exit code 0 — indistinguishable from success to any script or CI job
    // that only checks $?.
    $exitCode = $this->artisan('nodeflow:make-node-package', ['name' => 'Not Valid!'])->run();

    expect($exitCode)->toBe(1);
});

it('resolves a fresh target for a second, different call rather than reusing the first', function () {
    // F-3. Symfony resolves one command object per name and keeps it for the
    // process's lifetime, so this second artisan() call reuses the exact
    // same MakeNodePackageCommand instance — and target() is a cache-or-
    // compute getter (`$this->target ??= $this->resolveTarget()`), so
    // without the reset at the top of handle(), this second call would
    // short-circuit past resolveTarget() entirely and hand the FIRST call's
    // cached PackageTarget straight to the scaffolder: "beta/other" would be
    // refused as never validated, or worse, would silently scaffold
    // "acme/widgets" again under a request that named a different package.
    // Counterfactual: delete `$this->target = null;` from the top of
    // handle() and the second assertion below fails — composer.json at
    // packages/beta/other names "acme/widgets" instead.
    $this->artisan('nodeflow:make-node-package', ['name' => 'acme/widgets'])->assertExitCode(0);
    $this->artisan('nodeflow:make-node-package', ['name' => 'beta/other'])->assertExitCode(0);

    $decoded = json_decode(file_get_contents($this->root.'/packages/beta/other/composer.json'), true);
    expect($decoded['name'])->toBe('beta/other');
});

it('does not let a failed first call leave a stale target for a later successful one', function () {
    // F-3's other half: a first, failed resolution never assigns $this->target
    // (resolveTarget() throws before target()'s ??= can run), so this is
    // "reset never fires against something that would have blocked anyway" —
    // recorded as its own test because the earlier version of this suite
    // asserted exactly this shape and it passed even with the reset deleted
    // (the failed call's target() call never reaches the ??= assignment in
    // the first place). The real guard is the test above.
    $this->artisan('nodeflow:make-node-package', ['name' => 'Not Valid!'])->assertFailed();

    $this->artisan('nodeflow:make-node-package', ['name' => 'acme/widgets'])->assertExitCode(0);

    $decoded = json_decode(file_get_contents($this->root.'/packages/acme/widgets/composer.json'), true);
    expect($decoded['name'])->toBe('acme/widgets');
});
