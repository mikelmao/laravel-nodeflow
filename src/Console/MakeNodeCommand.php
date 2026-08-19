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
        // Both are resolved before parent::handle() writes anything, so a usage
        // error never leaves a half-generated file behind.
        try {
            $this->cardinality();
            $this->nodeType();
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

        return self::SUCCESS;
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
     * the same name under its base path. Following that convention costs six
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

        return str_replace(
            ['{{ type }}', '{{ label }}', '{{ group }}', '{{ outputs }}', '{{ firstOutput }}'],
            [
                $this->nodeType(),
                Str::headline(class_basename($this->getNameInput())),
                (string) $this->option('group'),
                implode(', ', array_map(fn (string $o) => "'{$o}'", $outputs)),
                $outputs[0],
            ],
            $stub,
        );
    }

    /** Reserved for the package's own nodes: core.wait, core.condition, and so on. */
    private const RESERVED_PREFIX = 'core.';

    /** Lowercase segments joined by dots or underscores: yaya.send_message, rada.read_severity. */
    private const TYPE_PATTERN = '/^[a-z0-9]+(?:[._][a-z0-9]+)*$/';

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

            throw new \InvalidArgumentException(
                "Type [{$type}] is already registered by [{$existing}]. Two nodes sharing a ".
                'type silently replace each other in the registry. Choose another type.'
            );
        }

        return $type;
    }

    /** @return string[] */
    protected function outputNames(): array
    {
        $outputs = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('outputs')),
        ), fn (string $o) => $o !== ''));

        return $outputs === [] ? ['default'] : $outputs;
    }

    protected function getOptions(): array
    {
        return [
            ['type', null, InputOption::VALUE_OPTIONAL, 'The stable type identifier, e.g. yaya.send_message'],
            ['cardinality', null, InputOption::VALUE_OPTIONAL, 'subject, audience, or both', 'subject'],
            ['outputs', null, InputOption::VALUE_OPTIONAL, 'Comma-separated output names', 'default'],
            ['group', null, InputOption::VALUE_OPTIONAL, 'Palette group shown in the editor', 'General'],
            ['test', null, InputOption::VALUE_NONE, 'Also generate a Pest test for the node'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the node if it already exists'],
        ];
    }
}
