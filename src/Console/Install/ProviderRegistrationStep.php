<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\NodeRegistrationOutcome;
use Nodeflow\Console\NodeRegistrationWriter;
use Nodeflow\Console\SourceText;

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

    private const SHORT_CLASS = 'NodeflowServiceProvider';

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

        if ($this->isPresent($contents)) {
            return InstallOutcome::AlreadyPresent;
        }

        return substr_count($contents, self::ANCHOR) === 1
            ? InstallOutcome::Writable
            : InstallOutcome::CannotWire;
    }

    public function apply(): InstallOutcome
    {
        // check() first, and not just for the standard "verify before write":
        // it is the only thing that recognises the SHORT form (see isPresent()),
        // and NodeRegistrationWriter::appendTo() only ever matches the exact
        // presence needle it is given. Without this, a host importing the class
        // and listing the short form would sail past this check as "missing" and
        // apply() would append a redundant fully-qualified duplicate — which is
        // exactly what happened before this method existed.
        $checked = $this->check();

        if ($checked !== InstallOutcome::Writable) {
            return $checked;
        }

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

    /**
     * True when the provider is listed, by either its fully-qualified class
     * string or the short form a host's own `use App\Providers\
     * NodeflowServiceProvider;` import allows Laravel's generated
     * bootstrap/providers.php to list instead. Measured on the one real
     * installed host: it imports and lists the short form, so a needle that
     * only recognises the fully-qualified string reports it as unwired.
     *
     * Comment-stripped first (E22), so a host who commented either form out
     * while debugging is told the truth rather than told they are wired.
     *
     * The short form is matched with a BOUNDED pattern, not plain
     * str_contains(): the character immediately before "NodeflowServiceProvider"
     * must not be an identifier character or a namespace separator, or
     * `App\Providers\CustomNodeflowServiceProvider::class` — a different
     * provider that merely ends with the same suffix — would count as this one.
     *
     * Full `use`-statement resolution (following the import to confirm it
     * really does alias this class, rather than trusting the bare suffix) is
     * deliberately not attempted. bootstrap/providers.php is Laravel's own
     * generated file, not hand-authored prose; a host importing an unrelated
     * class under the literal alias `NodeflowServiceProvider` is not a shape
     * this package has any evidence of, and the bounded suffix match is far
     * cheaper than parsing use-statements for a case that has not been seen.
     */
    private function isPresent(string $contents): bool
    {
        $stripped = SourceText::withoutPhpComments($contents);

        if (str_contains($stripped, $this->needle())) {
            return true;
        }

        return preg_match($this->shortFormPattern(), $stripped) === 1;
    }

    private function shortFormPattern(): string
    {
        return '/(?<![A-Za-z0-9_\\\\])'.preg_quote(self::SHORT_CLASS, '/').'::class/';
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
