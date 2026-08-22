<?php

namespace Nodeflow\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nodeflow\Console\Install\TsconfigPathsStep;
use Nodeflow\Console\Install\ViteAliasStep;
use RuntimeException;

/**
 * `nodeflow:make-node-package vendor/name` — the user-facing front door to
 * `PackageScaffolder`. Every value the scaffolder trusts unconditionally
 * (`PackageTarget`'s own docblock says so) is validated or resolved here
 * first: the Composer name, the derived-or-supplied namespace, the resolved
 * absolute path, the host's own `atram/laravel-nodeflow` constraint, and
 * whether the target directory may be written into.
 *
 * TWO INDEPENDENT VALIDATION LAYERS (E52), and this is the point of the
 * command. Composer's own name pattern is not a PHP identifier check:
 * `123vendor/456pkg` is a Composer name Composer itself accepts, and no
 * segment of it is a legal PHP namespace segment. Passing only the first
 * check would render `namespace 123Vendor\456Pkg;` — a parse error the
 * scaffolder's own pre-write check would eventually catch, but only after
 * this command had already told the caller everything was fine to attempt.
 * So the two checks are kept genuinely separate: the Composer pattern here,
 * the PHP identifier pattern on every segment of the fully-qualified
 * provider class name (which is a superset of the base namespace's own
 * segments) in assertValidNamespaceSegments().
 */
class MakeNodePackageCommand extends Command
{
    protected $signature = 'nodeflow:make-node-package
        {name : The Composer package name, e.g. acme/widgets}
        {--namespace= : PHP namespace for the package; default is derived from the name}
        {--path= : Path, relative to the host root, to scaffold into; default is packages/vendor/name}
        {--js : Also scaffold package.json, tsconfig.json, and resources/js/index.ts}
        {--force : Overwrite an occupied target directory that is not already this package}';

    protected $description = 'Scaffold a new Composer package that ships Nodeflow nodes.';

    /** Composer's own package name pattern. */
    private const COMPOSER_NAME_PATTERN = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/';

    /** A single PHP identifier segment (E52). */
    private const NAMESPACE_SEGMENT_PATTERN = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/';

    /**
     * Memoised the same way MakeNodeCommand::$resolvedType is (nodeType()'s
     * own docblock explains why): target() below is a cache-or-compute
     * getter, not a plain assignment, so a stale value here would be handed
     * back on a LATER call without ever re-validating that call's own
     * arguments. Symfony resolves one command object per name and keeps it
     * for the process's lifetime, so a second Artisan::call() of this exact
     * command — from a host script, or a test file that calls artisan()
     * twice — reuses this exact instance and this exact property. Reset at
     * the top of every handle() (F-3): without it, a second run for a
     * DIFFERENT package name would silently re-scaffold the FIRST run's
     * target instead of validating and scaffolding its own — the same shape
     * of bug that shipped twice against nodeType(), just with a package
     * target in place of a node type string.
     */
    private ?PackageTarget $target = null;

    public function __construct(private Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->target = null;

        try {
            $target = $this->target();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            (new PackageScaffolder($this->files, $this->laravel->basePath()))
                ->scaffold($target);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            "Scaffolded [{$target->composerName}] into [{$target->relativePath}]."
        );

        if ($target->withJs) {
            $this->printJsWiring();
        }

        return self::SUCCESS;
    }

    /**
     * Cache-or-compute: returns the memoised target for THIS handle() call
     * once resolveTarget() has succeeded once, rather than re-deriving it on
     * every read within the same call. Reading this after a failed
     * resolution never happens — resolveTarget() throws before returning,
     * so ??= never assigns, and handle() already returned FAILURE without
     * calling target() again.
     *
     * @throws InvalidArgumentException
     */
    private function target(): PackageTarget
    {
        return $this->target ??= $this->resolveTarget();
    }

