<?php

namespace Nodeflow\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Support\StableKey;
use Nodeflow\Triggers\TriggerDriverRegistry;
use Symfony\Component\Console\Input\InputOption;

class MakeTriggerCommand extends GeneratorCommand
{
    protected $name = 'nodeflow:make-trigger';

    protected $description = 'Create a Nodeflow trigger node class.';

    protected $type = 'Trigger node';

    private ?string $resolvedDriver = null;

    private ?string $resolvedType = null;

    public function handle(): int
    {
        $this->resolvedDriver = null;
        $this->resolvedType = null;
        $registrationOutcome = null;
        $class = null;

        try {
            $this->assertSafeName();
            if ($this->isReservedName($this->getNameInput())) {
                throw new InvalidArgumentException('The trigger node name is reserved by PHP.');
            }
            $this->driverKey();
            $this->graphType();
            $class = $this->qualifyClass($this->getNameInput());
            if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
                throw new InvalidArgumentException("Generated class [{$class}] already exists.");
            }
            $path = $this->getPath($class);
            $contents = $this->sortImports($this->buildClass($class));
            $registration = $this->registrationPlan($class);
            $registrationOutcome = $registration->outcome;
            if ($registrationOutcome === NodeRegistrationOutcome::WriteFailed) {
                throw new InvalidArgumentException('Automatic provider registration could not be planned safely.');
            }
            (new VerifiedGeneratorWriter($this->laravel->make('files'), [$this->laravel->basePath('app')]))->write(
                [$path => $contents],
                (bool) $this->option('force'),
                $registration,
            );
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Trigger node [{$path}] created successfully.");
        if ($this->requiresManualRegistration($registrationOutcome)) {
            $this->manualRegistration($class);
        } else {
            $this->components->info($registrationOutcome === NodeRegistrationOutcome::Appended
                ? 'Registered in app/Providers/NodeflowServiceProvider.php.'
                : 'Already registered in app/Providers/NodeflowServiceProvider.php.');
        }

        return self::SUCCESS;
    }

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/trigger-node.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Nodeflow\Triggers';
    }

    protected function buildClass($name): string
    {
        return strtr(parent::buildClass($name), [
            '{{ type }}' => $this->graphType(),
            '{{ driver }}' => $this->driverKey(),
            '{{ label }}' => Str::headline(class_basename($this->getNameInput())),
        ]);
    }

    protected function resolveStubPath(string $stub): string
    {
        $custom = $this->laravel->basePath(trim($stub, '/'));

        return file_exists($custom) ? $custom : __DIR__.'/../..'.$stub;
    }

    private function driverKey(): string
    {
        if ($this->resolvedDriver !== null) {
            return $this->resolvedDriver;
        }

        $driver = StableKey::assert(trim((string) $this->option('driver')), 'trigger driver key', 191);

        if (! $this->laravel->make(TriggerDriverRegistry::class)->has($driver)) {
            throw new InvalidArgumentException("Trigger driver [{$driver}] is not registered.");
        }

        return $this->resolvedDriver = $driver;
    }

    private function graphType(): string
    {
        if ($this->resolvedType !== null) {
            return $this->resolvedType;
        }

        $type = trim((string) $this->option('type'));
        if ($type === '') {
            $type = Str::snake(class_basename($this->getNameInput()));
            $this->components->warn("No --type given; derived [{$type}] from the class name.");
        }

        StableKey::assert($type, 'graph node type', 255);

        if (str_starts_with($type, 'core.')) {
            throw new InvalidArgumentException("Graph node type [{$type}] uses the package-reserved [core.] prefix.");
        }

        $family = $this->laravel->make(GraphTypeCatalog::class)->family($type);
        if ($family !== null) {
            throw new InvalidArgumentException("Graph node type [{$type}] is already registered as [{$family}].");
        }

        return $this->resolvedType = $type;
    }

    private function assertSafeName(): void
    {
        $name = trim($this->getNameInput(), '\\');
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/D', $name) !== 1) {
            throw new InvalidArgumentException('The trigger node name must be a PHP class name and may not contain path traversal.');
        }
    }

    private function registrationPlan(string $class): NodeRegistrationPlan
    {
        return (new NodeRegistrationWriter($this->laravel->make('files'), [$this->laravel->basePath('app')]))->planAppendTo(
            $this->laravel->basePath('app/Providers/NodeflowServiceProvider.php'),
            NodeRegistrationWriter::TRIGGER_NODE_ANCHOR,
            ltrim($class, '\\').'::class',
            '\\'.ltrim($class, '\\').'::class',
        );
    }

    private function manualRegistration(string $class): void
    {
        $this->components->warn('Automatic provider registration was unsafe. Register the trigger node yourself:');
        $this->line('    \\Nodeflow\\Nodeflow::registerTriggerNodes([');
        $this->line('        \\'.ltrim($class, '\\').'::class,');
        $this->line('    ]);');
    }

    private function requiresManualRegistration(?NodeRegistrationOutcome $outcome): bool
    {
        return in_array($outcome, [
            NodeRegistrationOutcome::ProviderMissing,
            NodeRegistrationOutcome::AnchorMissing,
            NodeRegistrationOutcome::AnchorAmbiguous,
        ], true);
    }

    protected function getOptions(): array
    {
        return [
            ['driver', null, InputOption::VALUE_REQUIRED, 'Registered trigger driver key'],
            ['type', null, InputOption::VALUE_OPTIONAL, 'Stable graph node type'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the trigger node if it exists'],
        ];
    }
}
