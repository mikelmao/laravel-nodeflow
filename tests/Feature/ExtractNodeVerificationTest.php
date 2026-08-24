<?php

use Nodeflow\Console\Extract\ComposerRunner;

/** Test-only entry point that deliberately bypasses G3 to challenge M9 with a drifting type. */
final class TypeDriftBypassExtractCommand extends \Nodeflow\Console\ExtractNodeCommand
{
    protected $signature = 'nodeflow:test-type-drift-bypass';

    public function __construct(
        \Illuminate\Filesystem\Filesystem $files,
        ComposerRunner $composerRunner,
        private string $hostPath,
        private string $oldClass,
        private string $recordedType,
    ) {
        parent::__construct($files, $composerRunner);
    }

    public function handle(\Nodeflow\Nodes\NodeRegistry $registry): int
    {
        $target = new \Nodeflow\Console\PackageTarget(
            composerName: 'acme/widgets',
            namespace: 'Acme\\Widgets',
            absolutePath: $this->hostPath.'/packages/acme/widgets',
            relativePath: 'packages/acme/widgets',
            providerClass: 'Acme\\Widgets\\WidgetsServiceProvider',
            nodeflowConstraint: '*',
            withJs: false,
        );

        $property = new ReflectionProperty(\Nodeflow\Console\ExtractNodeCommand::class, 'provenType');
        $property->setValue($this, $this->recordedType);

        $method = new ReflectionMethod(\Nodeflow\Console\ExtractNodeCommand::class, 'performMoves');

        return $method->invoke(
            $this,
            $this->oldClass,
            new ReflectionClass($this->oldClass),
            $this->hostPath,
            'acme/widgets',
            'packages/acme/widgets',
            $target,
        );
    }
}

function verificationFixtureRoot(string $suffix): string
{
    return sys_get_temp_dir().'/nodeflow composer fixtures/'.getmypid().'-'.$suffix;
}

/** Recursively removes a fixture without ever following directory symlinks. */
function verificationDeleteTree(string $path): void
{
    if (is_link($path)) {
        unlink($path);

        return;
    }

    if (! is_dir($path)) {
        if (file_exists($path)) {
            unlink($path);
        }

        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        verificationDeleteTree($path.'/'.$entry);
    }

    rmdir($path);
}

/**
 * Runs a command without a shell, returning its exit code and combined output.
 *
 * @param  list<string>  $command
 * @return array{exit: int, output: string}
 */
function verificationRun(array $command, string $cwd): array
{
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd, null, ['bypass_shell' => true]);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start '.implode(' ', $command));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit' => proc_close($process),
        'output' => $stdout.$stderr,
    ];
}

/** Returns whether a class is available from this fixture in a genuinely fresh PHP process. */
function verificationClassExists(string $hostPath, string $class): bool
{
    $result = verificationRun([
        PHP_BINARY,
        '-r',
        'require $argv[1]."/vendor/autoload.php"; exit(class_exists($argv[2]) ? 0 : 1);',
        $hostPath,
        $class,
    ], $hostPath);

    return $result['exit'] === 0;
}

/** Hashes file bytes, object kinds, modes, and symlink targets recursively, ignoring mtimes. */
function verificationTreeHash(string $root): string
{
    $entries = [];
    $walk = function (string $dir) use (&$walk, &$entries, $root): void {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir.'/'.$name;
            $relative = substr($path, strlen($root) + 1);

            if (is_link($path)) {
                $entries[$relative] = 'link:'.readlink($path);
            } elseif (is_dir($path)) {
                $entries[$relative.'/'] = 'directory:'.sprintf('%04o', fileperms($path) & 07777);
                $walk($path);
            } else {
                $entries[$relative] = 'file:'.sprintf('%04o', fileperms($path) & 07777)
                    .':'.hash_file('sha256', $path);
            }
        }
    };

    $walk($root);
    ksort($entries);

    return hash('sha256', json_encode($entries));
}

