<?php

use Illuminate\Support\Facades\Artisan;
use Nodeflow\Contracts\TriggerNode;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerNodeRegistry;

final class TriggerClassCollisionFixture {}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-make-trigger-node-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/app/Providers', 0777, true);
    file_put_contents($this->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    file_put_contents($this->root.'/app/Providers/NodeflowServiceProvider.php', "<?php\nclass NodeflowServiceProvider\n{\n    protected array \$triggerNodes = [\n    ];\n}\n");
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

it('scaffolds a parseable registerable trigger node for a registered driver', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'StripePayment', '--driver' => 'webhook', '--type' => 'shop.stripe_payment',
    ])->assertExitCode(0);

    $path = $this->root.'/app/Nodeflow/Triggers/StripePayment.php';
    expectParseablePhp($path);
    require $path;

    app(TriggerNodeRegistry::class)->register(App\Nodeflow\Triggers\StripePayment::class);
    $node = app(TriggerNodeRegistry::class)->resolve('shop.stripe_payment');
    $descriptor = $node->compile(['source' => 'shop.orders', 'mode' => 'safe']);

    expect($node)->toBeInstanceOf(TriggerNode::class)
        ->and($node->driver())->toBe('webhook')
        ->and($descriptor)->toBeInstanceOf(TriggerActivationDescriptor::class)
        ->and($descriptor->metadata)->toBe(['mode' => 'safe'])
        ->and(file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php'))
        ->toContain('\\App\\Nodeflow\\Triggers\\StripePayment::class,');
});

it('rejects unknown reserved malformed and colliding driver or graph keys before mutation', function (array $arguments) {
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $before = file_get_contents($provider);

    $this->artisan('nodeflow:make-trigger', array_merge(['name' => 'BadTrigger'], $arguments))
        ->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Triggers/BadTrigger.php')->not->toBeFile()
        ->and(file_get_contents($provider))->toBe($before);
})->with([
    'unknown driver' => [['--driver' => 'missing.driver', '--type' => 'shop.valid']],
    'manual is not a trigger driver' => [['--driver' => 'manual', '--type' => 'shop.valid']],
    'subflow is not a trigger driver' => [['--driver' => 'subflow', '--type' => 'shop.valid']],
    'malformed type' => [['--driver' => 'webhook', '--type' => '../bad']],
    'package type' => [['--driver' => 'webhook', '--type' => 'core.custom']],
]);

it('rejects path traversal and refuses overwrite', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => '../Escaped', '--driver' => 'webhook', '--type' => 'shop.escaped',
    ])->assertExitCode(1);

    $this->artisan('nodeflow:make-trigger', [
        'name' => 'Kept', '--driver' => 'webhook', '--type' => 'shop.kept',
    ])->assertExitCode(0);
    $path = $this->root.'/app/Nodeflow/Triggers/Kept.php';
    $before = file_get_contents($path);
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $providerBefore = file_get_contents($provider);

    $this->artisan('nodeflow:make-trigger', [
        'name' => 'Kept', '--driver' => 'webhook', '--type' => 'shop.changed',
    ])->assertExitCode(1);

    expect(file_get_contents($path))->toBe($before)
        ->and(file_get_contents($provider))->toBe($providerBefore)
        ->and($this->root.'/app/Nodeflow/Escaped.php')->not->toBeFile();
});

it('refuses shared graph catalog collisions before file or provider mutation', function () {
    app(GraphTypeCatalog::class)->claim('shop.claimed', 'executable', self::class);
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $before = file_get_contents($provider);

    $this->artisan('nodeflow:make-trigger', [
        'name' => 'ClaimedTrigger', '--driver' => 'webhook', '--type' => 'shop.claimed',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Triggers/ClaimedTrigger.php')->not->toBeFile()
        ->and(file_get_contents($provider))->toBe($before);
});

it('refuses a loaded generated class collision before mutation', function () {
    class_alias(TriggerClassCollisionFixture::class, 'App\\Nodeflow\\Triggers\\LoadedTrigger');
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $before = file_get_contents($provider);

    $this->artisan('nodeflow:make-trigger', [
        'name' => 'LoadedTrigger', '--driver' => 'webhook', '--type' => 'shop.loaded_trigger',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Triggers/LoadedTrigger.php')->not->toBeFile()
        ->and(file_get_contents($provider))->toBe($before);
});

it('prints the exact manual trigger-node registration when its anchor is unsafe', function () {
    file_put_contents($this->root.'/app/Providers/NodeflowServiceProvider.php', "<?php\nclass NodeflowServiceProvider {}\n");

    $exit = Artisan::call('nodeflow:make-trigger', [
        'name' => 'ManualNode', '--driver' => 'webhook', '--type' => 'shop.manual_node',
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('\\Nodeflow\\Nodeflow::registerTriggerNodes([')
        ->toContain('\\App\\Nodeflow\\Triggers\\ManualNode::class,');
});

it('deduplicates and preserves CRLF provider formatting', function () {
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    file_put_contents($provider, "<?php\r\nclass NodeflowServiceProvider\r\n{\r\n    protected array \$triggerNodes = [\r\n    ];\r\n}\r\n");

    $arguments = ['name' => 'CrlfNode', '--driver' => 'webhook', '--type' => 'shop.crlf_node'];
    $this->artisan('nodeflow:make-trigger', $arguments)->assertExitCode(0);
    $this->artisan('nodeflow:make-trigger', array_merge($arguments, ['--force' => true]))->assertExitCode(0);

    $contents = file_get_contents($provider);
    expect(substr_count($contents, '\\App\\Nodeflow\\Triggers\\CrlfNode::class'))->toBe(1)
        ->and(preg_match('/(?<!\r)\n/', $contents))->toBe(0);
});
