<?php

namespace Nodeflow\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Nodeflow\Nodes\NodeRegistry;
use Symfony\Component\Console\Input\InputOption;

use function Laravel\Prompts\text;

class MakeNodeCommand extends GeneratorCommand
{
    protected $name = 'nodeflow:make-node';

    protected $description = 'Create a Nodeflow node class.';

    protected $type = 'Node';

    private ?string $resolvedType = null;

    public function handle(): int
    {
        // All three are resolved before parent::handle() writes anything, so a
        // usage error never leaves a half-generated file behind.
        try {
            $this->cardinality();
            $this->nodeType();
            $this->outputNames();
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        // GeneratorCommand::handle() returns false when it refused to write (the
        // file already exists, the name is reserved) and null on success. Laravel
        // casts the return to an exit code with (int), which turns false into 0 —
        // a refusal would look like success to any caller. Map it explicitly
        // rather than inherit that wart.
        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $this->registerNode($this->qualifyClass($this->getNameInput()));

        if ($this->option('test')) {
            $this->writeTest($this->qualifyClass($this->getNameInput()));
        }

        return self::SUCCESS;
    }

    /**
     * Registration is explicit in this package by design — there is no directory
     * auto-discovery — so a generated node that nobody registers never reaches
     * the palette. The writer edits the provider only when it can prove where
     * the entry belongs; otherwise the author gets a line to paste, and is told
     * why they got it rather than left to wonder.
     */
    private function registerNode(string $nodeClass): void
    {
        $outcome = $this->laravel->make(NodeRegistrationWriter::class)->register(
            $this->laravel->basePath('app/Providers/NodeflowServiceProvider.php'),
            $nodeClass,
        );

        match ($outcome) {
            NodeRegistrationOutcome::Appended => $this->components->info(
                'Registered in app/Providers/NodeflowServiceProvider.php.'
            ),
            NodeRegistrationOutcome::AlreadyPresent => $this->components->info(
                'Already registered in app/Providers/NodeflowServiceProvider.php.'
            ),
            NodeRegistrationOutcome::ProviderMissing => $this->manualRegistration($nodeClass,
                'No app/Providers/NodeflowServiceProvider.php found.'
            ),
            NodeRegistrationOutcome::AnchorMissing => $this->manualRegistration($nodeClass,
                'app/Providers/NodeflowServiceProvider.php has no `'.NodeRegistrationWriter::ANCHOR.'` line.'
            ),
            NodeRegistrationOutcome::AnchorAmbiguous => $this->manualRegistration($nodeClass,
                'app/Providers/NodeflowServiceProvider.php has more than one `'.NodeRegistrationWriter::ANCHOR.'` line.'
            ),
        };
    }

    private function manualRegistration(string $nodeClass, string $because): void
    {
        $this->components->warn($because.' Register the node yourself:');
        $this->newLine();
        $this->line('    Nodeflow::register([');
        $this->line('        \\'.$nodeClass.'::class,');
        $this->line('    ]);');
        $this->newLine();
    }

    /**
     * The generated test asserts only what needs no database, because it lands in
     * the host's suite where the base TestCase is theirs. The four properties it
     * does assert are the ones that break silently: the type string, the declared
     * outputs, the cardinality interface, and that the registry accepts the class.
     */
    private function writeTest(string $nodeClass): void
    {
        $directory = $this->laravel->basePath('tests/Feature/Nodeflow');

        if (! $this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0777, true, true);
        }

        $class = class_basename($nodeClass);

        // {Class}Test, not {Class}NodeTest: the package's own nodes are WaitNode,
        // ExitNode, ConditionNode, so a host following that convention would get
        // SendSmsNodeNodeTest.php. This also matches Laravel's own generators.
        $path = $directory.'/'.$class.'Test.php';

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->warn("Test already exists at {$path}; left untouched.");

            return;
        }

        $outputs = $this->outputNames();

        [$imports, $expectations] = match ($this->cardinality()) {
            'audience' => [
                'use Nodeflow\Nodes\HandlesAudience;',
                '    expect(new '.$class.')->toBeInstanceOf(HandlesAudience::class);',
            ],
            'both' => [
                "use Nodeflow\Nodes\HandlesAudience;\nuse Nodeflow\Nodes\HandlesSubject;",
                '    expect(new '.$class.')->toBeInstanceOf(HandlesSubject::class)'.PHP_EOL
                    .'        ->toBeInstanceOf(HandlesAudience::class);',
            ],
            default => [
                'use Nodeflow\Nodes\HandlesSubject;',
                '    expect(new '.$class.')->toBeInstanceOf(HandlesSubject::class);',
            ],
        };

