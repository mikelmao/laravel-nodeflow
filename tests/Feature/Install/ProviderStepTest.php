<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\ProviderStep;
use Nodeflow\Console\Install\ProviderStructureInspector;
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

it('accepts valid mixed-case PHP class aliases and registration method calls', function () {
    $source = str_replace('{{ namespace }}', 'App\\Providers', file_get_contents(__DIR__.'/../../../stubs/nodeflow-provider.stub'));
    $source = str_replace(
        ['use Nodeflow\\Nodeflow;', 'class NodeflowServiceProvider extends ServiceProvider', 'public function boot()', 'protected function subjectAttributes()', 'Nodeflow::register(', 'Nodeflow::registerTriggerDrivers(', 'Nodeflow::registerTriggerNodes(', 'Nodeflow::registerTriggerSources('],
        ['use Nodeflow\\Nodeflow as NoDeFlOwApi;', 'class nOdEfLoWsErViCePrOvIdEr extends sErViCePrOvIdEr', 'public function BoOt()', 'protected function SuBjEcTaTtRiBuTeS()', 'nOdEfLoWaPi::ReGiStEr(', 'nOdEfLoWaPi::ReGiStErTrIgGeRdRiVeRs(', 'nOdEfLoWaPi::ReGiStErTrIgGeRnOdEs(', 'nOdEfLoWaPi::ReGiStErTrIgGeRsOuRcEs('],
        $source,
    );

    expect(ProviderStructureInspector::valid($source, 'app\\providers'))->toBeTrue();
});

it('rejects mixed-case source registration before the driver phase', function () {
    $source = str_replace('{{ namespace }}', 'App\\Providers', file_get_contents(__DIR__.'/../../../stubs/nodeflow-provider.stub'));
    $source = str_replace(
        'Nodeflow::register($this->nodes);',
        "NoDeFlOw::ReGiStErTrIgGeRsOuRcEs([\\App\\EarlySource::class]);\n        Nodeflow::register(\$this->nodes);",
        $source,
    );

    expect(ProviderStructureInspector::valid($source, 'App\\Providers'))->toBeFalse();
});

