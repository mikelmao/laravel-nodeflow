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

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/node.stub');
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
