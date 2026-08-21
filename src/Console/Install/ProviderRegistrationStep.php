<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\NodeRegistrationOutcome;
use Nodeflow\Console\NodeRegistrationWriter;

/**
 * The sixth wiring requirement, and the one the editor spec's list of five never
 * had — because it is not a client requirement.
 *
 * Laravel 12 discovers application providers from bootstrap/providers.php alone.
 * A NodeflowServiceProvider that nobody lists there does nothing at all: no nodes
 * register, no triggers fire, no attributes exist, and the palette is empty with
 * no error raised anywhere. It fails as quietly as the worst of the five.
 */
final class ProviderRegistrationStep implements InstallStep
{
    public const PATH = 'bootstrap/providers.php';

    private const ANCHOR = 'return [';

    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private string $rootNamespace,
        private NodeRegistrationWriter $writer,
    ) {}

    public function describe(): string
    {
        return 'Provider registration ('.self::PATH.')';
    }

    public function check(): InstallOutcome
    {
        if (! $this->files->exists($this->path())) {
            return InstallOutcome::CannotWire;
        }

        $contents = $this->files->get($this->path());

        if (str_contains($contents, $this->needle())) {
            return InstallOutcome::AlreadyPresent;
        }

        return substr_count($contents, self::ANCHOR) === 1
            ? InstallOutcome::Writable
            : InstallOutcome::CannotWire;
    }

    public function apply(): InstallOutcome
    {
        // Indent 4, not the writer's default 8: bootstrap/providers.php is a
        // top-level array literal, not a class property.
        $outcome = $this->writer->appendTo(
            $this->path(),
            self::ANCHOR,
            $this->needle(),
            $this->providerClass().'::class',
            '    ',
        );

        return match ($outcome) {
            NodeRegistrationOutcome::Appended => InstallOutcome::Wired,
            NodeRegistrationOutcome::AlreadyPresent => InstallOutcome::AlreadyPresent,
            default => InstallOutcome::CannotWire,
        };
    }

    public function snippet(): ?string
    {
        if ($this->check() !== InstallOutcome::CannotWire) {
            return null;
        }

        return 'Add '.$this->providerClass().'::class to the array in '.self::PATH.'.'
            .' Laravel discovers application providers from that file alone, so'
            .' without this the provider never boots and no node registers.';
    }

    private function needle(): string
    {
        return $this->providerClass().'::class';
    }

    private function providerClass(): string
    {
        return rtrim($this->rootNamespace, '\\').'\\Providers\\NodeflowServiceProvider';
    }

    private function path(): string
    {
        return $this->basePath.'/'.self::PATH;
    }
}
