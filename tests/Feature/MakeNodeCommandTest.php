<?php

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Models\Run;
use Nodeflow\Nodes\HandlesAudience;
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

/**
 * Writes a hand-written node class into the temp app root, loads it, and registers
 * it — standing in for a host application whose providers booted before the command
 * ran, which is the normal case and the one the temp-app-root harness otherwise
 * cannot reproduce.
 *
 * $class must be unique across this file: `require`ing two classes that share an
 * FQCN in one process fatals with "class already declared".
 */
function writeRegisteredNode(string $root, string $class, string $type, string $marker): void
{
    $directory = $root.'/app/Nodeflow/Nodes';

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = $directory.'/'.$class.'.php';

    file_put_contents($path, <<<PHP
    <?php

    namespace App\Nodeflow\Nodes;

    use Nodeflow\Execution\NodeResult;
    use Nodeflow\Execution\SubjectContext;
    use Nodeflow\Nodes\HandlesSubject;
    use Nodeflow\Nodes\Node;
    use Nodeflow\Schema\NodeDefinition;

    class {$class} extends Node implements HandlesSubject
    {
        // {$marker}

        public static function type(): string
        {
            return '{$type}';
        }

        public function definition(): NodeDefinition
        {
            return NodeDefinition::make('{$class}')->outputs(['default']);
        }

        public function forSubject(SubjectContext \$context): NodeResult
        {
            return \$context->continue('default');
        }
    }
    PHP);

    require $path;

    app(NodeRegistry::class)->register('App\Nodeflow\Nodes\\'.$class);
}

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

it('produces a subject class the registry accepts and the runtime can execute', function () {
    // Two counterfactuals. The registry half: drop `implements HandlesSubject`
    // from the stub and this fails, because NodeRegistry::register() rejects a node
    // implementing neither cardinality interface.
    //
    // The execution half exists because `php -l` resolves no symbols. Rename
    // NodeDefinition::outputNames(), Field::help() or SubjectContext::continue()
    // and every stub would keep passing the whole suite while emitting code that
    // fatals in every host that generates a node. These are also exactly the calls
    // the *generated test file* makes, and that file is never executed anywhere,
    // so this is what stands in for running it.
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendSms',
        '--type' => 'yaya.send_sms',
        '--outputs' => 'sent, failed',
    ])->assertExitCode(0);

    require $this->root.'/app/Nodeflow/Nodes/SendSms.php';

    app(NodeRegistry::class)->register('App\Nodeflow\Nodes\SendSms');

    expect(app(NodeRegistry::class)->has('yaya.send_sms'))->toBeTrue();

    $node = app(NodeRegistry::class)->resolve('yaya.send_sms');

    expect($node)->toBeInstanceOf(HandlesSubject::class);

    // definition() runs the whole Field::text()->label()->help()->required() chain
    // as a side effect of being called at all.
    expect($node->definition()->outputNames())->toBe(['sent', 'failed']);

    // The scaffolded field is required, which is the assertion the generated test
    // makes and the reason the stub ships a TODO next to the field name.
    expect($node->validate([]))->toHaveKey('example');

    // The isTest() branch must route the subject to the first declared output
    // without doing anything else. Asserting the routing, not just that no error
    // was thrown: a body returning NodeResult::empty() would pass the latter.
    $result = $node->forSubject(new SubjectContext(
        new Run(['is_test' => true]), 'n1', [], '42', null,
    ));

    expect($result->outputs())->toBe(['sent' => ['42']]);
});

it('produces an audience class the registry accepts and the runtime can execute', function () {
    // The audience equivalent of the test above, and the only thing in the suite
    // that calls AudienceContext::all() from generated code. It needs its own class
    // name: `require`ing two generated classes that share an FQCN in one process
    // fatals with "class already declared".
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendBlast',
        '--type' => 'yaya.send_blast',
        '--cardinality' => 'audience',
        '--outputs' => 'sent, failed',
    ])->assertExitCode(0);

    require $this->root.'/app/Nodeflow/Nodes/SendBlast.php';

    app(NodeRegistry::class)->register('App\Nodeflow\Nodes\SendBlast');

    $node = app(NodeRegistry::class)->resolve('yaya.send_blast');

    expect($node)->toBeInstanceOf(HandlesAudience::class);
    expect($node->definition()->outputNames())->toBe(['sent', 'failed']);
    expect($node->validate([]))->toHaveKey('example');

    $result = $node->forAudience(new AudienceContext(
        new Run(['is_test' => true]), 'n1', [], 'user', ['7', '8'],
    ));

    expect($result->outputs())->toBe(['sent' => ['7', '8']]);
});