it('validates a custom provider stub completely before creating the destination', function (Closure $mutate) {
    mkdir($this->root.'/stubs', 0777, true);
    $stub = file_get_contents(__DIR__.'/../../../stubs/nodeflow-provider.stub');
    file_put_contents($this->root.'/stubs/nodeflow-provider.stub', $mutate($stub));

    expect($this->step->apply())->toBe(InstallOutcome::CannotWire)
        ->and(file_exists($this->path))->toBeFalse();
})->with([
    'invalid syntax' => [fn (string $stub): string => $stub.' this is not php {'],
    'missing anchor' => [fn (string $stub): string => str_replace(NodeRegistrationWriter::TRIGGER_NODE_ANCHOR, 'protected array $other = [', $stub)],
    'duplicate anchor' => [fn (string $stub): string => str_replace(NodeRegistrationWriter::TRIGGER_NODE_ANCHOR, NodeRegistrationWriter::TRIGGER_NODE_ANCHOR."\n    ];\n\n    ".NodeRegistrationWriter::TRIGGER_NODE_ANCHOR, $stub)],
    'wrong property order' => [function (string $stub): string {
        $driver = strpos($stub, NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR);
        $node = strpos($stub, NodeRegistrationWriter::TRIGGER_NODE_ANCHOR);
        return substr_replace(substr_replace($stub, NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR, $node, strlen(NodeRegistrationWriter::TRIGGER_NODE_ANCHOR)), NodeRegistrationWriter::TRIGGER_NODE_ANCHOR, $driver, strlen(NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR));
    }],
    'wrong provider class' => [fn (string $stub): string => str_replace('class NodeflowServiceProvider', 'class OtherProvider', $stub)],
    'calls outside boot' => [fn (string $stub): string => str_replace('public function boot(): void', 'public function wire(): void', $stub)],
    'decoy provider class' => [fn (string $stub): string => str_replace(
        'class NodeflowServiceProvider extends ServiceProvider',
        'class DecoyProvider extends ServiceProvider',
        $stub,
    )."\n\nclass NodeflowServiceProvider extends ServiceProvider {}\n"],
    'anchors supplied by a trait' => [function (string $stub): string {
        $stub = str_replace(
            [
                NodeRegistrationWriter::ANCHOR,
                NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR,
                NodeRegistrationWriter::TRIGGER_NODE_ANCHOR,
                NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR,
            ],
            [
                'protected array $otherNodes = [',
                'protected array $otherDrivers = [',
                'protected array $otherTriggerNodes = [',
                'protected array $otherSources = [',
            ],
            $stub,
        );
        $trait = <<<'PHP'
        trait DecoyRegistrationHomes
        {
            protected array $nodes = [];
            protected array $triggerDrivers = [];
            protected array $triggerNodes = [];
            protected array $triggerSources = [];
        }


        PHP;

        return str_replace('class NodeflowServiceProvider', $trait.'class NodeflowServiceProvider', $stub);
    }],
    'calls supplied by strings' => [function (string $stub): string {
        return str_replace(
            [
                'Nodeflow::register($this->nodes);',
                'Nodeflow::registerTriggerDrivers($this->triggerDrivers);',
                'Nodeflow::registerTriggerNodes($this->triggerNodes);',
                'Nodeflow::registerTriggerSources($this->triggerSources);',
                'app(SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());',
            ],
            [
                "\$one = 'Nodeflow::register(\$this->nodes);';",
                "\$two = 'Nodeflow::registerTriggerDrivers(\$this->triggerDrivers);';",
                "\$three = 'Nodeflow::registerTriggerNodes(\$this->triggerNodes);';",
                "\$four = 'Nodeflow::registerTriggerSources(\$this->triggerSources);';",
                "\$five = 'app(SubjectAttributeRegistry::class)->register(...\$this->subjectAttributes());';",
            ],
            $stub,
        );
    }],
    'calls supplied by comments' => [fn (string $stub): string => str_replace(
        [
            'Nodeflow::register($this->nodes);',
            'Nodeflow::registerTriggerDrivers($this->triggerDrivers);',
            'Nodeflow::registerTriggerNodes($this->triggerNodes);',
            'Nodeflow::registerTriggerSources($this->triggerSources);',
            'app(SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());',
        ],
        [
            '// Nodeflow::register($this->nodes);',
            '// Nodeflow::registerTriggerDrivers($this->triggerDrivers);',
            '// Nodeflow::registerTriggerNodes($this->triggerNodes);',
            '// Nodeflow::registerTriggerSources($this->triggerSources);',
            '// app(SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());',
        ],
        $stub,
    )],
    'calls supplied by helper' => [function (string $stub): string {
        $stub = str_replace('public function boot(): void', 'private function helper(): void', $stub);

        return str_replace(
            NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
            "public function boot(): void\n    {\n    }\n\n    ".NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
            $stub,
        );
    }],
    'calls supplied by constructor' => [function (string $stub): string {
        $stub = str_replace('public function boot(): void', 'public function __construct()', $stub);

        return str_replace(
            NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
            "public function boot(): void\n    {\n    }\n\n    ".NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
            $stub,
        );
    }],
    'calls after provider class' => [function (string $stub): string {
        $start = strpos($stub, '    public function boot(): void');
        $end = strpos($stub, '    /** @return', $start);
        $boot = substr($stub, $start, $end - $start);
        $stub = substr_replace($stub, "    public function boot(): void\n    {\n    }\n\n", $start, $end - $start);

        return $stub."\n\nclass CallsAfterProvider\n{\n".str_replace('    public function boot(): void', '    public function helper(): void', $boot)."}\n";
    }],
    'multiple provider declarations' => [fn (string $stub): string => $stub."\n\nclass NodeflowServiceProvider extends ServiceProvider {}\n"],
    'ambiguous namespace' => [fn (string $stub): string => $stub."\n\nnamespace Other; class NodeflowServiceProvider {}\n"],
    'wrong namespace' => [fn (string $stub): string => str_replace('namespace {{ namespace }};', 'namespace Other\\Providers;', $stub)],
    'static boot with required parameter' => [fn (string $stub): string => str_replace(
        'public function boot(): void',
        'public static function boot(string $required): void',
        $stub,
    )],
    'registration calls guarded by a false expression' => [fn (string $stub): string => str_replace(
        [
            'Nodeflow::register($this->nodes);',
            'Nodeflow::registerTriggerDrivers($this->triggerDrivers);',
            'Nodeflow::registerTriggerNodes($this->triggerNodes);',
            'Nodeflow::registerTriggerSources($this->triggerSources);',
            'app(SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());',
        ],
        [
            'false && Nodeflow::register($this->nodes);',
            'false && Nodeflow::registerTriggerDrivers($this->triggerDrivers);',
            'false && Nodeflow::registerTriggerNodes($this->triggerNodes);',
            'false && Nodeflow::registerTriggerSources($this->triggerSources);',
            'false && app(SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());',
        ],
        $stub,
    )],
    'lookalike imports' => [fn (string $stub): string => str_replace(
        ['use Nodeflow\\Nodeflow;', 'use Nodeflow\\Schema\\SubjectAttributeRegistry;'],
        ['use Other\\Nodeflow;', 'use Other\\SubjectAttributeRegistry;'],
        $stub,
    )],
    'compound subject attribute return' => [fn (string $stub): string => str_replace(
        "return [\n        ];",
        'return [] + $invalid;',
        $stub,
    )],
    'conditional subject attribute return' => [fn (string $stub): string => str_replace(
        "return [\n        ];",
        "if (false) {\n            return [];\n        }\n\n        return [];",
        $stub,
    )],
    'provider class inside a false conditional' => [fn (string $stub): string => str_replace(
        'class NodeflowServiceProvider extends ServiceProvider',
        "if (false) {\nclass NodeflowServiceProvider extends ServiceProvider",
        $stub,
    )."\n}\n"],
    'static registration properties' => [fn (string $stub): string => str_replace(
        'protected array $',
        'static protected array $',
        $stub,
    )],
    'indexed subject attribute array' => [fn (string $stub): string => str_replace(
        "return [\n        ];",
        'return [1][0];',
        $stub,
    )],
    'coalesced subject attribute array' => [fn (string $stub): string => str_replace(
        "return [\n        ];",
        'return [$value] ?? [];',
        $stub,
    )],
    'abstract provider' => [fn (string $stub): string => str_replace(
        'class NodeflowServiceProvider extends ServiceProvider',
        'abstract class NodeflowServiceProvider extends ServiceProvider',
        $stub,
    )],
    'unrelated provider parent' => [fn (string $stub): string => str_replace(
        'class NodeflowServiceProvider extends ServiceProvider',
        'class NodeflowServiceProvider extends UnrelatedProvider',
        $stub,
    )],
    'imported non-Laravel app function' => [fn (string $stub): string => str_replace(
        'use Illuminate\\Support\\ServiceProvider;',
        "use Illuminate\\Support\\ServiceProvider;\nuse function Other\\app;",
        $stub,
    )],
    'aliased non-Laravel app function' => [fn (string $stub): string => str_replace(
        'use Illuminate\\Support\\ServiceProvider;',
        "use Illuminate\\Support\\ServiceProvider;\nuse function Other\\resolve as app;",
        $stub,
    )],
    'mixed-group imported app function' => [fn (string $stub): string => str_replace(
        'use Illuminate\\Support\\ServiceProvider;',
        "use Illuminate\\Support\\ServiceProvider;\nuse Other\\{function resolve as app};",
        $stub,
    )],
    'namespace-local app function' => [fn (string $stub): string => str_replace(
        'use Illuminate\\Support\\ServiceProvider;',
        "function app(...\$arguments): mixed { return null; }\n\nuse Illuminate\\Support\\ServiceProvider;",
        $stub,
    )],
    'direct source before driver phase' => [fn (string $stub): string => str_replace(
        'Nodeflow::register($this->nodes);',
        "Nodeflow::register(\$this->nodes);\n        Nodeflow::registerTriggerSources([\\App\\EarlySource::class]);",
        $stub,
    )],
    'direct node after source phase' => [fn (string $stub): string => str_replace(
        'Nodeflow::registerTriggerSources($this->triggerSources);',
        "Nodeflow::registerTriggerSources(\$this->triggerSources);\n        Nodeflow::registerTriggerNodes([\\App\\LateNode::class]);",
        $stub,
    )],
    'interleaved direct driver after node phase' => [fn (string $stub): string => str_replace(
        'Nodeflow::registerTriggerNodes($this->triggerNodes);',
        "Nodeflow::registerTriggerNodes(\$this->triggerNodes);\n        Nodeflow::registerTriggerDrivers([\\App\\LateDriver::class]);",
        $stub,
    )],
    'trigger registration hidden in a closure' => [fn (string $stub): string => str_replace(
        'Nodeflow::registerTriggerSources($this->triggerSources);',
        "Nodeflow::registerTriggerSources(\$this->triggerSources);\n        \$hidden = function (): void { Nodeflow::registerTriggerDrivers([\\App\\HiddenDriver::class]); };",
        $stub,
    )],
    'ambiguous dynamic trigger receiver' => [fn (string $stub): string => str_replace(
        'Nodeflow::registerTriggerSources($this->triggerSources);',
        "Nodeflow::registerTriggerSources(\$this->triggerSources);\n        (Nodeflow)::registerTriggerSources([\\App\\DynamicSource::class]);",
        $stub,
    )],
]);