/** Creates a root requiring a local path package, but deliberately installs nothing. */
function verificationWriteComposerFixture(string $suffix): string
{
    $root = verificationFixtureRoot($suffix);
    verificationDeleteTree($root);

    mkdir($root.'/packages/probe/pkg/src', 0777, true);

    file_put_contents($root.'/packages/probe/pkg/composer.json', json_encode([
        'name' => 'probe/pkg',
        'version' => '1.0.0',
        'autoload' => ['psr-4' => ['Probe\\Pkg\\' => 'src/']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    file_put_contents($root.'/packages/probe/pkg/src/Thing.php', <<<'PHP'
    <?php

    namespace Probe\Pkg;

    final class Thing {}
    PHP);

    file_put_contents($root.'/marker.php', <<<'PHP'
    <?php

    file_put_contents(__DIR__.'/post-autoload-dump-ran', 'ran');
    PHP);

    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'probe/host',
        'repositories' => [[
            'type' => 'path',
            'url' => 'packages/probe/pkg',
        ]],
        'require' => ['probe/pkg' => '*'],
        'scripts' => ['post-autoload-dump' => '@php marker.php'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $root;
}

/** Creates a real Composer plugin whose activation is an observable out-of-journal side effect. */
function verificationWriteComposerPluginFixture(string $suffix): string
{
    $root = verificationFixtureRoot($suffix);
    verificationDeleteTree($root);
    mkdir($root.'/packages/probe/side-effect-plugin/src', 0777, true);

    file_put_contents($root.'/packages/probe/side-effect-plugin/composer.json', json_encode([
        'name' => 'probe/side-effect-plugin',
        'version' => '1.0.0',
        'type' => 'composer-plugin',
        'require' => ['composer-plugin-api' => '^2.0'],
        'autoload' => ['psr-4' => ['Probe\\SideEffect\\' => 'src/']],
        'extra' => ['class' => 'Probe\\SideEffect\\SideEffectPlugin'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    file_put_contents($root.'/packages/probe/side-effect-plugin/src/SideEffectPlugin.php', <<<'PHP'
    <?php

    namespace Probe\SideEffect;

    use Composer\Composer;
    use Composer\IO\IOInterface;
    use Composer\Plugin\PluginInterface;

    final class SideEffectPlugin implements PluginInterface
    {
        public function activate(Composer $composer, IOInterface $io): void
        {
            file_put_contents(getcwd().'/composer-plugin-ran', 'activated');
        }

        public function deactivate(Composer $composer, IOInterface $io): void {}

        public function uninstall(Composer $composer, IOInterface $io): void {}
    }
    PHP);

    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'probe/plugin-host',
        'version' => '1.0.0',
        'repositories' => [[
            'type' => 'path',
            'url' => 'packages/probe/side-effect-plugin',
        ]],
        'require' => ['probe/side-effect-plugin' => '*'],
        'config' => ['allow-plugins' => ['probe/side-effect-plugin' => true]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $root;
}

/** Creates and installs a minimal real Laravel host with one discovered path package. */
function verificationWriteLaravelHostFixture(string $suffix): string
{
    $root = verificationFixtureRoot($suffix);
    verificationDeleteTree($root);

    mkdir($root.'/bootstrap/cache', 0777, true);
    mkdir($root.'/packages/probe/discovered/src/Nodes', 0777, true);

    $projectRoot = dirname(__DIR__, 2);

    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'probe/laravel-host',
        'version' => '1.0.0',
        'repositories' => [
            ['type' => 'path', 'url' => $projectRoot, 'options' => ['symlink' => true]],
            ['type' => 'path', 'url' => 'packages/probe/discovered', 'options' => ['symlink' => true]],
        ],
        'require' => [
            'atram/laravel-nodeflow' => '*',
            'probe/discovered' => '*',
        ],
        'minimum-stability' => 'dev',
        'prefer-stable' => true,
        'scripts' => ['post-autoload-dump' => '@php marker.php'],
        'config' => ['allow-plugins' => false],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    file_put_contents($root.'/marker.php', <<<'PHP'
    <?php

    file_put_contents(__DIR__.'/post-autoload-dump-ran', 'ran');
    PHP);

    file_put_contents($root.'/bootstrap/app.php', <<<'PHP'
    <?php

    use Illuminate\Foundation\Application;

    return Application::configure(basePath: dirname(__DIR__))->create();
    PHP);

    file_put_contents($root.'/packages/probe/discovered/composer.json', json_encode([
        'name' => 'probe/discovered',
        'version' => '1.0.0',
        'require' => ['atram/laravel-nodeflow' => '*'],
        'autoload' => ['psr-4' => ['Probe\\Discovered\\' => 'src/']],
        'extra' => ['laravel' => ['providers' => ['Probe\\Discovered\\DiscoveredServiceProvider']]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    file_put_contents($root.'/packages/probe/discovered/src/DiscoveredServiceProvider.php', <<<'PHP'
    <?php

    namespace Probe\Discovered;

    use Illuminate\Support\ServiceProvider;
    use Nodeflow\Nodes\NodeRegistry;
    use Probe\Discovered\Nodes\MovedNode;

    final class DiscoveredServiceProvider extends ServiceProvider
    {
        public function boot(): void
        {
            $this->app->make(NodeRegistry::class)->register(MovedNode::class);
        }
    }
    PHP);

    file_put_contents($root.'/packages/probe/discovered/src/Nodes/MovedNode.php', <<<'PHP'
    <?php

    namespace Probe\Discovered\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    final class MovedNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'probe.discovered';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('Moved');
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            return $context->continue();
        }
    }
    PHP);

    $install = verificationRun([
        'composer',
        'install',
        '--no-scripts',
        '--no-interaction',
        '--no-progress',
    ], $root);

    expect($install['exit'])->toBe(0, $install['output']);
    expect($root.'/post-autoload-dump-ran')->not->toBeFile();

    return $root;
}

/** Writes the smallest host tree that passes G1-G8 and reaches the move transaction. */
function verificationWriteExtractionFixture(string $suffix, string $shortClass, string $type): array
{
    $root = verificationFixtureRoot($suffix);
    verificationDeleteTree($root);
    mkdir($root.'/app/Nodeflow/Nodes', 0777, true);

    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'probe/extraction-host',
        'require' => ['atram/laravel-nodeflow' => '*'],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $path = $root.'/app/Nodeflow/Nodes/'.$shortClass.'.php';
    file_put_contents($path, <<<PHP
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    final class {$shortClass} extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return '{$type}';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('{$shortClass}');
        }

        public function forSubject(SubjectContext \$context): NodeResult
        {
            return \$context->continue();
        }
    }
    PHP);

    require $path;

    return [$root, 'App\\Nodeflow\\Nodes\\'.$shortClass];
}

afterEach(function () {
    verificationDeleteTree(verificationFixtureRoot('no lock; $PATH-safe'));
    verificationDeleteTree(verificationFixtureRoot('existing lock; scoped update'));
    verificationDeleteTree(verificationFixtureRoot('composer env isolation'));
    verificationDeleteTree(verificationFixtureRoot('external composer vendor'));
    verificationDeleteTree(verificationFixtureRoot('composer bin env isolation'));
    verificationDeleteTree(verificationFixtureRoot('external composer bin'));
    verificationDeleteTree(verificationFixtureRoot('composer home isolation'));
    verificationDeleteTree(verificationFixtureRoot('ambient composer home'));
    verificationDeleteTree(verificationFixtureRoot('external composer home vendor'));
    verificationDeleteTree(verificationFixtureRoot('composer file env isolation'));
    verificationDeleteTree(verificationFixtureRoot('external composer project'));
    verificationDeleteTree(verificationFixtureRoot('composer plugin isolation'));
    verificationDeleteTree(verificationFixtureRoot('composer plugin scoped isolation'));
    verificationDeleteTree(verificationFixtureRoot('lockless restore regeneration'));
    verificationDeleteTree(verificationFixtureRoot('fresh host; discovery'));
    verificationDeleteTree(verificationFixtureRoot('fresh host; frozen custom caches'));
    verificationDeleteTree(verificationFixtureRoot('fresh host; custom vendor'));
    verificationDeleteTree(verificationFixtureRoot('fresh host; forged result'));
    verificationDeleteTree(verificationFixtureRoot('fresh host; forged type map'));
    verificationDeleteTree(verificationFixtureRoot('injected success'));
    verificationDeleteTree(verificationFixtureRoot('committed cleanup failure'));
    verificationDeleteTree(verificationFixtureRoot('restore cleanup-only failure'));
    verificationDeleteTree(verificationFixtureRoot('restore cleanup and regeneration failure'));
    verificationDeleteTree(verificationFixtureRoot('double cleanup usable regeneration'));
    verificationDeleteTree(verificationFixtureRoot('double cleanup unusable regeneration'));
    verificationDeleteTree(verificationFixtureRoot('cleanup residue plus undo failure'));
    verificationDeleteTree(verificationFixtureRoot('stable scaffold extraction'));
    verificationDeleteTree(verificationFixtureRoot('M8 failure'));
    verificationDeleteTree(verificationFixtureRoot('M8 custom vendor-dir failure'));
    verificationDeleteTree(verificationFixtureRoot('M8 symlinked vendor-dir failure'));
    verificationDeleteTree(verificationFixtureRoot('M8 escaping vendor-dir'));
    verificationDeleteTree(verificationFixtureRoot('external configured vendor'));
    verificationDeleteTree(verificationFixtureRoot('M8 custom bin-dir failure'));
    verificationDeleteTree(verificationFixtureRoot('M8 escaping bin-dir'));
    verificationDeleteTree(verificationFixtureRoot('external configured bin'));
    verificationDeleteTree(verificationFixtureRoot('M8 Windows drive vendor-dir'));
    verificationDeleteTree(verificationFixtureRoot('M8 Windows drive-relative vendor-dir'));
    verificationDeleteTree(verificationFixtureRoot('M8 Windows UNC bin-dir'));
    verificationDeleteTree(verificationFixtureRoot('M8 symlinked lock failure'));
    verificationDeleteTree(verificationFixtureRoot('M8 dangling in-host lock failure'));
    verificationDeleteTree(verificationFixtureRoot('M8 lock state changed'));
    verificationDeleteTree(verificationFixtureRoot('M8 escaping lock'));
    verificationDeleteTree(verificationFixtureRoot('M8 dangling multi-hop lock'));
    verificationDeleteTree(verificationFixtureRoot('M8 cyclic lock'));
    verificationDeleteTree(verificationFixtureRoot('M8 Windows drive lock target'));
    verificationDeleteTree(verificationFixtureRoot('M8 Windows drive-relative lock target'));
    verificationDeleteTree(verificationFixtureRoot('M8 Windows UNC lock target'));
    verificationDeleteTree(verificationFixtureRoot('external composer lock'));
    verificationDeleteTree(verificationFixtureRoot('external dangling composer lock'));
    verificationDeleteTree(verificationFixtureRoot('M8 generated symlink failure'));
    verificationDeleteTree(verificationFixtureRoot('M8 escaping autoload symlink'));
    verificationDeleteTree(verificationFixtureRoot('M8 escaping composer subtree'));
    verificationDeleteTree(verificationFixtureRoot('external generated composer state'));
    verificationDeleteTree(verificationFixtureRoot('M9 external packages cache'));
    verificationDeleteTree(verificationFixtureRoot('M9 external services cache'));
    verificationDeleteTree(verificationFixtureRoot('M9 Windows packages cache'));
    verificationDeleteTree(verificationFixtureRoot('M9 Windows drive-relative packages cache'));
    verificationDeleteTree(verificationFixtureRoot('M9 UNC services cache'));
    verificationDeleteTree(verificationFixtureRoot('external Laravel cache'));
    verificationDeleteTree(verificationFixtureRoot('M9 custom cache rollback'));
    verificationDeleteTree(verificationFixtureRoot('M9 symlinked custom cache rollback'));
    verificationDeleteTree(verificationFixtureRoot('M6 escaping composer json'));
    verificationDeleteTree(verificationFixtureRoot('M5 escaping host provider'));
    verificationDeleteTree(verificationFixtureRoot('external mutable host file'));
    verificationDeleteTree(verificationFixtureRoot('M8 matching package failure'));
    verificationDeleteTree(verificationFixtureRoot('M8 forced package failure'));
    verificationDeleteTree(verificationFixtureRoot('restore regeneration failure'));
    verificationDeleteTree(verificationFixtureRoot('M9 undiscovered'));
    verificationDeleteTree(verificationFixtureRoot('M9 real undiscovered'));
    verificationDeleteTree(verificationFixtureRoot('M9 mismatch'));
    verificationDeleteTree(verificationFixtureRoot('M9 type drift'));
    verificationDeleteTree(verificationFixtureRoot('stale resident class'));
    verificationDeleteTree(verificationFixtureRoot('stale package manifest direct'));
    verificationDeleteTree(verificationFixtureRoot('stale package manifest extraction'));
    @unlink(verificationFixtureRoot('M8 restore ran'));
    @unlink(verificationFixtureRoot('M9 restore ran'));
    @unlink(verificationFixtureRoot('M9 mismatch restore ran'));
    @unlink(verificationFixtureRoot('restore cleanup-only regeneration ran'));
    putenv('COMPOSER_VENDOR_DIR');
    putenv('COMPOSER_BIN_DIR');
    putenv('COMPOSER');
    putenv('COMPOSER_HOME');
    \Illuminate\Support\Env::getRepository()->clear('APP_PACKAGES_CACHE');
    \Illuminate\Support\Env::getRepository()->clear('APP_SERVICES_CACHE');
});

it('uses a real full Composer install without scripts when no lock existed (E48)', function () {
    $root = verificationWriteComposerFixture('no lock; $PATH-safe');

    $dump = verificationRun(['composer', 'dump-autoload', '--no-scripts'], $root);

    expect($dump['exit'])->toBe(0, $dump['output']);
    expect(verificationClassExists($root, 'Probe\\Pkg\\Thing'))->toBeFalse();
    expect($root.'/post-autoload-dump-ran')->not->toBeFile();

    $installed = (new ComposerRunner())->install($root, 'probe/pkg');

    expect($installed)->toBeTrue();
    expect($root.'/composer.lock')->toBeFile();
    expect(verificationClassExists($root, 'Probe\\Pkg\\Thing'))->toBeTrue();
    expect($root.'/post-autoload-dump-ran')->not->toBeFile();
});

it('uses a real scoped Composer update without scripts when a lock existed (E48)', function () {
    $root = verificationWriteComposerFixture('existing lock; scoped update');
    $composerPath = $root.'/composer.json';
    $composer = json_decode(file_get_contents($composerPath), true);
    $composer['require'] = new stdClass();
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $initialInstall = verificationRun(['composer', 'install', '--no-scripts', '--no-interaction'], $root);
    expect($initialInstall['exit'])->toBe(0, $initialInstall['output']);

    $composer['require'] = ['probe/pkg' => '*'];
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $dump = verificationRun(['composer', 'dump-autoload', '--no-scripts'], $root);
    expect($dump['exit'])->toBe(0, $dump['output']);
    expect(verificationClassExists($root, 'Probe\\Pkg\\Thing'))->toBeFalse();

    $lockBefore = file_get_contents($root.'/composer.lock');
    $installed = (new ComposerRunner())->install($root, 'probe/pkg');

    expect($installed)->toBeTrue();
    expect(file_get_contents($root.'/composer.lock'))->not->toBe($lockBefore);
    expect(verificationClassExists($root, 'Probe\\Pkg\\Thing'))->toBeTrue();
    expect($root.'/post-autoload-dump-ran')->not->toBeFile();
});

it('does not let an inherited Composer vendor override redirect M8 outside the host', function () {
    $root = verificationWriteComposerFixture('composer env isolation');
    $externalVendor = verificationFixtureRoot('external composer vendor');
    verificationDeleteTree($externalVendor);
    putenv('COMPOSER_VENDOR_DIR='.$externalVendor);

    try {
        $installed = (new ComposerRunner())->install($root, 'probe/pkg');
    } finally {
        putenv('COMPOSER_VENDOR_DIR');
    }

    expect($installed)->toBeTrue();
    expect($root.'/vendor/autoload.php')->toBeFile();
    expect(verificationClassExists($root, 'Probe\\Pkg\\Thing'))->toBeTrue();
    expect($externalVendor)->not->toBeDirectory();
});

it('does not let an inherited Composer bin override redirect dependency proxies outside the host', function () {
    $root = verificationWriteComposerFixture('composer bin env isolation');
    $packageComposerPath = $root.'/packages/probe/pkg/composer.json';
    $packageComposer = json_decode(file_get_contents($packageComposerPath), true);
    $packageComposer['bin'] = ['bin/probe-tool'];
    file_put_contents($packageComposerPath, json_encode($packageComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    mkdir($root.'/packages/probe/pkg/bin', 0777, true);
    file_put_contents($root.'/packages/probe/pkg/bin/probe-tool', '#!/usr/bin/env php'.PHP_EOL.'<?php');
    chmod($root.'/packages/probe/pkg/bin/probe-tool', 0755);

    $externalBin = verificationFixtureRoot('external composer bin');
    verificationDeleteTree($externalBin);
    putenv('COMPOSER_BIN_DIR='.$externalBin);

    try {
        $installed = (new ComposerRunner())->install($root, 'probe/pkg');
    } finally {
        putenv('COMPOSER_BIN_DIR');
    }

    expect($installed)->toBeTrue();
    expect($root.'/vendor/bin/probe-tool')->toBeFile();
    expect(fileperms($root.'/vendor/bin/probe-tool') & 0111)->not->toBe(0);
    expect($externalBin)->not->toBeDirectory();
});

it('does not let inherited Composer home configuration redirect M8 outside the host', function () {
    $root = verificationWriteComposerFixture('composer home isolation');
    $composerHome = verificationFixtureRoot('ambient composer home');
    $externalVendor = verificationFixtureRoot('external composer home vendor');
    verificationDeleteTree($composerHome);
    verificationDeleteTree($externalVendor);
    mkdir($composerHome, 0777, true);
    file_put_contents($composerHome.'/config.json', json_encode([
        'config' => ['vendor-dir' => $externalVendor],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $temporaryHomesBefore = glob(sys_get_temp_dir().'/nodeflow-composer-home-*') ?: [];
    putenv('COMPOSER_HOME='.$composerHome);

    try {
        $installed = (new ComposerRunner())->install($root, 'probe/pkg');
    } finally {
        putenv('COMPOSER_HOME');
    }

    expect($installed)->toBeTrue();
    expect($root.'/vendor/autoload.php')->toBeFile();
    expect(verificationClassExists($root, 'Probe\\Pkg\\Thing'))->toBeTrue();
    expect($externalVendor)->not->toBeDirectory();
    expect(glob(sys_get_temp_dir().'/nodeflow-composer-home-*') ?: [])->toBe($temporaryHomesBefore);
});

it('does not let an inherited Composer file override redirect M8 away from the host', function () {
    $root = verificationWriteComposerFixture('composer file env isolation');
    $externalRoot = verificationFixtureRoot('external composer project');
    verificationDeleteTree($externalRoot);
    mkdir($externalRoot, 0777, true);
    $externalComposer = $externalRoot.'/outside.json';
    file_put_contents($externalComposer, json_encode([
        'name' => 'probe/outside',
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $outsideBefore = verificationTreeHash($externalRoot);
    putenv('COMPOSER='.$externalComposer);

    try {
        $installed = (new ComposerRunner())->install($root, 'probe/pkg');
    } finally {
        putenv('COMPOSER');
    }

    expect($installed)->toBeTrue();
    expect(verificationClassExists($root, 'Probe\\Pkg\\Thing'))->toBeTrue();
    expect(verificationTreeHash($externalRoot))->toBe($outsideBefore);
});

it('installs a real Composer package without activating its plugin code', function () {
    $root = verificationWriteComposerPluginFixture('composer plugin isolation');

    $installed = (new ComposerRunner())->install($root, 'probe/side-effect-plugin');

    expect($installed)->toBeTrue();
    expect($root.'/vendor/probe/side-effect-plugin')->toBeDirectory();
    expect($root.'/composer-plugin-ran')->not->toBeFile();
});

it('scoped-updates a real Composer package without activating its plugin code', function () {
    $root = verificationWriteComposerPluginFixture('composer plugin scoped isolation');
    $composerPath = $root.'/composer.json';
    $composer = json_decode(file_get_contents($composerPath), true);
    $composer['require'] = new stdClass();
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $initialInstall = verificationRun([
        'composer',
        'install',
        '--no-scripts',
        '--no-plugins',
        '--no-interaction',
        '--no-progress',
    ], $root);
    expect($initialInstall['exit'])->toBe(0, $initialInstall['output']);

    $composer['require'] = ['probe/side-effect-plugin' => '*'];
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $installed = (new ComposerRunner())->install($root, 'probe/side-effect-plugin');

    expect($installed)->toBeTrue();
    expect($root.'/vendor/probe/side-effect-plugin')->toBeDirectory();
    expect($root.'/composer-plugin-ran')->not->toBeFile();
});

it('regenerates a restored lockless autoloader without creating a lock or running scripts', function () {
    $root = verificationWriteComposerFixture('lockless restore regeneration');
    $install = verificationRun(['composer', 'install', '--no-scripts', '--no-interaction'], $root);
    expect($install['exit'])->toBe(0, $install['output']);
    unlink($root.'/composer.lock');

    expect(verificationClassExists($root, 'Probe\\Pkg\\Thing'))->toBeTrue();
    $before = verificationTreeHash($root);

    $regenerated = (new ComposerRunner())->regenerateAutoload($root);

    expect($regenerated)->toBeTrue();
    expect($root.'/composer.lock')->not->toBeFile();
    expect(verificationClassExists($root, 'Probe\\Pkg\\Thing'))->toBeTrue();
    expect($root.'/post-autoload-dump-ran')->not->toBeFile();
    expect(verificationTreeHash($root))->toBe($before);
});

it('boots a real Laravel host in a fresh process and returns the class package discovery registered (E49)', function () {
    $root = verificationWriteLaravelHostFixture('fresh host; discovery');

    $resolved = (new ComposerRunner())->bootAndResolve($root, 'probe.discovered');

    expect($resolved)->toBe('Probe\\Discovered\\Nodes\\MovedNode');
    expect($root.'/bootstrap/cache/packages.php')->toBeFile();
});

it('boots M9 with the exact in-host Laravel cache paths frozen by G8', function () {
    $root = verificationWriteLaravelHostFixture('fresh host; frozen custom caches');
    $customDirectory = $root.'/storage/framework/frozen-cache';
    mkdir($customDirectory, 0777, true);
    $packagesPath = $customDirectory.'/packages.php';
    $servicesPath = $customDirectory.'/services.php';
    file_put_contents($root.'/bootstrap/cache/packages.php', '<?php return [];');

    $runner = new ComposerRunner;
    $runner->freezeCachePaths($root, $packagesPath, $servicesPath);
    $resolved = $runner->bootAndResolve($root, 'probe.discovered');

    expect($resolved)->toBe('Probe\\Discovered\\Nodes\\MovedNode')
        ->and(file_get_contents($root.'/bootstrap/cache/packages.php'))->toBe('<?php return [];')
        ->and(file_get_contents($packagesPath))->toContain('probe/discovered')
        ->and($servicesPath)->toBeFile()
        ->and($root.'/post-autoload-dump-ran')->not->toBeFile();
});

it('boots a real Laravel host through its configured Composer vendor directory', function () {
    $root = verificationWriteLaravelHostFixture('fresh host; custom vendor');
    $composerPath = $root.'/composer.json';
    $composer = json_decode(file_get_contents($composerPath), true);
    $composer['config']['vendor-dir'] = 'deps with spaces';
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $install = verificationRun([
        'composer',
        'install',
        '--no-scripts',
        '--no-interaction',
        '--no-progress',
    ], $root);
    expect($install['exit'])->toBe(0, $install['output']);
    verificationDeleteTree($root.'/vendor');

    $resolved = (new ComposerRunner())->bootAndResolve($root, 'probe.discovered');

    expect($resolved)->toBe('Probe\\Discovered\\Nodes\\MovedNode');
    expect($root.'/post-autoload-dump-ran')->not->toBeFile();
});

it('does not accept a forged fixed result marker emitted by host shutdown code', function () {
    $root = verificationWriteLaravelHostFixture('fresh host; forged result');
    file_put_contents($root.'/bootstrap/app.php', <<<'PHP'
    <?php

    use Illuminate\Foundation\Application;

    register_shutdown_function(static function (): void {
        echo 'NODEFLOW_EXTRACT_RESULT_6f12d9a4:'.json_encode([
            'class' => 'Forged\\Host\\Result',
        ]).PHP_EOL;
    });

    echo "ordinary host boot noise\n";

    return Application::configure(basePath: dirname(__DIR__))->create();
    PHP);

    $resolved = (new ComposerRunner())->bootAndResolve($root, 'probe.discovered');

    expect($resolved)->toBe('Probe\\Discovered\\Nodes\\MovedNode');
});

it('rejects a registry map whose class type differs even when its key matches the recorded literal', function () {
    $root = verificationWriteLaravelHostFixture('fresh host; forged type map');
    file_put_contents(
        $root.'/packages/probe/discovered/src/DiscoveredServiceProvider.php',
        <<<'PHP'
        <?php

        namespace Probe\Discovered;

        use Illuminate\Support\ServiceProvider;
        use Nodeflow\Nodes\NodeRegistry;
        use Probe\Discovered\Nodes\MovedNode;

        final class ForgedTypeRegistry extends NodeRegistry
        {
            public function all(): array
            {
                return ['probe.recorded-literal' => MovedNode::class];
            }
        }

        final class DiscoveredServiceProvider extends ServiceProvider
        {
            public function register(): void
            {
                $this->app->singleton(NodeRegistry::class, fn (): NodeRegistry => new ForgedTypeRegistry);
            }
        }
        PHP,
    );
    $nodePath = $root.'/packages/probe/discovered/src/Nodes/MovedNode.php';
    $node = file_get_contents($nodePath);
    file_put_contents($nodePath, str_replace("return 'probe.discovered';", "return 'probe.drifted';", $node));

    $resolved = (new ComposerRunner())->bootAndResolve($root, 'probe.recorded-literal');

    expect($resolved)->toBeNull();
});

it('proves a stale Laravel package manifest blocks discovery until it is invalidated', function () {
    $root = verificationWriteLaravelHostFixture('stale package manifest direct');
    file_put_contents($root.'/bootstrap/cache/packages.php', '<?php return [];');

    $runner = new ComposerRunner;

    expect($runner->bootAndResolve($root, 'probe.discovered'))->toBeNull();

    unlink($root.'/bootstrap/cache/packages.php');

    expect($runner->bootAndResolve($root, 'probe.discovered'))
        ->toBe('Probe\\Discovered\\Nodes\\MovedNode');
    expect($root.'/post-autoload-dump-ran')->not->toBeFile();
});

it('keeps a successful extraction only after M8 installs and M9 resolves the exact moved class', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'injected success',
        'InstalledNode',
        'verification.installed',
    );

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            $path = $hostPath.'/vendor/'.$packageName;
            mkdir($path, 0777, true);
            file_put_contents($path.'/installed.marker', 'installed');

            return true;
        }

        public function bootAndResolve(string $hostPath, string $type): ?string
        {
            if (! is_file($hostPath.'/vendor/acme/widgets/installed.marker')) {
                return null;
            }

            file_put_contents($hostPath.'/fresh-host-verified.marker', 'fresh process result accepted');

            return 'Acme\\Widgets\\Nodes\\InstalledNode';
        }
    });

    $snapshotsBefore = glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [];

    $exitCode = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ]);

    expect($exitCode)->toBe(0, \Illuminate\Support\Facades\Artisan::output());

    expect($root.'/vendor/acme/widgets/installed.marker')->toBeFile();
    expect($root.'/fresh-host-verified.marker')->toBeFile();
    expect($root.'/packages/acme/widgets/src/Nodes/InstalledNode.php')->toBeFile();
    expect($root.'/app/Nodeflow/Nodes/InstalledNode.php')->not->toBeFile();
    expect(glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [])->toBe($snapshotsBefore);
});

it('never rolls back a verified extraction from partially deleted discard snapshots', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'committed cleanup failure',
        'CommittedCleanupNode',
        'verification.committed-cleanup',
    );
    mkdir($root.'/vendor', 0777, true);
    file_put_contents($root.'/vendor/original.txt', 'original vendor state');
    $snapshotsBefore = glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [];
    $files = new class extends \Illuminate\Filesystem\Filesystem
    {
        public bool $failSnapshotCleanup = true;

        public function deleteDirectory($directory, $preserve = false)
        {
            if ($this->failSnapshotCleanup && str_contains(basename($directory), 'nodeflow-extract-snapshot-')) {
                foreach (scandir($directory) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        parent::deleteDirectory($directory.'/'.$entry);
                        break;
                    }
                }

                return false;
            }

            return parent::deleteDirectory($directory, $preserve);
        }
    };
    $runner = new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            return true;
        }

        public function bootAndResolve(string $hostPath, string $type): ?string
        {
            return 'Acme\\Widgets\\Nodes\\CommittedCleanupNode';
        }
    };
    $this->app->setBasePath($root);
    $command = new \Nodeflow\Console\ExtractNodeCommand($files, $runner);
    $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($command);

    $exit = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ]);
    $output = \Illuminate\Support\Facades\Artisan::output();
    $retained = array_values(array_diff(
        glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [],
        $snapshotsBefore,
    ));

    try {
        expect($exit)->not->toBe(0)
            ->and($output)->toContain('installed and fresh-host verified')
            ->and($output)->toContain('committed host was NOT rolled back')
            ->and($retained)->toHaveCount(1)
            ->and(fileperms($retained[0]) & 07777)->toBe(0700)
            ->and($root.'/app/Nodeflow/Nodes/CommittedCleanupNode.php')->not->toBeFile()
            ->and($root.'/packages/acme/widgets/src/Nodes/CommittedCleanupNode.php')->toBeFile()
            ->and(file_get_contents($root.'/vendor/original.txt'))->toBe('original vendor state');
        $composer = json_decode(file_get_contents($root.'/composer.json'), true);
        expect($composer['require']['acme/widgets'] ?? null)->toBe('*');
    } finally {
        $files->failSnapshotCleanup = false;

        foreach ($retained as $path) {
            $files->deleteDirectory($path);
        }
    }
});

it('reports cleanup-only rollback accurately and still proves the restored autoloader', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'restore cleanup-only failure',
        'RestoreCleanupOnlyNode',
        'verification.restore-cleanup-only',
    );
    mkdir($root.'/vendor', 0777, true);
    file_put_contents($root.'/vendor/original.txt', 'original vendor state');
    $before = verificationTreeHash($root);
    $snapshotsBefore = glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [];
    $regenerationMarker = verificationFixtureRoot('restore cleanup-only regeneration ran');
    $files = new class extends \Illuminate\Filesystem\Filesystem
    {
        public int $snapshotCleanupFailuresRemaining = 1;

        public function deleteDirectory($directory, $preserve = false)
        {
            if ($this->snapshotCleanupFailuresRemaining > 0
                && str_contains(basename($directory), 'nodeflow-extract-snapshot-')) {
                $this->snapshotCleanupFailuresRemaining--;

                return false;
            }

            return parent::deleteDirectory($directory, $preserve);
        }
    };
    $runner = new class($regenerationMarker) extends ComposerRunner
    {
        public function __construct(private string $regenerationMarker) {}

        public function install(string $hostPath, string $packageName): bool
        {
            file_put_contents($hostPath.'/vendor/original.txt', 'partial Composer state');
            file_put_contents($hostPath.'/vendor/new-partial.txt', 'partial Composer state');

            return false;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            file_put_contents($this->regenerationMarker, 'restored autoload was proved usable');

            return true;
        }
    };
    $this->app->setBasePath($root);
    $command = new \Nodeflow\Console\ExtractNodeCommand($files, $runner);
    $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($command);

    $exit = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ]);
    $output = \Illuminate\Support\Facades\Artisan::output();
    $retained = array_values(array_diff(
        glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [],
        $snapshotsBefore,
    ));

    try {
        expect($exit)->not->toBe(0)
            ->and($output)->toContain('host was restored to its original state')
            ->and($output)->toContain('rollback storage cleanup failed')
            ->and($output)->not->toContain('partially modified')
            ->and($regenerationMarker)->toBeFile()
            ->and(verificationTreeHash($root))->toBe($before)
            ->and($retained)->toHaveCount(1)
            ->and(fileperms($retained[0]) & 07777)->toBe(0700);
    } finally {
        foreach ($retained as $path) {
            $files->deleteDirectory($path);
        }
    }
});

