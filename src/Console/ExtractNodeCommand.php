<?php

namespace Nodeflow\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Nodeflow\Console\Install\ProviderStep;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\InvalidNodeException;
use Nodeflow\Nodes\Node;
use Nodeflow\Nodes\NodeRegistry;
use ReflectionClass;
use RuntimeException;

/**
 * `nodeflow:extract-node {class} --package=vendor/name` — moves a node class
 * out of the host application into its own Composer package.
 *
 * THIS TASK IMPLEMENTS THE EIGHT READ-ONLY GATES ONLY. `handle()` runs all
 * eight, in order, and returns `self::FAILURE` the moment any one refuses.
 * If every gate passes, it prints a "gates passed, nothing moved yet" notice
 * and returns `self::SUCCESS` — Task 9 replaces that notice with the actual
 * moves. Every gate is strictly read-only: none of the eight may write to
 * the host, and a refusal test asserting the host tree is byte-identical
 * before and after is the point of gating before moving at all.
 *
 * THE CROSS-TASK OBLIGATION THIS CLASS OWNS. G5 refuses any reference to the
 * node class that the extraction will NOT itself rewrite. The set of spans
 * it WILL rewrite is defined by `rewritableSpans()` below — Task 9's moves
 * MUST call that exact method rather than re-derive the set. A gate and its
 * moves disagreeing about what counts as a rewritable span is precisely the
 * defect class (E45) this command exists to prevent: the first design draft
 * exempted whole FILES, which silently let a legacy `Nodeflow::register()`
 * call living in the same file as an exempted `$nodes` entry survive
 * undetected. Exemption must be per byte SPAN, and there must be exactly one
 * definition of that set, consumed by both the gate and the moves.
 */
class ExtractNodeCommand extends Command
{
    protected $signature = 'nodeflow:extract-node
        {class : Fully-qualified class name of the node to extract}
        {--package= : The Composer package name the class will move into, e.g. acme/widgets}
        {--namespace= : PHP namespace for the package; default is derived from --package}
        {--path= : Path, relative to the host root, to scaffold into; default is packages/vendor/name}
        {--force : Overwrite an occupied target directory that is not already this package}';

    protected $description = "Extract a node class into its own Composer package (gates only — this build doesn't move anything yet).";

    /** The Composer package name's own directory, excluded from G2's host containment rule — code already shipped as part of another package is not the host's own source (E51). */
    private const VENDOR_DIR = 'vendor';