it('removes a partially created provider when its destination write or verification throws', function (string $mode) {
    $files = new class($this->path, $mode) extends Filesystem
    {
        private bool $failedRead = false;

        public function __construct(private string $target, private string $mode) {}

        public function put($path, $contents, $lock = false)
        {
            if (str_contains($path, '.nodeflow-tmp-') && $this->mode === 'put') {
                parent::put($path, substr($contents, 0, -1), $lock);

                throw new RuntimeException('Injected destination write failure.');
            }

            return parent::put($path, $contents, $lock);
        }

        public function get($path, $lock = false)
        {
            if ($path === $this->target && $this->mode === 'get' && ! $this->failedRead) {
                $this->failedRead = true;
                throw new RuntimeException('Injected destination verification failure.');
            }

            return parent::get($path, $lock);
        }
    };
    $step = new ProviderStep($files, $this->root, 'App\\');

    expect($step->apply())->toBe(InstallOutcome::CannotWire)
        ->and($this->path)->not->toBeFile();
})->with([
    'write throws after partial bytes' => ['put'],
    'verification read throws after complete write' => ['get'],
]);

it('leaves no provider or sibling temp when the atomic create rename fails', function () {
    $files = new class extends Filesystem
    {
        public function move($path, $target)
        {
            if (str_contains($path, '.nodeflow-tmp-')) return false;

            return parent::move($path, $target);
        }
    };

    $step = new ProviderStep($files, $this->root, 'App\\');
    expect($step->apply())->toBe(InstallOutcome::CannotWire)
        ->and($this->path)->not->toBeFile()
        ->and(glob(dirname($this->path).'/*.nodeflow-tmp-*') ?: [])->toBe([]);
});