    /**
     * Every refusal below throws InvalidArgumentException with a message
     * naming both the problem and the fix, and handle() maps every one of
     * them to self::FAILURE (requirement 9) — returning false here, as
     * GeneratorCommand's own handle() does, would be cast to exit code 0 by
     * Laravel and make a refusal indistinguishable from success.
     *
     * @throws InvalidArgumentException
     */
    private function resolveTarget(): PackageTarget
    {
        $name = trim((string) $this->argument('name'));

        if (preg_match(self::COMPOSER_NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(
                "[{$name}] is not a valid Composer package name. It must match Composer's own ".
                'pattern '.self::COMPOSER_NAME_PATTERN.', e.g. acme/widgets.'
            );
        }

        [$vendor, $package] = explode('/', $name, 2);

        $namespaceOption = trim((string) ($this->option('namespace') ?? ''));

        // Default: StudlyCase each of the two Composer segments and join with
        // '\'. --namespace overrides the base namespace, but the provider's
        // own short class name is always derived from the package segment
        // (Str::studly($package).'ServiceProvider'), matching what the
        // scaffolder itself documents: it derives the short class name from
        // the fully-qualified provider class this command hands it, not the
        // other way around.
        $namespace = $namespaceOption !== ''
            ? trim($namespaceOption, '\\')
            : Str::studly($vendor).'\\'.Str::studly($package);

        $providerClass = $namespace.'\\'.Str::studly($package).'ServiceProvider';

        // Validated on the FULL provider class, not the base namespace alone:
        // that covers every base-namespace segment (a prefix of the provider
        // class's own segments) AND the derived short class name in one pass —
        // which matters because an explicit --namespace can be perfectly
        // valid PHP while the package segment used for the short class name
        // (e.g. "456pkg") is not.
        $this->assertValidNamespaceSegments($providerClass);

        // Whitespace-trimmed only — NOT slash-trimmed. HostPath::resolveWithin()
        // deliberately refuses a leading '/' as not being "a relative path
        // inside the project" (E51's own rule); stripping that leading slash
        // here, the way the namespace's stray backslashes are stripped below,
        // would silently reinterpret an absolute-looking --path as relative
        // instead of letting resolveWithin() refuse it the way it is designed
        // to.
        $pathOption = trim((string) ($this->option('path') ?? ''));
        $relativePath = $pathOption !== '' ? $pathOption : "packages/{$vendor}/{$package}";

        // HostPath::resolveWithin() throws InvalidArgumentException on a '..'
        // segment or a symlink escape (E51) — allowed to propagate as-is,
        // since it already carries the right exception type and a message
        // naming the problem.
        $absolutePath = HostPath::root($this->laravel->basePath())->resolveWithin($relativePath);

        $constraint = $this->hostNodeflowConstraint();

        if ($constraint === null) {
            throw new InvalidArgumentException(
                'The host composer.json does not require atram/laravel-nodeflow (E33). Run '.
                '`composer require atram/laravel-nodeflow` first, so the scaffolded package can '.
                'mirror the same constraint the host itself resolved to.'
            );
        }

        if (! $this->targetIsAvailable($absolutePath, $name)) {
            throw new InvalidArgumentException(
                "[{$relativePath}] already exists and its composer.json does not name [{$name}] ".
                '(E43). Pass --force to overwrite it anyway.'
            );
        }

        return new PackageTarget(
            composerName: $name,
            namespace: $namespace,
            absolutePath: $absolutePath,
            relativePath: $relativePath,
            providerClass: $providerClass,
            nodeflowConstraint: $constraint,
            withJs: (bool) $this->option('js'),
        );
    }

    /** @throws InvalidArgumentException */
    private function assertValidNamespaceSegments(string $fqcn): void
    {
        foreach (explode('\\', trim($fqcn, '\\')) as $segment) {
            if (preg_match(self::NAMESPACE_SEGMENT_PATTERN, $segment) !== 1) {
                throw new InvalidArgumentException(
                    "[{$segment}] is not a valid PHP identifier, so [{$fqcn}] is not a namespace ".
                    'PHP can parse (E52). Every segment must match the pattern '.
                    self::NAMESPACE_SEGMENT_PATTERN.' — a Composer name may contain characters '.
                    '(a leading digit, a literal dot) that Composer accepts and PHP cannot. Pass '.
                    '--namespace to supply one explicitly.'
                );
            }
        }
    }

    /**
     * The host's own `atram/laravel-nodeflow` require constraint (E33), or
     * null when there is nothing to mirror: no composer.json, an
     * unparseable one, or one whose `require` does not list the package.
     */
    private function hostNodeflowConstraint(): ?string
    {
        $path = $this->laravel->basePath('composer.json');

        if (! $this->files->exists($path)) {
            return null;
        }

        $decoded = json_decode($this->files->get($path), true);

        if (! is_array($decoded)) {
            return null;
        }

        $constraint = $decoded['require']['atram/laravel-nodeflow'] ?? null;

        return is_string($constraint) && $constraint !== '' ? $constraint : null;
    }

    /**
     * Whether $absolutePath is free to scaffold into: nothing is there yet,
     * --force was passed, or it already holds a composer.json naming this
     * exact package (E43) — the case that makes a second, idempotent run of
     * this same command succeed rather than being refused as "foreign".
     */
    private function targetIsAvailable(string $absolutePath, string $composerName): bool
    {
        if (! $this->files->isDirectory($absolutePath)) {
            return true;
        }

        if ((bool) $this->option('force')) {
            return true;
        }

        $composerJsonPath = $absolutePath.'/composer.json';

        if (! $this->files->exists($composerJsonPath)) {
            return false;
        }

        $decoded = json_decode($this->files->get($composerJsonPath), true);

        return is_array($decoded) && ($decoded['name'] ?? null) === $composerName;
    }

    /**
     * Prints, but never writes (E32, E20), the same host Vite alias and
     * tsconfig `paths` snippets InstallCommand would print for an unwired
     * host — reused rather than re-derived, since a JS-enabled package's own
     * resources/js/index.ts (see index.ts.stub) imports from `@nodeflow/editor`
     * and needs the exact same host wiring InstallCommand already knows how
     * to describe. Each step's own check() decides whether there is anything
     * to print; a host that is already wired gets nothing extra.
     */
    private function printJsWiring(): void
    {
        $base = $this->laravel->basePath();

        $steps = [
            new ViteAliasStep($this->files, $base),
            new TsconfigPathsStep($this->files, $base),
        ];

        foreach ($steps as $step) {
            $snippet = $step->snippet();

            if ($snippet === null) {
                continue;
            }

            $this->newLine();
            $this->components->warn($step->describe().' — add this to the host yourself:');
            $this->newLine();
            $this->line($snippet);
        }
    }
}
