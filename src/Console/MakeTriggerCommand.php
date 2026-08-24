<?php

namespace Nodeflow\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Nodeflow\Triggers\LaravelEvent\LaravelEventTriggerDriver;
use Nodeflow\Triggers\TriggerSourceRegistry;
use ReflectionClass;
use Symfony\Component\Console\Input\InputOption;

use function Laravel\Prompts\text;

/**
 * Scaffolds an allowlisted Laravel event trigger source.
 *
 * It earns its place on one method: eventClass() returns a host event class, and
 * naming the wrong one produces a trigger that fails silently rather than
 * loudly — the listener attaches to an event that never fires.
 */
class MakeTriggerCommand extends GeneratorCommand
{
    protected $name = 'nodeflow:make-trigger';

    protected $description = 'Create a Nodeflow trigger class.';

    protected $type = 'Trigger';

    private ?string $resolvedSource = null;

    private ?string $resolvedEvent = null;

    public function handle(): int
    {
        // Symfony's Application resolves one command object per name and keeps
        // it for the process's lifetime, so a second Artisan::call() of this
        // same command (from a host script, a queued job, or a test's own
        // artisan() call run twice) reuses this exact instance rather than a
        // fresh one. Without this reset, $resolvedSource/$resolvedEvent from a
        // first, unrelated invocation would still be set on the second call,
        // and sourceKey()/eventClass() would return the stale value straight
        // from cache — skipping validation entirely and rendering the first
        // trigger's type or event into the second file while still reporting
        // success. Resetting here keeps the memoization useful within one
        // handle() (each of eventClass()/sourceKey() is called twice per
        // run: once here, once again inside buildClass()) without letting it
        // survive across separate runs.
        $this->resolvedSource = null;
        $this->resolvedEvent = null;

        // Both resolved before parent::handle() writes anything, so a usage error
        // never leaves a half-generated file behind.
        try {
            $this->eventClass();
            $this->sourceKey();
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        // GeneratorCommand::handle() returns false when it refused to write.
        // Laravel casts the return with (int), turning false into 0 — a refusal
        // would look like success to any caller. Mapped explicitly, the same way
        // MakeNodeCommand does and for the same reason.
        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $this->registerTrigger($this->qualifyClass($this->getNameInput()));

        return self::SUCCESS;
    }

    /**
     * Registration attaches the event listener, at the moment it happens — so a
     * generated trigger nobody registers is a trigger that never fires. That is
     * why this command registers rather than only scaffolding (E24).
     */
    private function registerTrigger(string $triggerClass): void
    {
        $outcome = $this->laravel->make(NodeRegistrationWriter::class)->appendTo(
            $this->laravel->basePath('app/Providers/NodeflowServiceProvider.php'),
            NodeRegistrationWriter::TRIGGER_ANCHOR,
            ltrim('\\'.ltrim($triggerClass, '\\').'::class', '\\'),
            '\\'.ltrim($triggerClass, '\\').'::class',
        );

        match ($outcome) {
            NodeRegistrationOutcome::Appended => $this->components->info(
                'Registered in app/Providers/NodeflowServiceProvider.php.'
            ),
            NodeRegistrationOutcome::AlreadyPresent => $this->components->info(
                'Already registered in app/Providers/NodeflowServiceProvider.php.'
            ),
            NodeRegistrationOutcome::ProviderMissing => $this->manualRegistration($triggerClass,
                'No app/Providers/NodeflowServiceProvider.php found. Run `php artisan nodeflow:install`.'
            ),
            NodeRegistrationOutcome::AnchorMissing => $this->manualRegistration($triggerClass,
                'app/Providers/NodeflowServiceProvider.php has no `'.NodeRegistrationWriter::TRIGGER_ANCHOR.'` line.'
            ),
            NodeRegistrationOutcome::AnchorAmbiguous => $this->manualRegistration($triggerClass,
                'app/Providers/NodeflowServiceProvider.php has more than one `'.NodeRegistrationWriter::TRIGGER_ANCHOR.'` line.'
            ),
            NodeRegistrationOutcome::WriteFailed => $this->manualRegistration($triggerClass,
                'The automatic edit to app/Providers/NodeflowServiceProvider.php did not '
                .'produce valid PHP — the `'.NodeRegistrationWriter::TRIGGER_ANCHOR.'` line may be '
                .'commented out.'
            ),
        };
    }

    private function manualRegistration(string $triggerClass, string $because): void
    {
        $this->components->warn($because.' Register the trigger yourself:');
        $this->newLine();
        $this->line('    \\Nodeflow\\Nodeflow::registerTriggerSources([');
        $this->line('        \\'.$triggerClass.'::class,');
        $this->line('    ]);');
        $this->newLine();
        $this->components->warn(
            'Until it is registered no listener is attached, so this trigger will never fire.'
        );
    }

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/trigger.stub');
    }

    /** Laravel's own stub-override convention, as MakeNodeCommand follows it. */
    protected function resolveStubPath(string $stub): string
    {
        $custom = $this->laravel->basePath(trim($stub, '/'));

        return file_exists($custom) ? $custom : __DIR__.'/../..'.$stub;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Nodeflow\Triggers';
    }

