<?php

namespace Nodeflow\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Gate;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\InstallStep;
use Nodeflow\Console\Install\MigrationStep;
use Nodeflow\Console\Install\ProviderRegistrationStep;
use Nodeflow\Console\Install\ProviderStep;
use Nodeflow\Console\Install\PublishConfigStep;
use Nodeflow\Console\Install\TailwindSourceStep;
use Nodeflow\Console\Install\TsconfigPathsStep;
use Nodeflow\Console\Install\ViteAliasStep;
use Nodeflow\Console\Install\ViteDedupeStep;
use Nodeflow\Console\Install\XyflowDependencyStep;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Tenancy\NoTenancyResolver;

/**
 * Installs Nodeflow into a host application, and then verifies it did.
 *
 * The verification is the point, not a nicety. Of the five client-side wiring
 * requirements, three fail quietly — the tsconfig paths (Vite still builds, the
 * host's tsc does not), the Tailwind @source line (the build succeeds and every
 * utility used only by our source is missing), and resolve.dedupe (two React
 * copies, reported as "Invalid hook call", which reads as a React bug). A sixth,
 * listing the provider in bootstrap/providers.php, fails just as quietly.
 *
 * So the exit code is a contract. handle() is declared int deliberately:
 * returning false from a Laravel command's handle() is cast with (int) and exits
 * 0, which would silently defeat this command's own reason for existing.
 */
class InstallCommand extends Command
{
    protected $signature = 'nodeflow:install
        {--check : Verify everything and write nothing}
        {--publish-migrations : Also publish the package migrations into database/migrations}
        {--force-migrations : Re-publish over a published copy that has drifted}';

    protected $description = 'Install Nodeflow into this application, and verify the wiring.';

    private const GATES = [
        'nodeflow.viewAny',
        'nodeflow.update',
        'nodeflow.publish',
        'nodeflow.runManually',
    ];

    public function handle(): int
    {
        $steps = $this->steps();

        // Every check() before any apply(). A step that fails halfway through must
        // not be able to leave a host half-wired, and check() is contractually
        // read-only so this ordering costs nothing.
        $outcomes = array_map(fn (InstallStep $step) => $step->check(), $steps);

        if (! $this->option('check')) {
            foreach ($steps as $index => $step) {
                if ($outcomes[$index] === InstallOutcome::Writable) {
                    $outcomes[$index] = $step->apply();
                }
            }
        }

        $this->table(
            ['Requirement', 'Status'],
            array_map(
                fn (InstallStep $step, InstallOutcome $outcome) => [$step->describe(), $this->label($outcome)],
                $steps,
                $outcomes,
            ),
        );

        foreach ($steps as $index => $step) {
            if ($outcomes[$index] === InstallOutcome::CannotWire && $step->snippet() !== null) {
                $this->newLine();
                $this->components->warn($step->describe().' — add this yourself:');
                $this->newLine();
                $this->line($step->snippet());
            }
        }

        $this->newLine();
        $this->reportGates();
        $this->reportTenancy();

        return $this->exitCode($outcomes);
    }

    /** @return InstallStep[] */
    private function steps(): array
    {
        $files = $this->laravel->make(Filesystem::class);
        $base = $this->laravel->basePath();
        $namespace = $this->laravel->getNamespace();
        $writer = $this->laravel->make(NodeRegistrationWriter::class);

        $force = (bool) $this->option('force-migrations');

        return [
            new PublishConfigStep($files, $base),
            // --force-migrations implies --publish-migrations: re-publishing over a
            // drifted copy is publishing.
            new MigrationStep(
                $files,
                $base,
                publish: $force || (bool) $this->option('publish-migrations'),
                force: $force,
            ),
            new ProviderStep($files, $base, $namespace),
            new ProviderRegistrationStep($files, $base, $namespace, $writer),
            new TailwindSourceStep($files, $base),
            new ViteAliasStep($files, $base),
            new ViteDedupeStep($files, $base),
            new TsconfigPathsStep($files, $base),
            new XyflowDependencyStep($files, $base),
        ];
    }

    /**
     * Non-zero iff something is not wired.
     *
     * Under --check, Writable counts as unwired: the host asked whether this
     * application is installed, and "it would be if you let me write" is a no.
     */
    private function exitCode(array $outcomes): int
    {
        // Writable fails in BOTH modes, not only under --check. Under --check it
        // always means "would be written". In a normal run every step whose
        // check() returned Writable had apply() called on it above, and a
        // genuinely-fixable step resolves that to Wired or CannotWire — so a
        // Writable that survives to here can only belong to a verify-only step
        // (apply() just returns check() again), meaning nobody ever wrote it.
        // That is unwired either way and must be red either way.
        $failing = [InstallOutcome::CannotWire, InstallOutcome::Writable];

        foreach ($outcomes as $outcome) {
            if (in_array($outcome, $failing, true)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function label(InstallOutcome $outcome): string
    {
        return match ($outcome) {
            InstallOutcome::AlreadyPresent => 'already wired',
            InstallOutcome::Wired => 'wired',
            InstallOutcome::Writable => 'NOT WIRED (would be written)',
            InstallOutcome::CannotWire => 'NOT WIRED',
        };
    }

    /**
     * A report, never an outcome.
     *
     * An undefined gate is the correct state immediately after installing: the
     * host has not written its authorization rules yet. Folding this into the exit
     * code would make the very first run red, and a command whose first run is
     * always red teaches its users to ignore its exit code — which is the one
     * thing this command cannot afford.
     */
    private function reportGates(): void
    {
        $undefined = array_values(array_filter(self::GATES, fn (string $gate) => ! Gate::has($gate)));

        if ($undefined === []) {
            $this->components->info('All four authorization gates are defined.');

            return;
        }

        $this->components->warn(
            'Undefined authorization gates: '.implode(', ', $undefined).'. Nodeflow '
            .'denies every ability whose gate is undefined, so those actions return 403 '
            .'until you define them — see docs/02-integration.md, "Authorization: four gates".'
        );
    }

    /**
     * Reports what a null tenant will actually do, not just the config string.
     *
     * 'auto' alone tells a host nothing, because auto's answer depends on which
     * TenantResolver is in the container — which is exactly the thing a host is
     * least likely to have thought about.
     */
    private function reportTenancy(): void
    {
        $mode = config('nodeflow.tenancy');
        $resolver = $this->laravel->make(TenantResolver::class);

        $this->components->info('nodeflow.tenancy: '.match ($mode) {
            'auto' => $resolver instanceof NoTenancyResolver
                ? 'auto — no TenantResolver bound, so a null tenant means "this application has '
                    .'no tenancy" and scoped reads are unscoped'
                : 'auto — '.$resolver::class.' is bound, so a null tenant throws '
                    .'TenancyUnresolvedException rather than reading every tenant\'s rows. Bind it '
                    .'unconditionally in register(), never in middleware.',
            'disabled' => 'disabled — a null tenant always reads unscoped. Only correct if this '
                .'application genuinely has no tenancy.',
            'resolver' => 'resolver — a null tenant always throws.',
            default => 'UNRECOGNISED value '.var_export($mode, true).' — every scoped read will '
                .'throw InvalidArgumentException. Valid values are auto, disabled and resolver, '
                .'matched exactly. Run `php artisan config:clear` if a cached config predates the key.',
        });
    }
}
