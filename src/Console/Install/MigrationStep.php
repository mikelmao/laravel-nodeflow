<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;

/**
 * Audits any published copy of the package's migrations. Publishes only on request.
 *
 * WHY THIS IS AN AUDIT AND NOT A PUBLISH (E19). Laravel's
 * BaseCommand::getMigrationPaths() returns array_merge(registered paths, [the
 * host's database/migrations]) — the host's path last — and
 * Migrator::getMigrationFiles() reduces that list with keyBy(migration name),
 * which overwrites on collision. So a published copy of one of our migrations
 * silently shadows the package's own file for every `migrate` run, permanently,
 * with no warning. An in-place edit to the package's copy then diverges from the
 * host's, and no test on either side can see it: the package's assertions run
 * against the package's file, the host's tests against the host's.
 *
 * That happened once already, between Plan 4 and the demo application. This step
 * exists so the next one is a non-zero exit instead of a silent divergence.
 */
final class MigrationStep implements InstallStep
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private bool $publish = false,
        private bool $force = false,
    ) {}

    public function describe(): string
    {
        return 'Migrations (database/migrations)';
    }

    public function check(): InstallOutcome
    {
        $drifted = $this->drifted();

        if ($drifted !== []) {
            return $this->force ? InstallOutcome::Writable : InstallOutcome::CannotWire;
        }

        if ($this->publish && $this->unpublished() !== []) {
            return InstallOutcome::Writable;
        }

        // No published copy and no --publish-migrations is the state E19 wants a
        // host to be in, so it must read as fine rather than as work outstanding.
        return InstallOutcome::AlreadyPresent;
    }

    public function apply(): InstallOutcome
    {
        if (! $this->publish) {
            return $this->check();
        }

        $this->files->ensureDirectoryExists($this->hostDirectory());

        foreach (array_merge($this->unpublished(), $this->force ? $this->drifted() : []) as $source) {
            $this->files->copy($source, $this->hostDirectory().'/'.basename($source));
        }

        return $this->drifted() === [] ? InstallOutcome::Wired : InstallOutcome::CannotWire;
    }

    public function snippet(): ?string
    {
        $drifted = $this->drifted();

        if ($drifted === [] || $this->force) {
            return null;
        }

        return 'These published migrations differ from the package\'s own copies: '
            .implode(', ', array_map('basename', $drifted)).'. '
            .'A published copy shadows the package\'s file for every `migrate` run, so the '
            .'difference is what your database will be built from. Re-publish with '
            .'`--force-migrations`, or delete your copies and let the package\'s own '
            .'migrations load — that is the default and the recommended state.';
    }

    /** Package migrations with no host copy of the same name. */
    private function unpublished(): array
    {
        return array_values(array_filter(
            $this->packageMigrations(),
            fn (string $source) => ! $this->files->exists($this->hostDirectory().'/'.basename($source)),
        ));
    }

    /**
     * Package migrations whose host copy differs.
     *
     * Matched by basename against the package's own files, never by scanning the
     * host's directory: the host has migrations of its own and none of them are
     * this step's business.
     */
    private function drifted(): array
    {
        return array_values(array_filter($this->packageMigrations(), function (string $source) {
            $copy = $this->hostDirectory().'/'.basename($source);

            return $this->files->exists($copy) && sha1_file($copy) !== sha1_file($source);
        }));
    }

    private function packageMigrations(): array
    {
        return $this->files->glob(__DIR__.'/../../../database/migrations/*.php') ?: [];
    }

    private function hostDirectory(): string
    {
        return $this->basePath.'/database/migrations';
    }
}