it('retains the cleanup recovery path when restored autoload regeneration also fails', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'restore cleanup and regeneration failure',
        'RestoreCleanupAndRegenerationFailureNode',
        'verification.restore-cleanup-and-regeneration-failure',
    );
    mkdir($root.'/vendor', 0777, true);
    file_put_contents($root.'/vendor/original.txt', 'original vendor state');
    $before = verificationTreeHash($root);
    $snapshotsBefore = glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [];
    $files = new class extends \Illuminate\Filesystem\Filesystem
    {
        public int $snapshotCleanupFailuresRemaining = 1;

        public function deleteDirectory($directory, $preserve = false)
        {
            if ($this->snapshotCleanupFailuresRemaining > 0
                && str_contains(basename($directory), 'nodeflow-extract-snapshot-')) {
                $this->snapshotCleanupFailuresRemaining--;

                return false;
            }

            return parent::deleteDirectory($directory, $preserve);
        }
    };
    $runner = new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            file_put_contents($hostPath.'/vendor/original.txt', 'partial Composer state');
            file_put_contents($hostPath.'/vendor/new-install-state.txt', 'partial Composer state');

            return false;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            file_put_contents($hostPath.'/vendor/original.txt', 'partial regenerated state');
            file_put_contents($hostPath.'/vendor/new-regenerated-state.txt', 'partial regenerated state');

            return false;
        }
    };
    $this->app->setBasePath($root);
    $command = new \Nodeflow\Console\ExtractNodeCommand($files, $runner);
    $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($command);

    $exit = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ]);
    $output = \Illuminate\Support\Facades\Artisan::output();
    $retained = array_values(array_diff(
        glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [],
        $snapshotsBefore,
    ));

    try {
        expect($exit)->not->toBe(0)
            ->and($retained)->toHaveCount(1)
            ->and($output)->toContain($retained[0])
            ->and($output)->toContain('host files were restored to their original state')
            ->and($output)->toContain('Composer autoloader could not be proved usable')
            ->and($output)->not->toContain('partially modified')
            ->and(verificationTreeHash($root))->toBe($before)
            ->and($root.'/vendor/new-install-state.txt')->not->toBeFile()
            ->and($root.'/vendor/new-regenerated-state.txt')->not->toBeFile();
    } finally {
        foreach ($retained as $path) {
            $files->deleteDirectory($path);
        }
    }
});