it('produces a both-cardinality class the registry accepts and both paths execute', function () {
    // F-2. Nothing but `php -l` watched node.both.stub, and `php -l` resolves no
    // symbols: renaming ->help( to ->helpText( in that file alone left every test
    // green while the stub fataled in every host that generated from it.
    //
    // A fourth distinct class name is mandatory. SendSms and SendBlast are already
    // required into this process by the tests above, and `require`ing two
    // generated classes that share an FQCN fatals with "class already declared".
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendDigest',
        '--type' => 'yaya.send_digest',
        '--cardinality' => 'both',
        '--outputs' => 'sent, failed',
    ])->assertExitCode(0);

    require $this->root.'/app/Nodeflow/Nodes/SendDigest.php';

    app(NodeRegistry::class)->register('App\Nodeflow\Nodes\SendDigest');

    $node = app(NodeRegistry::class)->resolve('yaya.send_digest');

    expect($node)->toBeInstanceOf(HandlesSubject::class)
        ->toBeInstanceOf(HandlesAudience::class);

    // definition() executes the whole NodeDefinition::make()->group()
    // ->description()->outputs()->fields([Field::text()->label()->help()
    // ->required()]) chain as a side effect of being called at all. This is the
    // assertion that fails on an API rename confined to this stub.
    expect($node->definition()->outputNames())->toBe(['sent', 'failed']);
    expect($node->validate([]))->toHaveKey('example');

    // Both bodies, because a both-cardinality node whose two paths disagree is
    // invisible until scale changes which one the runtime picks. Asserting the
    // routing rather than merely that nothing threw: a body returning
    // NodeResult::empty() would satisfy the weaker check.
    $subject = $node->forSubject(new SubjectContext(
        new Run(['is_test' => true]), 'n1', [], '42', null,
    ));

    expect($subject->outputs())->toBe(['sent' => ['42']]);

    $audience = $node->forAudience(new AudienceContext(
        new Run(['is_test' => true]), 'n1', [], 'user', ['7', '8'],
    ));

    expect($audience->outputs())->toBe(['sent' => ['7', '8']]);
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

it('refuses an output name that would not render as PHP', function () {
    // The counterfactual: don't validate output names and this fails — the command
    // renders `->outputs(['sent', 'it's failed'])` into both the node and the
    // generated test, reports "created successfully" and exits 0, leaving two files
    // that do not parse. Asserting the message as well as the code, because a
    // malformed *type* would also exit 1 and this input has a valid type.
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendSms',
        '--type' => 'yaya.send_sms',
        '--outputs' => "sent, it's failed",
        '--test' => true,
    ])
        ->expectsOutputToContain('is not a valid output name')
        ->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Nodes/SendSms.php')->not->toBeFile();
    expect($this->root.'/tests/Feature/Nodeflow/SendSmsTest.php')->not->toBeFile();
});

it('refuses a duplicated output list', function () {
    // The counterfactual: drop the duplicate check and this fails — `sent, sent`
    // renders `->outputs(['sent', 'sent'])`, which parses, so nothing downstream
    // objects: a flow edge is matched to an output by name, leaving two edges an
    // author cannot tell apart.
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendSms',
        '--type' => 'yaya.send_sms',
        '--outputs' => 'sent, sent',
    ])
        ->expectsOutputToContain('Duplicate output name [sent]')
        ->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Nodes/SendSms.php')->not->toBeFile();
});

it('renders a group containing a quote as valid PHP', function () {
    // The counterfactual: render the group unescaped and this fails — `->group('O'Brien')`
    // is a parse error, which the lint catches, while the command still exits 0.
    // The group is escaped rather than refused because it is a human-facing palette
    // label, so the assertion is that it renders correctly, not that it is rejected.
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendSms',
        '--type' => 'yaya.send_sms',
        '--group' => "O'Brien",
    ])->assertExitCode(0);

    $path = $this->root.'/app/Nodeflow/Nodes/SendSms.php';

    expectParseablePhp($path);

    expect(file_get_contents($path))->toContain("->group('O\\'Brien')");
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

