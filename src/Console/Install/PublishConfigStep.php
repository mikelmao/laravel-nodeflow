<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;

/**
 * Publishes config/nodeflow.php, and never overwrites one that exists.
 *
 * Deliberately not `vendor:publish --tag=nodeflow-config`: this step has to
 * report AlreadyPresent distinctly from Wired, and vendor:publish exits 0 either
 * way. The file it copies is the same one that tag publishes.
 */
final class PublishConfigStep implements InstallStep
{
    public const PATH = 'config/nodeflow.php';

    public function __construct(private Filesystem $files, private string $basePath) {}

    public function describe(): string
    {
        return 'Config ('.self::PATH.')';
    }

    public function check(): InstallOutcome
    {
        return $this->files->exists($this->path())
            ? InstallOutcome::AlreadyPresent
            : InstallOutcome::Writable;
    }

    public function apply(): InstallOutcome
    {
        if ($this->files->exists($this->path())) {
            return InstallOutcome::AlreadyPresent;
        }

        $this->files->ensureDirectoryExists(dirname($this->path()));
        $this->files->copy(__DIR__.'/../../../config/nodeflow.php', $this->path());

        return $this->files->exists($this->path())
            ? InstallOutcome::Wired
            : InstallOutcome::CannotWire;
    }

    public function snippet(): ?string
    {
        return null;
    }

    private function path(): string
    {
        return $this->basePath.'/'.self::PATH;
    }
}
