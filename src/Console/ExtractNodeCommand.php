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

    /** Laravel's own default location for a host's node classes, and the one gate G2 requires the class to live inside (E51). */
    private const HOST_APP_DIR = 'app';

    /** The directories widened E46 requires G5 to scan, beyond app/ alone — a reference can live in config, a route file, a Blade view, or a migration. */
    private const REFERENCE_SCAN_DIRS = ['app', 'config', 'database', 'resources', 'routes'];

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

        $testFile = $hostBasePath.'/'.self::TEST_DIR.'/'.$reflection->getShortName().'Test.php';

        if (is_file($testFile)) {
            $spans[] = RewritableSpan::wholeFile($testFile);
        }

        $providerFile = $hostBasePath.'/'.ProviderStep::PATH;

        if (is_file($providerFile)) {
            array_push($spans, ...$this->providerSpans($providerFile, $class));
        }

        return $spans;
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
     * than trusted regardless). Then `HostPath::contains()` against the
     * host's own `app/` directory (E51): a class living under `vendor/`, or
     * reached only through a symlink that escapes `app/`, is not something
     * this command can extract FROM the host, because it is not part of
     * the host's own source in the first place. Then E47: the file must
     * declare exactly one top-level named symbol. M2 (Task 9) rewrites the
     * file's namespace, which moves EVERY declaration the file contains,
     * while NodeReferenceScanner (G5) only ever looks for references to the
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
            $appRoot = HostPath::root($hostBasePath.'/'.self::HOST_APP_DIR);
        } catch (InvalidArgumentException $e) {
            return "The host's own [".self::HOST_APP_DIR."] directory could not be resolved: {$e->getMessage()}";
        }

        if (! $appRoot->contains($file)) {
            return "[{$class}]'s file [{$file}] is not inside the host application's own [".self::HOST_APP_DIR
                .'] directory (E51). A class outside the host — for example one already shipped inside '
                .'vendor/ — cannot be extracted from it.';
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

    /** @param  array<string, mixed>  $decoded */
    private function requiredFromMatchingPathRepository(array $decoded, string $targetRelativePath): bool
    {
        $repositories = $decoded['repositories'] ?? [];

        if (! is_array($repositories)) {
            return false;
        }

        $normalisedTarget = trim($targetRelativePath, '/');

        foreach ($repositories as $repository) {
            if (! is_array($repository) || ($repository['type'] ?? null) !== 'path') {
                continue;
            }

            $url = $repository['url'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $normalisedUrl = trim($url, '/');

            // fnmatch(), not an equality check: Composer's own path repository
            // url may be a glob (e.g. "packages/*/*"), and a literal string
            // compare would treat every such host as "pointing elsewhere" even
            // when its glob genuinely covers $targetRelativePath. A literal
            // url with no glob metacharacter is already an exact match under
            // fnmatch() (confirmed: a mutation deleting a separate `===`
            // equality check alongside this call survives every test in this
            // suite), so no separate equality branch is kept here.
            if (fnmatch($normalisedUrl, $normalisedTarget)) {
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

    /** @return list<RewritableSpan> */
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

        $nodesArray = $this->nodesArrayBody(file_get_contents($providerFile) ?: '');

        if ($nodesArray !== null) {
            foreach ($inProvider as $reference) {
                if ($reference->byteStart >= $nodesArray['bodyStart'] && $reference->byteEnd <= $nodesArray['bodyEnd']) {
                    $spans[] = new RewritableSpan($reference->file, $reference->byteStart, $reference->byteEnd);
                }
            }
        }

        return $spans;
    }

    /**
     * The byte range strictly between the brackets of the provider's own
     * `protected array $nodes = [ ... ]` property (NodeRegistrationWriter's
     * own ANCHOR, reused verbatim rather than re-spelled here), found by
     * bracket-matching over TOKENS rather than raw characters — the same
     * reason NodeRegistrationWriter::arraySpan() does: a `]` sitting inside
     * a string literal or a comment must never be mistaken for the array's
     * own closing bracket.
     *
     * @return array{bodyStart: int, bodyEnd: int}|null
     */
    private function nodesArrayBody(string $contents): ?array
    {
        $anchor = NodeRegistrationWriter::ANCHOR;
        $anchorPos = strpos($contents, $anchor);

        if ($anchorPos === false) {
            return null;
        }

        // ANCHOR ends in '[' itself, so its own last character IS the
        // opening bracket; no separate search for it is needed.
        $openRaw = $anchorPos + strlen($anchor) - 1;

        $tokens = [];
        $raw = 0;

        foreach (token_get_all($contents) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $tokens[] = ['isPunct' => ! is_array($token), 'text' => $text, 'start' => $raw, 'end' => $raw + strlen($text)];
            $raw += strlen($text);
        }

        $openIndex = null;

        foreach ($tokens as $index => $token) {
            if ($token['start'] === $openRaw && $token['isPunct'] && $token['text'] === '[') {
                $openIndex = $index;

                break;
            }
        }

        if ($openIndex === null) {
            return null;
        }

        $depth = 0;

        for ($i = $openIndex, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (! $token['isPunct']) {
                continue;
            }

            if ($token['text'] === '[') {
                $depth++;
            } elseif ($token['text'] === ']') {
                $depth--;

                if ($depth === 0) {
                    return ['bodyStart' => $openRaw + 1, 'bodyEnd' => $token['start']];
                }
            }
        }

        return null;
    }

    /**
     * Every top-level named symbol in $source OTHER than $ownShortName —
     * the target class's own declaration, always excluded — or null when
     * there is none (E47).
     *
     * "Top-level" means at brace depth 0: a method inside the class body,
     * or a closure's own body, sits at depth 1 or deeper and is never a
     * candidate. Depth is tracked over literal '{'/'}' tokens only, the
     * same simplification NodeTypeLiteral's own brace matching makes — a
     * braced `namespace X { ... }` block (rather than the ordinary
     * `namespace X;` every generator in this package writes) would count
     * as one extra level of depth and is a stated limitation, not a
     * silent one.
     */
    private function findCompanionSymbol(string $source, string $ownShortName): ?string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;

            if ($id === null) {
                if ($text === '{') {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;
                }

                continue;
            }

            if ($depth !== 0) {
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

    /** Same as nextDeclaredName(), but tolerates a by-ref '&' between `function` and the name — a closure's `function (` (or `function &(`) still correctly yields null. */
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

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
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
