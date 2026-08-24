<?php

use Illuminate\Support\Facades\Artisan;
use Nodeflow\Nodeflow;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\LaravelEvent\LaravelEventOccurrence;
use Nodeflow\Triggers\LaravelEvent\LaravelEventTriggerDriver;
use Nodeflow\Triggers\LaravelEvent\LaravelEventTriggerSource;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;
use Nodeflow\Triggers\TriggerSourceRegistry;

function triggerManualRegistrationSnippet(string $output): string
{
    $lines = preg_split('/\R/', $output) ?: [];
    $start = null;

    foreach ($lines as $index => $line) {
        if (str_contains($line, 'Nodeflow::registerTriggerSources')) {
            $start = $index;

            break;
        }
    }

    if ($start === null) {
        return '';
    }

    for ($end = $start; $end < count($lines); $end++) {
        if (str_contains($lines[$end], ']);')) {
            return implode(PHP_EOL, array_slice($lines, $start, $end - $start + 1));
        }
    }

    return '';
}

final class MakeTriggerTestEvent
{
    public function __construct(public string $tenantId = 't1', public array $userIds = ['7']) {}
}

final class MakeTriggerTestEventTwo
{
    public function __construct(public string $tenantId = 't2', public array $userIds = ['9']) {}
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-make-trigger-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/app', 0777, true);

    file_put_contents($this->root.'/composer.json', json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));

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

it('generates an allowlisted Laravel event source at the conventional path', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'FloodAlertFires',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'rada.flood_alert',
    ])->assertExitCode(0);

    $path = $this->root.'/app/Nodeflow/Triggers/FloodAlertFires.php';

    expect($path)->toBeFile()
        ->and(file_get_contents($path))
        ->toContain('namespace App\Nodeflow\Triggers;')
        ->toContain('implements LaravelEventTriggerSource')
        ->toContain("return 'rada.flood_alert';")
        ->toContain('return \MakeTriggerTestEvent::class;')
        ->toContain('public function snapshot(object $event): LaravelEventOccurrence')
        ->toContain('public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch');
});

it('produces a source the registry accepts and whose typed methods execute', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'OrderPlacedTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.order_placed',
    ])->assertExitCode(0);

    require $this->root.'/app/Nodeflow/Triggers/OrderPlacedTrigger.php';

    $class = 'App\Nodeflow\Triggers\OrderPlacedTrigger';
    Nodeflow::registerTriggerSources([$class]);
    $source = app(TriggerSourceRegistry::class)->resolve(LaravelEventTriggerDriver::key(), 'shop.order_placed');
    $payload = $source->snapshot(new MakeTriggerTestEvent);
    $match = $source->resolve(new TriggerOccurrence(
        driver: LaravelEventTriggerDriver::key(),
        source: 'shop.order_placed',
        payload: $payload,
    ), []);

    expect($source)->toBeInstanceOf(LaravelEventTriggerSource::class)
        ->and($source->definition())->toBeInstanceOf(TriggerDefinition::class)
        ->and($payload)->toBeInstanceOf(LaravelEventOccurrence::class)
        ->and($payload->eventClass)->toBe(MakeTriggerTestEvent::class)
        ->and($payload->data)->toBe([])
        ->and($match)->toBeInstanceOf(TriggerMatch::class)
        ->and($match->tenants())->toBe([]);
});

it('registers the source in the provider through the trigger anchor', function () {
    mkdir($this->root.'/app/Providers', 0777, true);
    file_put_contents($this->root.'/app/Providers/NodeflowServiceProvider.php', <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;

    class NodeflowServiceProvider extends ServiceProvider
    {
        protected array $triggers = [
        ];
    }
    PHP);

    $this->artisan('nodeflow:make-trigger', [
        'name' => 'RegisteredTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.registered',
    ])->assertExitCode(0);

    expect(file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php'))
        ->toContain('\App\Nodeflow\Triggers\RegisteredTrigger::class,');
});

it('prints an executable source-registration call when there is no provider', function () {
    $exitCode = Artisan::call('nodeflow:make-trigger', [
        'name' => 'ManualTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.manual',
    ]);
    $output = Artisan::output();
    $snippet = triggerManualRegistrationSnippet($output);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Nodeflow::registerTriggerSources')
        ->and($snippet)->not->toBe('');

    mkdir($this->root.'/app/Providers', 0777, true);
    $probePath = $this->root.'/app/Providers/ManualRegistrationProbe.php';
    file_put_contents(
        $probePath,
        "<?php\n\nnamespace App\\Providers;\n\n"
        ."final class ManualRegistrationProbe\n{\n"
        ."    public static function run(): void\n    {\n"
        .$snippet."\n    }\n}\n",
    );

    expectParseablePhp($probePath);

    require $this->root.'/app/Nodeflow/Triggers/ManualTrigger.php';
    require $probePath;

    App\Providers\ManualRegistrationProbe::run();

    expect(app(TriggerSourceRegistry::class)->has('event', 'shop.manual'))->toBeTrue();
});

it('warns but still generates when the event class does not exist', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'FutureTrigger',
        '--event' => 'App\Events\NotWrittenYet',
        '--type' => 'shop.future',
    ])
        ->expectsOutputToContain('App\Events\NotWrittenYet')
        ->assertExitCode(0);

    expect($this->root.'/app/Nodeflow/Triggers/FutureTrigger.php')->toBeFile();
});

it('fails without an event class before writing a file', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'NoEventTrigger',
        '--type' => 'shop.no_event',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Triggers/NoEventTrigger.php')->not->toBeFile();
});

it('rejects reserved or malformed source keys', function (string $key) {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'BadSourceTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => $key,
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Triggers/BadSourceTrigger.php')->not->toBeFile();
})->with(['core.something', 'Shop Order']);

it('generates parseable PHP', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'LintedTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.linted',
    ])->assertExitCode(0);

    expectParseablePhp($this->root.'/app/Nodeflow/Triggers/LintedTrigger.php');
});

it('validates each invocation independently when the command instance is reused', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'FirstInvocationTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.first_invocation',
    ])->assertExitCode(0);

    $this->artisan('nodeflow:make-trigger', [
        'name' => 'SecondInvocationTrigger',
        '--event' => MakeTriggerTestEventTwo::class,
        '--type' => 'shop.second_invocation',
    ])->assertExitCode(0);

    $secondFile = file_get_contents($this->root.'/app/Nodeflow/Triggers/SecondInvocationTrigger.php');

    expect($secondFile)
        ->toContain("return 'shop.second_invocation';")
        ->toContain('return \MakeTriggerTestEventTwo::class;')
        ->not->toContain("return 'shop.first_invocation';")
        ->not->toContain('return \MakeTriggerTestEvent::class;');
});

it('refuses a source key already registered by another source class', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'FirstClaimantTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.claimed_type',
    ])->assertExitCode(0);

    require $this->root.'/app/Nodeflow/Triggers/FirstClaimantTrigger.php';
    Nodeflow::registerTriggerSources(['App\Nodeflow\Triggers\FirstClaimantTrigger']);

    $this->artisan('nodeflow:make-trigger', [
        'name' => 'SecondClaimantTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.claimed_type',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Triggers/SecondClaimantTrigger.php')->not->toBeFile();
});
