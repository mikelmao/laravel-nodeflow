<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;

/**
 * Verifies @xyflow/react is in the host's manifest.
 *
 * The host's Vite compiles the package source, so Composer and the alias install
 * no npm dependencies on the package's behalf and an alias into vendor/ does not
 * pull that package's own dependencies.
 *
 * VERIFY-ONLY, and this one is a choice rather than a limitation: package.json
 * does parse as strict JSON, so writing the dependency is technically easy. It is
 * not done because writing it without running npm install leaves the manifest,
 * the lockfile and node_modules disagreeing — a worse state than the one before
 * the edit, and one whose symptom appears at build time in someone else's terminal.
 */
final class XyflowDependencyStep implements InstallStep
{
    public const PATH = 'package.json';

    private const PACKAGE = '@xyflow/react';

    public function __construct(private Filesystem $files, private string $basePath) {}

    public function describe(): string
    {
        return 'Host dependency ('.self::PACKAGE.')';
    }

    public function check(): InstallOutcome
    {
        $path = $this->basePath.'/'.self::PATH;

        if (! $this->files->exists($path)) {
            return InstallOutcome::CannotWire;
        }

        $manifest = json_decode($this->files->get($path), true);

        if (! is_array($manifest)) {
            return InstallOutcome::CannotWire;
        }

        $declared = array_merge(
            $manifest['dependencies'] ?? [],
            $manifest['devDependencies'] ?? [],
        );

        return array_key_exists(self::PACKAGE, $declared)
            ? InstallOutcome::AlreadyPresent
            : InstallOutcome::CannotWire;
    }

    /** Verify-only: check() never returns Writable, so this is unreachable. */
    public function apply(): InstallOutcome
    {
        return $this->check();
    }

    public function snippet(): ?string
    {
        return $this->check() === InstallOutcome::AlreadyPresent
            ? null
            : 'Run `npm install '.self::PACKAGE.'` in the application root. Nodeflow '
                .'does not add it for you: your Vite compiles our source, so an alias '
                .'into vendor/ pulls no npm dependencies, and writing the manifest '
                .'without running the installer would leave your lockfile disagreeing '
                .'with it.';
    }
}
