<?php

namespace Nodeflow\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nodeflow\Support\StableKey;
use Nodeflow\Triggers\TriggerDriverRegistry;
use Symfony\Component\Console\Input\InputOption;

class MakeTriggerDriverCommand extends GeneratorCommand
{
    protected $name = 'nodeflow:make-trigger-driver';

    protected $description = 'Create a Nodeflow trigger driver class.';

    protected $type = 'Trigger driver';

    private ?string $resolvedKey = null;

    public function handle(): int
    {
        $this->resolvedKey = null;

        try {
            $this->assertSafeName();
            $this->driverKey();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (parent::handle() === false) return self::FAILURE;

        $this->register($this->qualifyClass($this->getNameInput()));

        return self::SUCCESS;
    }

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/trigger-driver.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Nodeflow\TriggerDrivers';
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

    private function driverKey(): string
    {
        if ($this->resolvedKey !== null) return $this->resolvedKey;

        $key = trim((string) $this->option('key'));
        if ($key === '') {
            $key = Str::snake(class_basename($this->getNameInput()));
            $this->components->warn("No --key given; derived [{$key}] from the class name.");
        }
        StableKey::assert($key, 'trigger driver key', 191);

        if (in_array($key, ['webhook', 'model', 'event', 'manual', 'subflow'], true)
            || str_starts_with($key, 'core.')) {
            throw new InvalidArgumentException("Trigger driver key [{$key}] is reserved.");
        }

        $drivers = $this->laravel->make(TriggerDriverRegistry::class);
        if ($drivers->has($key)) {
            $existing = $drivers->resolve($key)::class;
            if ($existing !== $this->qualifyClass($this->getNameInput())) {
                throw new InvalidArgumentException("Trigger driver key [{$key}] is already registered by [{$existing}].");
            }
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

    private function register(string $class): void
    {
        $outcome = $this->laravel->make(NodeRegistrationWriter::class)->appendTo(
            $this->laravel->basePath('app/Providers/NodeflowServiceProvider.php'),
            NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR,
            ltrim($class, '\\').'::class',
            '\\'.ltrim($class, '\\').'::class',
        );
        if (in_array($outcome, [NodeRegistrationOutcome::Appended, NodeRegistrationOutcome::AlreadyPresent], true)) {
            $this->components->info($outcome === NodeRegistrationOutcome::Appended ? 'Registered trigger driver.' : 'Trigger driver already registered.');
            return;
        }

        $this->components->warn('Automatic provider registration was unsafe. Register the trigger driver yourself:');
        $this->line('    \\Nodeflow\\Nodeflow::registerTriggerDrivers([');
        $this->line('        \\'.ltrim($class, '\\').'::class,');
        $this->line('    ]);');
    }

    protected function getOptions(): array
    {
        return [
            ['key', null, InputOption::VALUE_OPTIONAL, 'Stable trigger driver key'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the driver if it exists'],
        ];
    }
}
