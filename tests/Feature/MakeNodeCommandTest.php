<?php

use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\NodeRegistry;
use Tests\Support\FakeSendNode;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-make-node-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/app', 0777, true);
    mkdir($this->root.'/tests', 0777, true);

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

it('generates a subject node at the conventional path', function () {
    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->assertExitCode(0);

    $path = $this->root.'/app/Nodeflow/Nodes/SendSms.php';

    expect($path)->toBeFile();

    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('namespace App\Nodeflow\Nodes;')
        ->toContain('class SendSms extends Node implements HandlesSubject')
        ->toContain("return 'yaya.send_sms';")
        ->toContain('public function forSubject(SubjectContext $context): NodeResult');
});

it('produces a class the registry accepts and can resolve', function () {
    // The counterfactual: drop `implements HandlesSubject` from the stub and this
    // fails. NodeRegistry::register() rejects a node implementing neither
    // cardinality interface, which is the whole reason the stub declares one.
    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->assertExitCode(0);

    require $this->root.'/app/Nodeflow/Nodes/SendSms.php';

    app(NodeRegistry::class)->register('App\Nodeflow\Nodes\SendSms');

    expect(app(NodeRegistry::class)->has('yaya.send_sms'))->toBeTrue();
    expect(app(NodeRegistry::class)->resolve('yaya.send_sms'))
        ->toBeInstanceOf(HandlesSubject::class);
});

it('generates an audience node that does not also declare forSubject', function () {
    // The counterfactual: make getStub() ignore --cardinality and this fails on
    // the forSubject assertion, because the subject stub would be rendered.
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendBatch',
        '--type' => 'yaya.send_batch',
        '--cardinality' => 'audience',
    ])->assertExitCode(0);

    $contents = file_get_contents($this->root.'/app/Nodeflow/Nodes/SendBatch.php');

    expect($contents)
        ->toContain('class SendBatch extends Node implements HandlesAudience')
        ->toContain('public function forAudience(AudienceContext $context): NodeResult')
        ->not->toContain('forSubject');
});

it('generates a both-cardinality node declaring two interfaces and two methods', function () {
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendEither',
        '--type' => 'yaya.send_either',
        '--cardinality' => 'both',
    ])->assertExitCode(0);

    $contents = file_get_contents($this->root.'/app/Nodeflow/Nodes/SendEither.php');

    expect($contents)
        ->toContain('implements HandlesSubject, HandlesAudience')
        ->toContain('public function forSubject(SubjectContext $context): NodeResult')
        ->toContain('public function forAudience(AudienceContext $context): NodeResult');
});

it('refuses an unknown cardinality without writing a file', function () {
    // The counterfactual: accept any string and this fails, because getStub()
    // would resolve a nonexistent stub path and throw instead of exiting 1.
    $this->artisan('nodeflow:make-node', [
        'name' => 'Broken',
        '--type' => 'yaya.broken',
        '--cardinality' => 'sideways',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Nodes/Broken.php')->not->toBeFile();
});

it('renders the declared outputs and group into the definition', function () {
    // The counterfactual: hard-code ['default'] in the stub and this fails.
    // Non-default values are used deliberately — asserting on 'default' would
    // pass while --outputs was being ignored entirely.
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendSms',
        '--type' => 'yaya.send_sms',
        '--outputs' => 'sent, failed',
        '--group' => 'Messaging',
    ])->assertExitCode(0);

    $contents = file_get_contents($this->root.'/app/Nodeflow/Nodes/SendSms.php');

    expect($contents)
        ->toContain("->outputs(['sent', 'failed'])")
        ->toContain("->group('Messaging')")
        ->toContain("return \$context->continue('sent');")
        ->toContain("NodeDefinition::make('Send Sms')");
});

it('refuses a type using the reserved core prefix', function () {
    // Asserting the message, not just the exit code. `core.wait` is BOTH reserved
    // and already registered, so an exit-code-only assertion would pass even if
    // the reserved-prefix rule did not exist — the duplicate rule would catch it.
    // Two rules that can both fire on one input need messages to tell apart.
    $this->artisan('nodeflow:make-node', [
        'name' => 'Sneaky',
        '--type' => 'core.wait',
    ])
        ->expectsOutputToContain('reserved [core.] prefix')
        ->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Nodes/Sneaky.php')->not->toBeFile();
});

it('refuses a type already registered by another node', function () {
    // NodeRegistry::register() assigns $types[$class::type()] = $class, so a
    // duplicate type silently replaces the existing node in every palette and
    // every graph that resolves it.
    //
    // `test.send` is used rather than a core.* type precisely because the
    // reserved-prefix rule runs first: a core.* type would exit 1 for the wrong
    // reason and this test would pass with the duplicate check deleted.
    app(NodeRegistry::class)->register(FakeSendNode::class);

    $this->artisan('nodeflow:make-node', [
        'name' => 'MyDuplicate',
        '--type' => 'test.send',
    ])
        ->expectsOutputToContain('is already registered by')
        ->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Nodes/MyDuplicate.php')->not->toBeFile();
});