it('reports every retained path when both restoration journals fail cleanup', function () {
    $cases = [
        ['double cleanup usable regeneration', 'DoubleCleanupUsableNode', true],
        ['double cleanup unusable regeneration', 'DoubleCleanupUnusableNode', false],
    ];

    foreach ($cases as [$suffix, $shortClass, $regenerated]) {
        [$root, $class] = verificationWriteExtractionFixture(
            $suffix,
            $shortClass,
            'verification.'.strtolower($shortClass),
        );
        mkdir($root.'/vendor', 0777, true);
        file_put_contents($root.'/vendor/original.txt', 'original vendor state');
        $before = verificationTreeHash($root);
        $snapshotsBefore = glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [];
        $files = new class extends \Illuminate\Filesystem\Filesystem
        {
            public bool $failSnapshotCleanup = true;

            public function deleteDirectory($directory, $preserve = false)
            {
                if ($this->failSnapshotCleanup
                    && str_contains(basename($directory), 'nodeflow-extract-snapshot-')) {
                    return false;
                }

                return parent::deleteDirectory($directory, $preserve);
            }
        };
        $runner = new class($regenerated) extends ComposerRunner
        {
            public function __construct(private bool $regenerated) {}

            public function install(string $hostPath, string $packageName): bool
            {
                file_put_contents($hostPath.'/vendor/original.txt', 'partial Composer state');
                file_put_contents($hostPath.'/vendor/new-install-state.txt', 'partial Composer state');

                return false;
            }

            public function regenerateAutoload(string $hostPath): bool
            {
                file_put_contents($hostPath.'/vendor/original.txt', 'partial regenerated state');
                file_put_contents($hostPath.'/vendor/new-regenerated-state.txt', 'partial regenerated state');

                return $this->regenerated;
            }
        };
        $this->app->setBasePath($root);
        $command = new \Nodeflow\Console\ExtractNodeCommand($files, $runner);
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($command);

        $exit = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
            'class' => $class,
            '--package' => 'acme/widgets',
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $retained = array_values(array_diff(
            glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [],
            $snapshotsBefore,
        ));

        try {
            expect($exit)->not->toBe(0)
                ->and($retained)->toHaveCount(2)
                ->and($output)->not->toContain('partially modified')
                ->and(verificationTreeHash($root))->toBe($before)
                ->and($root.'/vendor/new-install-state.txt')->not->toBeFile()
                ->and($root.'/vendor/new-regenerated-state.txt')->not->toBeFile();

            foreach ($retained as $path) {
                expect($output)->toContain($path)
                    ->and(fileperms($path) & 07777)->toBe(0700);
            }

            expect($output)->toContain($regenerated
                ? 'Composer autoloader was regenerated'
                : 'Composer autoloader could not be proved usable');
        } finally {
            $files->failSnapshotCleanup = false;

            foreach ($retained as $path) {
                $files->deleteDirectory($path);
            }
        }
    }
});

it('reports the first cleanup residue when the later autoloader journal cannot undo', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'cleanup residue plus undo failure',
        'CleanupResidueAndUndoFailureNode',
        'verification.cleanup-residue-and-undo-failure',
    );
    mkdir($root.'/vendor', 0777, true);
    file_put_contents($root.'/vendor/original.txt', 'original vendor state');
    $snapshotsBefore = glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [];
    $files = new class((string) realpath($root.'/vendor')) extends \Illuminate\Filesystem\Filesystem
    {
        public bool $injectFailures = true;

        private bool $mainCleanupFailed = false;

        public function __construct(private string $hostVendor) {}

        public function deleteDirectory($directory, $preserve = false)
        {
            if ($this->injectFailures
                && ! $this->mainCleanupFailed
                && str_contains(basename($directory), 'nodeflow-extract-snapshot-')) {
                $this->mainCleanupFailed = true;

                return false;
            }

            if ($this->injectFailures && $this->mainCleanupFailed && $directory === $this->hostVendor) {
                return false;
            }

            return parent::deleteDirectory($directory, $preserve);
        }
    };
    $runner = new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            file_put_contents($hostPath.'/vendor/original.txt', 'partial Composer state');

            return false;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            file_put_contents($hostPath.'/vendor/original.txt', 'partial regenerated state');

            return true;
        }
    };
    $this->app->setBasePath($root);
    $command = new \Nodeflow\Console\ExtractNodeCommand($files, $runner);
    $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($command);

    $exit = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ]);
    $output = \Illuminate\Support\Facades\Artisan::output();
    $retained = array_values(array_diff(
        glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [],
        $snapshotsBefore,
    ));

    try {
        expect($exit)->not->toBe(0)
            ->and($output)->toContain('partially modified')
            ->and($retained)->toHaveCount(2);

        foreach ($retained as $path) {
            expect($output)->toContain($path)
                ->and(fileperms($path) & 07777)->toBe(0700);
        }
    } finally {
        $files->injectFailures = false;

        foreach ($retained as $path) {
            $files->deleteDirectory($path);
        }
    }
});

it('installs the actual versionless scaffold on a default-stability host through a path repository version alias', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'stable scaffold extraction',
        'StableScaffoldNode',
        'verification.stable-scaffold',
    );
    $composerPath = $root.'/composer.json';
    $composer = json_decode(file_get_contents($composerPath), true);
    $composer['version'] = '1.0.0';
    mkdir($root.'/packages/probe/nodeflow', 0777, true);
    file_put_contents($root.'/packages/probe/nodeflow/composer.json', json_encode([
        'name' => 'atram/laravel-nodeflow',
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $composer['repositories'] = [[
        'type' => 'path',
        'url' => 'packages/probe/nodeflow',
    ]];
    $composer['require']['atram/laravel-nodeflow'] = '^1.0';
    $composer['config'] = ['allow-plugins' => false];
    $composer['scripts'] = ['post-autoload-dump' => '@php marker.php'];
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($root.'/marker.php', <<<'PHP'
    <?php

    file_put_contents(__DIR__.'/post-autoload-dump-ran', 'ran');
    PHP);

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class extends ComposerRunner
    {
        public function bootAndResolve(string $hostPath, string $type): ?string
        {
            return 'Acme\\Widgets\\Nodes\\StableScaffoldNode';
        }
    });

    $snapshotsBefore = glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [];

    $exitCode = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ]);

    expect($exitCode)->toBe(0, \Illuminate\Support\Facades\Artisan::output());

    $hostComposer = json_decode(file_get_contents($composerPath), true);
    $packageComposer = json_decode(file_get_contents($root.'/packages/acme/widgets/composer.json'), true);
    $repository = collect($hostComposer['repositories'])
        ->first(fn (array $candidate): bool => ($candidate['url'] ?? null) === 'packages/acme/widgets');

    expect($hostComposer)->not->toHaveKey('minimum-stability');
    expect($packageComposer)->not->toHaveKey('version');
    expect($repository['options']['versions']['acme/widgets'] ?? null)->toBe('1.0.0');
    expect($root.'/vendor/acme/widgets')->toBeDirectory();
    expect($root.'/post-autoload-dump-ran')->not->toBeFile();
    expect(glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [])->toBe($snapshotsBefore);
});