it('lets --force overwrite a node that already owns its registered type', function () {
    // The counterfactual: delete the same-class exemption in validateType() and this
    // fails — the command exits 1 with "is already registered by", because the class
    // being regenerated has itself claimed the type. --force exists for exactly this
    // case, so without the exemption the option cannot do the one job it has.
    //
    // Asserting on the absence of the collision message and on the rewritten body,
    // not on the exit code alone: a command that refused for some other reason, or
    // one that exited 0 having written nothing, would both pass a code-only check.
    writeRegisteredNode($this->root, 'SendForce', 'yaya.send_force', 'hand-written marker');

    $this->artisan('nodeflow:make-node', [
        'name' => 'SendForce',
        '--type' => 'yaya.send_force',
        '--force' => true,
    ])
        ->doesntExpectOutputToContain('is already registered by')
        ->assertExitCode(0);

    expect(file_get_contents($this->root.'/app/Nodeflow/Nodes/SendForce.php'))
        ->toContain("return 'yaya.send_force';")
        ->toContain('TODO: describe this node')
        ->not->toContain('hand-written marker');
});

it('refuses a registered node without --force as an existing file, not a type collision', function () {
    // The same exemption, seen from the other side. Both refusals exit 1, so only
    // the messages tell them apart: the counterfactual is deleting the exemption,
    // after which this fails because the author is told to "choose another type" —
    // the one action the node contract forbids for a node with published graph
    // versions resolving through that string.
    writeRegisteredNode($this->root, 'SendGuard', 'yaya.send_guard', 'hand-written marker');

    $this->artisan('nodeflow:make-node', ['name' => 'SendGuard', '--type' => 'yaya.send_guard'])
        ->expectsOutputToContain('Node already exists.')
        ->doesntExpectOutputToContain('Choose another type.')
        ->assertExitCode(1);

    expect(file_get_contents($this->root.'/app/Nodeflow/Nodes/SendGuard.php'))
        ->toContain('hand-written marker');
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
    //
    // The reason it also asserts the reason, and not just the snippet: three of
    // registerNode()'s match arms print the same snippet and differ only in the
    // sentence above it, so swapping two of those strings would pass a
    // snippet-only assertion in all three.
    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->expectsOutputToContain('No app/Providers/NodeflowServiceProvider.php found.')
        ->expectsOutputToContain('Nodeflow::register([')
        ->expectsOutputToContain('\App\Nodeflow\Nodes\SendSms::class')
        ->assertExitCode(0);
});

it('says the anchor is missing when the provider has no nodes array', function () {
    // The distinguishing half of the message is "has no": the ambiguous case says
    // "has more than one" about the same file and the same anchor. The
    // counterfactual is swapping those two strings, which nothing else notices.
    mkdir($this->root.'/app/Providers', 0777, true);
    file_put_contents($this->root.'/app/Providers/NodeflowServiceProvider.php', <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;

    class NodeflowServiceProvider extends ServiceProvider
    {
        public function boot(): void
        {
            //
        }
    }
    PHP);

    $before = file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php');

    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->expectsOutputToContain('has no `protected array $nodes = [` line')
        ->expectsOutputToContain('Nodeflow::register([')
        ->assertExitCode(0);

    // The provider is left exactly as it was: refusing to guess is the point.
    expect(file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php'))->toBe($before);
});

it('says the anchor is ambiguous when the provider has two nodes arrays', function () {
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

        protected array $nodes = [
            //
        ];
    }
    PHP);

    $before = file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php');

    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->expectsOutputToContain('has more than one `protected array $nodes = [` line')
        ->expectsOutputToContain('Nodeflow::register([')
        ->assertExitCode(0);

    expect(file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php'))->toBe($before);
});

it('generates no test unless asked', function () {
    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->assertExitCode(0);

    expect($this->root.'/tests/Feature/Nodeflow/SendSmsTest.php')->not->toBeFile();
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

    $test = file_get_contents($this->root.'/tests/Feature/Nodeflow/SendSmsTest.php');
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

    $test = file_get_contents($this->root.'/tests/Feature/Nodeflow/SendBatchTest.php');

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
        $this->root.'/tests/Feature/Nodeflow/'.$class.'Test.php',
    ];

    foreach ($paths as $path) {
        expect($path)->toBeFile();

        expectParseablePhp($path);
    }

    // No placeholder survived rendering in either file.
    foreach ($paths as $path) {
        expect(file_get_contents($path))->not->toContain('{{');
    }
})->with(['subject', 'audience', 'both']);

