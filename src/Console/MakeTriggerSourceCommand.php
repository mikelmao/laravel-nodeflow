<?php

namespace Nodeflow\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nodeflow\Support\StableKey;
use Nodeflow\Triggers\TriggerDriverRegistry;
use Nodeflow\Triggers\TriggerSourceRegistry;
use ReflectionClass;
use Symfony\Component\Console\Input\InputOption;

class MakeTriggerSourceCommand extends GeneratorCommand
{
    protected $name = 'nodeflow:make-trigger-source';

    protected $description = 'Create an allowlisted Nodeflow trigger source class.';

    protected $type = 'Trigger source';

    private ?string $resolvedDriver = null;

    private ?string $resolvedKey = null;

    private ?string $resolvedSelector = null;

    public function handle(): int
    {
        $this->resolvedDriver = $this->resolvedKey = $this->resolvedSelector = null;

        try {
            $this->assertSafeName();
            $this->driverKey();
            $this->sourceKey();
            $this->selector();
            $class = $this->qualifyClass($this->getNameInput());
            if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
                throw new InvalidArgumentException("Generated class [{$class}] already exists.");
            }
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $this->register($this->qualifyClass($this->getNameInput()));

        return self::SUCCESS;
    }

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/trigger-source.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Nodeflow\TriggerSources';
    }

    protected function buildClass($name): string
    {
        $family = $this->familyTemplate();

        return strtr(parent::buildClass($name), [
            '{{ key }}' => $this->sourceKey(),
            '{{ driver }}' => $this->driverKey(),
            '{{ label }}' => Str::headline(class_basename($this->getNameInput())),
            '{{ familyImports }}' => $family['imports'],
            '{{ sourceInterface }}' => $family['interface'],
            '{{ familyMethods }}' => $family['methods'],
            '{{ payloadGuard }}' => $family['guard'],
        ]);
    }

    protected function resolveStubPath(string $stub): string
    {
        $custom = $this->laravel->basePath(trim($stub, '/'));

        return file_exists($custom) ? $custom : __DIR__.'/../..'.$stub;
    }

    /** @return array{imports: string, interface: string, methods: string, guard: string} */
    private function familyTemplate(): array
    {
        return match ($this->driverKey()) {
            'webhook' => [
                'imports' => "use Nodeflow\\Triggers\\Webhook\\WebhookOccurrence;\nuse Nodeflow\\Triggers\\Webhook\\WebhookTriggerSource;",
                'interface' => 'WebhookTriggerSource',
                'methods' => '',
                'guard' => "        if (! \$occurrence->payload instanceof WebhookOccurrence) {\n            throw new InvalidArgumentException('Expected a webhook occurrence.');\n        }",
            ],
            'model' => [
                'imports' => "use Nodeflow\\Triggers\\ModelObserver\\ModelObserverTriggerSource;\nuse Nodeflow\\Triggers\\ModelObserver\\ModelOccurrence;",
                'interface' => 'ModelObserverTriggerSource',
                'methods' => "    /** The only Eloquent model class this source allows Nodeflow to observe. */\n    public static function modelClass(): string\n    {\n        return \\".$this->selector()."::class;\n    }\n",
                'guard' => "        if (! \$occurrence->payload instanceof ModelOccurrence) {\n            throw new InvalidArgumentException('Expected a model occurrence.');\n        }",
            ],
            'event' => [
                'imports' => "use Nodeflow\\Triggers\\LaravelEvent\\LaravelEventOccurrence;\nuse Nodeflow\\Triggers\\LaravelEvent\\LaravelEventTriggerSource;",
                'interface' => 'LaravelEventTriggerSource',
                'methods' => "    /** The only Laravel event class this source allows Nodeflow to observe. */\n    public static function eventClass(): string\n    {\n        return \\".$this->selector()."::class;\n    }\n\n    /** Extract only explicit immutable value data; never serialize the event object. */\n    public function snapshot(object \$event): LaravelEventOccurrence\n    {\n        if (! \$event instanceof \\".$this->selector().") {\n            throw new InvalidArgumentException('Unexpected Laravel event class.');\n        }\n\n        return new LaravelEventOccurrence(\$event::class, [\n            // TODO: copy only safe scalar/array values needed by resolve().\n        ]);\n    }\n",
                'guard' => "        if (! \$occurrence->payload instanceof LaravelEventOccurrence) {\n            throw new InvalidArgumentException('Expected a Laravel event occurrence.');\n        }",
            ],
            default => [
                'imports' => '',
                'interface' => 'TriggerSource',
                'methods' => '',
                'guard' => "        // TODO: validate your driver's immutable occurrence payload explicitly.",
            ],
        };
    }

    private function driverKey(): string
    {
        if ($this->resolvedDriver !== null) return $this->resolvedDriver;

        $driver = StableKey::assert(trim((string) $this->option('driver')), 'trigger driver key', 191);
        if (! $this->laravel->make(TriggerDriverRegistry::class)->has($driver)) {
            throw new InvalidArgumentException("Trigger driver [{$driver}] is not registered.");
        }

        return $this->resolvedDriver = $driver;
    }

    private function sourceKey(): string
    {
        if ($this->resolvedKey !== null) return $this->resolvedKey;

        $key = trim((string) $this->option('key'));
        if ($key === '') {
            $key = Str::snake(class_basename($this->getNameInput()));
            $this->components->warn("No --key given; derived [{$key}] from the class name.");
        }
        StableKey::assert($key, 'trigger source key', 191);
        if (str_starts_with($key, 'core.')) {
            throw new InvalidArgumentException("Trigger source key [{$key}] uses the package-reserved [core.] prefix.");
        }

        $sources = $this->laravel->make(TriggerSourceRegistry::class);
        if ($sources->has($this->driverKey(), $key)) {
            $existing = $sources->all()[$this->driverKey()."\0".$key];
            throw new InvalidArgumentException("Trigger source [{$this->driverKey()}:{$key}] is already registered by [{$existing}].");
        }

        return $this->resolvedKey = $key;
    }

    private function selector(): string
    {
        if ($this->resolvedSelector !== null) return $this->resolvedSelector;

        $driver = $this->driverKey();
        $option = $driver === 'model' ? 'model' : ($driver === 'event' ? 'event' : null);
        if ($option === null) {
            if (trim((string) $this->option('model')) !== '' || trim((string) $this->option('event')) !== '') {
                throw new InvalidArgumentException('--model and --event are allowed only for their matching built-in driver.');
            }

            return $this->resolvedSelector = '';
        }

        $class = ltrim(trim((string) $this->option($option)), '\\');
        if ($class === '') {
            throw new InvalidArgumentException("The [{$driver}] driver requires --{$option} with an allowlisted host class.");
        }
        if (! class_exists($class)) {
            throw new InvalidArgumentException("The --{$option} class [{$class}] does not exist.");
        }

        $reflection = new ReflectionClass($class);
        if (! $reflection->isInstantiable() || ($driver === 'model' && ! is_a($class, Model::class, true))) {
            throw new InvalidArgumentException("The --{$option} class [{$class}] is not a compatible concrete class.");
        }

        return $this->resolvedSelector = $reflection->getName();
    }

    private function assertSafeName(): void
    {
        $name = trim($this->getNameInput(), '\\');
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/D', $name) !== 1) {
            throw new InvalidArgumentException('The trigger source name must be a PHP class name and may not contain path traversal.');
        }
    }

    private function register(string $class): void
    {
        $outcome = $this->laravel->make(NodeRegistrationWriter::class)->appendTo(
            $this->laravel->basePath('app/Providers/NodeflowServiceProvider.php'),
            NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR,
            ltrim($class, '\\').'::class',
            '\\'.ltrim($class, '\\').'::class',
        );
        if (in_array($outcome, [NodeRegistrationOutcome::Appended, NodeRegistrationOutcome::AlreadyPresent], true)) {
            $this->components->info($outcome === NodeRegistrationOutcome::Appended ? 'Registered trigger source.' : 'Trigger source already registered.');
            return;
        }

        $this->components->warn('Automatic provider registration was unsafe. Register the trigger source yourself:');
        $this->line('    \\Nodeflow\\Nodeflow::registerTriggerSources([');
        $this->line('        \\'.ltrim($class, '\\').'::class,');
        $this->line('    ]);');
    }

    protected function getOptions(): array
    {
        return [
            ['driver', null, InputOption::VALUE_REQUIRED, 'Registered trigger driver key'],
            ['key', null, InputOption::VALUE_OPTIONAL, 'Stable source key'],
            ['model', null, InputOption::VALUE_OPTIONAL, 'Allowlisted Eloquent model class for the model driver'],
            ['event', null, InputOption::VALUE_OPTIONAL, 'Allowlisted event class for the event driver'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the source if it exists'],
        ];
    }
}
