<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\ProviderStep;
use Nodeflow\Console\NodeRegistrationWriter;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-provider-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/app/Providers', 0777, true);

    $this->step = new ProviderStep(
        new Filesystem,
        $this->root,
        'App\\',
    );

    $this->path = $this->root.'/'.ProviderStep::PATH;
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

it('reports the provider as writable when it does not exist', function () {
    expect($this->step->check())->toBe(InstallOutcome::Writable);
});

it('creates a provider whose trigger extension anchors each appear exactly once', function () {
    // Counterfactual: put `protected array $nodes = [];` on one line twice in the
    // stub, or omit it, and this fails — NodeRegistrationWriter refuses on zero
    // matches and on more than one, so nodeflow:make-node could never register
    // into the file install just created.
    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    $contents = file_get_contents($this->path);

    expect(substr_count($contents, NodeRegistrationWriter::ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::TRIGGER_NODE_ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::ATTRIBUTE_ANCHOR))->toBe(1);
});

it('creates a provider in the host root namespace that parses', function () {
    $this->step->apply();

    $contents = file_get_contents($this->path);

    expect($contents)->toContain('namespace App\Providers;')
        ->toContain('class NodeflowServiceProvider extends ServiceProvider');

    expectParseablePhp($this->path);
});

it('registers host trigger extensions in driver node source order', function () {
    $this->step->apply();
    $contents = file_get_contents($this->path);

    $driver = strpos($contents, 'registerTriggerDrivers($this->triggerDrivers)');
    $node = strpos($contents, 'registerTriggerNodes($this->triggerNodes)');
    $source = strpos($contents, 'registerTriggerSources($this->triggerSources)');

    expect($driver)->toBeInt()->and($node)->toBeInt()->and($source)->toBeInt()
        ->and($driver)->toBeLessThan($node)
        ->and($node)->toBeLessThan($source);
});

it('upgrades a CRLF host provider without introducing bare line feeds', function () {
    file_put_contents($this->path, str_replace("\n", "\r\n", handWrittenProvider()));

    expect($this->step->apply())->toBe(InstallOutcome::Wired);
    $contents = file_get_contents($this->path);

    expect(preg_match('/(?<!\r)\n/', $contents))->toBe(0);
});

it('creates a provider all extension generators can append into', function () {
    // The composition test. Counterfactual: change the stub's empty arrays to
    // `= [];` on one line and the node/trigger appends still work but render
    // valid-and-ugly; change the attribute method's body shape and the attribute
    // append returns AnchorMissing. Either way this test names which one broke.
    $this->step->apply();

    $writer = new NodeRegistrationWriter(new Filesystem);

    expect($writer->register($this->path, 'App\Nodeflow\Nodes\SendSms'))
        ->toBe(\Nodeflow\Console\NodeRegistrationOutcome::Appended);

    expect($writer->appendTo(
        $this->path,
        NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR,
        'App\Nodeflow\TriggerDrivers\CustomDriver::class',
        '\App\Nodeflow\TriggerDrivers\CustomDriver::class',
    ))->toBe(\Nodeflow\Console\NodeRegistrationOutcome::Appended);

    expect($writer->appendTo(
        $this->path,
        NodeRegistrationWriter::TRIGGER_NODE_ANCHOR,
        'App\Nodeflow\Triggers\OrderPlaced::class',
        '\App\Nodeflow\Triggers\OrderPlaced::class',
    ))->toBe(\Nodeflow\Console\NodeRegistrationOutcome::Appended);

    expect($writer->appendTo(
        $this->path,
        NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR,
        'App\Nodeflow\TriggerSources\OrderPlaced::class',
        '\App\Nodeflow\TriggerSources\OrderPlaced::class',
    ))->toBe(\Nodeflow\Console\NodeRegistrationOutcome::Appended);

    expect($writer->appendTo(
        $this->path,
        NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        "SubjectAttribute::make('clicked'",
        "\Nodeflow\Schema\SubjectAttribute::make('clicked', 'Clicked', 'boolean', fn (\$subject) => null)",
        '            ',
    ))->toBe(\Nodeflow\Console\NodeRegistrationOutcome::Appended);

    // All three appended, and the result still parses.
    expectParseablePhp($this->path);
});