it('restores all Composer state and regenerates the restored autoloader when M8 fails', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M8 failure',
        'InstallFailureNode',
        'verification.install-failure',
    );
    file_put_contents($root.'/composer.lock', 'original lock bytes');
    mkdir($root.'/vendor/composer', 0777, true);
    file_put_contents($root.'/vendor/autoload.php', 'original root autoload');
    file_put_contents($root.'/vendor/composer/installed.json', 'original installed bytes');
    file_put_contents($root.'/vendor/composer/autoload_psr4.php', 'original generated autoload');
    mkdir($root.'/vendor/existing/dependency', 0777, true);
    file_put_contents($root.'/vendor/existing/dependency/original.php', 'original dependency bytes');
    $nodePath = $root.'/app/Nodeflow/Nodes/InstallFailureNode.php';
    chmod($nodePath, 0755);

    $before = verificationTreeHash($root);
    $restoreMarker = verificationFixtureRoot('M8 restore ran');

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class($restoreMarker) extends ComposerRunner
    {
        private int $attempts = 0;

        public function __construct(private string $restoreMarker) {}

        public function install(string $hostPath, string $packageName): bool
        {
            $this->attempts++;

            if ($this->attempts !== 1) {
                throw new RuntimeException('Forward installation must not be reused for restore regeneration.');
            }

            file_put_contents($hostPath.'/composer.lock', 'partial changed lock');
            file_put_contents($hostPath.'/vendor/composer/installed.json', 'partial installed state');
            unlink($hostPath.'/vendor/composer/autoload_psr4.php');
            file_put_contents($hostPath.'/vendor/composer/new-autoload.php', 'new generated state');
            mkdir($hostPath.'/vendor/acme/widgets', 0777, true);
            file_put_contents($hostPath.'/vendor/acme/widgets/partial.marker', 'partial package');
            file_put_contents(
                $hostPath.'/vendor/existing/dependency/original.php',
                'partially updated dependency bytes',
            );
            mkdir($hostPath.'/vendor/new/transitive', 0777, true);
            file_put_contents($hostPath.'/vendor/new/transitive/partial.php', 'new transitive dependency');

            return false;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            file_put_contents($this->restoreMarker, 'restored autoload regenerated');

            return true;
        }

        public function bootAndResolve(string $hostPath, string $type): ?string
        {
            throw new RuntimeException('M9 must not run after M8 fails.');
        }
    });

    $snapshotsBefore = glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [];

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('Composer dependency installation failed')
        ->assertFailed();

    expect($restoreMarker)->toBeFile();
    expect(verificationTreeHash($root))->toBe($before);
    expect(file_get_contents($root.'/vendor/existing/dependency/original.php'))
        ->toBe('original dependency bytes');
    expect($root.'/vendor/new')->not->toBeDirectory();
    expect($root.'/vendor/acme')->not->toBeDirectory();
    expect($root.'/packages/acme/widgets')->not->toBeDirectory();
    expect($nodePath)->toBeFile();
    expect(fileperms($nodePath) & 07777)->toBe(0755);
    expect(glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [])->toBe($snapshotsBefore);
});

it('restores Composer state from the host configured vendor directory when M8 fails', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M8 custom vendor-dir failure',
        'CustomVendorFailureNode',
        'verification.custom-vendor-failure',
    );
    $composerPath = $root.'/composer.json';
    $composer = json_decode(file_get_contents($composerPath), true);
    $composer['config'] = ['vendor-dir' => 'deps with spaces'];
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $vendorPath = $root.'/deps with spaces';
    mkdir($vendorPath.'/composer', 0777, true);
    file_put_contents($vendorPath.'/autoload.php', 'original custom autoload');
    file_put_contents($vendorPath.'/composer/installed.json', 'original custom installed state');
    file_put_contents($vendorPath.'/composer/autoload_psr4.php', 'original custom generated state');
    $before = verificationTreeHash($root);

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            $vendorPath = $hostPath.'/deps with spaces';
            file_put_contents($vendorPath.'/composer/installed.json', 'partially mutated custom state');
            unlink($vendorPath.'/composer/autoload_psr4.php');
            file_put_contents($vendorPath.'/composer/new-generated.php', 'new custom generated state');
            mkdir($vendorPath.'/acme/widgets', 0777, true);
            file_put_contents($vendorPath.'/acme/widgets/partial.marker', 'partial custom package');

            return false;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            return true;
        }
    });

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('Composer dependency installation failed')
        ->assertFailed();

    expect(verificationTreeHash($root))->toBe($before);
    expect($vendorPath.'/composer/autoload_psr4.php')->toBeFile();
    expect($vendorPath.'/composer/new-generated.php')->not->toBeFile();
    expect($vendorPath.'/acme')->not->toBeDirectory();
});

it('restores the in-host target of a symlinked Composer vendor directory when M8 fails', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M8 symlinked vendor-dir failure',
        'SymlinkedVendorFailureNode',
        'verification.symlinked-vendor-failure',
    );
    $composerPath = $root.'/composer.json';
    $composer = json_decode(file_get_contents($composerPath), true);
    $composer['config'] = ['vendor-dir' => 'deps-link'];
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $realVendor = $root.'/storage/vendor-real';
    mkdir($realVendor.'/composer', 0777, true);
    file_put_contents($realVendor.'/autoload.php', 'original symlinked autoload');
    file_put_contents($realVendor.'/composer/installed.json', 'original symlinked installed state');
    symlink('storage/vendor-real', $root.'/deps-link');
    $before = verificationTreeHash($root);

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            file_put_contents($hostPath.'/deps-link/composer/installed.json', 'partial symlink mutation');
            mkdir($hostPath.'/deps-link/acme/widgets', 0777, true);

            return false;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            return true;
        }
    });

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ])->assertFailed();

    expect(verificationTreeHash($root))->toBe($before);
    expect($root.'/deps-link')->toBeDirectory();
    expect(is_link($root.'/deps-link'))->toBeTrue();
    expect(file_get_contents($realVendor.'/composer/installed.json'))
        ->toBe('original symlinked installed state');
    expect($realVendor.'/acme')->not->toBeDirectory();
});

it('refuses a Composer vendor directory outside the host before M8 can run', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M8 escaping vendor-dir',
        'EscapingVendorNode',
        'verification.escaping-vendor',
    );
    $externalVendor = verificationFixtureRoot('external configured vendor');
    $composerPath = $root.'/composer.json';
    $composer = json_decode(file_get_contents($composerPath), true);
    $composer['config'] = ['vendor-dir' => $externalVendor];
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    mkdir($root.'/stubs/package', 0777, true);
    file_put_contents($root.'/stubs/package/provider.stub', '<?php this M1 stub does not parse');
    $before = verificationTreeHash($root);

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            throw new RuntimeException('unsafe external Composer install was reached');
        }
    });

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('must be a relative path inside the project')
        ->assertFailed();

    expect(verificationTreeHash($root))->toBe($before);
    expect($externalVendor)->not->toBeDirectory();
    expect($root.'/packages/acme/widgets')->not->toBeDirectory();
});

it('restores Composer binaries from an in-host custom bin directory after M8 and regeneration fail', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M8 custom bin-dir failure',
        'CustomBinFailureNode',
        'verification.custom-bin-failure',
    );
    $composerPath = $root.'/composer.json';
    $composer = json_decode(file_get_contents($composerPath), true);
    $composer['config'] = ['bin-dir' => 'tools/bin'];
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    mkdir($root.'/tools/bin', 0777, true);
    file_put_contents($root.'/tools/bin/original-tool', '#!/bin/sh'.PHP_EOL.'exit 0');
    chmod($root.'/tools/bin/original-tool', 0755);
    $before = verificationTreeHash($root);

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            file_put_contents($hostPath.'/tools/bin/original-tool', 'partial forward proxy');
            file_put_contents($hostPath.'/tools/bin/new-forward-tool', 'partial forward proxy');

            return false;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            file_put_contents($hostPath.'/tools/bin/original-tool', 'partial regeneration proxy');
            file_put_contents($hostPath.'/tools/bin/new-regeneration-tool', 'partial regeneration proxy');

            return true;
        }
    });

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ])->assertFailed();

    expect(verificationTreeHash($root))->toBe($before);
    expect(file_get_contents($root.'/tools/bin/original-tool'))->toContain('exit 0');
    expect(fileperms($root.'/tools/bin/original-tool') & 07777)->toBe(0755);
    expect($root.'/tools/bin/new-forward-tool')->not->toBeFile();
    expect($root.'/tools/bin/new-regeneration-tool')->not->toBeFile();
});

it('refuses a Composer bin directory outside the host during G8 before any package writes', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M8 escaping bin-dir',
        'EscapingBinNode',
        'verification.escaping-bin',
    );
    $externalBin = verificationFixtureRoot('external configured bin');
    $composerPath = $root.'/composer.json';
    $composer = json_decode(file_get_contents($composerPath), true);
    $composer['config'] = ['bin-dir' => $externalBin];
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    mkdir($root.'/stubs/package', 0777, true);
    file_put_contents($root.'/stubs/package/provider.stub', '<?php this M1 stub does not parse');
    $before = verificationTreeHash($root);

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            throw new RuntimeException('unsafe external Composer bin install was reached');
        }
    });

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('configured bin directory is unsafe')
        ->assertFailed();

    expect(verificationTreeHash($root))->toBe($before);
    expect($externalBin)->not->toBeDirectory();
    expect($root.'/packages/acme/widgets')->not->toBeDirectory();
});

it('refuses Windows drive and UNC Composer paths during G8 on every operating system', function () {
    $cases = [
        ['M8 Windows drive vendor-dir', 'WindowsDrivePathNode', 'vendor-dir', 'C:\\outside\\vendor'],
        ['M8 Windows drive-relative vendor-dir', 'WindowsDriveRelativePathNode', 'vendor-dir', 'C:relative\\vendor'],
        ['M8 Windows UNC bin-dir', 'WindowsUncPathNode', 'bin-dir', '\\\\server\\share\\bin'],
    ];

    foreach ($cases as [$suffix, $shortClass, $key, $configuredPath]) {
        [$root, $class] = verificationWriteExtractionFixture(
            $suffix,
            $shortClass,
            'verification.'.strtolower($shortClass),
        );
        $composerPath = $root.'/composer.json';
        $composer = json_decode(file_get_contents($composerPath), true);
        $composer['config'] = [$key => $configuredPath];
        file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        mkdir($root.'/stubs/package', 0777, true);
        file_put_contents($root.'/stubs/package/provider.stub', '<?php this M1 stub does not parse');
        $before = verificationTreeHash($root);
        $this->app->setBasePath($root);

        $exitCode = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
            'class' => $class,
            '--package' => 'acme/widgets',
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('configured '.($key === 'vendor-dir' ? 'vendor' : 'bin').' directory is unsafe');
        expect($output)->toContain('Windows drive-qualified/UNC path');
        expect(verificationTreeHash($root))->toBe($before);
        expect($root.'/packages/acme/widgets')->not->toBeDirectory();
    }
});

it('restores an in-host composer lock symlink and its target after M8 and regeneration fail', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M8 symlinked lock failure',
        'SymlinkedLockFailureNode',
        'verification.symlinked-lock-failure',
    );
    mkdir($root.'/storage', 0777, true);
    file_put_contents($root.'/storage/real-composer.lock', 'original lock target');
    symlink('storage/real-composer.lock', $root.'/composer.lock');
    $before = verificationTreeHash($root);

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            file_put_contents($hostPath.'/composer.lock', 'partial forward lock target');

            return false;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            file_put_contents($hostPath.'/composer.lock', 'partial regeneration lock target');

            return true;
        }
    });

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ])->assertFailed();

    expect(verificationTreeHash($root))->toBe($before);
    expect(is_link($root.'/composer.lock'))->toBeTrue();
    expect(readlink($root.'/composer.lock'))->toBe('storage/real-composer.lock');
    expect(file_get_contents($root.'/storage/real-composer.lock'))->toBe('original lock target');
});