it('refuses a malformed type', function () {
    $this->artisan('nodeflow:make-node', [
        'name' => 'Shouty',
        '--type' => 'Yaya Send Message',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Nodes/Shouty.php')->not->toBeFile();
});

it('refuses a type already registered through an alias, naming the real owner', function () {
    // NodeRegistry::has() resolves through canonical(), which follows aliases,
    // but the previous implementation looked the raw (unaliased) type up in
    // all() to report the owner — an undefined array key for any aliased type,
    // silently reporting no owner at all. Asserting on the class name (not just
    // the exit code) is what catches that: an exit-code-only assertion would
    // pass even with the bug present, because the duplicate is still refused.
    app(NodeRegistry::class)->register(FakeSendNode::class);
    app(NodeRegistry::class)->alias('test.old_send', 'test.send');

    $this->artisan('nodeflow:make-node', [
        'name' => 'MyAliasedDuplicate',
        '--type' => 'test.old_send',
    ])
        ->expectsOutputToContain('is already registered by ['.FakeSendNode::class.']')
        ->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Nodes/MyAliasedDuplicate.php')->not->toBeFile();
});

it('warns when deriving the type non-interactively because --type was omitted', function () {
    // Non-interactive derivation (CI, --no-interaction) must not error — that
    // would break legitimate scripting — but the derived type is permanent and
    // carries no domain prefix, so silence would violate the very convention
    // the interactive prompt's own hint teaches. Asserting on the derived type
    // string, not just that some warning fired, is what catches a deleted
    // warning: a looser assertion would pass with the warning gone.
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendReceipt',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('derived [send_receipt]')
        ->assertExitCode(0);

    expect($this->root.'/app/Nodeflow/Nodes/SendReceipt.php')->toBeFile();
});

it('registers the generated node in the host provider when it can', function () {
    mkdir($this->root.'/app/Providers', 0777, true);
    file_put_contents($this->root.'/app/Providers/NodeflowServiceProvider.php', <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;

    class NodeflowServiceProvider extends ServiceProvider
    {
        protected array $nodes = [
            //
        ];
    }
    PHP);

    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->assertExitCode(0);

    expect(file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php'))
        ->toContain('\App\Nodeflow\Nodes\SendSms::class,');
});

it('prints the registration snippet when there is no provider to edit', function () {
    // nodeflow:install lands in Plan 5, so through Plans 1-4 this is the normal
    // path, not the edge case. The counterfactual: exit non-zero or say nothing
    // when the provider is absent, and the author is left with an unregistered
    // node that never appears in the palette.
    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->expectsOutputToContain('Nodeflow::register([')
        ->expectsOutputToContain('\App\Nodeflow\Nodes\SendSms::class')
        ->assertExitCode(0);
});

it('generates no test unless asked', function () {
    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->assertExitCode(0);

    expect($this->root.'/tests/Feature/Nodeflow/SendSmsNodeTest.php')->not->toBeFile();
});

it('generates a test whose expectations match the node it generated', function () {
    // The counterfactual, and the reason this assertion is shaped this way: a
    // test stub that hard-codes ['default'] passes a "file exists" check while
    // asserting the wrong outputs. Non-default outputs are used so drift
    // between the two stubs is detectable at all.
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendSms',
        '--type' => 'yaya.send_sms',
        '--outputs' => 'sent, failed',
        '--test' => true,
    ])->assertExitCode(0);

    $test = file_get_contents($this->root.'/tests/Feature/Nodeflow/SendSmsNodeTest.php');
    $node = file_get_contents($this->root.'/app/Nodeflow/Nodes/SendSms.php');

    expect($test)
        ->toContain('use App\Nodeflow\Nodes\SendSms;')
        ->toContain("expect(SendSms::type())->toBe('yaya.send_sms');")
        ->toContain("->toBe(['sent', 'failed'])")
        ->toContain('HandlesSubject::class');

    // Both files must name the same output list, or the generated test asserts
    // something the generated node does not do.
    expect($node)->toContain("['sent', 'failed']");
});

it('asserts the audience interface for an audience node', function () {
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendBatch',
        '--type' => 'yaya.send_batch',
        '--cardinality' => 'audience',
        '--test' => true,
    ])->assertExitCode(0);

    $test = file_get_contents($this->root.'/tests/Feature/Nodeflow/SendBatchNodeTest.php');

    expect($test)
        ->toContain('HandlesAudience::class')
        ->not->toContain('HandlesSubject');
});

it('generates syntactically valid PHP for every cardinality', function (string $cardinality) {
    // The counterfactual: leave an unbalanced brace or a stray {{ placeholder }}
    // in any stub and this fails, while every substring assertion above still
    // passes. Four stubs render PHP; nothing else verifies that it parses.
    $class = 'Send'.ucfirst($cardinality);

    $this->artisan('nodeflow:make-node', [
        'name' => $class,
        '--type' => 'yaya.send_'.$cardinality,
        '--cardinality' => $cardinality,
        '--outputs' => 'sent, failed',
        '--test' => true,
    ])->assertExitCode(0);

    $paths = [
        $this->root.'/app/Nodeflow/Nodes/'.$class.'.php',
        $this->root.'/tests/Feature/Nodeflow/'.$class.'NodeTest.php',
    ];

    foreach ($paths as $path) {
        expect($path)->toBeFile();

        exec('php -l '.escapeshellarg($path).' 2>&1', $output, $exitCode);

        expect($exitCode)->toBe(0, "php -l failed for {$path}: ".implode(PHP_EOL, $output));
    }

    // No placeholder survived rendering in either file.
    foreach ($paths as $path) {
        expect(file_get_contents($path))->not->toContain('{{');
    }
})->with(['subject', 'audience', 'both']);