    protected function buildClass($name): string
    {
        // strtr, not str_replace: see F-1 in MakeNodeCommand::paletteGroup().
        return strtr(parent::buildClass($name), [
            '{{ type }}' => $this->sourceKey(),
            '{{ event }}' => ltrim($this->eventClass(), '\\'),
            '{{ label }}' => Str::headline(class_basename($this->getNameInput())),
        ]);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function eventClass(): string
    {
        if ($this->resolvedEvent !== null) {
            return $this->resolvedEvent;
        }

        $event = trim((string) $this->option('event'));

        // Guarded on isInteractive() rather than on --no-interaction: a Testbench
        // PendingCommand does not necessarily pass that flag, and an unguarded
        // prompt hangs a test suite rather than failing it. runningUnitTests() is
        // checked too, and for the same reason Laravel's own ConfiguresPrompts
        // enables the Prompts fallback unconditionally under tests
        // (Prompt::fallbackWhen(... || $this->laravel->runningUnitTests())):
        // Testbench's mocked console output has no `askQuestion` expectations
        // unless a test opts in with expectsQuestion(), so a prompt reached from
        // a bare artisan() call crashes ugly instead of failing the assertion
        // that is actually under test.
        if ($event === '' && $this->input->isInteractive() && ! $this->laravel->runningUnitTests()) {
            $event = trim(text(
                label: 'Which of your event classes does this trigger listen to?',
                placeholder: 'App\Events\OrderPlaced',
                hint: 'Registering the trigger attaches a listener to this class. Name the wrong one and nothing errors — the trigger is simply never fired.',
            ));
        }

        if ($event === '') {
            throw new \InvalidArgumentException(
                'No --event given. Unlike --type there is no safe default: a trigger whose '
                .'eventClass() names the wrong class attaches its listener to an event that never '
                .'fires, and nothing reports it. Pass --event with your event class.'
            );
        }

        $eventSymbolIsLoaded = class_exists($event)
            || interface_exists($event)
            || trait_exists($event);

        if ($eventSymbolIsLoaded && ! (new ReflectionClass($event))->isInstantiable()) {
            throw new \InvalidArgumentException(
                "Loaded event symbol [{$event}] is not a concrete event class. "
                .'Pass --event with an instantiable event class.'
            );
        }

        // A warning, not a refusal. Generating the trigger before writing the
        // event is a normal order of work, and ::class needs no loaded class.
        if (! class_exists($event)) {
            $this->components->warn(
                "Event class [{$event}] could not be found. The trigger has still been "
                .'generated — ::class does not require the class to exist. If that name is '
                .'wrong, the listener attaches to an event that never fires and nothing will '
                .'tell you.'
            );
        }

        return $this->resolvedEvent = $event;
    }

    /**
     * Identical syntax rules to MakeNodeCommand::nodeType(), but this value is a
     * source key within the Laravel-event driver rather than a mutable flow type.
     *
     * @throws \InvalidArgumentException
     */
    private function sourceKey(): string
    {
        if ($this->resolvedSource !== null) {
            return $this->resolvedSource;
        }

        $suggested = Str::snake(class_basename($this->getNameInput()));

        $type = trim((string) $this->option('type'));

        // See the matching comment in eventClass(): guarded on runningUnitTests()
        // too, or a bare artisan() call in a host's test suite crashes on the
        // mocked output's unstubbed askQuestion() instead of taking the derived-
        // type warning path below.
        if ($type === '' && $this->input->isInteractive() && ! $this->laravel->runningUnitTests()) {
            $type = trim(text(
                label: 'Stable source identifier for this trigger',
                placeholder: 'shop.order_placed',
                default: $suggested,
                hint: 'Published trigger nodes store this source key. Prefix it with your domain.',
            ));
        } elseif ($type === '') {
            $type = $suggested;

            $this->components->warn(
                "No --type given; derived [{$type}] from the class name. Published trigger nodes "
                .'store this source key, so pass --type explicitly with your domain prefix.'
            );
        }

        if (preg_match('/^[a-z0-9]+(?:[._][a-z0-9]+)*$/', $type) !== 1) {
            throw new \InvalidArgumentException(
                "[{$type}] is not a valid trigger type. Use lowercase letters, digits, dots "
                .'and underscores, e.g. shop.order_placed.'
            );
        }

        if (str_starts_with($type, 'core.')) {
            throw new \InvalidArgumentException(
                "[{$type}] uses the reserved [core.] prefix, which belongs to the package "
                .'itself. Prefix your own types with your domain instead.'
            );
        }

        $registry = $this->laravel->make(TriggerSourceRegistry::class);

        if ($registry->has(LaravelEventTriggerDriver::key(), $type)) {
            // Regenerating a trigger that already owns its own type is not a
            // collision — the same exemption MakeNodeCommand::validateType()
            // makes, and for the same reason: it is what makes --force usable.
            $existing = $registry->resolve(LaravelEventTriggerDriver::key(), $type)::class;

            if ($existing === $this->qualifyClass($this->getNameInput())) {
                return $this->resolvedSource = $type;
            }

            throw new \InvalidArgumentException(
                "Source [{$type}] is already registered by [{$existing}] for the Laravel event "
                .'driver. Choose another source key.'
            );
        }

        return $this->resolvedSource = $type;
    }

    protected function getOptions(): array
    {
        return [
            ['event', null, InputOption::VALUE_OPTIONAL, 'The host event class this trigger listens to'],
            ['type', null, InputOption::VALUE_OPTIONAL, 'The stable type identifier, e.g. shop.order_placed'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the trigger if it already exists'],
        ];
    }
}