it('removes a final file Composer creates through a dangling in-host multi-hop lock chain', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M8 dangling in-host lock failure',
        'DanglingInHostLockFailureNode',
        'verification.dangling-in-host-lock-failure',
    );
    mkdir($root.'/storage', 0777, true);
    symlink('storage/new-composer.lock', $root.'/hop-two');
    symlink('hop-two', $root.'/composer.lock');
    $before = verificationTreeHash($root);

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            file_put_contents($hostPath.'/composer.lock', 'partial forward lock target');

            return false;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            file_put_contents($hostPath.'/composer.lock', 'partial regeneration lock target');

            return true;
        }
    });

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ])->assertFailed();

    expect(verificationTreeHash($root))->toBe($before)
        ->and(is_link($root.'/composer.lock'))->toBeTrue()
        ->and(readlink($root.'/composer.lock'))->toBe('hop-two')
        ->and(is_link($root.'/hop-two'))->toBeTrue()
        ->and(readlink($root.'/hop-two'))->toBe('storage/new-composer.lock')
        ->and($root.'/storage/new-composer.lock')->not->toBeFile();
});

it('refuses M8 when composer lock presence changed after the state G8 recorded', function () {
    $root = verificationFixtureRoot('M8 lock state changed');
    verificationDeleteTree($root);
    mkdir($root, 0777, true);
    file_put_contents($root.'/composer.json', json_encode(['name' => 'probe/host']));
    file_put_contents($root.'/composer.lock', 'appeared after G8');
    $runner = new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            file_put_contents($hostPath.'/m8-install-was-reached', 'unsafe');

            return true;
        }
    };
    $this->app->setBasePath($root);
    $command = new \Nodeflow\Console\ExtractNodeCommand(new \Illuminate\Filesystem\Filesystem, $runner);
    $command->setLaravel($this->app);
    $recorded = new ReflectionProperty($command, 'composerLockExisted');
    $recorded->setValue($command, false);
    $journal = new \Nodeflow\Console\Extract\ExtractJournal(new \Illuminate\Filesystem\Filesystem);
    $attempted = false;
    $method = new ReflectionMethod($command, 'installDependency');

    try {
        $method->invokeArgs($command, [$root, 'acme/widgets', $journal, &$attempted]);
        $failure = null;
    } catch (RuntimeException $e) {
        $failure = $e;
    } finally {
        $journal->restore();
    }

    expect($failure)->toBeInstanceOf(RuntimeException::class)
        ->and($failure?->getMessage())->toContain('composer.lock presence changed after G8')
        ->and($attempted)->toBeFalse()
        ->and($root.'/m8-install-was-reached')->not->toBeFile()
        ->and(file_get_contents($root.'/composer.lock'))->toBe('appeared after G8');
});

it('refuses an escaping composer lock symlink during G8 before any package writes', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M8 escaping lock',
        'EscapingLockNode',
        'verification.escaping-lock',
    );
    $external = verificationFixtureRoot('external composer lock');
    verificationDeleteTree($external);
    mkdir($external, 0777, true);
    file_put_contents($external.'/composer.lock', 'external lock bytes');
    symlink($external.'/composer.lock', $root.'/composer.lock');
    mkdir($root.'/stubs/package', 0777, true);
    file_put_contents($root.'/stubs/package/provider.stub', '<?php this M1 stub does not parse');
    $before = verificationTreeHash($root);
    $externalBefore = verificationTreeHash($external);

    $this->app->setBasePath($root);

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('composer.lock symlink is unsafe')
        ->assertFailed();

    expect(verificationTreeHash($root))->toBe($before);
    expect(verificationTreeHash($external))->toBe($externalBefore);
    expect($root.'/packages/acme/widgets')->not->toBeDirectory();
});

it('refuses dangling multi-hop cyclic and portable-absolute lock symlinks during G8', function () {
    $external = verificationFixtureRoot('external dangling composer lock');
    verificationDeleteTree($external);
    mkdir($external, 0777, true);

    $cases = [
        ['M8 dangling multi-hop lock', 'DanglingMultiHopLockNode', static function (string $root) use ($external): void {
            symlink($external.'/missing.lock', $root.'/hop-two');
            symlink('hop-two', $root.'/composer.lock');
        }],
        ['M8 cyclic lock', 'CyclicLockNode', static function (string $root): void {
            symlink('hop-two', $root.'/composer.lock');
            symlink('composer.lock', $root.'/hop-two');
        }],
        ['M8 Windows drive lock target', 'WindowsDriveLockTargetNode', static function (string $root): void {
            symlink('C:\\outside\\composer.lock', $root.'/composer.lock');
        }],
        ['M8 Windows drive-relative lock target', 'WindowsDriveRelativeLockTargetNode', static function (string $root): void {
            symlink('C:relative\\composer.lock', $root.'/composer.lock');
        }],
        ['M8 Windows UNC lock target', 'WindowsUncLockTargetNode', static function (string $root): void {
            symlink('\\\\server\\share\\composer.lock', $root.'/composer.lock');
        }],
    ];

    foreach ($cases as [$suffix, $shortClass, $makeLinks]) {
        [$root, $class] = verificationWriteExtractionFixture(
            $suffix,
            $shortClass,
            'verification.'.strtolower($shortClass),
        );
        $makeLinks($root);
        mkdir($root.'/stubs/package', 0777, true);
        file_put_contents($root.'/stubs/package/provider.stub', '<?php this M1 stub does not parse');
        $before = verificationTreeHash($root);
        $externalBefore = verificationTreeHash($external);
        $this->app->setBasePath($root);

        $exit = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
            'class' => $class,
            '--package' => 'acme/widgets',
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        expect($exit)->not->toBe(0)
            ->and($output)->toContain('composer.lock symlink is unsafe')
            ->and(verificationTreeHash($root))->toBe($before)
            ->and(verificationTreeHash($external))->toBe($externalBefore)
            ->and($external.'/missing.lock')->not->toBeFile()
            ->and($root.'/packages/acme/widgets')->not->toBeDirectory();
    }
});

it('restores in-host targets of generated Composer state symlinks after M8 and regeneration fail', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M8 generated symlink failure',
        'GeneratedSymlinkFailureNode',
        'verification.generated-symlink-failure',
    );
    mkdir($root.'/vendor', 0777, true);
    mkdir($root.'/storage/composer-state', 0777, true);
    file_put_contents($root.'/storage/autoload.php', 'original autoload target');
    file_put_contents($root.'/storage/composer-state/installed.json', 'original installed target');
    symlink('../storage/autoload.php', $root.'/vendor/autoload.php');
    symlink('../storage/composer-state', $root.'/vendor/composer');
    $before = verificationTreeHash($root);

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            file_put_contents($hostPath.'/vendor/autoload.php', 'partial forward autoload');
            file_put_contents($hostPath.'/vendor/composer/installed.json', 'partial forward installed');

            return false;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            file_put_contents($hostPath.'/vendor/autoload.php', 'partial regeneration autoload');
            file_put_contents($hostPath.'/vendor/composer/installed.json', 'partial regeneration installed');

            return true;
        }
    });

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ])->assertFailed();

    expect(verificationTreeHash($root))->toBe($before)
        ->and(is_link($root.'/vendor/autoload.php'))->toBeTrue()
        ->and(is_link($root.'/vendor/composer'))->toBeTrue()
        ->and(file_get_contents($root.'/storage/autoload.php'))->toBe('original autoload target')
        ->and(file_get_contents($root.'/storage/composer-state/installed.json'))->toBe('original installed target');
});

it('refuses escaping generated Composer state symlinks during G8 before M1', function () {
    $cases = [
        ['M8 escaping autoload symlink', 'EscapingAutoloadSymlinkNode', 'autoload'],
        ['M8 escaping composer subtree', 'EscapingComposerSubtreeNode', 'composer'],
    ];

    foreach ($cases as [$suffix, $shortClass, $kind]) {
        [$root, $class] = verificationWriteExtractionFixture(
            $suffix,
            $shortClass,
            'verification.'.strtolower($shortClass),
        );
        $external = verificationFixtureRoot('external generated composer state');
        verificationDeleteTree($external);
        mkdir($external, 0777, true);
        mkdir($root.'/vendor', 0777, true);

        if ($kind === 'autoload') {
            file_put_contents($external.'/autoload.php', 'external autoload');
            symlink($external.'/autoload.php', $root.'/vendor/autoload.php');
        } else {
            mkdir($external.'/composer', 0777, true);
            file_put_contents($external.'/composer/installed.json', 'external installed state');
            symlink($external.'/composer', $root.'/vendor/composer');
        }

        mkdir($root.'/stubs/package', 0777, true);
        file_put_contents($root.'/stubs/package/provider.stub', '<?php this M1 stub does not parse');
        $before = verificationTreeHash($root);
        $externalBefore = verificationTreeHash($external);
        $this->app->setBasePath($root);

        $exit = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
            'class' => $class,
            '--package' => 'acme/widgets',
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        expect($exit)->not->toBe(0)
            ->and($output)->toContain('generated Composer state symlink is unsafe')
            ->and(verificationTreeHash($root))->toBe($before)
            ->and(verificationTreeHash($external))->toBe($externalBefore)
            ->and($root.'/packages/acme/widgets')->not->toBeDirectory();
    }
});

it('refuses effective Laravel cache paths outside the host during G8 before M1', function () {
    $cases = [
        ['M9 external packages cache', 'ExternalPackagesCacheNode', 'APP_PACKAGES_CACHE', 'packages'],
        ['M9 external services cache', 'ExternalServicesCacheNode', 'APP_SERVICES_CACHE', 'services'],
    ];

    foreach ($cases as [$suffix, $shortClass, $environmentKey, $label]) {
        [$root, $class] = verificationWriteExtractionFixture(
            $suffix,
            $shortClass,
            'verification.'.strtolower($shortClass),
        );
        $external = verificationFixtureRoot('external Laravel cache');
        verificationDeleteTree($external);
        mkdir($external, 0777, true);
        $externalPath = $external.'/'.$label.'.php';
        file_put_contents($externalPath, 'external cache bytes');
        mkdir($root.'/stubs/package', 0777, true);
        file_put_contents($root.'/stubs/package/provider.stub', '<?php this M1 stub does not parse');
        $before = verificationTreeHash($root);
        $externalBefore = verificationTreeHash($external);
        \Illuminate\Support\Env::getRepository()->set($environmentKey, $externalPath);
        $this->app->setBasePath($root);

        try {
            $exit = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
                'class' => $class,
                '--package' => 'acme/widgets',
            ]);
            $output = \Illuminate\Support\Facades\Artisan::output();
        } finally {
            \Illuminate\Support\Env::getRepository()->clear($environmentKey);
        }

        expect($exit)->not->toBe(0)
            ->and($output)->toContain("Laravel's {$label} cache path is unsafe")
            ->and(verificationTreeHash($root))->toBe($before)
            ->and(verificationTreeHash($external))->toBe($externalBefore)
            ->and($root.'/packages/acme/widgets')->not->toBeDirectory();
    }
});

it('refuses Windows drive and UNC Laravel cache overrides during G8 on every operating system', function () {
    $cases = [
        ['M9 Windows packages cache', 'WindowsPackagesCacheNode', 'APP_PACKAGES_CACHE', 'packages', 'C:\\outside\\packages.php'],
        ['M9 Windows drive-relative packages cache', 'WindowsDriveRelativePackagesCacheNode', 'APP_PACKAGES_CACHE', 'packages', 'C:relative\\packages.php'],
        ['M9 UNC services cache', 'UncServicesCacheNode', 'APP_SERVICES_CACHE', 'services', '\\\\server\\share\\services.php'],
    ];

    foreach ($cases as [$suffix, $shortClass, $environmentKey, $label, $configuredPath]) {
        [$root, $class] = verificationWriteExtractionFixture(
            $suffix,
            $shortClass,
            'verification.'.strtolower($shortClass),
        );
        mkdir($root.'/stubs/package', 0777, true);
        file_put_contents($root.'/stubs/package/provider.stub', '<?php this M1 stub does not parse');
        $before = verificationTreeHash($root);
        \Illuminate\Support\Env::getRepository()->set($environmentKey, $configuredPath);
        $this->app->setBasePath($root);

        try {
            $exit = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
                'class' => $class,
                '--package' => 'acme/widgets',
            ]);
            $output = \Illuminate\Support\Facades\Artisan::output();
        } finally {
            \Illuminate\Support\Env::getRepository()->clear($environmentKey);
        }

        expect($exit)->not->toBe(0)
            ->and($output)->toContain("Laravel's {$label} cache path is unsafe")
            ->and($output)->toContain('Windows drive-qualified/UNC path')
            ->and(verificationTreeHash($root))->toBe($before)
            ->and($root.'/packages/acme/widgets')->not->toBeDirectory();
    }
});

