<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Filesystem\Filesystem;
use Nodeflow\Contracts\TriggerNode;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerNodeRegistry;

final class TriggerClassCollisionFixture {}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-make-trigger-node-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/app/Providers', 0777, true);
    file_put_contents($this->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    file_put_contents($this->root.'/app/Providers/NodeflowServiceProvider.php', "<?php\nclass NodeflowServiceProvider extends \\Illuminate\\Support\\ServiceProvider\n{\n    protected array \$triggerNodes = [\n    ];\n}\n");
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
        ->toContain('\\App\\Nodeflow\\Triggers\\ManualNode::class,')
        ->and($this->root.'/app/Nodeflow/Triggers/ManualNode.php')->toBeFile();
    expectParseablePhp($this->root.'/app/Nodeflow/Triggers/ManualNode.php');
});

it('fails and removes the generated node when a real provider write fails', function () {
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $before = file_get_contents($provider);
    $files = new class($provider) extends Filesystem
    {
        public function __construct(private string $provider) {}
        public function move($path, $target) { return $target === $this->provider ? false : parent::move($path, $target); }
    };
    $this->app->instance(Filesystem::class, $files);
    $this->app->instance('files', $files);

    $this->artisan('nodeflow:make-trigger', [
        'name' => 'ProviderFailureNode', '--driver' => 'webhook', '--type' => 'shop.provider_failure',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Triggers/ProviderFailureNode.php')->not->toBeFile()
        ->and(file_get_contents($provider))->toBe($before);
});

it('rolls back provider registration when the generated node disappears during provider commit', function () {
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $node = $this->root.'/app/Nodeflow/Triggers/VanishingNode.php';
    $before = file_get_contents($provider);
    $files = new class($provider, $node) extends Filesystem
    {
        private bool $intercept = true;
        public function __construct(private string $provider, private string $node) {}
        public function move($path, $target)
        {
            $moved = parent::move($path, $target);
            if ($this->intercept && $target === $this->provider) {
                $this->intercept = false;
                unlink($this->node);
            }

            return $moved;
        }
    };
    $this->app->instance(Filesystem::class, $files);
    $this->app->instance('files', $files);

    $this->artisan('nodeflow:make-trigger', [
        'name' => 'VanishingNode', '--driver' => 'webhook', '--type' => 'shop.vanishing_node',
    ])->assertExitCode(1);

    expect($node)->not->toBeFile()
        ->and(file_get_contents($provider))->toBe($before)
        ->and(glob($this->root.'/app/Providers/*.nodeflow-tmp-*') ?: [])->toBe([])
        ->and(glob(dirname($node).'/*.nodeflow-tmp-*') ?: [])->toBe([]);
});

it('rolls back the node when the provider changes after the artifact commit', function () {
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $node = $this->root.'/app/Nodeflow/Triggers/RacedProviderNode.php';
    $external = "<?php\n// external provider replacement\n";
    $files = new class($provider, $node, $external) extends Filesystem
    {
        private bool $intercept = true;
        public function __construct(private string $provider, private string $node, private string $external) {}
        public function move($path, $target)
        {
            $moved = parent::move($path, $target);
            if ($this->intercept && $target === $this->node) {
                $this->intercept = false;
                unlink($this->provider);
                file_put_contents($this->provider, $this->external);
            }

            return $moved;
        }
    };
    $this->app->instance(Filesystem::class, $files);
    $this->app->instance('files', $files);

    $this->artisan('nodeflow:make-trigger', [
        'name' => 'RacedProviderNode', '--driver' => 'webhook', '--type' => 'shop.raced_provider_node',
    ])->assertExitCode(1);

    expect($node)->not->toBeFile()
        ->and(file_get_contents($provider))->toBe($external)
        ->and(glob($this->root.'/app/Providers/*.nodeflow-tmp-*') ?: [])->toBe([]);
});

it('does not overwrite provider bytes changed in place after registration planning', function () {
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    $node = $this->root.'/app/Nodeflow/Triggers/StalePlanNode.php';
    $external = "<?php\n// changed after planning\n";
    $files = new class($provider, $external) extends Filesystem
    {
        private int $providerReads = 0;
        public function __construct(private string $provider, private string $external) {}
        public function get($path, $lock = false)
        {
            if ($path === $this->provider && ++$this->providerReads === 2) {
                file_put_contents($this->provider, $this->external);
            }

            return parent::get($path, $lock);
        }
    };
    $this->app->instance(Filesystem::class, $files);
    $this->app->instance('files', $files);

    $this->artisan('nodeflow:make-trigger', [
        'name' => 'StalePlanNode', '--driver' => 'webhook', '--type' => 'shop.stale_plan_node',
    ])->assertExitCode(1);

    expect($node)->not->toBeFile()
        ->and(file_get_contents($provider))->toBe($external);
});

it('guards an already-present provider registration while committing the generated node', function () {
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    file_put_contents($provider, "<?php\nclass NodeflowServiceProvider extends \\Illuminate\\Support\\ServiceProvider\n{\n    protected array \$triggerNodes = [\n        \\App\\Nodeflow\\Triggers\\GuardedNode::class,\n    ];\n}\n");
    $node = $this->root.'/app/Nodeflow/Triggers/GuardedNode.php';
    $external = "<?php\n// external provider replacement\n";
    $files = new class($provider, $node, $external) extends Filesystem
    {
        private bool $intercept = true;
        public function __construct(private string $provider, private string $node, private string $external) {}
        public function move($path, $target)
        {
            $moved = parent::move($path, $target);
            if ($this->intercept && $target === $this->node) {
                $this->intercept = false;
                unlink($this->provider);
                file_put_contents($this->provider, $this->external);
            }

            return $moved;
        }
    };
    $this->app->instance(Filesystem::class, $files);
    $this->app->instance('files', $files);

    $this->artisan('nodeflow:make-trigger', [
        'name' => 'GuardedNode', '--driver' => 'webhook', '--type' => 'shop.guarded_node',
    ])->assertExitCode(1);

    expect($node)->not->toBeFile()
        ->and(file_get_contents($provider))->toBe($external);
});

it('deduplicates and preserves CRLF provider formatting', function () {
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    file_put_contents($provider, "<?php\r\nclass NodeflowServiceProvider extends \\Illuminate\\Support\\ServiceProvider\r\n{\r\n    protected array \$triggerNodes = [\r\n    ];\r\n}\r\n");

    $arguments = ['name' => 'CrlfNode', '--driver' => 'webhook', '--type' => 'shop.crlf_node'];
    $this->artisan('nodeflow:make-trigger', $arguments)->assertExitCode(0);
    $this->artisan('nodeflow:make-trigger', array_merge($arguments, ['--force' => true]))->assertExitCode(0);

    $contents = file_get_contents($provider);
    expect(substr_count($contents, '\\App\\Nodeflow\\Triggers\\CrlfNode::class'))->toBe(1)
        ->and(preg_match('/(?<!\r)\n/', $contents))->toBe(0);
});

it('fails and restores generator writes before touching a CRLF provider', function (string $mode, bool $existing) {
    $path = $this->root.'/app/Nodeflow/Triggers/VerifiedTrigger.php';
    $original = '<?php // pre-existing trigger';
    if ($existing) {
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, $original);
    }
    $provider = $this->root.'/app/Providers/NodeflowServiceProvider.php';
    file_put_contents($provider, str_replace("\n", "\r\n", file_get_contents($provider)));
    $providerBefore = file_get_contents($provider);
    $files = new class($path, $mode) extends Filesystem
    {
        private bool $intercept = true;

        public function __construct(private string $target, private string $mode) {}

        public function put($path, $contents, $lock = false)
        {
            if (str_contains($path, '.nodeflow-tmp-') && $this->intercept) {
                $this->intercept = false;
                $written = $this->mode === 'short' ? substr($contents, 0, -1) : '<?php malformed {';
                parent::put($path, $written, $lock);

                return $this->mode === 'short' ? strlen($contents) - 1 : strlen($contents);
            }

            return parent::put($path, $contents, $lock);
        }
    };
    $this->app->instance(Filesystem::class, $files);
    $this->app->instance('files', $files);

    $this->artisan('nodeflow:make-trigger', [
        'name' => 'VerifiedTrigger', '--driver' => 'webhook', '--type' => 'shop.verified_trigger', '--force' => $existing,
    ])->expectsOutputToContain('generation transaction failed')->assertExitCode(1);

    expect(file_get_contents($provider))->toBe($providerBefore);
    if ($existing) {
        expect(file_get_contents($path))->toBe($original);
    } else {
        expect($path)->not->toBeFile();
    }
})->with([
    'short new write' => ['short', false],
    'changed overwrite' => ['changed', true],
]);
