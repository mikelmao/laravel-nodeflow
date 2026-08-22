<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\ProviderRegistrationStep;
use Nodeflow\Console\Install\ProviderStep;
use Nodeflow\Console\InstallCommand;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-cmd-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/app/Providers', 0777, true);
    mkdir($this->root.'/bootstrap', 0777, true);
    mkdir($this->root.'/config', 0777, true);
    mkdir($this->root.'/resources/css', 0777, true);
    mkdir($this->root.'/database/migrations', 0777, true);

    file_put_contents($this->root.'/composer.json', json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));

    file_put_contents($this->root.'/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");
    file_put_contents($this->root.'/resources/css/app.css', "@import 'tailwindcss';\n");

    $this->app->setBasePath($this->root);
});

afterEach(function () {
    $delete = function (string $dir) use (&$delete) {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $delete($path) : unlink($path);
        }
        rmdir($dir);
    };

    if (is_dir($this->root)) {
        $delete($this->root);
    }
});

/** Write the three client settings install cannot write, so only the writable ones are missing. */
function writeClientWiring(string $root): void
{
    file_put_contents($root.'/vite.config.ts', <<<'TS'
    export default defineConfig({
        resolve: {
            alias: { '@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js') },
            dedupe: ['react', 'react-dom', '@xyflow/react'],
        },
    })
    TS);

    file_put_contents($root.'/tsconfig.json', json_encode(['compilerOptions' => ['paths' => [
        '@nodeflow/editor' => ['./vendor/atram/laravel-nodeflow/resources/js'],
        '@nodeflow/editor/*' => ['./vendor/atram/laravel-nodeflow/resources/js/*'],
    ]]]));

    file_put_contents($root.'/package.json', json_encode(['dependencies' => ['@xyflow/react' => '^12.0.0']]));
}

it('exits non-zero when it cannot wire the client requirements', function () {
    // The whole reason this command exists. Counterfactual: return SUCCESS
    // unconditionally — or return `false` from handle() instead of an int, which
    // Laravel casts to exit code 0 — and this fails. Three of the five client
    // requirements fail quietly, so a half-installed host that exits 0 is
    // indistinguishable from a working one in CI.
    $this->artisan('nodeflow:install')->assertExitCode(1);
});

it('exits zero on a host it could fully wire', function () {
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install')->assertExitCode(0);

    expect($this->root.'/'.ProviderStep::PATH)->toBeFile();
    expect($this->root.'/config/nodeflow.php')->not->toBeFile();
    expect(file_get_contents($this->root.'/bootstrap/providers.php'))
        ->toContain('NodeflowServiceProvider::class');
    expect(file_get_contents($this->root.'/resources/css/app.css'))
        ->toContain('atram/laravel-nodeflow/resources/js');
});

it('is idempotent: a second run writes nothing and still exits zero', function () {
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install')->assertExitCode(0);

    $before = [
        'provider' => file_get_contents($this->root.'/'.ProviderStep::PATH),
        'bootstrap' => file_get_contents($this->root.'/bootstrap/providers.php'),
        'css' => file_get_contents($this->root.'/resources/css/app.css'),
    ];

    $this->artisan('nodeflow:install')->assertExitCode(0);

    expect(file_get_contents($this->root.'/'.ProviderStep::PATH))->toBe($before['provider']);
    expect(file_get_contents($this->root.'/bootstrap/providers.php'))->toBe($before['bootstrap']);
    expect(file_get_contents($this->root.'/resources/css/app.css'))->toBe($before['css']);
});

it('keeps the optional config healthy when every other step is wired', function () {
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install')->assertExitCode(0);

    expect($this->root.'/'.ProviderStep::PATH)->toBeFile();
    expect($this->root.'/'.ProviderRegistrationStep::PATH)->toBeFile();
    expect(file_get_contents($this->root.'/bootstrap/providers.php'))
        ->toContain('NodeflowServiceProvider::class');
    expect(file_get_contents($this->root.'/resources/css/app.css'))
        ->toContain('atram/laravel-nodeflow/resources/js');
    expect(file_get_contents($this->root.'/vite.config.ts'))
        ->toContain("'@nodeflow/editor'")
        ->toContain('dedupe');
    expect(json_decode(file_get_contents($this->root.'/tsconfig.json'), true)['compilerOptions']['paths'])
        ->toHaveKeys(['@nodeflow/editor', '@nodeflow/editor/*']);
    expect(json_decode(file_get_contents($this->root.'/package.json'), true)['dependencies'])
        ->toHaveKey('@xyflow/react');
    expect($this->root.'/config/nodeflow.php')->not->toBeFile();

    $this->artisan('nodeflow:install', ['--check' => true])->assertExitCode(0);
});