it('restores effective in-host custom Laravel caches changed by a failed M9 boot', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M9 custom cache rollback',
        'CustomCacheRollbackNode',
        'verification.custom-cache-rollback',
    );
    $cacheDirectory = $root.'/storage/framework/custom-cache';
    mkdir($cacheDirectory, 0777, true);
    $packagesPath = $cacheDirectory.'/packages.php';
    $servicesPath = $cacheDirectory.'/services.php';
    file_put_contents($packagesPath, 'original custom packages cache');
    file_put_contents($servicesPath, 'original custom services cache');
    chmod($packagesPath, 0600);
    chmod($servicesPath, 0640);
    $before = verificationTreeHash($root);
    \Illuminate\Support\Env::getRepository()->set('APP_PACKAGES_CACHE', $packagesPath);
    \Illuminate\Support\Env::getRepository()->set('APP_SERVICES_CACHE', $servicesPath);
    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class($packagesPath, $servicesPath) extends ComposerRunner
    {
        public function __construct(private string $packagesPath, private string $servicesPath) {}

        public function install(string $hostPath, string $packageName): bool
        {
            return true;
        }

        public function bootAndResolve(string $hostPath, string $type): ?string
        {
            file_put_contents($this->packagesPath, 'rebuilt custom packages cache');
            file_put_contents($this->servicesPath, 'rebuilt custom services cache');

            return null;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            return true;
        }
    });

    try {
        $this->artisan('nodeflow:extract-node', [
            'class' => $class,
            '--package' => 'acme/widgets',
        ])
            ->expectsOutputToContain('discovery/type verification')
            ->assertFailed();
    } finally {
        \Illuminate\Support\Env::getRepository()->clear('APP_PACKAGES_CACHE');
        \Illuminate\Support\Env::getRepository()->clear('APP_SERVICES_CACHE');
    }

    expect(verificationTreeHash($root))->toBe($before)
        ->and(file_get_contents($packagesPath))->toBe('original custom packages cache')
        ->and(file_get_contents($servicesPath))->toBe('original custom services cache')
        ->and(fileperms($packagesPath) & 07777)->toBe(0600)
        ->and(fileperms($servicesPath) & 07777)->toBe(0640);
});

it('restores lexical links and distinct in-host targets for effective Laravel caches', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M9 symlinked custom cache rollback',
        'SymlinkedCustomCacheNode',
        'verification.symlinked-custom-cache',
    );
    mkdir($root.'/storage/framework/cache-links', 0777, true);
    mkdir($root.'/storage/framework/cache-targets', 0777, true);
    $packagesPath = $root.'/storage/framework/cache-links/packages.php';
    $servicesPath = $root.'/storage/framework/cache-links/services.php';
    $packagesTarget = $root.'/storage/framework/cache-targets/packages.php';
    $servicesTarget = $root.'/storage/framework/cache-targets/services.php';
    file_put_contents($packagesTarget, 'original linked packages cache');
    file_put_contents($servicesTarget, 'original linked services cache');
    symlink('../cache-targets/packages.php', $packagesPath);
    symlink('../cache-targets/services.php', $servicesPath);
    $before = verificationTreeHash($root);
    \Illuminate\Support\Env::getRepository()->set('APP_PACKAGES_CACHE', $packagesPath);
    \Illuminate\Support\Env::getRepository()->set('APP_SERVICES_CACHE', $servicesPath);
    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class($packagesPath, $servicesPath) extends ComposerRunner
    {
        public function __construct(private string $packagesPath, private string $servicesPath) {}

        public function install(string $hostPath, string $packageName): bool
        {
            return true;
        }

        public function bootAndResolve(string $hostPath, string $type): ?string
        {
            file_put_contents($this->packagesPath, 'changed linked packages cache');
            file_put_contents($this->servicesPath, 'changed linked services cache');

            return null;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            return true;
        }
    });

    try {
        $this->artisan('nodeflow:extract-node', [
            'class' => $class,
            '--package' => 'acme/widgets',
        ])->assertFailed();
    } finally {
        \Illuminate\Support\Env::getRepository()->clear('APP_PACKAGES_CACHE');
        \Illuminate\Support\Env::getRepository()->clear('APP_SERVICES_CACHE');
    }

    expect(verificationTreeHash($root))->toBe($before)
        ->and(is_link($packagesPath))->toBeTrue()
        ->and(is_link($servicesPath))->toBeTrue()
        ->and(readlink($packagesPath))->toBe('../cache-targets/packages.php')
        ->and(readlink($servicesPath))->toBe('../cache-targets/services.php')
        ->and(file_get_contents($packagesTarget))->toBe('original linked packages cache')
        ->and(file_get_contents($servicesTarget))->toBe('original linked services cache');
});

it('refuses mutable host files whose symlink targets escape before M1', function () {
    $cases = [
        ['M5 escaping host provider', 'EscapingHostProviderNode', 'provider'],
        ['M6 escaping composer json', 'EscapingComposerJsonNode', 'composer'],
    ];

    foreach ($cases as [$suffix, $shortClass, $kind]) {
        [$root, $class] = verificationWriteExtractionFixture(
            $suffix,
            $shortClass,
            'verification.'.strtolower($shortClass),
        );
        $external = verificationFixtureRoot('external mutable host file');
        verificationDeleteTree($external);
        mkdir($external, 0777, true);

        if ($kind === 'composer') {
            rename($root.'/composer.json', $external.'/composer.json');
            symlink($external.'/composer.json', $root.'/composer.json');
        } else {
            mkdir($root.'/app/Providers', 0777, true);
            file_put_contents($external.'/NodeflowServiceProvider.php', <<<PHP
            <?php

            namespace App\Providers;

            use {$class};

            class NodeflowServiceProvider extends \Illuminate\Support\ServiceProvider
            {
                protected array \$nodes = [
                    {$shortClass}::class,
                ];
            }
            PHP);
            symlink($external.'/NodeflowServiceProvider.php', $root.'/app/Providers/NodeflowServiceProvider.php');
        }

        mkdir($root.'/stubs/package', 0777, true);
        file_put_contents($root.'/stubs/package/provider.stub', '<?php this M1 stub does not parse');
        $before = verificationTreeHash($root);
        $externalBefore = verificationTreeHash($external);
        $this->app->setBasePath($root);

        $exit = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
            'class' => $class,
            '--package' => 'acme/widgets',
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        expect($exit)->not->toBe(0)
            ->and($output)->toContain(($kind === 'composer' ? 'composer.json' : 'host provider').' symlink is unsafe')
            ->and(verificationTreeHash($root))->toBe($before)
            ->and(verificationTreeHash($external))->toBe($externalBefore)
            ->and($root.'/packages/acme/widgets')->not->toBeDirectory();
    }
});

it('preserves an E43 matching pre-existing package exactly when M8 fails', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M8 matching package failure',
        'MatchingPackageFailureNode',
        'verification.matching-package-failure',
    );
    $packagePath = $root.'/packages/acme/widgets';
    mkdir($packagePath.'/kept', 0777, true);
    file_put_contents($packagePath.'/composer.json', json_encode([
        'name' => 'acme/widgets',
        'description' => 'pre-existing matching package',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($packagePath.'/kept/original.txt', 'pre-existing package bytes');
    $before = verificationTreeHash($root);

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            mkdir($hostPath.'/vendor/acme/widgets', 0777, true);
            file_put_contents($hostPath.'/vendor/acme/widgets/partial.marker', 'partial install');

            return false;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            return true;
        }
    });

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ])->assertFailed();

    expect(verificationTreeHash($root))->toBe($before);
    expect($packagePath)->toBeDirectory();
    expect(file_get_contents($packagePath.'/kept/original.txt'))->toBe('pre-existing package bytes');
    expect($root.'/app/Nodeflow/Nodes/MatchingPackageFailureNode.php')->toBeFile();
    expect($root.'/vendor')->not->toBeDirectory();
});

it('restores an E43 force-overwritten foreign package exactly when M8 fails', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M8 forced package failure',
        'ForcedPackageFailureNode',
        'verification.forced-package-failure',
    );
    $packagePath = $root.'/packages/acme/widgets';
    mkdir($packagePath.'/foreign', 0777, true);
    file_put_contents($packagePath.'/composer.json', json_encode([
        'name' => 'someone/else',
        'description' => 'foreign occupied package',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($packagePath.'/foreign/original.txt', 'foreign package bytes');
    $before = verificationTreeHash($root);

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            return false;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            return true;
        }
    });

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
        '--force' => true,
    ])->assertFailed();

    expect(verificationTreeHash($root))->toBe($before);
    expect(json_decode(file_get_contents($packagePath.'/composer.json'), true)['name'])
        ->toBe('someone/else');
    expect(file_get_contents($packagePath.'/foreign/original.txt'))->toBe('foreign package bytes');
    expect($root.'/app/Nodeflow/Nodes/ForcedPackageFailureNode.php')->toBeFile();
    expect($root.'/vendor')->not->toBeDirectory();
});

it('reports a degraded restore when Composer cannot regenerate the restored autoloader', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'restore regeneration failure',
        'RestoreRegenerationFailureNode',
        'verification.restore-regeneration-failure',
    );
    mkdir($root.'/vendor/composer', 0777, true);
    file_put_contents($root.'/vendor/autoload.php', 'original autoload');
    file_put_contents($root.'/vendor/composer/installed.json', 'original installed state');
    $before = verificationTreeHash($root);

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class extends ComposerRunner
    {
        public function install(string $hostPath, string $packageName): bool
        {
            file_put_contents($hostPath.'/vendor/composer/installed.json', 'partial installed state');

            return false;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            file_put_contents($hostPath.'/vendor/autoload.php', 'partially regenerated autoload');
            file_put_contents($hostPath.'/vendor/composer/new-during-regeneration.php', 'partial');

            return false;
        }
    });

    $exitCode = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ]);
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->not->toBe(0);
    expect($output)->toContain('restoring the host also failed');
    expect($output)->toContain('Composer could not regenerate');
    expect($output)->toContain('restored host autoloader');
    expect($output)->not->toContain('the host has been restored to its original state');

    expect(verificationTreeHash($root))->toBe($before);
    expect($root.'/app/Nodeflow/Nodes/RestoreRegenerationFailureNode.php')->toBeFile();
    expect($root.'/packages/acme/widgets')->not->toBeDirectory();
});

