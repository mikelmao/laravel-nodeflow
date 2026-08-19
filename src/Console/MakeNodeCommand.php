<?php

namespace Nodeflow\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

class MakeNodeCommand extends GeneratorCommand
{
    protected $name = 'nodeflow:make-node';

    protected $description = 'Create a Nodeflow node class.';

    protected $type = 'Node';

    public function handle(): int
    {
        try {
            $this->cardinality();
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

    protected function nodeType(): string
    {
        return (string) ($this->option('type') ?: Str::snake(class_basename($this->getNameInput())));
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
