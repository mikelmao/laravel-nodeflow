<?php

use Illuminate\Support\Facades\Artisan;
use Nodeflow\Contracts\TriggerDriver;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerDriverRegistry;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-make-trigger-driver-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/app/Providers', 0777, true);
    file_put_contents($this->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    file_put_contents($this->root.'/app/Providers/NodeflowServiceProvider.php', "<?php\nclass NodeflowServiceProvider\n{\n    protected array \$triggerDrivers = [\n    ];\n}\n");
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

it('scaffolds a parseable registerable driver with a safe validation seam', function () {
    $this->artisan('nodeflow:make-trigger-driver', ['name' => 'BillingDriver', '--key' => 'billing.events'])
        ->assertExitCode(0);
    $path = $this->root.'/app/Nodeflow/TriggerDrivers/BillingDriver.php';
    expectParseablePhp($path);
    require $path;
    app(TriggerDriverRegistry::class)->register(App\Nodeflow\TriggerDrivers\BillingDriver::class);
    $driver = app(TriggerDriverRegistry::class)->resolve('billing.events');

    expect($driver)->toBeInstanceOf(TriggerDriver::class)
        ->and($driver->validate(new TriggerActivationDescriptor('billing.events', 'shop.order', null, [])))->toBe([])
        ->and($driver->validate(new TriggerActivationDescriptor('other', 'shop.order', null, [])))->toHaveKey('driver')
        ->and(file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php'))
        ->toContain('\\App\\Nodeflow\\TriggerDrivers\\BillingDriver::class,');
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

it('refuses overwrite and prints the exact manual driver registration fallback', function () {
    $arguments = ['name' => 'KeptDriver', '--key' => 'shop.kept_driver'];
    $this->artisan('nodeflow:make-trigger-driver', $arguments)->assertExitCode(0);
    $path = $this->root.'/app/Nodeflow/TriggerDrivers/KeptDriver.php';
    $before = file_get_contents($path);
    $this->artisan('nodeflow:make-trigger-driver', $arguments)->assertExitCode(1);
    expect(file_get_contents($path))->toBe($before);

    file_put_contents($this->root.'/app/Providers/NodeflowServiceProvider.php', "<?php\nclass NodeflowServiceProvider {}\n");
    Artisan::call('nodeflow:make-trigger-driver', ['name' => 'ManualDriver', '--key' => 'shop.manual_driver']);
    expect(Artisan::output())->toContain('\\Nodeflow\\Nodeflow::registerTriggerDrivers([')
        ->toContain('\\App\\Nodeflow\\TriggerDrivers\\ManualDriver::class,');
});