it('aborts and restores when a fresh host boot does not discover the package provider', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M9 undiscovered',
        'UndiscoveredNode',
        'verification.undiscovered',
    );
    mkdir($root.'/bootstrap/cache', 0777, true);
    file_put_contents($root.'/bootstrap/cache/packages.php', 'original stale package manifest');
    file_put_contents($root.'/bootstrap/cache/services.php', 'original services manifest');

    $before = verificationTreeHash($root);
    $restoreMarker = verificationFixtureRoot('M9 restore ran');

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class($restoreMarker) extends ComposerRunner
    {
        public function __construct(private string $restoreMarker) {}

        public function install(string $hostPath, string $packageName): bool
        {
            mkdir($hostPath.'/vendor/acme/widgets', 0777, true);
            file_put_contents($hostPath.'/vendor/acme/widgets/installed.marker', 'installed');

            return true;
        }

        public function bootAndResolve(string $hostPath, string $type): ?string
        {
            file_put_contents($hostPath.'/bootstrap/cache/packages.php', 'rebuilt without provider');
            file_put_contents($hostPath.'/bootstrap/cache/services.php', 'recompiled services');

            return null;
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            file_put_contents($this->restoreMarker, 'restored autoload regenerated');

            return true;
        }
    });

    $exitCode = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ]);
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->not->toBe(0);
    expect($output)->toContain('package discovery');
    expect($output)->toContain('did not register');

    expect($restoreMarker)->toBeFile();
    expect(verificationTreeHash($root))->toBe($before);
    expect($root.'/app/Nodeflow/Nodes/UndiscoveredNode.php')->toBeFile();
    expect($root.'/packages/acme/widgets')->not->toBeDirectory();
    expect($root.'/vendor')->not->toBeDirectory();
    expect(file_get_contents($root.'/bootstrap/cache/packages.php'))->toBe('original stale package manifest');
    expect(file_get_contents($root.'/bootstrap/cache/services.php'))->toBe('original services manifest');
});

it('uses a real Composer install and fresh Laravel boot to abort and restore when the scaffold omits provider discovery', function () {
    $root = verificationWriteLaravelHostFixture('M9 real undiscovered');
    $composerPath = $root.'/composer.json';
    $composer = json_decode(file_get_contents($composerPath), true);
    $composer['autoload'] = ['psr-4' => ['App\\' => 'app/']];
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    mkdir($root.'/app/Nodeflow/Nodes', 0777, true);
    $nodePath = $root.'/app/Nodeflow/Nodes/RealUndiscoveredNode.php';
    file_put_contents($nodePath, <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    final class RealUndiscoveredNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'verification.real-undiscovered';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('Real undiscovered');
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            return $context->continue();
        }
    }
    PHP);
    require $nodePath;

    // A host-owned scaffold override is a real package shape whose provider
    // exists but is intentionally absent from extra.laravel.providers. This
    // is distinct from G6's read-only dont-discover refusal: it reaches M8,
    // installs through real Composer, then lets M9's fresh boot prove that
    // discovery did not register the node.
    mkdir($root.'/stubs/package', 0777, true);
    file_put_contents($root.'/stubs/package/composer.json.stub', <<<'JSON'
    {
        "name": {{ name }},
        "description": {{ description }},
        "type": "library",
        "license": "MIT",
        "require": {
            "php": "^8.3",
            "atram/laravel-nodeflow": {{ nodeflowConstraint }}
        },
        "autoload": {
            "psr-4": {
                {{ namespaceKey }}: "src/"
            }
        },
        "autoload-dev": {
            "psr-4": {
                "Tests\\": "tests/"
            }
        }
    }
    JSON);

    expect($composer['extra']['laravel']['dont-discover'] ?? [])->toBe([]);
    $before = verificationTreeHash($root);
    $snapshotsBefore = glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [];
    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new ComposerRunner);

    $exitCode = \Illuminate\Support\Facades\Artisan::call('nodeflow:extract-node', [
        'class' => 'App\\Nodeflow\\Nodes\\RealUndiscoveredNode',
        '--package' => 'acme/widgets',
    ]);
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->not->toBe(0);
    expect($output)->toContain('package discovery');
    expect($output)->toContain('did not register');
    expect(verificationTreeHash($root))->toBe($before);
    expect($nodePath)->toBeFile();
    expect($root.'/packages/acme/widgets')->not->toBeDirectory();
    expect($root.'/post-autoload-dump-ran')->not->toBeFile();
    expect(verificationClassExists($root, 'Probe\\Discovered\\Nodes\\MovedNode'))->toBeTrue();
    expect(glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [])->toBe($snapshotsBefore);
});

it('aborts and restores when the fresh registry maps the type to a different class', function () {
    [$root, $class] = verificationWriteExtractionFixture(
        'M9 mismatch',
        'MismatchNode',
        'verification.mismatch',
    );
    $before = verificationTreeHash($root);
    $restoreMarker = verificationFixtureRoot('M9 mismatch restore ran');

    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new class($restoreMarker) extends ComposerRunner
    {
        public function __construct(private string $restoreMarker) {}

        public function install(string $hostPath, string $packageName): bool
        {
            return true;
        }

        public function bootAndResolve(string $hostPath, string $type): ?string
        {
            return 'Someone\\Else\\WrongNode';
        }

        public function regenerateAutoload(string $hostPath): bool
        {
            file_put_contents($this->restoreMarker, 'regenerated');

            return true;
        }
    });

    $this->artisan('nodeflow:extract-node', [
        'class' => $class,
        '--package' => 'acme/widgets',
    ])
        ->expectsOutputToContain('Someone\\Else\\WrongNode')
        ->assertFailed();

    expect($restoreMarker)->toBeFile();
    expect(verificationTreeHash($root))->toBe($before);
});

it('aborts and restores when a G3-bypassed static class type drifts after the move', function () {
    $root = verificationWriteLaravelHostFixture('M9 type drift');
    $composerPath = $root.'/composer.json';
    $composer = json_decode(file_get_contents($composerPath), true);
    $composer['autoload'] = ['psr-4' => ['App\\' => 'app/']];
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    mkdir($root.'/app/Nodeflow/Nodes', 0777, true);
    $nodePath = $root.'/app/Nodeflow/Nodes/StaticClassDriftNode.php';
    file_put_contents($nodePath, <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    final class StaticClassDriftNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return static::class;
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('Drift');
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            return $context->continue();
        }
    }
    PHP);

    $dump = verificationRun(['composer', 'dump-autoload', '--no-scripts', '--no-interaction'], $root);
    expect($dump['exit'])->toBe(0, $dump['output']);

    require $nodePath;
    $oldClass = 'App\\Nodeflow\\Nodes\\StaticClassDriftNode';
    expect($oldClass::type())->toBe($oldClass);
    $before = verificationTreeHash($root);

    // This test deliberately invokes performMoves() without handle()/G8,
    // so point the already-booted Application at the fixture explicitly;
    // ordinary command execution already has this host Application.
    $this->app->setBasePath($root);
    $command = new TypeDriftBypassExtractCommand(
        $this->app->make(\Illuminate\Filesystem\Filesystem::class),
        new ComposerRunner,
        $root,
        $oldClass,
        $oldClass,
    );
    $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($command);

    $this->artisan('nodeflow:test-type-drift-bypass')
        ->expectsOutputToContain('discovery/type verification')
        ->assertFailed();

    expect(verificationTreeHash($root))->toBe($before);
    expect($nodePath)->toBeFile();
    expect($root.'/packages/acme/widgets')->not->toBeDirectory();
    expect($root.'/vendor/acme')->not->toBeDirectory();
    expect($root.'/post-autoload-dump-ran')->not->toBeFile();
});

it('proves an old resident class passes in process while the fresh host subprocess rejects its stale classmap', function () {
    $root = verificationWriteLaravelHostFixture('stale resident class');
    $composerPath = $root.'/composer.json';
    $composer = json_decode(file_get_contents($composerPath), true);
    $composer['autoload'] = ['psr-4' => ['App\\' => 'app/']];
    $composer['config']['optimize-autoloader'] = true;
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    mkdir($root.'/app/Nodeflow/Nodes', 0777, true);
    $oldPath = $root.'/app/Nodeflow/Nodes/StaleResidentNode.php';
    file_put_contents($oldPath, <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    final class StaleResidentNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'verification.stale-resident';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('Stale');
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            return $context->continue();
        }
    }
    PHP);

    file_put_contents(
        $root.'/packages/probe/discovered/src/DiscoveredServiceProvider.php',
        <<<'PHP'
        <?php

        namespace Probe\Discovered;

        use App\Nodeflow\Nodes\StaleResidentNode;
        use Illuminate\Support\ServiceProvider;
        use Nodeflow\Nodes\NodeRegistry;

        final class DiscoveredServiceProvider extends ServiceProvider
        {
            public function boot(): void
            {
                $this->app->make(NodeRegistry::class)->register(StaleResidentNode::class);
            }
        }
        PHP,
    );

    $dump = verificationRun([
        'composer',
        'dump-autoload',
        '--optimize',
        '--no-scripts',
        '--no-interaction',
    ], $root);
    expect($dump['exit'])->toBe(0, $dump['output']);

    require $oldPath;
    $oldClass = 'App\\Nodeflow\\Nodes\\StaleResidentNode';
    unlink($oldPath);

    $inProcessRegistry = new \Nodeflow\Nodes\NodeRegistry;
    $inProcessRegistry->register($oldClass);

    expect($inProcessRegistry->all()['verification.stale-resident'] ?? null)->toBe($oldClass);
    expect(class_exists($oldClass))->toBeTrue();

    $freshResult = (new ComposerRunner)->bootAndResolve($root, 'verification.stale-resident');

    expect($freshResult)->toBeNull();
    expect($root.'/post-autoload-dump-ran')->not->toBeFile();
});

it('freezes and invalidates effective custom cache paths so a real M8 and M9 extraction succeeds', function () {
    $root = verificationWriteLaravelHostFixture('stale package manifest extraction');
    $composerPath = $root.'/composer.json';
    $composer = json_decode(file_get_contents($composerPath), true);
    $composer['autoload'] = ['psr-4' => ['App\\' => 'app/']];
    file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    mkdir($root.'/app/Nodeflow/Nodes', 0777, true);
    $nodePath = $root.'/app/Nodeflow/Nodes/RealVerifiedNode.php';
    file_put_contents($nodePath, <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    final class RealVerifiedNode extends Node implements HandlesSubject
    {
        public static function type(): string
        {
            return 'verification.real-extraction';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('Real');
        }

        public function forSubject(SubjectContext $context): NodeResult
        {
            return $context->continue();
        }
    }
    PHP);

    $dump = verificationRun(['composer', 'dump-autoload', '--no-scripts', '--no-interaction'], $root);
    expect($dump['exit'])->toBe(0, $dump['output']);
    $customCacheDirectory = $root.'/storage/framework/real-command-cache';
    mkdir($customCacheDirectory, 0777, true);
    $customPackagesPath = $customCacheDirectory.'/packages.php';
    $customServicesPath = $customCacheDirectory.'/services.php';
    file_put_contents($customPackagesPath, '<?php return [];');
    file_put_contents($root.'/bootstrap/cache/packages.php', '<?php return [];');
    require $nodePath;

    \Illuminate\Support\Env::getRepository()->set('APP_PACKAGES_CACHE', $customPackagesPath);
    \Illuminate\Support\Env::getRepository()->set('APP_SERVICES_CACHE', $customServicesPath);
    $this->app->setBasePath($root);
    $this->app->instance(ComposerRunner::class, new ComposerRunner);

    try {
        $this->artisan('nodeflow:extract-node', [
            'class' => 'App\\Nodeflow\\Nodes\\RealVerifiedNode',
            '--package' => 'acme/widgets',
        ])->assertSuccessful();
    } finally {
        \Illuminate\Support\Env::getRepository()->clear('APP_PACKAGES_CACHE');
        \Illuminate\Support\Env::getRepository()->clear('APP_SERVICES_CACHE');
    }

    expect($root.'/vendor/acme/widgets')->toBeDirectory();
    expect(is_link($root.'/vendor/acme/widgets'))->toBeTrue();
    expect($root.'/packages/acme/widgets/src/Nodes/RealVerifiedNode.php')->toBeFile();
    expect($nodePath)->not->toBeFile();
    expect(file_get_contents($root.'/bootstrap/cache/packages.php'))->toBe('<?php return [];');
    expect(file_get_contents($customPackagesPath))->toContain('acme/widgets');
    expect($customServicesPath)->toBeFile();
    expect($root.'/post-autoload-dump-ran')->not->toBeFile();
});