it('preserves a restrictive provider mode through an atomic upgrade', function () {
    if (DIRECTORY_SEPARATOR === '\\') $this->markTestSkipped('Unix modes are not portable to Windows.');

    file_put_contents($this->path, handWrittenProvider());
    chmod($this->path, 0600);

    expect($this->step->apply())->toBe(InstallOutcome::Wired)
        ->and(fileperms($this->path) & 0777)->toBe(0600);
});

it('accepts a real boot method containing nested closure braces', function () {
    mkdir($this->root.'/stubs', 0777, true);
    $stub = file_get_contents(__DIR__.'/../../../stubs/nodeflow-provider.stub');
    $stub = str_replace(
        "    public function boot(): void\n    {",
        "    public function boot(): void\n    {\n        \$decoy = function (): array { return ['brace' => '}']; };",
        $stub,
    );
    file_put_contents($this->root.'/stubs/nodeflow-provider.stub', $stub);

    expect($this->step->apply())->toBe(InstallOutcome::Wired);
    expectParseablePhp($this->path);
});

it('accepts unrelated direct host registrations in the real boot method', function () {
    mkdir($this->root.'/stubs', 0777, true);
    $stub = file_get_contents(__DIR__.'/../../../stubs/nodeflow-provider.stub');
    $stub = str_replace(
        "    public function boot(): void\n    {",
        "    public function boot(): void\n    {\n        \$this->app->register(\\App\\Providers\\AuthServiceProvider::class);",
        $stub,
    );
    file_put_contents($this->root.'/stubs/nodeflow-provider.stub', $stub);

    expect($this->step->apply())->toBe(InstallOutcome::Wired);
    expectParseablePhp($this->path);
});