it('does not clobber a hand-edited test file on regeneration without --force', function () {
    // Finding: writeTest() skips an existing test file unless --force, warning as
    // it does so — but nothing in the suite exercised that branch. It matters more
    // than a typical uncovered branch because the stub it renders ends with
    // `// TODO: add a test per output`, i.e. it explicitly tells the author to
    // hand-edit this file. A silent overwrite destroys real work, not boilerplate.
    //
    // Reaching writeTest()'s own guard — rather than the unrelated "already
    // exists" guard GeneratorCommand::handle() runs first, which refuses the
    // whole command before writeTest() is ever reached — requires the class file
    // to be absent while the test file survives. Re-running with the class file
    // still present was tried and confirmed (by temporarily disabling this exact
    // guard) to pass regardless of whether the guard exists at all: the outer
    // "Node already exists." refusal intercepts first and writeTest() never runs,
    // so a test built that way could not have detected the regression it exists
    // to catch. Deleting the class file — standing in for an author who moved or
    // rewrote it while keeping their edited test — is what lets this test reach
    // the code the finding is actually about.
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendSms',
        '--type' => 'yaya.send_sms',
        '--test' => true,
    ])->assertExitCode(0);

    $testPath = $this->root.'/tests/Feature/Nodeflow/SendSmsTest.php';
    $handEdited = "<?php\n\n// hand-edited by the author — must survive regeneration\n";
    file_put_contents($testPath, $handEdited);

    unlink($this->root.'/app/Nodeflow/Nodes/SendSms.php');

    $this->artisan('nodeflow:make-node', [
        'name' => 'SendSms',
        '--type' => 'yaya.send_sms',
        '--test' => true,
    ])
        ->expectsOutputToContain('Test already exists at')
        ->assertExitCode(0);

    // Asserting on the exact content, not just that the file exists — a
    // file-exists check passes even with the guard deleted, since writeTest()
    // would just re-render the same path with fresh (non-hand-edited) content.
    expect(file_get_contents($testPath))->toBe($handEdited);
});

it('renders a group containing a placeholder literally instead of re-substituting it', function () {
    // F-1. The counterfactual: restore str_replace() in buildClass() and this
    // fails, because the sequential substitution turns --group='{{ outputs }}'
    // into ->group(''default'') — a parse error the command reports as success.
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendPlaceholder',
        '--type' => 'yaya.send_placeholder',
        '--group' => '{{ outputs }}',
    ])->assertExitCode(0);

    $path = $this->root.'/app/Nodeflow/Nodes/SendPlaceholder.php';

    expect(file_get_contents($path))->toContain("->group('{{ outputs }}')");

    // php -l is the only thing that catches an unparseable render, and it is
    // what reported success on the broken version.
    expectParseablePhp($path);
});

it('validates each invocation independently, even when the command instance is reused', function () {
    // Symfony's Application resolves one command object per command name and
    // keeps it for the process's lifetime, so a second artisan() call of
    // nodeflow:make-node reuses this exact same MakeNodeCommand instance
    // rather than a fresh one. Counterfactual: without resetting
    // $resolvedType at the top of handle(), nodeType() would short-circuit
    // on its memoized-not-null guard and return the FIRST call's already-
    // validated type, silently rendering the first node's type into the
    // second file while still reporting success — and published flow
    // versions resolve through that string forever, so the wrong value is
    // permanent, not cosmetic.
    $this->artisan('nodeflow:make-node', [
        'name' => 'FirstLeakProbeNode',
        '--type' => 'yaya.first_leak_probe',
    ])->assertExitCode(0);

    $this->artisan('nodeflow:make-node', [
        'name' => 'SecondLeakProbeNode',
        '--type' => 'yaya.second_leak_probe',
    ])->assertExitCode(0);

    $secondFile = file_get_contents($this->root.'/app/Nodeflow/Nodes/SecondLeakProbeNode.php');

    expect($secondFile)
        ->toContain("return 'yaya.second_leak_probe';")
        ->not->toContain("return 'yaya.first_leak_probe';");
});