it('reports already present when the provider exists', function () {
    $this->step->apply();

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('does not rewrite a provider that already exists', function () {
    // Idempotency, asserted byte-for-byte rather than by outcome alone.
    $this->step->apply();

    $before = file_get_contents($this->path);

    $this->step->apply();

    expect(file_get_contents($this->path))->toBe($before);
});

/** A provider shaped the way docs/02-integration.md taught, which is what the demo has. */
function handWrittenProvider(): string
{
    return <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;
    use Nodeflow\Nodeflow;

    class NodeflowServiceProvider extends ServiceProvider
    {
        public function boot(): void
        {
            Nodeflow::register([
                \App\Nodeflow\Nodes\SendMessage::class,
            ]);
        }
    }
    PHP;
}

it('reports a provider without the anchors as writable', function () {
    // Counterfactual: keep Task 4's `exists() ? AlreadyPresent : Writable` and
    // this fails — the host who followed the docs is told everything is fine
    // while make-node still cannot register into their file.
    file_put_contents($this->path, handWrittenProvider());

    expect($this->step->check())->toBe(InstallOutcome::Writable);
});

it('adds all three homes to a hand-written provider without touching its register call', function () {
    file_put_contents($this->path, handWrittenProvider());

    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    $contents = file_get_contents($this->path);

    expect(substr_count($contents, NodeRegistrationWriter::ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::TRIGGER_NODE_ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::ATTRIBUTE_ANCHOR))->toBe(1);

    // The host's own registration survives verbatim. Counterfactual: rewrite the
    // existing list into $nodes instead of leaving it, and this fails.
    expect($contents)->toContain('\App\Nodeflow\Nodes\SendMessage::class,');

    // Fully-qualified, unlike the stub's own use-imported form: this file's
    // existing imports are unknown, so the insertion cannot rely on one.
    expect($contents)->toContain('\Nodeflow\Nodeflow::register($this->nodes);')
        ->toContain('\Nodeflow\Nodeflow::registerTriggerDrivers($this->triggerDrivers);')
        ->toContain('\Nodeflow\Nodeflow::registerTriggerNodes($this->triggerNodes);')
        ->toContain('\Nodeflow\Nodeflow::registerTriggerSources($this->triggerSources);')
        ->toContain('app(\Nodeflow\Schema\SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());');

    expectParseablePhp($this->path);
});

it('adds only the missing home when one is already there', function () {
    // Counterfactual: insert unconditionally rather than per-home, and this fails
    // with two $nodes arrays — which is exactly the AnchorAmbiguous state that
    // makes the writer refuse every future make-node.
    file_put_contents($this->path, str_replace(
        "    public function boot(): void",
        "    protected array \$nodes = [\n    ];\n\n    public function boot(): void",
        handWrittenProvider(),
    ));

    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    $contents = file_get_contents($this->path);

    expect(substr_count($contents, NodeRegistrationWriter::ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::TRIGGER_NODE_ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR))->toBe(1);
});

it('is idempotent on a provider it already wired', function () {
    file_put_contents($this->path, handWrittenProvider());

    $this->step->apply();
    $before = file_get_contents($this->path);

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->step->apply())->toBe(InstallOutcome::AlreadyPresent);
    expect(file_get_contents($this->path))->toBe($before);
});

it('refuses a provider with no boot method and offers the snippet', function () {
    // Counterfactual: synthesise a boot() method and this fails — writing a new
    // method into someone else's class is the one edit this step will not make,
    // because there is no anchor that proves where it belongs.
    file_put_contents($this->path, <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;

    class NodeflowServiceProvider extends ServiceProvider
    {
    }
    PHP);

    $before = file_get_contents($this->path);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect($this->step->snippet())->toContain('protected array $nodes = [');
    expect(file_get_contents($this->path))->toBe($before);
});

it('refuses a differently formatted trigger home instead of creating a duplicate property', function () {
    file_put_contents($this->path, str_replace(
        '    public function boot(): void',
        "    protected array \$triggerDrivers=[];\n\n    public function boot(): void",
        handWrittenProvider(),
    ));
    $before = file_get_contents($this->path);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire)
        ->and($this->step->apply())->toBe(InstallOutcome::CannotWire)
        ->and(file_get_contents($this->path))->toBe($before)
        ->and($this->step->snippet())->toContain('protected array $triggerDrivers = [');
});

/**
 * C4. All three registration homes exist, but every boot() call is commented
 * out — the exact host where nothing registers and the palette is empty.
 */
function providerWithCommentedOutBootCalls(): string
{
    return <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;
    use Nodeflow\Nodeflow;

    class NodeflowServiceProvider extends ServiceProvider
    {
        protected array $nodes = [
        ];

        protected array $triggerDrivers = [
        ];

        protected array $triggerNodes = [
        ];

        protected array $triggerSources = [
        ];

        public function boot(): void
        {
            // Nodeflow::register($this->nodes);
            // Nodeflow::registerTriggerDrivers($this->triggerDrivers);
            // Nodeflow::registerTriggerNodes($this->triggerNodes);
            // Nodeflow::registerTriggerSources($this->triggerSources);
            // app(SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());
        }

        /** @return \Nodeflow\Schema\SubjectAttribute[] */
        protected function subjectAttributes(): array
        {
            return [
            ];
        }
    }
    PHP;
}

it('reports a provider with every boot() call commented out as writable, not already present', function () {
    // Counterfactual: match the boot() needles against raw text and this fails
    // — the commented-out calls are found "raw" and check() reports
    // AlreadyPresent, which is `install` exit 0 on a host where nothing
    // registers and the palette is empty (E22).
    file_put_contents($this->path, providerWithCommentedOutBootCalls());

    expect($this->step->check())->toBe(InstallOutcome::Writable);
});

it('adds real boot() calls next to the commented-out ones and ends up wired', function () {
    file_put_contents($this->path, providerWithCommentedOutBootCalls());

    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    $contents = file_get_contents($this->path);

    expect($contents)->toContain('\Nodeflow\Nodeflow::register($this->nodes);')
        ->toContain('\Nodeflow\Nodeflow::registerTriggerDrivers($this->triggerDrivers);')
        ->toContain('\Nodeflow\Nodeflow::registerTriggerNodes($this->triggerNodes);')
        ->toContain('\Nodeflow\Nodeflow::registerTriggerSources($this->triggerSources);')
        ->toContain('app(\Nodeflow\Schema\SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());')
        // The commented-out calls survive untouched alongside the real ones.
        ->toContain('// Nodeflow::register($this->nodes);');

    expectParseablePhp($this->path);

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('refuses a provider with two boot methods and writes nothing', function () {
    // A duplicated anchor means the step cannot know which boot() the host runs.
    file_put_contents($this->path, str_replace(
        '    public function boot(): void',
        "    public function boot(): void\n    {\n    }\n\n    public function boot(): void",
        handWrittenProvider(),
    ));

    $before = file_get_contents($this->path);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect(file_get_contents($this->path))->toBe($before);
});