it('accepts direct trigger registrations when every call stays in driver node source phases', function () {
    mkdir($this->root.'/stubs', 0777, true);
    $stub = file_get_contents(__DIR__.'/../../../stubs/nodeflow-provider.stub');
    $stub = str_replace(
        [
            'Nodeflow::registerTriggerDrivers($this->triggerDrivers);',
            'Nodeflow::registerTriggerNodes($this->triggerNodes);',
            'Nodeflow::registerTriggerSources($this->triggerSources);',
        ],
        [
            "Nodeflow::registerTriggerDrivers([\\App\\FirstDriver::class]);\n        Nodeflow::registerTriggerDrivers(\$this->triggerDrivers);",
            "Nodeflow::registerTriggerNodes([\\App\\FirstNode::class]);\n        Nodeflow::registerTriggerNodes(\$this->triggerNodes);",
            "Nodeflow::registerTriggerSources([\\App\\FirstSource::class]);\n        Nodeflow::registerTriggerSources(\$this->triggerSources);",
        ],
        $stub,
    );
    file_put_contents($this->root.'/stubs/nodeflow-provider.stub', $stub);

    expect($this->step->apply())->toBe(InstallOutcome::Wired);
});

it('leaves an existing structurally decoy provider untouched', function () {
    $stub = file_get_contents(__DIR__.'/../../../stubs/nodeflow-provider.stub');
    $contents = str_replace('{{ namespace }}', 'App\\Providers', $stub);
    $contents = str_replace(
        'class NodeflowServiceProvider extends ServiceProvider',
        'class DecoyProvider extends ServiceProvider',
        $contents,
    )."\n\nclass NodeflowServiceProvider extends ServiceProvider {}\n";
    file_put_contents($this->path, $contents);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire)
        ->and($this->step->apply())->toBe(InstallOutcome::CannotWire)
        ->and(file_get_contents($this->path))->toBe($contents);
});

it('does not treat duplicate or out-of-order complete providers as ready and leaves them untouched', function (Closure $mutate) {
    $this->step->apply();
    $contents = $mutate(file_get_contents($this->path));
    file_put_contents($this->path, $contents);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire)
        ->and($this->step->apply())->toBe(InstallOutcome::CannotWire)
        ->and(file_get_contents($this->path))->toBe($contents);
})->with([
    'duplicate registration call' => [fn (string $contents): string => str_replace(
        'Nodeflow::registerTriggerNodes($this->triggerNodes);',
        "Nodeflow::registerTriggerNodes(\$this->triggerNodes);\n        Nodeflow::registerTriggerNodes(\$this->triggerNodes);",
        $contents,
    )],
    'duplicate exact anchor' => [fn (string $contents): string => str_replace(
        NodeRegistrationWriter::TRIGGER_NODE_ANCHOR,
        NodeRegistrationWriter::TRIGGER_NODE_ANCHOR."\n    ];\n\n    ".NodeRegistrationWriter::TRIGGER_NODE_ANCHOR,
        $contents,
    )],
    'out of order registration calls' => [function (string $contents): string {
        return str_replace(
            [
                'Nodeflow::registerTriggerDrivers($this->triggerDrivers);',
                'Nodeflow::registerTriggerNodes($this->triggerNodes);',
            ],
            [
                'Nodeflow::registerTriggerNodes($this->triggerNodes);',
                'Nodeflow::registerTriggerDrivers($this->triggerDrivers);',
            ],
            $contents,
        );
    }],
    'out of order properties' => [function (string $contents): string {
        return str_replace(
            [NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR, NodeRegistrationWriter::TRIGGER_NODE_ANCHOR],
            [NodeRegistrationWriter::TRIGGER_NODE_ANCHOR, NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR],
            $contents,
        );
    }],
]);

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
