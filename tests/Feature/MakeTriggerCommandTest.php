<?php

use App\Providers\ManualRegistrationProbe;
use Illuminate\Support\Facades\Artisan;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\Trigger;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerRegistry;

function triggerManualRegistrationSnippet(string $output): string
{
    $lines = preg_split('/\R/', $output) ?: [];
    $start = null;

    foreach ($lines as $index => $line) {
        if (str_contains($line, 'app(') && str_contains($line, 'TriggerRegistry::class')) {
            $start = $index;

            break;
        }
    }

    if ($start === null) {
        return '';
    }

    for ($end = $start; $end < count($lines); $end++) {
        if (trim($lines[$end]) === ');') {
            return implode(PHP_EOL, array_slice($lines, $start, $end - $start + 1));
        }
    }

    return '';
}

/** A stand-in host event, so --event can name a class that genuinely exists. */
class MakeTriggerTestEvent
{
    public function __construct(public string $tenantId = 't1', public array $userIds = ['7']) {}
}

/**
 * A second, distinct stand-in host event. Needed because two generated
 * triggers `require`d into the same process cannot share an FQCN — PHP
 * fatals with "class already declared" — and the leak-detection test below
 * needs two invocations whose --event genuinely differ.
 */
class MakeTriggerTestEventTwo
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

it('generates a trigger at the conventional path naming the event class', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'FloodAlertFires',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'rada.flood_alert',
    ])->assertExitCode(0);

    $path = $this->root.'/app/Nodeflow/Triggers/FloodAlertFires.php';

    expect($path)->toBeFile();

    expect(file_get_contents($path))
        ->toContain('namespace App\Nodeflow\Triggers;')
        ->toContain('class FloodAlertFires extends Trigger')
        ->toContain("return 'rada.flood_alert';")
        ->toContain('return \MakeTriggerTestEvent::class;');
});

it('produces a trigger the registry accepts and whose methods execute', function () {
    // The require-and-execute test, for the same reason node.stub has one: php -l
    // resolves no symbols, so a rename of TriggerDefinition::make(),
    // ::description() or TriggerMatch::make() would leave this suite green while
    // the stub fataled in every host that generated from it.
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'OrderPlacedTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.order_placed',
    ])->assertExitCode(0);

    require $this->root.'/app/Nodeflow/Triggers/OrderPlacedTrigger.php';

    $class = 'App\Nodeflow\Triggers\OrderPlacedTrigger';

    app(TriggerRegistry::class)->register($class);

    expect($class::type())->toBe('shop.order_placed');
    expect($class::event())->toBe(MakeTriggerTestEvent::class);

    $trigger = new $class;

    expect($trigger)->toBeInstanceOf(Trigger::class);

    // definition() executes the whole TriggerDefinition chain as a side effect.
    expect($trigger->definition())->toBeInstanceOf(TriggerDefinition::class);
    expect($trigger->definition()->toArray())->toHaveKey('label');

    // resolve() must return a real TriggerMatch, not null: the scaffolded body is
    // a safe no-op, and "no tenants" is a legitimate answer, but the type is not
    // optional and a stub returning nothing would fatal on the first event.
    $match = $trigger->resolve(new MakeTriggerTestEvent);

    expect($match)->toBeInstanceOf(TriggerMatch::class);
    expect($match->tenants())->toBe([]);

    // The two commented overrides must stay commented, and the inherited defaults
    // must therefore still answer.
    expect($trigger->idempotencyKey(new MakeTriggerTestEvent))->toBeNull();
    expect($trigger->matchesConfig(new MakeTriggerTestEvent, []))->toBeTrue();
});

it('registers the trigger in the provider through the trigger anchor', function () {
    // E24. Counterfactual: skip registration and this fails — and a host whose
    // generated trigger is never registered gets a listener that was never
    // attached, so the trigger silently never fires.
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

it('prints the line that registers the trigger when there is no provider, and still exits zero', function () {
    // Same contract as make-node: never guess, always explain, and generating the
    // file is still a success.
    $exitCode = Artisan::call('nodeflow:make-trigger', [
        'name' => 'ManualTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.manual',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('TriggerRegistry');
    $snippet = triggerManualRegistrationSnippet($output);
    expect($snippet)->not->toBe('');

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

    ManualRegistrationProbe::run();

    expect(app(TriggerRegistry::class)->has('shop.manual'))->toBeTrue();
});

it('warns but still generates when the event class does not exist', function () {
    // Generating the trigger before writing the event is a normal order of work,
    // and ::class renders without a loaded class. Counterfactual: reject the
    // missing class and this fails, blocking that order of work.
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'FutureTrigger',
        '--event' => 'App\Events\NotWrittenYet',
        '--type' => 'shop.future',
    ])
        ->expectsOutputToContain('App\Events\NotWrittenYet')
        ->assertExitCode(0);

    expect($this->root.'/app/Nodeflow/Triggers/FutureTrigger.php')->toBeFile();
});

it('fails without --event when it cannot prompt', function () {
    // There is no sane default for an event class, and a trigger whose event() is
    // wrong never fires. Counterfactual: derive one from the class name and this
    // fails — the derived value would be a class that does not exist, silently.
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'NoEventTrigger',
        '--type' => 'shop.no_event',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Triggers/NoEventTrigger.php')->not->toBeFile();
});

it('rejects a reserved core type', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'ReservedTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'core.something',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Triggers/ReservedTrigger.php')->not->toBeFile();
});

it('rejects a malformed type', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'BadTypeTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'Shop Order',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Triggers/BadTypeTrigger.php')->not->toBeFile();
});

it('generates a file that passes php -l', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'LintedTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.linted',
    ])->assertExitCode(0);

    expectParseablePhp($this->root.'/app/Nodeflow/Triggers/LintedTrigger.php');
});

it('validates each invocation independently, even when the command instance is reused', function () {
    // Symfony's Application resolves one command object per command name and
    // keeps it for the process's lifetime, so a second artisan() call of
    // nodeflow:make-trigger reuses this exact same MakeTriggerCommand
    // instance rather than a fresh one. Counterfactual: without resetting
    // $resolvedType/$resolvedEvent at the top of handle(), triggerType() and
    // eventClass() would short-circuit on their memoized-not-null guard and
    // return the FIRST call's already-validated values, silently rendering
    // the first trigger's type and event into the second file while still
    // reporting success.
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

it('refuses a --type colliding with an already-registered trigger type', function () {
    // Same rule, and the same reason, as MakeNodeCommand's NodeRegistry check:
    // TriggerRegistry keys by type, so a second trigger sharing an existing
    // type would silently replace the first one in every host boot that
    // resolves it. The registry is genuinely populated here — a real
    // register() call, not merely a file written to disk — so the collision
    // triggerType() must catch is real.
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'FirstClaimantTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.claimed_type',
    ])->assertExitCode(0);

    require $this->root.'/app/Nodeflow/Triggers/FirstClaimantTrigger.php';

    app(TriggerRegistry::class)->register('App\Nodeflow\Triggers\FirstClaimantTrigger');

    $this->artisan('nodeflow:make-trigger', [
        'name' => 'SecondClaimantTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.claimed_type',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Triggers/SecondClaimantTrigger.php')->not->toBeFile();
});
