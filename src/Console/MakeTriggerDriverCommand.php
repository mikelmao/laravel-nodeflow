<?php

namespace Nodeflow\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Support\StableKey;
use Nodeflow\Triggers\TriggerDriverRegistry;
use Symfony\Component\Console\Input\InputOption;

class MakeTriggerDriverCommand extends GeneratorCommand
{
    protected $name = 'nodeflow:make-trigger-driver';

    protected $description = 'Create an atomic Nodeflow trigger driver extension kit.';

    protected $type = 'Trigger driver kit';

    private ?string $resolvedKey = null;

    public function handle(): int
    {
        $this->resolvedKey = null;

        try {
            $this->assertSafeName();
            if ($this->isReservedName($this->getNameInput())) {
                throw new InvalidArgumentException('The trigger driver name is reserved by PHP.');
            }
            $key = $this->driverKey();
            $driverClass = $this->qualifyClass($this->getNameInput());
            $nodeClass = rtrim($this->rootNamespace(), '\\').'\\Nodeflow\\Triggers\\'.class_basename($driverClass).'Trigger';
            $type = StableKey::assert($key.'.trigger', 'graph node type', 255);

            if ($this->laravel->make(GraphTypeCatalog::class)->family($type) !== null) {
                throw new InvalidArgumentException("Reference trigger graph type [{$type}] is already registered.");
            }

            $paths = [
                $this->getPath($driverClass) => $this->sortImports($this->buildClass($driverClass)),
                $this->getPath($nodeClass) => $this->renderReferenceNode($nodeClass, $key, $type),
                $this->testPath($driverClass) => $this->renderContractTest($driverClass, $nodeClass, $key, $type),
            ];

            $this->assertAvailableClasses([$driverClass, $nodeClass]);
            $this->laravel->make(VerifiedGeneratorWriter::class)->write(
                $paths,
                (bool) $this->option('force'),
            );
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Trigger driver [{$driverClass}] created.");
        $this->components->info("Reference trigger [{$nodeClass}] created with type [{$type}].");
        $this->components->info('Contract test ['.$this->testPath($driverClass).'] created.');
        $this->registerKit($driverClass, $nodeClass);

        return self::SUCCESS;
    }

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/trigger-driver.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Nodeflow\\TriggerDrivers';
    }

    protected function buildClass($name): string
    {
        return strtr(parent::buildClass($name), [
            '{{ key }}' => $this->driverKey(),
            '{{ label }}' => Str::headline(class_basename($this->getNameInput())),
        ]);
    }

    protected function resolveStubPath(string $stub): string
    {
        $custom = $this->laravel->basePath(trim($stub, '/'));

        return file_exists($custom) ? $custom : __DIR__.'/../..'.$stub;
    }

    private function renderReferenceNode(string $class, string $driver, string $type): string
    {
        $stub = $this->files->get($this->resolveStubPath('/stubs/trigger-node.stub'));

        return strtr($stub, [
            '{{ namespace }}' => Str::beforeLast($class, '\\'),
            '{{ class }}' => class_basename($class),
            '{{ type }}' => $type,
            '{{ driver }}' => $driver,
            '{{ label }}' => Str::headline(class_basename($class)),
        ]);
    }

    private function renderContractTest(string $driverClass, string $nodeClass, string $key, string $type): string
    {
        return strtr($this->files->get(__DIR__.'/../../stubs/trigger-driver.test.stub'), [
            '{{ driver }}' => $driverClass,
            '{{ node }}' => $nodeClass,
            '{{ driverClass }}' => class_basename($driverClass),
            '{{ nodeClass }}' => class_basename($nodeClass),
            '{{ key }}' => $key,
            '{{ type }}' => $type,
        ]);
    }

    private function testPath(string $driverClass): string
    {
        return $this->laravel->basePath('tests/Feature/Nodeflow/TriggerDrivers/'.class_basename($driverClass).'Test.php');
    }

    /**
     * @param  string[]  $classes
     */
    private function assertAvailableClasses(array $classes): void
    {
        foreach ($classes as $class) {
            if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
                throw new InvalidArgumentException("Generated class [{$class}] already exists.");
            }
        }
    }

    private function driverKey(): string
    {
        if ($this->resolvedKey !== null) {
            return $this->resolvedKey;
        }

        $key = trim((string) $this->option('key'));
        if ($key === '') {
            $key = Str::snake(class_basename($this->getNameInput()));
            $this->components->warn("No --key given; derived [{$key}] from the class name.");
        }
        StableKey::assert($key, 'trigger driver key', 191);

        if (in_array($key, ['webhook', 'model', 'event', 'manual', 'subflow'], true) || str_starts_with($key, 'core.')) {
            throw new InvalidArgumentException("Trigger driver key [{$key}] is reserved.");
        }

        $drivers = $this->laravel->make(TriggerDriverRegistry::class);
        if ($drivers->has($key)) {
            $existing = $drivers->all()[$key];
            throw new InvalidArgumentException("Trigger driver key [{$key}] is already registered by [{$existing}].");
        }

        return $this->resolvedKey = $key;
    }

    private function assertSafeName(): void
    {
        $name = trim($this->getNameInput(), '\\');
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/D', $name) !== 1) {
            throw new InvalidArgumentException('The trigger driver name must be a PHP class name and may not contain path traversal.');
        }
    }

    private function registerKit(string $driverClass, string $nodeClass): void
    {
        $outcome = $this->laravel->make(NodeRegistrationWriter::class)->appendMany(
            $this->laravel->basePath('app/Providers/NodeflowServiceProvider.php'),
            [
                ['anchor' => NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR, 'presence' => $driverClass.'::class', 'entry' => '\\'.$driverClass.'::class'],
                ['anchor' => NodeRegistrationWriter::TRIGGER_NODE_ANCHOR, 'presence' => $nodeClass.'::class', 'entry' => '\\'.$nodeClass.'::class'],
            ],
        );

        if (in_array($outcome, [NodeRegistrationOutcome::Appended, NodeRegistrationOutcome::AlreadyPresent], true)) {
            $this->components->info('Registered trigger driver and reference node in dependency order.');

            return;
        }

        $this->components->warn('Automatic provider registration was unsafe. Register the extension kit yourself in this order:');
        $this->line("    \\Nodeflow\\Nodeflow::registerTriggerDrivers([\\{$driverClass}::class]);");
        $this->line("    \\Nodeflow\\Nodeflow::registerTriggerNodes([\\{$nodeClass}::class]);");
    }

    protected function getOptions(): array
    {
        return [
            ['key', null, InputOption::VALUE_OPTIONAL, 'Stable trigger driver key'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the complete extension kit'],
        ];
    }
}