it('writes nothing under --check and exits non-zero when anything is unwired', function () {
    // Counterfactual: let --check fall through to apply() and this fails, having
    // modified four host files during what the host asked to be a read.
    $this->artisan('nodeflow:install', ['--check' => true])->assertExitCode(1);

    expect($this->root.'/'.ProviderStep::PATH)->not->toBeFile();
    expect($this->root.'/config/nodeflow.php')->not->toBeFile();
    expect(file_get_contents($this->root.'/bootstrap/providers.php'))
        ->not->toContain('NodeflowServiceProvider');
    expect(file_get_contents($this->root.'/resources/css/app.css'))
        ->not->toContain('atram/laravel-nodeflow');
});

it('exits zero under --check on a fully wired host', function () {
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install')->assertExitCode(0);
    $this->artisan('nodeflow:install', ['--check' => true])->assertExitCode(0);
});

it('does not publish migrations by default', function () {
    // E19. Counterfactual: publish by default and every fresh install lays down a
    // copy that shadows the package's own file for every migrate run, forever.
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install')->assertExitCode(0);

    expect(glob($this->root.'/database/migrations/*.php'))->toBe([]);
});

it('publishes migrations on request', function () {
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install', ['--publish-migrations' => true])->assertExitCode(0);

    expect(glob($this->root.'/database/migrations/*.php'))->not->toBe([]);
});

it('exits non-zero when a published migration has drifted', function () {
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install', ['--publish-migrations' => true])->assertExitCode(0);

    $copy = glob($this->root.'/database/migrations/*.php')[0];
    file_put_contents($copy, file_get_contents($copy)."\n// host edit\n");

    $this->artisan('nodeflow:install')->assertExitCode(1);
    $this->artisan('nodeflow:install', ['--force-migrations' => true])->assertExitCode(0);
});

it('reports undefined gates without failing on them', function () {
    // A report, never an outcome. Counterfactual: fold the gate report into the
    // exit code and this fails — an undefined gate is the correct state
    // immediately after install, so the first run would always be red and every
    // host would learn to ignore the exit code that Task 11's whole point is.
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install')
        ->expectsOutputToContain('nodeflow.viewAny')
        ->assertExitCode(0);
});

it('reports all four gates as defined when they are', function () {
    writeClientWiring($this->root);

    foreach (['viewAny', 'update', 'publish', 'runManually'] as $ability) {
        Gate::define('nodeflow.'.$ability, fn () => true);
    }

    $this->artisan('nodeflow:install')
        ->expectsOutputToContain('All four authorization gates are defined')
        ->assertExitCode(0);
});

it('reports the resolved tenancy mode and which resolver auto is reading', function () {
    // Counterfactual: print config('nodeflow.tenancy') alone and this fails — the
    // string 'auto' does not tell a host what a null tenant will do, and which
    // resolver is bound is exactly what decides it.
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install')
        ->expectsOutputToContain('no TenantResolver bound')
        ->assertExitCode(0);
});

it('fails on a residual Writable outcome in either mode, not only under --check', function () {
    // I2. No shipped step's apply() actually LEAVES a Writable outcome behind
    // today: every step whose check() can return Writable resolves it to Wired
    // or CannotWire once apply() runs, and every verify-only step (whose
    // apply() just re-returns check()) never returns Writable from check() in
    // the first place. So this cannot be reproduced by running the real
    // command against any fixture — there is no live bug to reproduce, only a
    // fragile invariant ("no future step's apply() may ever return Writable")
    // that nothing enforces. Testing exitCode() directly, by reflection, pins
    // the hardening itself rather than a scenario that does not exist yet.
    $command = $this->app->make(InstallCommand::class);

    $exitCode = new ReflectionMethod($command, 'exitCode');
    $exitCode->setAccessible(true);

    expect($exitCode->invoke($command, [InstallOutcome::AlreadyPresent, InstallOutcome::Writable]))
        ->toBe(Command::FAILURE);
    expect($exitCode->invoke($command, [InstallOutcome::AlreadyPresent, InstallOutcome::Wired]))
        ->toBe(Command::SUCCESS);
});

it('prints the exact snippet for each requirement it cannot wire', function () {
    $this->artisan('nodeflow:install')
        ->expectsOutputToContain('@nodeflow/editor')
        ->expectsOutputToContain('dedupe')
        ->expectsOutputToContain('npm install @xyflow/react')
        ->assertExitCode(1);
});
