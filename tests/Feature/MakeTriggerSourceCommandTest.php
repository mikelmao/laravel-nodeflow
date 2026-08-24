<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Triggers\LaravelEvent\LaravelEventOccurrence;
use Nodeflow\Triggers\LaravelEvent\LaravelEventTriggerSource;
use Nodeflow\Triggers\ModelObserver\ModelObserverTriggerSource;
use Nodeflow\Triggers\ModelObserver\ModelOccurrence;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;
use Nodeflow\Triggers\TriggerSourceRegistry;
use Nodeflow\Triggers\Webhook\WebhookOccurrence;
use Nodeflow\Triggers\Webhook\WebhookTriggerSource;

final class GeneratedSourceEvent {}
final class GeneratedSourceModel extends Model {}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-make-trigger-source-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/app/Providers', 0777, true);
    file_put_contents($this->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    file_put_contents($this->root.'/app/Providers/NodeflowServiceProvider.php', "<?php\nclass NodeflowServiceProvider\n{\n    protected array \$triggerSources = [\n    ];\n}\n");
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

it('scaffolds typed built-in source families that parse register and execute safely', function (string $name, string $driver, array $extra, string $interface, object $payload) {
    $arguments = array_merge(['name' => $name, '--driver' => $driver, '--key' => 'shop.'.strtolower($name)], $extra);
    $this->artisan('nodeflow:make-trigger-source', $arguments)->assertExitCode(0);

    $path = $this->root.'/app/Nodeflow/TriggerSources/'.$name.'.php';
    expectParseablePhp($path);
    require $path;
    $class = 'App\\Nodeflow\\TriggerSources\\'.$name;
    app(TriggerSourceRegistry::class)->register($class);
    $source = app(TriggerSourceRegistry::class)->resolve($driver, 'shop.'.strtolower($name));
    $match = $source->resolve(new TriggerOccurrence($driver, $source::key(), $payload), []);

    expect($source)->toBeInstanceOf($interface)
        ->and($match)->toBeInstanceOf(TriggerMatch::class)
        ->and($match->tenants())->toBe([]);
})->with([
    'webhook' => ['OrdersWebhook', 'webhook', [], WebhookTriggerSource::class, new WebhookOccurrence([], 'delivery-1', 1)],
    'model' => ['UserModel', 'model', ['--model' => GeneratedSourceModel::class], ModelObserverTriggerSource::class, new ModelOccurrence(GeneratedSourceModel::class, '1', 'testing', 'created', [], [], [])],
    'event' => ['OrderEvent', 'event', ['--event' => GeneratedSourceEvent::class], LaravelEventTriggerSource::class, new LaravelEventOccurrence(GeneratedSourceEvent::class, [])],
]);

it('scaffolds a generic custom-driver source without runtime selectors', function () {
    $this->artisan('nodeflow:make-trigger-source', [
        'name' => 'CustomSource', '--driver' => 'test.fake', '--key' => 'shop.custom_source',
    ])->assertExitCode(0);

    $path = $this->root.'/app/Nodeflow/TriggerSources/CustomSource.php';
    require $path;
    app(TriggerSourceRegistry::class)->register(App\Nodeflow\TriggerSources\CustomSource::class);
    $source = app(TriggerSourceRegistry::class)->resolve('test.fake', 'shop.custom_source');

    expect($source)->toBeInstanceOf(TriggerSource::class)
        ->and($source->resolve(new TriggerOccurrence('test.fake', 'shop.custom_source', []), []))->toBeInstanceOf(TriggerMatch::class)
        ->and(file_get_contents($path))->not->toContain('class_exists($config')
        ->not->toContain('$config[\'model\']')
        ->not->toContain('$config[\'event\']');
});

it('requires the built-in allowlist selector before writing anything', function (string $driver, string $option) {
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $before = file_get_contents($provider);
    $this->artisan('nodeflow:make-trigger-source', [
        'name' => 'MissingSelector', '--driver' => $driver, '--key' => 'shop.missing',
    ])->expectsOutputToContain($option)->assertExitCode(1);
    expect($this->root.'/app/Nodeflow/TriggerSources/MissingSelector.php')->not->toBeFile()
        ->and(file_get_contents($provider))->toBe($before);
})->with([['model', '--model'], ['event', '--event']]);

it('rejects unknown drivers malformed keys and traversal before mutation', function (array $arguments) {
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $before = file_get_contents($provider);
    $this->artisan('nodeflow:make-trigger-source', array_merge(['name' => 'BadSource'], $arguments))->assertExitCode(1);
    expect(file_get_contents($provider))->toBe($before);
})->with([
    [['--driver' => 'not.registered', '--key' => 'shop.ok']],
    [['--driver' => 'webhook', '--key' => '../bad']],
    [['name' => '../Escape', '--driver' => 'webhook', '--key' => 'shop.escape']],
]);

it('prints the exact manual source registration and does not duplicate its own anchor', function () {
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    file_put_contents($provider, "<?php\nclass NodeflowServiceProvider {}\n");
    Artisan::call('nodeflow:make-trigger-source', ['name' => 'ManualSource', '--driver' => 'webhook', '--key' => 'shop.manual_source']);
    expect(Artisan::output())->toContain('\\Nodeflow\\Nodeflow::registerTriggerSources([')
        ->toContain('\\App\\Nodeflow\\TriggerSources\\ManualSource::class,');
});