    /**
     * The directories E46 requires G5 to scan: a reference can live in
     * config, a route file, a Blade view, a migration, the host's own
     * bootstrap file (bootstrap/app.php is Laravel 11's own provider
     * registration site), or the host's own test suite.
     */
    private const REFERENCE_SCAN_DIRS = ['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'tests'];

    /** Where MakeNodeCommand::writeTest() puts a generated node's test — the one convention this command has to guess a test file's location by. */
    private const TEST_DIR = 'tests/Feature/Nodeflow';

    /**
     * The `type()` literal G3 proved for the class under extraction, or null
     * before G3 runs (or after handle()'s own F-3 reset). Task 9's M9 needs
     * this exact, already-proven value rather than re-deriving it —
     * `NodeTypeLiteral::resolve()` is not free, and G3 having already
     * refused every shape that cannot be proven statically is the whole
     * reason M9 is allowed to trust it.
     */
    private ?string $provenType = null;

    /**
     * Whether the host's composer.lock exists, recorded by G8 for E48, or
     * null before G8 runs.
     */
    private ?bool $composerLockExisted = null;

    public function __construct(private Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(NodeRegistry $registry): int
    {
        // F-3: this exact bug shipped twice already against a DIFFERENT
        // cached property on a different command (MakeNodeCommand's
        // nodeType(), MakeNodePackageCommand's target()) — a second
        // Artisan::call() of this same command instance, from a host script
        // or a test file invoking artisan() twice, reuses this exact object
        // and these exact properties. Reset both here, unconditionally,
        // before gate 1 ever runs.
        $this->provenType = null;
        $this->composerLockExisted = null;

        $class = trim((string) $this->argument('class'));
        $hostBasePath = $this->laravel->basePath();

        if (($message = $this->gate1($class)) !== null) {
            return $this->refuse($message);
        }

        if (($message = $this->gate2($class, $hostBasePath)) !== null) {
            return $this->refuse($message);
        }

        // G2 already proved this file exists, is inside the host, and
        // declares exactly one top-level named symbol — the target class
        // itself — so reading it again here for G3 is safe.
        $source = file_get_contents((new ReflectionClass($class))->getFileName());

        if (($message = $this->gate3($class, $source)) !== null) {
            return $this->refuse($message);
        }

        if (($message = $this->gate4($registry, $class)) !== null) {
            return $this->refuse($message);
        }

        if (($message = $this->gate5($class, $hostBasePath)) !== null) {
            return $this->refuse($message);
        }

        $packageName = trim((string) $this->option('package'));

        if ($packageName === '') {
            return $this->refuse(
                'A --package name is required, e.g. --package=acme/widgets. Extraction needs to know '
                .'which Composer package this class is moving into before it can check the host for a '
                .'naming conflict (G6) or a target-path collision (G7).'
            );
        }

        // Reuses MakeNodePackageCommand's own Composer-name pattern rather than
        // inventing a second one. This also closes a real G6 bypass as a side
        // effect, not by a separate case rule: the pattern is lowercase-only,
        // so `--package=ACME/Widgets` is refused HERE, before it can ever reach
        // G6's exact `===` comparison against a lowercase dont-discover entry
        // (or a lowercase composer.json require key) and pass one check by
        // spelling alone.
        if (preg_match(MakeNodePackageCommand::COMPOSER_NAME_PATTERN, $packageName) !== 1) {
            return $this->refuse(
                "[{$packageName}] is not a valid Composer package name. It must match Composer's own ".
                'pattern '.MakeNodePackageCommand::COMPOSER_NAME_PATTERN.', e.g. acme/widgets.'
            );
        }

        $targetRelativePath = $this->targetRelativePath($packageName);

        if (($message = $this->gate6($hostBasePath, $packageName, $targetRelativePath)) !== null) {
            return $this->refuse($message);
        }

        if (($message = $this->gate7($hostBasePath, $packageName, $targetRelativePath)) !== null) {
            return $this->refuse($message);
        }

        if (($message = $this->gate8($hostBasePath)) !== null) {
            return $this->refuse($message);
        }

        $lockState = $this->composerLockExisted ? 'present' : 'absent';

        $this->components->info(
            "All eight gates passed for [{$class}]. Nothing has been moved — this build stops here; "
            ."Task 9 performs the actual moves. (composer.lock: {$lockState})"
        );

        return self::SUCCESS;
    }

    /**
     * The byte spans THIS extraction will itself transform: the node's own
     * file (in full — moving it rewrites its namespace, which moves every
     * declaration the file has, which is exactly why G2 requires there be
     * only one), its test file if one exists at the conventional path (in
     * full, same reason), the host's own NodeflowServiceProvider `$nodes`
     * entry for this class, and that same provider's `use` import for this
     * class.
     *
     * G5 subtracts exactly this set from what `NodeReferenceScanner::scan()`
     * finds; any reference left over refuses. TASK 9'S MOVES MUST CALL THIS
     * EXACT METHOD RATHER THAN RE-DERIVE THE SET — see this class's own
     * docblock for why a gate and its moves disagreeing about what counts as
     * a rewritable span is precisely the defect (E45) this command exists to
     * prevent. Public, and not `static`, so Task 9's moves (added to this
     * same command) can call it directly on `$this`.
     *
     * @return list<RewritableSpan>
     */
    public function rewritableSpans(string $class, string $hostBasePath): array
    {
        $spans = [];

        $reflection = new ReflectionClass($class);
        $nodeFile = $reflection->getFileName();

        if ($nodeFile !== false && is_file($nodeFile)) {
            $spans[] = RewritableSpan::wholeFile($nodeFile);
        }

        // The conventional path is only a CANDIDATE, not proof: it is keyed
        // by short class name alone, so two classes sharing a short name in
        // different namespaces collide on the exact same test path. Claiming
        // it regardless would hand Task 9's moves a file to move that may
        // belong to a DIFFERENT class entirely — so the candidate is only
        // trusted once it is confirmed to actually reference $class.
        $testFile = $hostBasePath.'/'.self::TEST_DIR.'/'.$reflection->getShortName().'Test.php';

        if (is_file($testFile) && $this->fileReferencesClass($testFile, $class)) {
            $spans[] = RewritableSpan::wholeFile($testFile);
        }

        $providerFile = $hostBasePath.'/'.ProviderStep::PATH;

        if (is_file($providerFile)) {
            array_push($spans, ...$this->providerSpans($providerFile, $class));
        }

        return $spans;
    }

    /**
     * Whether $file itself (not merely some sibling in the same directory)
     * contains a reference $class resolves to — scanning $file's own
     * directory and then filtering the results down to $file's own
     * canonical path, the same pattern `providerSpans()` uses and for the
     * same reason: `NodeReferenceScanner::scan()` only accepts a directory,
     * not a single file.
     */
    private function fileReferencesClass(string $file, string $class): bool
    {
        try {
            $references = NodeReferenceScanner::scan($class, [dirname($file)]);
        } catch (RuntimeException) {
            return false;
        }

        $canonical = realpath($file) ?: $file;

        foreach ($references as $reference) {
            if ((realpath($reference->file) ?: $reference->file) === $canonical) {
                return true;
            }
        }

        return false;
    }

    /**
     * G1 — the same class-existence, `is_a(Node::class)`, and cardinality
     * rules `NodeRegistry::register()` enforces, with its own exception
     * messages reused verbatim rather than invented afresh here. Stops
     * short of calling `register()` itself: that method's last line calls
     * `$class::type()`, and G3 (not G1) is this command's only sanctioned
     * way to learn anything about `type()` — it does so by reading the
     * SOURCE, never by executing the method, which is exactly what
     * "read-only" rules out.
     */
    private function gate1(string $class): ?string
    {
        if ($class === '' || ! class_exists($class)) {
            return InvalidNodeException::notANode($class)->getMessage();
        }

        if (! is_a($class, Node::class, true)) {
            return InvalidNodeException::notANode($class)->getMessage();
        }

        if (! is_a($class, HandlesSubject::class, true) && ! is_a($class, HandlesAudience::class, true)) {
            return InvalidNodeException::noCardinality($class)->getMessage();
        }

        return null;
    }

    /**
     * G2 — where the class lives, and what else its file declares.
     *
     * `ReflectionClass::getFileName()` first (false only for an internal or
     * eval'd class — unreachable once G1 has already proven `$class`
     * extends the project's own, PHP-defined `Node`, but refused rather
     * than trusted regardless). Then containment (E51): the rule is
     * "inside the host root, and not under vendor/" — NOT "inside app/
     * specifically". A host is free to map its own root namespace onto
     * `src/`, or anywhere else, via its own PSR-4 config; requiring `app/`
     * literally would fail safe (refuse) for such a host's otherwise
     * perfectly legitimate node, and reading the host's own PSR-4 map to
     * find out is more surface than this gate needs for what it actually
     * has to prevent — extracting a class that is not part of the host's
     * own source at all, either because it lives outside the host root
     * entirely, or because it is reached only through a symlink that
     * escapes the root, or because it already ships inside `vendor/` as
     * part of some OTHER Composer package. Then E47: the file must declare
     * exactly one top-level named symbol. M2 (Task 9) rewrites the file's
     * namespace, which moves EVERY declaration the file contains, while
     * NodeReferenceScanner (G5) only ever looks for references to the
     * NODE — so a companion trait, interface, enum, function, or constant
     * would move silently and break any host code that still uses it under
     * its old name.
     */
    private function gate2(string $class, string $hostBasePath): ?string
    {
        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();

        if ($file === false) {
            return "[{$class}] has no source file ReflectionClass can locate (an internal or eval'd "
                .'class); extraction cannot proceed.';
        }

        try {
            $hostRoot = HostPath::root($hostBasePath);
        } catch (InvalidArgumentException $e) {
            return "The host's own root could not be resolved: {$e->getMessage()}";
        }

        if (! $hostRoot->contains($file)) {
            return "[{$class}]'s file [{$file}] is not inside the host application's own root (E51). A "
                .'class outside the host cannot be extracted from it.';
        }

        if ($this->underVendor($file, $hostBasePath)) {
            return "[{$class}]'s file [{$file}] lives under [".self::VENDOR_DIR.'/] (E51). A class already '
                .'shipped as part of another Composer package is not the host\'s own source and cannot be '
                .'extracted from it.';
        }

        $source = file_get_contents($file);
        $companion = $this->findCompanionSymbol($source, $reflection->getShortName());

        if ($companion !== null) {
            return "[{$file}] also declares [{$companion}] (E47). Extraction rewrites this file's own "
                .'namespace, which moves EVERY declaration inside it, while the reference scan (G5) only '
                ."looks for uses of [{$reflection->getShortName()}] itself — so [{$companion}] would move "
                .'silently and any host code still using it under its old name would break. Move '
                ."[{$companion}] into its own file before extracting.";
        }

        return null;
    }

    /**
     * Whether $file lives inside the host's own vendor/, canonically
     * (E51). A host missing a vendor/ directory entirely (freshly cloned,
     * dependencies not yet installed) has nothing to be "under" — treated
     * as false rather than an error, since the containment check against
     * the host ROOT above has already run and already passed by the time
     * this is called.
     */
    private function underVendor(string $file, string $hostBasePath): bool
    {
        try {
            $vendorRoot = HostPath::root($hostBasePath.'/'.self::VENDOR_DIR);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $vendorRoot->contains($file);
    }

    /**
     * G3 — proves `type()` is a fixed literal (E36), and records it (M9).
     * The one gate whose absence a re-run cannot repair: a `type()` derived
     * from the class name (e.g. `strtolower(class_basename(static::class))`)
     * silently changes identity the moment the namespace moves, orphaning
     * every already-published flow version and every run sitting mid-wait
     * that resolves through the old string.
     */
    private function gate3(string $class, string $source): ?string
    {
        $result = NodeTypeLiteral::resolve($source, class_basename($class));

        if (! $result->ok()) {
            return $result->reason;
        }

        $this->provenType = $result->type;

        return null;
    }

    /**
     * G4 — refuses only when the type is already claimed by ANOTHER class.
     * Unregistered is explicitly NOT a refusal: a freshly generated node,
     * never yet wired into the host's provider, is legitimately extractable
     * — G4 exists to catch a COLLISION, not to require prior registration.
     */
    private function gate4(NodeRegistry $registry, string $class): ?string
    {
        $type = $this->provenType;

        if (! $registry->has($type)) {
            return null;
        }

        $owner = $registry->resolve($type)::class;

        if ($owner === $class) {
            return null;
        }

        return "Type [{$type}] is already registered by [{$owner}], not [{$class}]. Two classes cannot "
            .'share one type, so extraction refuses rather than move a class whose type the registry '
            .'attributes to something else.';
    }

    /**
     * G5 — scans the widened set of host roots (E46) for every reference to
     * $class, then subtracts exactly the spans `rewritableSpans()` proves
     * the extraction will itself transform (E45). Any survivor refuses,
     * named as `file:line`. `NodeReferenceScanner::scan()` throws a
     * `RuntimeException` naming a file with more than one `namespace` block
     * — allowed to propagate into a clean refusal here rather than caught
     * and re-wrapped, since it already names the file and the reason.
     */
    private function gate5(string $class, string $hostBasePath): ?string
    {
        $roots = array_values(array_filter(
            array_map(
                static fn (string $dir): string => $hostBasePath.'/'.$dir,
                self::REFERENCE_SCAN_DIRS,
            ),
            fn (string $root): bool => $this->files->isDirectory($root),
        ));

        try {
            $found = NodeReferenceScanner::scan($class, $roots);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        if ($found === []) {
            return null;
        }

        $spans = $this->rewritableSpans($class, $hostBasePath);

        $survivors = array_values(array_filter(
            $found,
            fn (NodeReference $reference): bool => ! $this->isRewritten($reference, $spans),
        ));

        if ($survivors === []) {
            return null;
        }

        $locations = array_map(
            static fn (NodeReference $reference): string => "{$reference->file}:{$reference->line}",
            $survivors,
        );

        return "Extraction would leave the host still naming [{$class}] by its old FQCN after the move, "
            .'because NodeRegistry::register() autoloads through is_a() and a stale reference is a fatal '
            ."in the host's boot() on every request (E45), at:\n".implode("\n", $locations);
    }

    private function isRewritten(NodeReference $reference, array $spans): bool
    {
        foreach ($spans as $span) {
            if ($span->covers($reference->file, $reference->byteStart, $reference->byteEnd)) {
                return true;
            }
        }

        return false;
    }

    /**
     * G6 — the host's composer.json must parse; $packageName must not
     * already be required from a DIFFERENT source (a path repository
     * pointing somewhere other than $targetRelativePath, or no path
     * repository at all — either way, extraction would create a second,
     * conflicting source for the same package name); and
     * extra.laravel.dont-discover must not cover the new package (E49) — a
     * "*" entry, or the package's own name, would silently stop the
     * extracted package's provider from ever being discovered, so the host
     * would lose its only registration of the node with no error anywhere.
     */
    private function gate6(string $hostBasePath, string $packageName, string $targetRelativePath): ?string
    {
        $path = $hostBasePath.'/composer.json';

        if (! $this->files->exists($path)) {
            return "The host [{$path}] does not exist; extraction cannot check it for a naming conflict "
                .'or a discovery override.';
        }

        $decoded = json_decode($this->files->get($path), true);

        if (! is_array($decoded)) {
            return "The host [{$path}] does not parse as JSON; extraction cannot check it for a naming "
                .'conflict or a discovery override.';
        }

        $require = is_array($decoded['require'] ?? null) ? $decoded['require'] : [];
        $requireDev = is_array($decoded['require-dev'] ?? null) ? $decoded['require-dev'] : [];

        if ((array_key_exists($packageName, $require) || array_key_exists($packageName, $requireDev))
            && ! $this->requiredFromMatchingPathRepository($decoded, $targetRelativePath)) {
            return "[{$packageName}] is already required in the host's composer.json, but not from a "
                ."path repository pointing at [{$targetRelativePath}]. Extraction would create a second, "
                .'conflicting source for the same package name — remove or repoint the existing '
                .'requirement first.';
        }

        foreach ($this->dontDiscoverEntries($decoded) as $entry) {
            if ($entry === '*' || $entry === $packageName) {
                return "The host's composer.json lists [{$entry}] under extra.laravel.dont-discover "
                    ."(E49), which would silently stop [{$packageName}]'s provider from ever being "
                    .'discovered — the host would lose its only registration of this node with no error '
                    .'anywhere. Remove or narrow that entry before extracting.';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     *
     * TWO SEPARATE COMPARISONS, deliberately, not `fnmatch()` alone. A
     * segment-wise equality check (via `HostPath::segments()`, the same
     * canonical, slash-agnostic comparison every other path comparison in
     * this codebase already uses) handles a literal url. A SEPARATE
     * `fnmatch()` call, with `FNM_PATHNAME` and only when the url actually
     * contains a glob metacharacter, handles a genuine glob — `*` cannot
     * cross a `/` under `FNM_PATHNAME`, which is exactly what makes
     * Composer's own idiomatic monorepo form (`"packages/*"`, ONE
     * wildcard segment) fail to match a TWO-segment target
     * (`packages/acme/widgets`) the same way Composer's own path
     * repository resolution would. Without `FNM_PATHNAME`, `packages/*`
     * matches ANY target nested arbitrarily deep under `packages/` —
     * a segment-wise glob doing a "does this string merely start with
     * this prefix" job, the substring-shaped mistake this codebase's own
     * `HostPath` docblock names as its most recent recurrence.
     */
    private function requiredFromMatchingPathRepository(array $decoded, string $targetRelativePath): bool
    {
        $repositories = $decoded['repositories'] ?? [];

        if (! is_array($repositories)) {
            return false;
        }

        $targetSegments = HostPath::segments($targetRelativePath);
        $normalisedTarget = implode('/', $targetSegments);

        foreach ($repositories as $repository) {
            if (! is_array($repository) || ($repository['type'] ?? null) !== 'path') {
                continue;
            }

            $url = $repository['url'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            if (HostPath::segments($url) === $targetSegments) {
                return true;
            }

            if (preg_match('/[*?\[]/', $url) === 1
                && fnmatch(implode('/', HostPath::segments($url)), $normalisedTarget, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return list<string>
     */
    private function dontDiscoverEntries(array $decoded): array
    {
        $raw = $decoded['extra']['laravel']['dont-discover'] ?? [];

        if (is_string($raw)) {
            return [$raw];
        }

        return is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
    }

    /**
     * G7 (E43) — the target path must be absent, empty, or already hold
     * exactly the package named by --package (a legitimate re-run of this
     * same extraction). --force overrides an otherwise-foreign occupant,
     * matching MakeNodePackageCommand's own targetIsAvailable() rule for
     * the same option.
     */
    private function gate7(string $hostBasePath, string $packageName, string $targetRelativePath): ?string
    {
        try {
            $host = HostPath::root($hostBasePath);
            $absolutePath = $host->resolveWithin($targetRelativePath);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        if (! $this->files->isDirectory($absolutePath)) {
            return null;
        }

        if ((bool) $this->option('force')) {
            return null;
        }

        $entries = array_diff(scandir($absolutePath) ?: [], ['.', '..']);

        if ($entries === []) {
            return null;
        }

        $composerJsonPath = $absolutePath.'/composer.json';

        if ($this->files->exists($composerJsonPath)) {
            $decoded = json_decode($this->files->get($composerJsonPath), true);

            if (is_array($decoded) && ($decoded['name'] ?? null) === $packageName) {
                return null;
            }

            $occupant = is_array($decoded) && is_string($decoded['name'] ?? null)
                ? $decoded['name']
                : '(a composer.json with no readable name)';

            return "[{$targetRelativePath}] is occupied by [{$occupant}], not [{$packageName}] (E43). "
                .'Pass --force to overwrite it anyway.';
        }

        return "[{$targetRelativePath}] is occupied (it holds files but no composer.json) (E43). Pass "
            .'--force to overwrite it anyway.';
    }

    /**
     * G8 — Composer must be invocable, and whether composer.lock exists is
     * recorded (not acted on) for Task 10's E48.
     */
    private function gate8(string $hostBasePath): ?string
    {
        $output = [];
        exec('composer --version 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            return 'The `composer` executable is not invocable (`composer --version` exited '
                ."[{$exitCode}]). Extraction needs Composer to update the host's composer.json and "
                ."regenerate the autoloader once Task 9's moves actually run, so it refuses now rather "
                .'than fail partway through a move later.';
        }

        $this->composerLockExisted = $this->files->exists($hostBasePath.'/composer.lock');

        return null;
    }

    /**
     * The provider's own `use` import for $class (found by scanning, kept
     * only when it names THIS file), plus the exact byte span of every
     * PLAIN `$class::class` entry in the provider's `$nodes` array —
     * reusing `NodeRegistrationWriter::findClassEntrySpans()`'s own element
     * classification rather than exempting the array's whole body.
     *
     * WHY REUSE RATHER THAN A LOCAL BRACKET RANGE (Important 2). An
     * earlier version of this method exempted every byte between the
     * array's own brackets, kind-blind — which meant `protected array
     * $nodes = [ ...config('x', [Foo::class => 'alias']) ]` exempted a
     * REAL reference to Foo that the later move (built on
     * `NodeRegistrationWriter::removeFrom()`) can never actually touch,
     * because `removeFrom()` refuses a spread/nested-array element as
     * EntryUnsupported and leaves the WHOLE array alone. A too-wide
     * exemption silently certifying a rewrite that never happens is the
     * same defect E45 already named once, in a different shape.
     * `findClassEntrySpans()` returns [] whenever ANY element in the array
     * is not a plain `<name>::class` it can classify — so the gate and the
     * move now agree BY CONSTRUCTION: neither one certifies anything about
     * an array it cannot fully understand.
     *
     * @return list<RewritableSpan>
     */
    private function providerSpans(string $providerFile, string $class): array
    {
        try {
            $references = NodeReferenceScanner::scan($class, [dirname($providerFile)]);
        } catch (RuntimeException) {
            // The main G5 scan (over app/, which contains this same file)
            // already ran successfully by the time rewritableSpans() is
            // ever reached from gate5 — a multi-namespace provider would
            // have refused there first. Reached defensively for a direct
            // rewritableSpans() call outside that order (Task 9's own use);
            // no spans to report for a file this command cannot safely read.
            return [];
        }

        // Filtered to THIS file, canonically, and not merely by raw string
        // equality: NodeReferenceScanner::scan() is handed the provider's
        // own DIRECTORY, and every sibling file living alongside it (an
        // AppServiceProvider, say) is scanned too. A reference sitting in
        // a SIBLING file must never be folded into this provider's own
        // exemption set — that sibling is not one of the files Task 9
        // rewrites, and exempting it here would silently certify a rewrite
        // that will never happen to it either.
        $canonicalProvider = realpath($providerFile) ?: $providerFile;

        $inProvider = array_values(array_filter(
            $references,
            static fn (NodeReference $reference): bool => (realpath($reference->file) ?: $reference->file) === $canonicalProvider,
        ));

        $spans = [];

        foreach ($inProvider as $reference) {
            if ($reference->kind === 'import') {
                $spans[] = new RewritableSpan($reference->file, $reference->byteStart, $reference->byteEnd);
            }
        }

        $writer = new NodeRegistrationWriter($this->files);
        $contents = file_get_contents($providerFile) ?: '';

        foreach ($writer->findClassEntrySpans($contents, NodeRegistrationWriter::ANCHOR, $class) as $entry) {
            $spans[] = new RewritableSpan($providerFile, $entry['start'], $entry['end']);
        }

        return $spans;
    }

    /**
     * Every top-level named symbol in $source OTHER than $ownShortName —
     * the target class's own declaration, always excluded — or null when
     * there is none (E47).
     *
     * "Top-level" means: not nested inside any brace OTHER than a
     * namespace's own braced block. A method inside the class body, or a
     * closure's own body, sits inside a real ('other') brace and is never
     * a candidate — but a braced `namespace X { ... }` block's OWN brace
     * does NOT count as one, exactly the distinction `PhpNameResolver` and
     * `NodeReferenceScanner` already make for the same reason (a `use`
     * statement, there; a companion declaration, here): a namespace brace
     * is not a SCOPE that hides what is inside it. A plain depth counter
     * over every '{'/'}' cannot make that distinction, which is why this
     * tracks a STACK of brace KINDS instead — `namespaceBraceIndexes()`
     * below is the exact algorithm those two classes already use to tell
     * a namespace's own brace apart from any other.
     */
    private function findCompanionSymbol(string $source, string $ownShortName): ?string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $namespaceBraces = $this->namespaceBraceIndexes($tokens);
        $braceKinds = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;

            if ($id === null) {
                if ($text === '{') {
                    $braceKinds[] = isset($namespaceBraces[$i]) ? 'namespace' : 'other';
                } elseif ($text === '}') {
                    array_pop($braceKinds);
                }

                continue;
            }

            if (in_array('other', $braceKinds, true)) {
                continue;
            }

            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $name = $this->nextDeclaredName($tokens, $i);

                if ($name !== null && $name !== $ownShortName) {
                    return $name;
                }

                continue;
            }

            if ($id === T_FUNCTION) {
                $name = $this->nextFunctionName($tokens, $i);

                if ($name !== null) {
                    return $name;
                }

                continue;
            }

            if ($id === T_CONST) {
                $name = $this->nextDeclaredName($tokens, $i);

                if ($name !== null) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Token index of every `{` opening a namespace's braced body
     * (`namespace App\Foo { ... }`, or the bare global `namespace { ... }`),
     * the same algorithm `PhpNameResolver::namespaceBraceIndexes()` and
     * `NodeReferenceScanner::namespaceBraceIndexes()` already use — walked
     * here rather than shared with either, because both of those operate
     * on a comment/whitespace-FILTERED token list whose indexes would not
     * line up with this method's own unfiltered one (findCompanionSymbol()
     * needs the raw stream so nextDeclaredName()/nextFunctionName() can
     * skip whitespace themselves, token by token, rather than being handed
     * an already-filtered array).
     *
     * @param  list<array{0:int,1:string}|string>  $tokens
     * @return array<int, true>
     */
    private function namespaceBraceIndexes(array $tokens): array
    {
        $indexes = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            $j = $i + 1;

            while ($j < $count) {
                $next = $tokens[$j];

                if (is_array($next) && in_array(
                    $next[0],
                    [T_NAME_QUALIFIED, T_STRING, T_NS_SEPARATOR, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                    true
                )) {
                    $j++;

                    continue;
                }

                break;
            }

            if (($tokens[$j] ?? null) === '{') {
                $indexes[$j] = true;
            }
        }

        return $indexes;
    }

    /** The T_STRING immediately following $keywordIndex (whitespace/comments skipped), or null — an anonymous class/expression has none. */
    private function nextDeclaredName(array $tokens, int $keywordIndex): ?string
    {
        for ($j = $keywordIndex + 1, $count = count($tokens); $j < $count; $j++) {
            $token = $tokens[$j];

            if (! is_array($token)) {
                return null;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token[0] === T_STRING ? $token[1] : null;
        }

        return null;
    }

    /**
     * Same as nextDeclaredName(), but tolerates a by-ref '&' between
     * `function` and the name — a closure's `function (` (or `function
     * &(`) still correctly yields null.
     *
     * The by-ref '&' is NOT the plain string token `nextDeclaredName()`
     * would see for e.g. a bitwise-and: PHP 8.1+'s tokeniser emits a
     * dedicated ARRAY token for it —
     * `T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG` specifically, since it is
     * followed by an identifier (the function's name), not a `$variable`
     * or `...` — so a check for the literal string `'&'` alone
     * (`! is_array($token) && $token === '&'`) can never match it and is
     * dead code on every supported PHP version. Confirmed empirically:
     * `token_get_all('<?php function &foo() {}')` emits
     * `T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG` as an array token, not a
     * bare string. Without recognising this token, a by-ref top-level
     * companion function was read as having NO name at all (the array
     * form fell through to the "not an array we care about" — wait, IS an
     * array — branch and was treated as an ordinary, unrecognised token),
     * silently passing G2 while the by-ref function moved with the file.
     */
    private function nextFunctionName(array $tokens, int $keywordIndex): ?string
    {
        for ($j = $keywordIndex + 1, $count = count($tokens); $j < $count; $j++) {
            $token = $tokens[$j];

            if (! is_array($token)) {
                if ($token === '&') {
                    continue;
                }

                return null;
            }

            if (in_array($token[0], [
                T_WHITESPACE,
                T_COMMENT,
                T_DOC_COMMENT,
                T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
                T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
            ], true)) {
                continue;
            }

            return $token[0] === T_STRING ? $token[1] : null;
        }

        return null;
    }

    private function targetRelativePath(string $packageName): string
    {
        $pathOption = trim((string) ($this->option('path') ?? ''));

        if ($pathOption !== '') {
            return $pathOption;
        }

        [$vendor, $package] = array_pad(explode('/', $packageName, 2), 2, '');

        return "packages/{$vendor}/{$package}";
    }

    private function refuse(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }

    public function provenType(): ?string
    {
        return $this->provenType;
    }

    public function composerLockExisted(): ?bool
    {
        return $this->composerLockExisted;
    }
}
