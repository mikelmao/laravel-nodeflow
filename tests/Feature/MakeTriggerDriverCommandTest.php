<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Filesystem\Filesystem;
use Nodeflow\Contracts\TriggerDriver;
use Nodeflow\Contracts\TriggerNode;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerDriverRegistry;
use Nodeflow\Triggers\TriggerNodeRegistry;

final class TriggerDriverClassCollisionFixture {}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-make-trigger-driver-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/app/Providers', 0777, true);
    file_put_contents($this->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    file_put_contents($this->root.'/app/Providers/NodeflowServiceProvider.php', "<?php\nclass NodeflowServiceProvider\n{\n    protected array \$triggerDrivers = [\n    ];\n    protected array \$triggerNodes = [\n    ];\n}\n");
    $this->app->setBasePath($this->root);
});

afterEach(function () {
    $delete = function (string $dir) use (&$delete): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir.'/'.$entry;
            is_dir($path) ? $delete($path) : unlink($path);
        }
        rmdir($dir);
    };
    if (is_dir($this->root)) $delete($this->root);
});

it('scaffolds an executable driver reference-node and contract-test kit atomically', function () {
    $this->artisan('nodeflow:make-trigger-driver', ['name' => 'BillingDriver', '--key' => 'billing.events'])
        ->assertExitCode(0);
    $path = $this->root.'/app/Nodeflow/TriggerDrivers/BillingDriver.php';
    $nodePath = $this->root.'/app/Nodeflow/Triggers/BillingDriverTrigger.php';
    $testPath = $this->root.'/tests/Feature/Nodeflow/TriggerDrivers/BillingDriverTest.php';
    expectParseablePhp($path);
    expectParseablePhp($nodePath);
    expectParseablePhp($testPath);
    require $path;
    require $nodePath;
    app(TriggerDriverRegistry::class)->register(App\Nodeflow\TriggerDrivers\BillingDriver::class);
    app(TriggerNodeRegistry::class)->register(App\Nodeflow\Triggers\BillingDriverTrigger::class);
    $driver = app(TriggerDriverRegistry::class)->resolve('billing.events');
    $node = app(TriggerNodeRegistry::class)->resolve('billing.events.trigger');
    $occurrence = $driver->occurrence('shop.order', ['id' => 1]);

    expect($driver)->toBeInstanceOf(TriggerDriver::class)
        ->and($node)->toBeInstanceOf(TriggerNode::class)
        ->and($node->driver())->toBe('billing.events')
        ->and($node->definition()->label)->not->toBeEmpty()
        ->and($occurrence->driver)->toBe('billing.events')
        ->and($occurrence->source)->toBe('shop.order')
        ->and($driver->validate(new TriggerActivationDescriptor('billing.events', 'shop.order', null, [])))->toBe([])
        ->and($driver->validate(new TriggerActivationDescriptor('other', 'shop.order', null, [])))->toHaveKey('driver')
        ->and(file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php'))
        ->toContain('\\App\\Nodeflow\\TriggerDrivers\\BillingDriver::class,')
        ->toContain('\\App\\Nodeflow\\Triggers\\BillingDriverTrigger::class,');

    $provider = file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php');
    expect(strpos($provider, 'BillingDriver::class'))->toBeLessThan(strpos($provider, 'BillingDriverTrigger::class'));
});

it('rejects package-reserved malformed colliding keys and traversal before mutation', function (array $arguments) {
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $before = file_get_contents($provider);
    $this->artisan('nodeflow:make-trigger-driver', array_merge(['name' => 'BadDriver'], $arguments))->assertExitCode(1);
    expect(file_get_contents($provider))->toBe($before);
})->with([
    [['--key' => 'webhook']],
    [['--key' => 'manual']],
    [['--key' => 'subflow']],
    [['--key' => '../bad']],
    [['name' => '../Escape', '--key' => 'shop.escape']],
]);

it('refuses any target collision without partial files or provider mutation', function () {
    $arguments = ['name' => 'KeptDriver', '--key' => 'shop.kept_driver'];
    $this->artisan('nodeflow:make-trigger-driver', $arguments)->assertExitCode(0);
    $path = $this->root.'/app/Nodeflow/TriggerDrivers/KeptDriver.php';
    $before = file_get_contents($path);
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $providerBefore = file_get_contents($provider);
    $this->artisan('nodeflow:make-trigger-driver', $arguments)->assertExitCode(1);
    expect(file_get_contents($path))->toBe($before)
        ->and(file_get_contents($provider))->toBe($providerBefore);
});

it('preflights every kit path so a collision cannot create a partial kit', function (string $collisionPath) {
    $paths = [
        'driver' => $this->root.'/app/Nodeflow/TriggerDrivers/AtomicDriver.php',
        'node' => $this->root.'/app/Nodeflow/Triggers/AtomicDriverTrigger.php',
        'test' => $this->root.'/tests/Feature/Nodeflow/TriggerDrivers/AtomicDriverTest.php',
    ];
    $target = $paths[$collisionPath];
    mkdir(dirname($target), 0777, true);
    file_put_contents($target, '<?php // keep');
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $before = file_get_contents($provider);

    $this->artisan('nodeflow:make-trigger-driver', ['name' => 'AtomicDriver', '--key' => 'shop.atomic'])->assertExitCode(1);

    expect(file_get_contents($target))->toBe('<?php // keep')
        ->and(file_get_contents($provider))->toBe($before);
    foreach ($paths as $kind => $path) {
        if ($kind !== $collisionPath) expect(file_exists($path))->toBeFalse();
    }
})->with(['driver', 'node', 'test']);

it('rolls back every kit file after a short write', function () {
    $files = new class extends Filesystem
    {
        private bool $truncate = true;

        public function put($path, $contents, $lock = false)
        {
            if ($this->truncate && str_ends_with($path, '/ShortDriver.php')) {
                $this->truncate = false;
                parent::put($path, substr($contents, 0, -1), $lock);

                return strlen($contents) - 1;
            }

            return parent::put($path, $contents, $lock);
        }
    };
    $this->app->instance(Filesystem::class, $files);
    $this->app->instance('files', $files);
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $before = file_get_contents($provider);

    $this->artisan('nodeflow:make-trigger-driver', ['name' => 'ShortDriver', '--key' => 'shop.short'])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/TriggerDrivers/ShortDriver.php')->not->toBeFile()
        ->and($this->root.'/app/Nodeflow/Triggers/ShortDriverTrigger.php')->not->toBeFile()
        ->and($this->root.'/tests/Feature/Nodeflow/TriggerDrivers/ShortDriverTest.php')->not->toBeFile()
        ->and(file_get_contents($provider))->toBe($before);
});

it('refuses registered driver and reference graph type collisions before writing', function () {
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $before = file_get_contents($provider);
    $this->artisan('nodeflow:make-trigger-driver', ['name' => 'ExistingDriver', '--key' => 'test.fake'])->assertExitCode(1);
    expect(file_exists($this->root.'/app/Nodeflow/TriggerDrivers/ExistingDriver.php'))->toBeFalse()
        ->and(file_get_contents($provider))->toBe($before);

    app(GraphTypeCatalog::class)->claim('shop.collision.trigger', 'executable', self::class);
    $this->artisan('nodeflow:make-trigger-driver', ['name' => 'CollisionDriver', '--key' => 'shop.collision'])->assertExitCode(1);
    expect(file_exists($this->root.'/app/Nodeflow/TriggerDrivers/CollisionDriver.php'))->toBeFalse()
        ->and(file_exists($this->root.'/app/Nodeflow/Triggers/CollisionDriverTrigger.php'))->toBeFalse()
        ->and(file_get_contents($provider))->toBe($before);
});

it('refuses a loaded driver or reference-node class before writing any kit file', function (string $class, string $name, string $key) {
    class_alias(TriggerDriverClassCollisionFixture::class, $class);
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $before = file_get_contents($provider);

    $this->artisan('nodeflow:make-trigger-driver', ['name' => $name, '--key' => $key])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/TriggerDrivers/'.$name.'.php')->not->toBeFile()
        ->and($this->root.'/app/Nodeflow/Triggers/'.$name.'Trigger.php')->not->toBeFile()
        ->and(file_get_contents($provider))->toBe($before);
})->with([
    'driver class' => ['App\\Nodeflow\\TriggerDrivers\\LoadedDriver', 'LoadedDriver', 'shop.loaded_driver'],
    'reference node class' => ['App\\Nodeflow\\Triggers\\LoadedReferenceTrigger', 'LoadedReference', 'shop.loaded_reference'],
]);

it('prints exact manual driver then node registration calls when provider insertion is unsafe', function () {
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    file_put_contents($provider, "<?php\nclass NodeflowServiceProvider {\n    protected array \$triggerDrivers = [\n    ];\n}\n");
    $before = file_get_contents($provider);
    Artisan::call('nodeflow:make-trigger-driver', ['name' => 'ManualDriver', '--key' => 'shop.manual_driver']);
    $output = Artisan::output();
    expect($output)->toContain('\\Nodeflow\\Nodeflow::registerTriggerDrivers([\\App\\Nodeflow\\TriggerDrivers\\ManualDriver::class]);')
        ->toContain('\\Nodeflow\\Nodeflow::registerTriggerNodes([\\App\\Nodeflow\\Triggers\\ManualDriverTrigger::class]);')
        ->and(strpos($output, 'registerTriggerDrivers'))->toBeLessThan(strpos($output, 'registerTriggerNodes'))
        ->and(file_get_contents($provider))->toBe($before);
});

it('preserves CRLF while registering both kit classes in order', function () {
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    file_put_contents($provider, str_replace("\n", "\r\n", file_get_contents($provider)));
    $this->artisan('nodeflow:make-trigger-driver', ['name' => 'CrlfDriver', '--key' => 'shop.crlf'])->assertExitCode(0);
    $contents = file_get_contents($provider);
    expect(preg_match('/(?<!\r)\n/', $contents))->toBe(0)
        ->and(strpos($contents, 'CrlfDriver::class'))->toBeLessThan(strpos($contents, 'CrlfDriverTrigger::class'));
});
