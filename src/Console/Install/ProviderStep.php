<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\NodeRegistrationWriter;

/**
 * The registration home every generator writes into.
 *
 * `nodeflow:make-node` has always looked for this exact file with this exact
 * anchor, and until this command existed nothing created it — so every host took
 * the generator's fallback path and pasted a `Nodeflow::register([...])` line
 * instead. That is the story this step ends.
 */
final class ProviderStep implements InstallStep
{
    public const PATH = 'app/Providers/NodeflowServiceProvider.php';

    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private string $rootNamespace,
        private NodeRegistrationWriter $writer,
    ) {}

    public function describe(): string
    {
        return 'Provider ('.self::PATH.')';
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

        $this->files->put($this->path(), strtr($this->stub(), [
            '{{ namespace }}' => rtrim($this->rootNamespace, '\\').'\\Providers',
        ]));

        // E11: re-read and prove the anchors are there. A stub edited past
        // recognition would otherwise ship a provider no generator can write to,
        // and nothing would say so until a host ran make-node and got a paste
        // instruction it could not explain.
        $written = $this->files->get($this->path());

        foreach ([
            NodeRegistrationWriter::ANCHOR,
            NodeRegistrationWriter::TRIGGER_ANCHOR,
            NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        ] as $anchor) {
            if (substr_count($written, $anchor) !== 1) {
                return InstallOutcome::CannotWire;
            }
        }

        return InstallOutcome::Wired;
    }

    public function snippet(): ?string
    {
        return null;
    }

    private function path(): string
    {
        return $this->basePath.'/'.self::PATH;
    }

    /** Host stub overrides, the same convention MakeNodeCommand::resolveStubPath() follows. */
    private function stub(): string
    {
        $custom = $this->basePath.'/stubs/provider.stub';

        return $this->files->get(
            $this->files->exists($custom) ? $custom : __DIR__.'/../../../stubs/provider.stub'
        );
    }
}