        // strtr for the same reason as buildClass(): see F-1 in paletteGroup().
        $this->files->put($path, strtr(
            $this->files->get($this->resolveStubPath('/stubs/node.test.stub')),
            [
                '{{ namespacedClass }}' => $nodeClass,
                '{{ cardinalityImports }}' => $imports,
                '{{ cardinalityExpectations }}' => $expectations,
                '{{ class }}' => $class,
                '{{ type }}' => $this->nodeType(),
                '{{ outputs }}' => implode(', ', array_map(fn (string $o) => "'{$o}'", $outputs)),
            ],
        ));

        $this->components->info("Test [{$path}] created successfully.");
    }

    protected function getStub(): string
    {
        return $this->resolveStubPath(match ($this->cardinality()) {
            'audience' => '/stubs/node.audience.stub',
            'both' => '/stubs/node.both.stub',
            default => '/stubs/node.stub',
        });
    }

    /**
     * Validated here rather than by an InputOption suggestion list, because an
     * unrecognised value would otherwise resolve a stub path that does not
     * exist and surface as a file-not-found rather than as a usage error.
     *
     * @throws \InvalidArgumentException
     */
    protected function cardinality(): string
    {
        $cardinality = strtolower(trim((string) $this->option('cardinality')));

        if (! in_array($cardinality, ['subject', 'audience', 'both'], true)) {
            throw new \InvalidArgumentException(
                "Unknown cardinality [{$cardinality}]. Use subject, audience, or both. ".
                'A node must implement at least one cardinality interface: forSubject() lets '.
                'the runtime chunk and iterate for you, forAudience() hands you the whole '.
                'audience for work that batches natively.'
            );
        }

        return $cardinality;
    }

    /**
     * Laravel's own generators let a host override a stub by placing a file of
     * the same name under its base path. Following that convention costs three
     * lines and is what a Laravel developer will expect.
     */
    protected function resolveStubPath(string $stub): string
    {
        $custom = $this->laravel->basePath(trim($stub, '/'));

        return file_exists($custom) ? $custom : __DIR__.'/../..'.$stub;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Nodeflow\Nodes';
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $outputs = $this->outputNames();

        // strtr, not str_replace: str_replace with array arguments is sequential
        // and re-substitutes inside its own output, so a --group value containing
        // a later placeholder rendered an unparseable file and exited 0 (F-1).
        return strtr($stub, [
            '{{ type }}' => $this->nodeType(),
            '{{ label }}' => Str::headline(class_basename($this->getNameInput())),
            '{{ group }}' => $this->paletteGroup(),
            '{{ outputs }}' => implode(', ', array_map(fn (string $o) => "'{$o}'", $outputs)),
            '{{ firstOutput }}' => $outputs[0],
        ]);
    }

    /** Reserved for the package's own nodes: core.wait, core.condition, and so on. */
    private const RESERVED_PREFIX = 'core.';

    /** Lowercase segments joined by dots or underscores: yaya.send_message, rada.read_severity. */
    private const TYPE_PATTERN = '/^[a-z0-9]+(?:[._][a-z0-9]+)*$/';

    /**
     * Lowercase segments joined by underscores: sent, failed, delivery_failed.
     * Narrower than TYPE_PATTERN by leaving out the dot — an output is a label on
     * an edge, not a namespaced identifier.
     */
    private const OUTPUT_PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/';

    protected function nodeType(): string
    {
        if ($this->resolvedType !== null) {
            return $this->resolvedType;
        }

        $suggested = Str::snake(class_basename($this->getNameInput()));

        $type = trim((string) $this->option('type'));

        // Guarded on isInteractive() rather than on the --no-interaction option:
        // a Testbench PendingCommand does not necessarily pass that flag, and an
        // unguarded prompt in a test suite hangs rather than fails.
        if ($type === '' && $this->input->isInteractive()) {
            $type = trim(text(
                label: 'Stable type identifier for this node',
                placeholder: 'yaya.send_message',
                default: $suggested,
                hint: 'Published flow versions resolve through this string forever. Prefix it with your domain.',
            ));
        } elseif ($type === '') {
            // Non-interactive with no --type (CI, --no-interaction): erroring
            // here would break legitimate scripting, but the derived value is
            // permanent and carries no domain prefix, silently violating the
            // convention the interactive prompt's own hint teaches. The choice
            // must be visible, so warn rather than stay silent.
            $type = $suggested;

            $this->components->warn(
                "No --type given; derived [{$type}] from the class name. Published flow ".
                'versions resolve through this string forever — pass --type explicitly '.
                'with your domain prefix.'
            );
        }

        return $this->resolvedType = $this->validateType($type);
    }

    /** @throws \InvalidArgumentException */
    private function validateType(string $type): string
    {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new \InvalidArgumentException(
                "[{$type}] is not a valid node type. Use lowercase letters, digits, dots and ".
                'underscores, e.g. yaya.send_message.'
            );
        }

        if (str_starts_with($type, self::RESERVED_PREFIX)) {
            throw new \InvalidArgumentException(
                "[{$type}] uses the reserved [core.] prefix, which belongs to the nodes the ".
                'package itself ships. Prefix your own types with your domain instead.'
            );
        }

        // NodeRegistry keys by type, so registering a second node with an existing
        // type silently replaces the first in every palette and every graph that
        // resolves it. Refuse here rather than let that be discovered at runtime.
        $registry = $this->laravel->make(NodeRegistry::class);

        if ($registry->has($type)) {
            // has() resolves through NodeRegistry's alias table, so the owner must
            // be looked up the same way. Indexing all() by the raw $type here would
            // miss any type reached only through an alias, since all() is keyed by
            // the canonical type, not every name that resolves to it.
            $existing = $registry->resolve($type)::class;

            // A node that is already registered owns its own type, so regenerating
            // it is not a collision. This exemption is what makes --force usable at
            // all: in a real host application providers boot before the command
            // runs, so the class being regenerated has already claimed its type and
            // every re-run would otherwise be refused — with advice ("choose
            // another type") that the node contract forbids for a live node, since
            // published graph versions and waiting runs resolve through that string
            // forever. Whether an existing file may be overwritten is
            // GeneratorCommand's already-exists guard's decision, not this rule's.
            if ($existing === $this->qualifyClass($this->getNameInput())) {
                return $type;
            }

            throw new \InvalidArgumentException(
                "Type [{$type}] is already registered by [{$existing}]. Two nodes sharing a ".
                'type silently replace each other in the registry. Choose another type.'
            );
        }

        return $type;
    }

    /**
     * Output names are rendered into two PHP files and are the edge labels a flow
     * graph routes on, so they are validated at least as tightly as the type.
     * Before this, `--outputs="it's"` rendered `->outputs(['it's'])` into both the
     * node and its test — a parse error in each — and the command still reported
     * success and exited 0.
     *
     * @return string[]
     *
     * @throws \InvalidArgumentException
     */
    protected function outputNames(): array
    {
        $outputs = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('outputs')),
        ), fn (string $o) => $o !== ''));

        if ($outputs === []) {
            return ['default'];
        }

        foreach ($outputs as $output) {
            if (preg_match(self::OUTPUT_PATTERN, $output) !== 1) {
                throw new \InvalidArgumentException(
                    "[{$output}] is not a valid output name. Use lowercase letters, digits and ".
                    'underscores, e.g. sent or delivery_failed. An output name is rendered into '.
                    'PHP and used as an edge label in a flow graph, so it stays conservative.'
                );
            }
        }

        // GraphValidator matches an edge to an output by name, and NodeDefinition
        // keys nothing by it, so a repeat is not caught downstream: it renders a
        // duplicated outputs() list and two edges no author can tell apart.
        $repeated = array_keys(array_filter(array_count_values($outputs), fn (int $n) => $n > 1));

        if ($repeated !== []) {
            throw new \InvalidArgumentException(
                'Duplicate output name ['.implode(', ', $repeated).']. Declare each output once — '.
                'a flow edge is matched to an output by name, so a repeat gives two edges the '.
                'same label.'
            );
        }

        return $outputs;
    }

    /**
     * Escaped rather than rejected, unlike the type and the output names: the group
     * is a human-facing palette label, and "Client's Tools" is a fair thing to call
     * one. It is rendered inside a single-quoted PHP string, so a backslash and a
     * single quote are escaped here.
     *
     * Escaping those two is NOT sufficient on its own, and a previous version of
     * this comment claimed it was. A value containing another stub placeholder —
     * `--group='{{ outputs }}'` — needed no quote to break the render, because the
     * renderer substituted this value and then kept substituting *inside it*.
     * buildClass() and writeTest() use strtr() rather than str_replace() for that
     * reason: strtr never re-scans what it has already written. Do not change
     * either back.
     */
    protected function paletteGroup(): string
    {
        return addcslashes((string) $this->option('group'), '\\\'');
    }

    protected function getOptions(): array
    {
        return [
            ['type', null, InputOption::VALUE_OPTIONAL, 'The stable type identifier, e.g. yaya.send_message'],
            ['cardinality', null, InputOption::VALUE_OPTIONAL, 'subject, audience, or both', 'subject'],
            ['outputs', null, InputOption::VALUE_OPTIONAL, 'Comma-separated output names', 'default'],
            ['group', null, InputOption::VALUE_OPTIONAL, 'Palette group shown in the editor', 'General'],
            ['test', null, InputOption::VALUE_NONE, 'Also generate a Pest test for the node'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the node, and the generated test, if they already exist'],
        ];
    }
}
