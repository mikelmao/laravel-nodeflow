<?php

namespace Nodeflow\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nodeflow\Console\Extract\ExtractJournal;
use Nodeflow\Console\Install\ProviderStep;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\InvalidNodeException;
use Nodeflow\Nodes\Node;
use Nodeflow\Nodes\NodeRegistry;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * `nodeflow:extract-node {class} --package=vendor/name` — moves a node class
 * out of the host application into its own Composer package.
 *
 * `handle()` runs all eight gates (Task 8), in order, and returns
 * `self::FAILURE` the moment any one refuses — every gate is strictly
 * read-only, and a refusal test asserting the host tree is byte-identical
 * before and after is the point of gating before moving at all. Once every
 * gate passes, `performMoves()` (Task 9) runs M1 through M7 plus M6a: it
 * scaffolds the package, moves the class and its test into it, edits both
 * providers and the host's own composer.json, re-scans the post-move tree
 * (M6a, E45), and only then deletes the originals (M7). Any failure at any
 * point in that sequence — including M6a's own abort — restores the host to
 * exactly the bytes it had before `performMoves()` ran (`ExtractJournal`).
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

    protected $description = 'Extract a node class into its own Composer package.';

    /** The directory segment excluded from G2's containment rule at ANY depth below the host root — code already shipped as part of a Composer package, the host's own or a nested one, is not the host's own source (E51). */
    private const VENDOR_DIR = 'vendor';

    /** Excluded from sharedScanRoots() the same way VENDOR_DIR is: a JS dependency tree is never the host's own source. */
    private const NODE_MODULES_DIR = 'node_modules';

    /**
     * Top-level directory NAME => the subdirectory NAMES sharedScanRoots()
     * excludes from it specifically (via NodeReferenceScanner's own
     * $excludedTopLevelNames parameter), for a review-round finding: the
     * gate that USED TO be a fixed, narrow allowlist (REFERENCE_SCAN_DIRS
     * — app, bootstrap, config, database, resources, routes, tests) missed
     * real references sitting in an ordinary top-level directory it never
     * scanned at all (scripts/, public/, a sibling local package under
     * packages/), while M6a's own separately-derived wider scan admitted
     * `storage/framework/` and `bootstrap/cache/` — COMPILED artifacts,
     * not source — and could abort a legitimate move over a stale cached
     * Blade view. sharedScanRoots() fixes both directions at once by
     * scanning every top-level directory (which is what E46's own
     * bootstrap/app.php requirement always needed anyway) and excluding
     * only the two known-artifact subdirectories, by name, scoped to their
     * OWN parent only — never the whole storage/ or bootstrap/ tree, and
     * never a same-named directory anywhere else.
     */
    private const ARTIFACT_SUBDIRECTORIES = [
        'storage' => ['framework'],
        'bootstrap' => ['cache'],
    ];

    /** Where MakeNodeCommand::writeTest() puts a generated node's test — the one convention this command has to guess a test file's location by. */
    private const TEST_DIR = 'tests/Feature/Nodeflow';

    /**
     * A single PHP identifier segment (E52) — the same pattern
     * MakeNodePackageCommand::NAMESPACE_SEGMENT_PATTERN uses, kept as its own
     * private copy rather than made public there: this class already
     * derives its OWN namespace independently of that command (it accepts
     * --namespace directly rather than going through
     * MakeNodePackageCommand::resolveTarget()), so the two validations are
     * two call sites of the same RULE, not one shared piece of state.
     */
    private const NAMESPACE_SEGMENT_PATTERN = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/';

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

        try {
            $target = $this->buildPackageTarget($packageName, $targetRelativePath, $hostBasePath);
        } catch (RuntimeException $e) {
            return $this->refuse($e->getMessage());
        }

        return $this->performMoves(
            $class,
            new ReflectionClass($class),
            $hostBasePath,
            $packageName,
            $targetRelativePath,
            $target,
        );
    }

    /**
     * M1-M7 and M6a — the actual moves, run only once every one of the eight
     * gates above has passed. Everything here is journaled: any failure at
     * ANY point, including M6a's own post-move rescan, restores the host to
     * exactly the bytes it had before this method ever ran (ExtractJournal's
     * own docblock explains why the undo order matters). M7 deletes the
     * ORIGINAL files last — after M6a has already proven nothing still
     * names them by their old FQCN — because deleting first and discovering
     * a survivor after would make "restore" mean "resurrect a file", which
     * is a needless risk when refusing BEFORE the delete costs nothing.
     */
    private function performMoves(
        string $class,
        ReflectionClass $reflection,
        string $hostBasePath,
        string $packageName,
        string $targetRelativePath,
        PackageTarget $target,
    ): int {
        $journal = new ExtractJournal($this->files);

        $nodeFile = $reflection->getFileName();
        $shortName = $reflection->getShortName();
        $newNamespace = rtrim($target->namespace, '\\').'\\Nodes';
        $newFqcn = $newNamespace.'\\'.$shortName;
        $testFile = $hostBasePath.'/'.self::TEST_DIR.'/'.$shortName.'Test.php';
        $providerFile = $hostBasePath.'/'.ProviderStep::PATH;
        $testMoved = false;

        try {
            $this->scaffoldPackage($target, $hostBasePath, $journal);
            $this->moveClassFile($class, $reflection, $nodeFile, $newNamespace, $newFqcn, $target, $journal);

            if (is_file($testFile) && $this->fileReferencesClass($testFile, $class)) {
                $this->moveTestFile($class, $testFile, $shortName, $newFqcn, $target, $journal);
                $testMoved = true;
            }

            $this->registerInPackage($newFqcn, $target, $journal);
            $this->deregisterFromHost($class, $shortName, $providerFile, $journal);
            $this->updateHostComposerJson($hostBasePath, $packageName, $targetRelativePath, $journal);

            // M6a (E45): the same rescan G5 already ran, but over the tree AS
            // IT NOW STANDS — including the package directory M1 only just
            // created, ground G5 could never have scanned because it did not
            // exist yet — and BEFORE M7 deletes anything, while restoring is
            // still cheap.
            $this->rescanPostMoveTree($class, $hostBasePath);

            $this->deleteOriginals($nodeFile, $testMoved ? $testFile : null, $journal);
        } catch (Throwable $e) {
            try {
                $journal->restore();
            } catch (Throwable $restoreFailure) {
                return $this->refuse(
                    "Extraction of [{$class}] aborted ({$e->getMessage()}), AND restoring the host also ".
                    "failed — it may be left partially modified and needs inspecting by hand: "
                    .$restoreFailure->getMessage()
                );
            }

            return $this->refuse(
                "Extraction of [{$class}] aborted; the host has been restored to its original state. "
                .$e->getMessage()
            );
        }

        $this->components->info(
            "Extracted [{$class}] into [{$packageName}] at [{$targetRelativePath}] as [{$newFqcn}]. Run "
            .'`composer install` (or `composer update`) to finish wiring the package in.'
        );

        return self::SUCCESS;
    }

    /**
     * Everything PackageScaffolder::scaffold() needs, validated the same way
     * MakeNodePackageCommand::resolveTarget() validates it for
     * nodeflow:make-node-package — but derived from THIS command's own
     * --package/--namespace options rather than routed through that other
     * command's object, since the two commands' inputs differ ($packageName
     * and $targetRelativePath are already validated by G6/G7 by the time
     * this runs).
     *
     * Deliberately called BEFORE any journal exists: every refusal here
     * happens before a single byte has been touched, so there is nothing to
     * restore — this is closer in spirit to a ninth gate than to a move.
     *
     * @throws RuntimeException
     */
    private function buildPackageTarget(string $packageName, string $targetRelativePath, string $hostBasePath): PackageTarget
    {
        [$vendor, $package] = array_pad(explode('/', $packageName, 2), 2, '');

        $namespaceOption = trim((string) ($this->option('namespace') ?? ''));

        $namespace = $namespaceOption !== ''
            ? trim($namespaceOption, '\\')
            : Str::studly($vendor).'\\'.Str::studly($package);

        $namespaceSegments = explode('\\', $namespace);
        $shortClassBase = Str::studly(end($namespaceSegments));
        $providerClass = $namespace.'\\'.$shortClassBase.'ServiceProvider';

        $this->assertValidNamespaceSegments($providerClass);

        $host = HostPath::root($hostBasePath);
        $absolutePath = $host->resolveWithin($targetRelativePath);

        $constraint = $this->hostNodeflowConstraint($hostBasePath);

        if ($constraint === null) {
            throw new RuntimeException(
                "The host's composer.json does not require atram/laravel-nodeflow (E33); extraction "
                .'cannot mirror a constraint that is not there. Run `composer require '
                .'atram/laravel-nodeflow` first.'
            );
        }

        return new PackageTarget(
            composerName: $packageName,
            namespace: $namespace,
            absolutePath: $absolutePath,
            relativePath: $targetRelativePath,
            providerClass: $providerClass,
            nodeflowConstraint: $constraint,
            withJs: false,
        );
    }

    /** @throws RuntimeException */
    private function assertValidNamespaceSegments(string $fqcn): void
    {
        foreach (explode('\\', trim($fqcn, '\\')) as $segment) {
            if (preg_match(self::NAMESPACE_SEGMENT_PATTERN, $segment) !== 1) {
                throw new RuntimeException(
                    "[{$segment}] is not a valid PHP identifier, so [{$fqcn}] is not a namespace PHP can "
                    .'parse (E52). Pass --namespace to supply one explicitly.'
                );
            }
        }
    }

    /** The host's own `atram/laravel-nodeflow` require constraint (E33), or null when there is nothing to mirror. */
    private function hostNodeflowConstraint(string $hostBasePath): ?string
    {
        $decoded = json_decode($this->files->get($hostBasePath.'/composer.json'), true);
        $constraint = is_array($decoded) ? ($decoded['require']['atram/laravel-nodeflow'] ?? null) : null;

        return is_string($constraint) && $constraint !== '' ? $constraint : null;
    }

    /**
     * M1 — scaffolds the package, then journals exactly what changed on
     * disk: every file PackageScaffolder overwrote that already EXISTED is
     * journaled as a write (captured BEFORE scaffold() runs, so the
     * original bytes are in hand); every path that did not exist before is
     * journaled as a create. Diffing the tree before and after, rather than
     * hard-coding the list of files PackageScaffolder happens to write today,
     * is what keeps this correct across all three E43 target states — absent,
     * a matching re-run, and a foreign --force overwrite — without this
     * class needing to know PackageScaffolder's own file list at all.
     */
    private function scaffoldPackage(PackageTarget $target, string $hostBasePath, ExtractJournal $journal): void
    {
        $existedBefore = $this->files->isDirectory($target->absolutePath);
        $before = $existedBefore ? $this->treeEntries($target->absolutePath) : [];

        // Computed BEFORE scaffold() runs, while it is still possible to see
        // which ancestor directories do not exist yet: --path may nest the
        // target several levels below anything the host already has (the
        // default packages/vendor/name is itself two levels), and
        // PackageScaffolder's own ensureDirectoryExists() creates every one
        // of them via a single recursive mkdir(). Recording only the LEAF
        // directory as a create would undo the leaf and leave its now-empty
        // ancestors behind — exactly the gap a mutation of this method
        // survived until a real diff against the host tree caught it.
        $missingRoot = $existedBefore ? null : $this->shallowestMissingAncestor($target->absolutePath);

        foreach ($before as $relative => $isDirectory) {
            if (! $isDirectory) {
                $journal->recordWrite($target->absolutePath.'/'.$relative);
            }
        }

        // try/finally, not a plain sequential call, and this is the
        // SECOND CRITICAL fix this exact method needed. scaffold() writes
        // its own files one at a time (composer.json, README.md, the
        // provider, the example test) with no transaction of its own —
        // and, decisively, `Filesystem::put()` calls a BARE
        // `file_put_contents()` with no `@` suppression, so a write that
        // fails (a `src/` or `tests/` directory a foreign occupant, or an
        // already-matching prior run, left read-only — E43's foreign/
        // --force AND matching-existing target states both) does not
        // return false for scaffold() to notice: it raises a warning that
        // PHPUnit's own error handler (and plenty of production error
        // handlers) turns into a THROWN exception, propagating stright out
        // of scaffold() itself. Journaling AFTER a plain `scaffold($target);`
        // call — even journaling that ran before some LATER throw — never
        // executes at all when scaffold() is what throws, and restore()
        // then has nothing to undo: the command would report "the host has
        // been restored to its original state" while whatever scaffold()
        // DID manage to write before failing sits there, unaccounted for.
        // `finally` guarantees this journaling runs whether scaffold()
        // returns normally or throws, and BEFORE that exception (if any)
        // is allowed to continue propagating to performMoves()'s own catch.
        try {
            (new PackageScaffolder($this->files, $hostBasePath))->scaffold($target);
        } finally {
            if (! $existedBefore) {
                // The whole subtree, from the shallowest missing ancestor
                // down, is new; one entry undoes all of it, whatever
                // fraction of it scaffold() actually managed to write
                // before failing, if it failed at all.
                $journal->recordCreate($missingRoot);
            } else {
                $after = $this->treeEntries($target->absolutePath);
                $newRelatives = array_values(array_diff(array_keys($after), array_keys($before)));

                // Shallowest first, so a later restore() (which processes
                // entries in REVERSE) removes the deepest new paths before
                // the directories that contain them — see ExtractJournal's
                // own docblock.
                usort($newRelatives, static fn (string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/'));

                foreach ($newRelatives as $relative) {
                    $journal->recordCreate($target->absolutePath.'/'.$relative);
                }
            }
        }

        // E11: re-verify rather than trust. PackageScaffolder validates every
        // rendered .php file parses BEFORE it writes anything, but does not
        // itself re-check that each write actually landed — file_put_contents()
        // returns false, rather than throwing, on a genuine disk failure (a
        // path component that collides with a plain file where a directory is
        // expected, most concretely). The provider file is the one M4 depends
        // on next, so its absence is the cheapest, earliest signal that
        // something about this scaffold did not actually take.
        $providerPath = $target->absolutePath.'/src/'.$this->shortClassName($target->providerClass).'.php';

        if (! $this->files->exists($providerPath)) {
            throw new RuntimeException(
                "Scaffolding [{$target->composerName}] did not produce its own provider at "
                ."[{$providerPath}]; nothing was moved."
            );
        }
    }

    /**
     * Walking UP from $path, the shallowest directory that does not exist
     * yet — the one whose recursive removal undoes every directory
     * PackageScaffolder's own single recursive mkdir() is about to create
     * along the way to $path, not merely $path itself. Must be computed
     * BEFORE anything creates those directories; once they exist, there is
     * nothing left to distinguish "always existed" from "created by this
     * run" by looking at the filesystem alone.
     */
    private function shallowestMissingAncestor(string $path): string
    {
        $current = $path;
        $missing = $path;

        // The host root itself always exists (handle() already resolved it
        // via HostPath::root()), so this loop is guaranteed to terminate at
        // or before reaching it; the dirname()-fixed-point check is
        // defensive only, for a $path this command somehow reached without
        // it being rooted under the host at all.
        while (! $this->files->isDirectory($current)) {
            $missing = $current;
            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }

        return $missing;
    }

    /**
     * Every entry (file or directory) recursively under $root, keyed by its
     * path relative to $root, valued true for a directory. Empty when $root
     * does not exist yet.
     *
     * @return array<string, bool>
     */
    private function treeEntries(string $root): array
    {
        $entries = [];

        $walk = function (string $dir) use (&$walk, &$entries, $root): void {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $path = $dir.'/'.$name;
                $relative = substr($path, strlen($root) + 1);

                if (is_dir($path)) {
                    $entries[$relative] = true;
                    $walk($path);
                } else {
                    $entries[$relative] = false;
                }
            }
        };

        if (is_dir($root)) {
            $walk($root);
        }

        return $entries;
    }

    /**
     * M2 — writes $class's own source into the package at
     * `src/Nodes/{ShortClass}.php` (mirroring the host's own
     * `app/Nodeflow/Nodes/` convention onto the package's PSR-4 root,
     * exactly the empty sibling directory PackageScaffolder already
     * prepared), rewriting ONLY the namespace declaration's own name and
     * every reference to $class found INSIDE the file itself — never a
     * global str_replace of the old namespace text (F-1), which would just
     * as happily rewrite a docblock or a string literal that legitimately
     * still names the old location and is not, in fact, a live reference at
     * all.
     */
    private function moveClassFile(
        string $class,
        ReflectionClass $reflection,
        string $nodeFile,
        string $newNamespace,
        string $newFqcn,
        PackageTarget $target,
        ExtractJournal $journal,
    ): void {
        $source = file_get_contents($nodeFile);

        if ($source === false) {
            throw new RuntimeException("[{$nodeFile}] could not be read; extraction cannot move it.");
        }

        $namespaceSpan = $this->namespaceDeclarationSpan($source);

        if ($namespaceSpan === null) {
            throw new RuntimeException(
                "[{$nodeFile}] has no namespace declaration extraction knows how to rewrite."
            );
        }

        $shortName = $reflection->getShortName();

        $replacements = [[
            'start' => $namespaceSpan['start'],
            'end' => $namespaceSpan['end'],
            'text' => $newNamespace,
        ]];

        foreach ($this->fileOwnReferences($class, $nodeFile, $source) as $reference) {
            $replacements[] = [
                'start' => $reference->byteStart,
                'end' => $reference->byteEnd,
                // A bare self-reference (`Foo::make()`) keeps resolving to
                // itself correctly once written as the short name alone,
                // because a name unqualified in its own (new) namespace
                // always resolves to a sibling declared in that namespace —
                // $preferShortName is true here for exactly that reason.
                // referenceReplacement() handles 'import' and
                // 'string_literal' the same way regardless of caller.
                'text' => $this->referenceReplacement($reference, $source, $class, $newFqcn, $shortName, true),
            ];
        }

        $rewritten = $this->applySpanReplacements($source, $replacements);

        if (! $this->parses($rewritten)) {
            throw new RuntimeException(
                "Rewriting [{$nodeFile}]'s namespace to [{$newNamespace}] produced PHP that does not "
                .'parse; nothing was moved.'
            );
        }

        $this->writeJournaled($target->absolutePath.'/src/Nodes/'.$shortName.'.php', $rewritten, $journal);
    }

    /**
     * M3 — moves the host's own test for $class (if the conventional path
     * holds one that genuinely references it — the same proof
     * `rewritableSpans()` requires) into the package at
     * `tests/{ShortClass}Test.php`, rewriting only its resolved `use`
     * import, never a namespace declaration: `stubs/node.test.stub` opens
     * `<?php` and declares no namespace at all, so a namespace-declaration
     * rewrite is a verified no-op on the exact file this move exists to fix.
     */
    private function moveTestFile(
        string $class,
        string $testFile,
        string $shortName,
        string $newFqcn,
        PackageTarget $target,
        ExtractJournal $journal,
    ): void {
        $source = file_get_contents($testFile);

        if ($source === false) {
            throw new RuntimeException("[{$testFile}] could not be read; extraction cannot move it.");
        }

        $references = $this->fileOwnReferences($class, $testFile, $source);

        if ($references === []) {
            // rewritableSpans()'s own fileReferencesClass() already proved
            // this file references $class before performMoves() ever
            // called this method — reached defensively rather than
            // expected, since fileOwnReferences() runs the identical scan
            // with the identical filter.
            throw new RuntimeException(
                "[{$testFile}] was proven to reference [{$class}] but no rewritable reference could be ".
                'found; nothing was moved.'
            );
        }

        $replacements = [];

        foreach ($references as $reference) {
            $replacements[] = [
                'start' => $reference->byteStart,
                'end' => $reference->byteEnd,
                // CRITICAL review finding: stubs/node.test.stub declares NO
                // namespace at all, so — unlike moveClassFile()'s own bare
                // short name, which resolves correctly only because that
                // file DOES declare $newNamespace — a bare name here has no
                // enclosing namespace to resolve against. Every non-import,
                // non-string-literal self-reference is therefore rewritten
                // FULLY QUALIFIED ($preferShortName = false), which resolves
                // correctly whether or not the `use` import to which it
                // might have deferred survives elsewhere in this same file.
                // Rewriting EVERY recorded span — not the import alone — is
                // what makes rewritableSpans()'s whole-file exemption for
                // this file honest, rather than narrower than what G5
                // actually certified as covered.
                'text' => $this->referenceReplacement($reference, $source, $class, $newFqcn, $shortName, false),
            ];
        }

        $rewritten = $this->applySpanReplacements($source, $replacements);

        if (! $this->parses($rewritten)) {
            throw new RuntimeException(
                "Rewriting [{$testFile}]'s references to [{$newFqcn}] produced PHP that does not parse; "
                .'nothing was moved.'
            );
        }

        $this->writeJournaled($target->absolutePath.'/tests/'.$shortName.'Test.php', $rewritten, $journal);
    }

    /**
     * The replacement TEXT for one found self-reference to $class, shared
     * by moveClassFile() (M2) and moveTestFile() (M3) so the two decide
     * "what does this span become" by the SAME rule rather than two
     * independently maintained ones.
     *
     * - `import`: the `use` statement's own member span, replaced with the
     *   plain new FQCN (correct in a `use` statement's name position
     *   regardless of enclosing namespace).
     * - `string_literal`: delegated to stringLiteralReplacement() — a
     *   string's VALUE does not resolve through PHP's namespace rules the
     *   way a name token does, and (Important 3) NOT every `string_literal`
     *   reference's span carries its own surrounding quotes.
     * - anything else (a bare self-reference such as `Foo::make()`, an
     *   `extends` clause, …): the bare short name when $preferShortName is
     *   true — correct ONLY inside a file that itself declares the new
     *   namespace (M2's class file) — or the fully-qualified new FQCN
     *   otherwise (M3's namespace-less test file), which resolves correctly
     *   with or without whatever `use` import happens to survive.
     */
    private function referenceReplacement(
        NodeReference $reference,
        string $source,
        string $class,
        string $newFqcn,
        string $shortName,
        bool $preferShortName,
    ): string {
        if ($reference->kind === 'import') {
            return $newFqcn;
        }

        $original = substr($source, $reference->byteStart, $reference->byteEnd - $reference->byteStart);

        if ($reference->kind === 'string_literal') {
            return $this->stringLiteralReplacement($reference, $source, $original, $class, $newFqcn);
        }

        return $preferShortName ? $shortName : '\\'.$newFqcn;
    }

    /**
     * Important 3 (review round). `NodeReferenceScanner` finds a
     * `string_literal` reference two structurally different ways, and
     * requote() alone is only correct for one of them:
     *
     *   - `scanStringLiterals()` matches a whole quoted
     *     `T_CONSTANT_ENCAPSED_STRING` token — its span INCLUDES the
     *     opening and closing quote characters, so requote() (replacing
     *     the whole span with a freshly quoted new FQCN) is correct.
     *   - `scanBoundedText()` matches a BOUNDED SUBSTRING inside a
     *     heredoc/nowdoc body or Blade/inline-HTML markup
     *     (`T_ENCAPSED_AND_WHITESPACE` / `T_INLINE_HTML`) — its span covers
     *     ONLY the matched bytes, with NO surrounding quotes at all (see
     *     that method's own docblock). Calling requote() on such a span
     *     would splice literal quote characters into the middle of a
     *     heredoc body or HTML markup, corrupting the value the moved file
     *     evaluates at runtime — `php -l` still passes (quotes inside a
     *     heredoc body are just text) and the command would exit 0 having
     *     silently broken the reference it was supposed to fix.
     *
     * A quoted token is distinguished from a bounded match by the ONE fact
     * that actually differs: whether $original itself starts with a quote
     * character.
     *
     * PROMOTED FINDING (review round 3). A bounded match inside a REAL
     * heredoc (`<<<LABEL`, not a nowdoc) is NOT simply "preserve whatever
     * spelling matched" the way a nowdoc or Blade/inline-HTML match is.
     * A heredoc body processes escape sequences exactly like a
     * double-quoted string — so if the NEW FQCN's own text contains a
     * single backslash immediately followed by a recognised escape letter
     * (`t`, `n`, `r`, `v`, `f`, `e`, `x`, `u`, or an octal digit — every one
     * reachable from a lowercase, PHP-identifier-legal namespace segment,
     * e.g. `--namespace=acme\things` makes the very next character after
     * the separator a `t`), splicing that text in UNESCAPED writes a
     * SYNTACTICALLY VALID file (`php -l` passes) whose heredoc silently
     * evaluates to a value containing a literal TAB where a backslash and
     * a letter belonged — corruption at exit 0, the same failure shape
     * Important 3 already fixed, just one step further down the same
     * bounded-match path. The fix always doubles every backslash in the
     * REPLACEMENT when it lands inside a real heredoc, regardless of how
     * the ORIGINAL text was spelled — a doubled backslash always collapses
     * to exactly one literal backslash under heredoc/double-quoted escape
     * rules, so this is unconditionally safe. A nowdoc and Blade/inline-
     * HTML markup process NO escapes at all, so THEIR replacement keeps
     * matching whichever spelling (plain or escaped) the original text
     * matched — that spelling already IS the runtime value, verbatim, and
     * needsHeredocEscaping() returning false for both is what keeps this
     * method from "fixing" something that was never broken.
     */
    private function stringLiteralReplacement(NodeReference $reference, string $source, string $original, string $class, string $newFqcn): string
    {
        if ($original !== '' && ($original[0] === "'" || $original[0] === '"')) {
            return $this->requote($original, $newFqcn);
        }

        if ($this->needsHeredocEscaping($source, $reference->byteStart)) {
            return str_replace('\\', '\\\\', $newFqcn);
        }

        $oldPlain = ltrim($class, '\\');
        $oldEscaped = str_replace('\\', '\\\\', $oldPlain);

        return $original === $oldEscaped ? str_replace('\\', '\\\\', $newFqcn) : $newFqcn;
    }

    /**
     * Whether the byte at $byteStart sits inside a REAL heredoc's own body
     * — a `T_ENCAPSED_AND_WHITESPACE` token whose OPENING `<<<LABEL` (no
     * quotes around LABEL) processes escape sequences — as opposed to a
     * NOWDOC's body (`<<<'LABEL'`, quoted, processes NO escapes at all) or
     * Blade/inline-HTML markup (`T_INLINE_HTML`, also no escape
     * processing). `T_START_HEREDOC`'s own token TEXT is the opening
     * marker verbatim, quote characters included, which is what makes
     * checking it for a `'` enough to tell the two apart — PHP's tokeniser
     * does not expose a separate token id for "heredoc" vs "nowdoc";
     * both a heredoc's and a nowdoc's body chunks are
     * `T_ENCAPSED_AND_WHITESPACE`.
     */
    private function needsHeredocEscaping(string $source, int $byteStart): bool
    {
        $isNowdoc = null;
        $raw = 0;

        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $id = is_array($token) ? $token[0] : null;
            $length = strlen($text);

            if ($id === T_START_HEREDOC) {
                $isNowdoc = str_contains($text, "'");
            }

            if ($byteStart >= $raw && $byteStart < $raw + $length) {
                return $id === T_ENCAPSED_AND_WHITESPACE && $isNowdoc === false;
            }

            if ($id === T_END_HEREDOC) {
                $isNowdoc = null;
            }

            $raw += $length;
        }

        return false;
    }

    /**
     * M4 — registers $newFqcn into the PACKAGE's own freshly-scaffolded
     * provider (never the host's). `AlreadyPresent` is treated the same as
     * `Appended`: a re-run of extraction against an already-matching
     * package (E43's "matching existing" target state) must not refuse just
     * because a previous run already got this far.
     */
    private function registerInPackage(string $newFqcn, PackageTarget $target, ExtractJournal $journal): void
    {
        $providerPath = $target->absolutePath.'/src/'.$this->shortClassName($target->providerClass).'.php';

        $journal->recordWrite($providerPath);

        $outcome = (new NodeRegistrationWriter($this->files))->register($providerPath, $newFqcn);

        if ($outcome !== NodeRegistrationOutcome::Appended && $outcome !== NodeRegistrationOutcome::AlreadyPresent) {
            throw new RuntimeException(
                "Registering [{$newFqcn}] into the package's own provider [{$providerPath}] failed "
                ."({$outcome->name}); nothing was moved."
            );
        }
    }

    /** The short (unqualified) class name at the end of a fully-qualified one. */
    private function shortClassName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    /**
     * M5 — removes $class from the HOST's own provider, then removes the
     * now-unused `use` import for it, but only when the short name appears
     * nowhere else in the file (identifierAppearsOutside()'s own docblock
     * explains why that check is structural, not a substring search).
     *
     * Every one of NodeRemovalOutcome's eight cases is handled explicitly.
     * `Removed`, `NotPresent`, `ProviderMissing`, `AnchorMissing`, and
     * `AnchorAmbiguous` all mean the SAME thing from this method's own point
     * of view — there is nothing left in the host naming $class that
     * `removeFrom()` was able to (or needed to) touch, which G5 having
     * already passed guarantees is safe: if a REAL, resolvable reference to
     * $class survived in an ambiguous or unsupported form, G5's own scan
     * would already have refused the whole extraction before this method
     * ever ran. `EntryUnsupported`, `EntryAmbiguous`, and `WriteFailed` are
     * genuine failures — the writer found something it would not safely
     * edit, or the edit it attempted did not hold up — and NONE of the three
     * may be read as "nothing to do": doing so would let extraction proceed
     * to delete the class file a stale reference still, in fact, names.
     */
    private function deregisterFromHost(string $class, string $shortName, string $providerFile, ExtractJournal $journal): void
    {
        if (! $this->files->exists($providerFile)) {
            return;
        }

        $journal->recordWrite($providerFile);

        $outcome = (new NodeRegistrationWriter($this->files))->removeFrom($providerFile, NodeRegistrationWriter::ANCHOR, $class);

        match ($outcome) {
            NodeRemovalOutcome::Removed,
            NodeRemovalOutcome::NotPresent,
            NodeRemovalOutcome::ProviderMissing,
            NodeRemovalOutcome::AnchorMissing,
            NodeRemovalOutcome::AnchorAmbiguous => null,
            NodeRemovalOutcome::EntryUnsupported,
            NodeRemovalOutcome::EntryAmbiguous,
            NodeRemovalOutcome::WriteFailed => throw new RuntimeException(
                "Removing [{$class}] from the host provider [{$providerFile}] failed ({$outcome->name}); "
                .'nothing was moved. The provider may name this class in a form extraction cannot safely edit.'
            ),
        };

        $this->removeUnusedImportIfSafe($class, $shortName, $providerFile, $journal);
    }

    /**
     * Removes $providerFile's own `use` import for $class, but only when
     * $shortName does not appear anywhere else in the file — checked
     * structurally (identifierAppearsOutside()), not by searching the raw
     * text for the name, and left alone entirely (not an error) whenever
     * removing it is not provably safe: there is no reference left to
     * refuse over at this point, so the worst a wrong guess here could do is
     * leave a harmless unused import, never break the host.
     */
    private function removeUnusedImportIfSafe(string $class, string $shortName, string $providerFile, ExtractJournal $journal): void
    {
        $contents = $this->files->get($providerFile);
        $importSpan = $this->importSpanFor($class, $providerFile, $contents);

        if ($importSpan === null) {
            return;
        }

        if ($this->identifierAppearsOutside($contents, $shortName, $importSpan['start'], $importSpan['end'])) {
            return;
        }

        $updated = $this->deleteUseStatement($contents, $importSpan['start']);

        if ($updated === null || ! $this->parses($updated)) {
            return;
        }

        $journal->recordWrite($providerFile);
        $this->files->put($providerFile, $updated);

        // E11: re-verify rather than trust, the same reason every OTHER
        // journaled write in this class does (writeJournaled(),
        // updateHostComposerJson()) — a Minor review finding: this write
        // was the one exception, silently trusting put() despite the
        // report's own claim that every write re-verifies. Removing an
        // import is never load-bearing for correctness (leaving it behind
        // is merely untidy, never a stale reference), so a failed write
        // here is downgraded to "leave the import alone" rather than
        // aborting the whole extraction over a cosmetic cleanup step.
        //
        // DEFENSIVE, CURRENTLY UNREACHABLE THROUGH ANY VALID-INPUT TEST
        // (round 3 mutation testing: deleting this whole block leaves the
        // suite green). By the time this line runs, $providerFile has
        // JUST been proven writable one call earlier in this exact
        // sequence: deregisterFromHost() already called
        // NodeRegistrationWriter::removeFrom() on this SAME file, which
        // only returns `Removed` (the one outcome that reaches this
        // method at all) after its OWN put()-then-reread check already
        // succeeded — nothing between that check and this one changes the
        // file's permissions or the filesystem's ability to write it, so
        // there is no way to make THIS put() fail while leaving that
        // EARLIER one to have already succeeded, through any host
        // configuration a real Laravel application could have. Kept
        // anyway, the same reasoning as hostPsr4Directories()'s and
        // gate6()'s own "defensive, currently unreachable" guards: the
        // dependency is on ANOTHER method (removeFrom()) staying exactly
        // as strict as it is today, and if a future change ever lets
        // control reach this method without that same guarantee, this
        // keeps its own protection rather than trusting put() silently.
        if ($this->files->get($providerFile) !== $updated) {
            $this->files->put($providerFile, $contents);
        }
    }

    /**
     * Deletes the `use ... ;` statement whose own imported name STARTS at
     * $memberStart — found by walking backward to the nearest preceding
     * `use` keyword and forward to its terminating `;` — absorbing one
     * trailing newline so the removal does not leave a blank line behind.
     * Null when no enclosing `use ... ;` can be found at all (defensive;
     * unreachable through this command's own call sites, which only ever
     * pass a span `importSpanFor()` itself already found inside a real `use`
     * statement).
     */
    private function deleteUseStatement(string $contents, int $memberStart): ?string
    {
        $tokens = $this->tokenizeWithOffsets($contents);
        $useIndex = null;

        foreach ($tokens as $index => $token) {
            if ($token['start'] >= $memberStart) {
                break;
            }

            if ($token['id'] === T_USE) {
                $useIndex = $index;
            }
        }

        if ($useIndex === null) {
            return null;
        }

        $semicolonEnd = null;

        foreach ($tokens as $index => $token) {
            if ($index <= $useIndex) {
                continue;
            }

            if ($token['id'] === null && $token['text'] === ';') {
                $semicolonEnd = $token['end'];

                break;
            }
        }

        if ($semicolonEnd === null) {
            return null;
        }

        $start = $tokens[$useIndex]['start'];
        $end = $semicolonEnd;

        if (($contents[$end] ?? null) === "\n") {
            $end++;
        }

        return substr_replace($contents, '', $start, $end - $start);
    }

    /**
     * M6 (E29) — adds a path repository pointing at $targetRelativePath,
     * ALWAYS relative, never resolved to an absolute path: an absolute path
     * breaks the moment the host is committed and rebuilt on another
     * machine. Reuses `requiredFromMatchingPathRepository()` (G6's own
     * matching logic — literal or glob, via `fnmatch()`) to decide whether a
     * repository entry is already there, so a re-run against an
     * already-wired host (E43's "matching existing" target state) does not
     * duplicate anything. `require[$packageName]` is added only when the
     * package is not already required from EITHER `require` or
     * `require-dev` — G6 already refused any OTHER kind of pre-existing
     * requirement before this point was ever reached.
     */
    private function updateHostComposerJson(string $hostBasePath, string $packageName, string $targetRelativePath, ExtractJournal $journal): void
    {
        $path = $hostBasePath.'/composer.json';
        $decoded = json_decode($this->files->get($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("[{$path}] does not parse as JSON; extraction cannot update it.");
        }

        $changed = false;

        if (! $this->requiredFromMatchingPathRepository($decoded, $targetRelativePath)) {
            $repositories = is_array($decoded['repositories'] ?? null) ? $decoded['repositories'] : [];
            $repositories[] = ['type' => 'path', 'url' => $targetRelativePath];
            $decoded['repositories'] = $repositories;
            $changed = true;
        }

        $require = is_array($decoded['require'] ?? null) ? $decoded['require'] : [];
        $requireDev = is_array($decoded['require-dev'] ?? null) ? $decoded['require-dev'] : [];

        if (! array_key_exists($packageName, $require) && ! array_key_exists($packageName, $requireDev)) {
            $require[$packageName] = '*';
            $decoded['require'] = $require;
            $changed = true;
        }

        if (! $changed) {
            return;
        }

        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

        $journal->recordWrite($path);
        $this->files->put($path, $encoded);

        // E11: re-verify rather than trust, the same reason writeJournaled()
        // checks its own write.
        if ($this->files->get($path) !== $encoded) {
            throw new RuntimeException("[{$path}] could not be updated; nothing further was moved.");
        }
    }

    /**
     * M6a (E45) — re-runs the SAME reference scan G5 already ran, over the
     * SAME shared roots (scanSharedRoots(), G5's own docblock explains why
     * it is shared rather than derived twice), subtracting exactly the set
     * `rewritableSpans()` proves the extraction transforms — recomputed
     * fresh rather than reused from earlier in this same run, because the
     * provider file's own content has since changed (M5 already edited
     * it). Any survivor aborts BEFORE M7 deletes anything (E45): a
     * reference the ORIGINAL gates could not see (this run's own moves had
     * not created the package directory yet when G5 ran), caught here
     * instead, while restoring is still cheap.
     *
     * @throws RuntimeException
     */
    private function rescanPostMoveTree(string $class, string $hostBasePath): void
    {
        try {
            $found = $this->scanSharedRoots($class, $hostBasePath);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Post-move reference rescan (E45) failed: '.$e->getMessage());
        }

        if ($found === []) {
            return;
        }

        $spans = $this->rewritableSpans($class, $hostBasePath);

        $survivors = array_values(array_filter(
            $found,
            fn (NodeReference $reference): bool => ! $this->isRewritten($reference, $spans),
        ));

        if ($survivors === []) {
            return;
        }

        $locations = array_map(
            static fn (NodeReference $reference): string => "{$reference->file}:{$reference->line}",
            $survivors,
        );

        throw new RuntimeException(
            "Extraction would still leave [{$class}] referenced by its old FQCN after the move (E45) — ".
            "a reference the earlier gates could not see, at:\n".implode("\n", $locations)
        );
    }

    /**
     * Scans `sharedScanRoots()` for every reference to $class — the ONE
     * scan both G5 (gate5()) and M6a (rescanPostMoveTree()) run, so the two
     * can never independently drift about what ground is worth checking.
     * `ARTIFACT_SUBDIRECTORIES` is applied per matching root here, not
     * baked into `sharedScanRoots()` itself, so that method can stay a
     * plain list of directories reusable by anything that just wants
     * "every root," while the artifact exclusion — a NodeReferenceScanner
     * concept — stays where the scanning happens.
     *
     * @return list<NodeReference>
     *
     * @throws RuntimeException when NodeReferenceScanner::scan() finds a
     *                           multi-namespace file
     */
    private function scanSharedRoots(string $class, string $hostBasePath): array
    {
        $found = [];

        foreach ($this->sharedScanRoots($hostBasePath) as $root) {
            $excluded = self::ARTIFACT_SUBDIRECTORIES[basename($root)] ?? [];

            // basename() alone would also match a PSR-4 directory that
            // happens to be named "storage" or "bootstrap" somewhere other
            // than directly under the host root — guarded against by only
            // ever applying the exclusion to the TOP-LEVEL host directory
            // of that exact name, never a same-named root reached some
            // other way.
            if ($excluded !== [] && $root !== $hostBasePath.'/'.basename($root)) {
                $excluded = [];
            }

            array_push($found, ...NodeReferenceScanner::scan($class, [$root], $excluded));
        }

        return $found;
    }

    /**
     * Every top-level directory under $hostBasePath except `vendor/`,
     * `node_modules/`, and any dot-prefixed directory (`.git` and
     * similar), unioned with the host's own PSR-4 directories
     * (`hostPsr4Directories()` — the SAME set G2 requires the node's own
     * file to sit under, for the same "must never admit ground the scan
     * does not cover" reason that method's own docblock already gives).
     *
     * WIDER than the gate's own OLD, narrow REFERENCE_SCAN_DIRS allowlist
     * (app, bootstrap, config, database, resources, routes, tests) —
     * deliberately, following a review-round finding: that allowlist
     * missed a real reference sitting in an ordinary top-level directory
     * it never scanned (`scripts/`, `public/`, a sibling local package
     * under `packages/`), which is fatal the same way any other missed
     * reference is (E46). `ARTIFACT_SUBDIRECTORIES` (applied by
     * `scanSharedRoots()`, not here) is what keeps this width from ALSO
     * admitting a compiled cache artifact as if it were source.
     *
     * A candidate is dropped, not included, when it canonically escapes the
     * host root through a symlink (E51's own rule, checked here the same
     * way `isUnderAnyMappedRoot()` checks it for G2's own scan roots): a
     * top-level entry that is itself a symlink pointing outside the host —
     * exactly the shape Important N2's own PSR-4 guard exists to keep out
     * of G5's roots — must not become a scan root here either, or this
     * scan would find, and wrongly refuse or abort on, a reference planted
     * only in whatever the symlink happens to point at.
     *
     * DOCUMENTED RESIDUAL (round 3): a reference in a loose `.php` FILE
     * sitting directly at the HOST ROOT ITSELF — not inside any
     * directory at all — is invisible to this method and therefore to
     * both G5 and M6a, since every entry this method returns is a
     * DIRECTORY handed to `NodeReferenceScanner::scan()`, which only ever
     * walks directories. This is not a regression: the OLD, narrower
     * `REFERENCE_SCAN_DIRS` allowlist had the exact same gap (a root-level
     * file was never one of its named directories either), and a real
     * Laravel host does not put PHP source directly at its own project
     * root (`artisan` has no `.php` extension and is never autoloaded
     * source). Stated here rather than silently carried forward.
     *
     * DOCUMENTED COST (round 3): this widening means a full non-vendor,
     * non-node_modules tree walk now happens TWICE per extraction — once
     * for G5, once more for M6a's post-move rescan, which is a superset
     * of the same ground plus the newly-created package directory. Each
     * gate used to be cheap (a handful of named directories); G5 alone is
     * now the same cost M6a already was. Judged acceptable for the same
     * reason M6a's own cost was: this command runs once, by a developer,
     * not in a hot path.
     *
     * @return list<string>
     */
    private function sharedScanRoots(string $hostBasePath): array
    {
        $hostRoot = HostPath::root($hostBasePath);
        $roots = [];

        foreach (scandir($hostBasePath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === self::VENDOR_DIR
                || $entry === self::NODE_MODULES_DIR || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $hostBasePath.'/'.$entry;

            if ($this->files->isDirectory($path) && $hostRoot->contains($path)) {
                $roots[] = $path;
            }
        }

        return array_values(array_unique(array_merge(
            $roots,
            $this->hostPsr4Directories($hostBasePath),
        )));
    }

    /**
     * M7 — deletes the original class file and, if M3 moved one, the
     * original test file, journaling each deletion (with the bytes read
     * BEFORE the delete) so a failure elsewhere in the SAME performMoves()
     * call — unreachable today, since M7 is the last step, but kept for the
     * same reason PackageScaffolder's own currently-unreachable guards are —
     * can still restore them.
     */
    private function deleteOriginals(string $nodeFile, ?string $testFile, ExtractJournal $journal): void
    {
        $this->deleteJournaled($nodeFile, $journal);

        if ($testFile !== null) {
            $this->deleteJournaled($testFile, $journal);
        }
    }

    /**
     * Deletes $path, journaling its bytes first (read BEFORE the delete, the
     * same "capture before you mutate" rule every other journaled mutation
     * in this class follows) and re-verifying afterwards (E11) that it is
     * actually gone: `Filesystem::delete()` reports failure as a boolean
     * return, never an exception, so trusting it without checking would let
     * extraction report success while the original file it was supposed to
     * remove — the entire reason G5's guarantee holds — is still sitting
     * there under its old FQCN.
     */
    private function deleteJournaled(string $path, ExtractJournal $journal): void
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("[{$path}] could not be read; extraction cannot delete it safely.");
        }

        $journal->recordDelete($path, $contents);
        $this->files->delete($path);

        if ($this->files->exists($path)) {
            throw new RuntimeException("[{$path}] could not be deleted; nothing further was moved.");
        }
    }

    /**
     * Writes $contents to $path, journaling it correctly whether $path
     * already existed (a write, undone by restoring the original bytes) or
     * not (a create, undone by deleting it) — the same before/after
     * distinction scaffoldPackage() applies to the whole package directory,
     * applied here to one file at a time for M2's and M3's own destination
     * writes.
     */
    private function writeJournaled(string $path, string $contents, ExtractJournal $journal): void
    {
        if ($this->files->exists($path)) {
            $journal->recordWrite($path);
        } else {
            $journal->recordCreate($path);
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $contents);

        // E11: re-verify rather than trust — put() reports a genuine disk
        // failure (a path component colliding with a plain file where a
        // directory belongs, most concretely) by returning false, never by
        // throwing, so an unchecked call here would let extraction report
        // success while the bytes it was supposed to move never actually
        // landed.
        if ($this->files->get($path) !== $contents) {
            throw new RuntimeException("[{$path}] could not be written; nothing further was moved.");
        }
    }

    /**
     * Every reference to $class found INSIDE $file itself — a self-reference
     * such as a static call the class makes on its own name, or a
     * class-string literal naming itself — never the class's own
     * declaration, which NodeReferenceScanner already treats as a
     * definition rather than a use. Scans $file's own directory (the
     * scanner accepts only directories) and filters down to $file's own
     * canonical path, the same pattern `providerSpans()` already uses and
     * for the same reason: a reference sitting in a SIBLING file must never
     * be folded into this file's own set.
     *
     * @return list<NodeReference>
     */
    private function fileOwnReferences(string $class, string $file, string $source): array
    {
        try {
            $references = NodeReferenceScanner::scan($class, [dirname($file)]);
        } catch (RuntimeException) {
            return [];
        }

        $canonical = realpath($file) ?: $file;

        return array_values(array_filter(
            $references,
            static fn (NodeReference $reference): bool => (realpath($reference->file) ?: $reference->file) === $canonical,
        ));
    }

    /** @return array{start: int, end: int}|null */
    private function importSpanFor(string $class, string $file, string $source): ?array
    {
        foreach ($this->fileOwnReferences($class, $file, $source) as $reference) {
            if ($reference->kind === 'import') {
                return ['start' => $reference->byteStart, 'end' => $reference->byteEnd];
            }
        }

        return null;
    }

    /**
     * The exact byte range of $source's namespace declaration's own NAME —
     * not the `namespace` keyword, not the terminating `;` — e.g. for
     * `namespace App\Nodeflow\Nodes;`, the span covering exactly
     * `App\Nodeflow\Nodes`. Substituting new text into THIS span, and this
     * span alone, is what keeps M2's rewrite structural rather than a
     * global find/replace of the old namespace string (F-1): a docblock or
     * string literal that happens to spell the same text is never touched,
     * because it is never part of this span.
     *
     * @return array{start: int, end: int}|null null when $source has no
     *  namespace declaration at all — G2's own PSR-4 containment rule makes
     *  this unreachable through this command's own call sites, since a file
     *  with no namespace could never sit under a mapped PSR-4 directory and
     *  declare the class under extraction, but this is defensive rather than
     *  assumed.
     */
    private function namespaceDeclarationSpan(string $source): ?array
    {
        $tokens = $this->tokenizeWithOffsets($source);
        $nameTokenIds = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR];
        $ignored = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_NAMESPACE) {
                continue;
            }

            $start = null;
            $end = null;

            for ($j = $index + 1, $count = count($tokens); $j < $count; $j++) {
                $next = $tokens[$j];

                if ($next['id'] !== null && in_array($next['id'], $ignored, true)) {
                    continue;
                }

                if ($next['id'] === null || ! in_array($next['id'], $nameTokenIds, true)) {
                    break;
                }

                $start ??= $next['start'];
                $end = $next['end'];
            }

            return $start === null ? null : ['start' => $start, 'end' => $end];
        }

        return null;
    }

    /**
     * Whether the bare identifier $shortName appears, as a NAME TOKEN (never
     * a raw substring — a comment or a string literal spelling the same
     * text does not count, matching NodeReferenceScanner's own rule that a
     * comment is invisible to any scan), anywhere in $contents OUTSIDE the
     * byte range [$excludeStart, $excludeEnd) — the import statement's own
     * member listing. Only the LAST segment of a qualified name is compared
     * (`Other\Foo` still names something called "Foo"), because what this
     * check protects against is a DIFFERENT reference that happens to share
     * $class's own short name — removeUnusedImportIfSafe()'s own docblock
     * explains why a plain, RESOLUTION-blind identifier match is the correct
     * (deliberately conservative) rule here, not a bug where a resolution
     * check was intended instead.
     */
    private function identifierAppearsOutside(string $contents, string $shortName, int $excludeStart, int $excludeEnd): bool
    {
        $nameTokenIds = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR];
        $ignored = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

        $tokens = array_values(array_filter(
            $this->tokenizeWithOffsets($contents),
            static fn (array $token): bool => $token['id'] === null || ! in_array($token['id'], $ignored, true),
        ));

        $count = count($tokens);
        $i = 0;

        // A `while` loop with a manually managed index, deliberately, not a
        // `for`: the inner run-consuming loop below already advances $i
        // itself, and a `for` header's own `$i++` on top of that would
        // double-increment and silently skip the token immediately after
        // every run — the exact defect class NodeReferenceScanner's own
        // scanTokens() docblock names as having shipped once already.
        while ($i < $count) {
            if ($tokens[$i]['id'] === null || ! in_array($tokens[$i]['id'], $nameTokenIds, true)) {
                $i++;

                continue;
            }

            $runText = '';
            $runStart = $tokens[$i]['start'];
            $runEnd = $tokens[$i]['end'];

            while ($i < $count && $tokens[$i]['id'] !== null && in_array($tokens[$i]['id'], $nameTokenIds, true)) {
                $runText .= $tokens[$i]['text'];
                $runEnd = $tokens[$i]['end'];
                $i++;
            }

            $segments = explode('\\', $runText);
            $last = end($segments);

            if ($last === $shortName && ! ($runStart >= $excludeStart && $runEnd <= $excludeEnd)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Re-quotes $newValue in the SAME quote style as $originalToken (its
     * own raw text, quotes included) — so a self-referencing class-string
     * literal keeps working once the namespace it names changes. $newValue
     * is always a plain FQCN, so escaping it correctly only ever needs to
     * double a literal backslash (and, for a single-quoted result, escape a
     * literal quote too) — neither of which a class's own FQCN ever
     * contains, but both are handled on principle rather than assumed away.
     */
    private function requote(string $originalToken, string $newValue): string
    {
        if ($originalToken !== '' && $originalToken[0] === "'") {
            return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $newValue)."'";
        }

        return '"'.str_replace('\\', '\\\\', $newValue).'"';
    }

    /**
     * Applies every [start, end) => text replacement in $replacements to
     * $source, processing them in DESCENDING order by start so an earlier
     * substr_replace() never invalidates a later one's already-computed
     * byte range — the same ordering rule NodeRegistrationWriter::removeFrom()
     * applies to its own deletions.
     *
     * @param  list<array{start: int, end: int, text: string}>  $replacements
     */
    private function applySpanReplacements(string $source, array $replacements): string
    {
        usort($replacements, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);

        foreach ($replacements as $replacement) {
            $source = substr_replace(
                $source,
                $replacement['text'],
                $replacement['start'],
                $replacement['end'] - $replacement['start'],
            );
        }

        return $source;
    }

    /**
     * Every PHP token in $source alongside its own raw byte range —
     * token_get_all() is a lossless lexer, so each token's raw start is just
     * the running length of every token before it, the same property
     * NodeReferenceScanner::tokenise() and NodeRegistrationWriter::tokens()
     * both already rely on.
     *
     * @return list<array{id: int|null, text: string, start: int, end: int}>
     */
    private function tokenizeWithOffsets(string $source): array
    {
        $tokens = [];
        $raw = 0;

        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $id = is_array($token) ? $token[0] : null;
            $length = strlen($text);

            $tokens[] = ['id' => $id, 'text' => $text, 'start' => $raw, 'end' => $raw + $length];
            $raw += $length;
        }

        return $tokens;
    }

    /**
     * Whether $source is valid PHP — the same TOKEN_PARSE approach
     * PackageScaffolder::parses() and NodeRegistrationWriter::parses() both
     * already use, for the same reason: staying in-process avoids a
     * subprocess per rewritten file, and a syntax error is exactly what
     * TOKEN_PARSE catches.
     */
    private function parses(string $source): bool
    {
        try {
            token_get_all($source, TOKEN_PARSE);

            return true;
        } catch (\ParseError) {
            return false;
        }
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
     * than trusted regardless). Then containment (E51), and this is the
     * SECOND ruling this exact question has had:
     *
     * ROUND 1 said "inside the host root, and not under vendor/ [at the
     * top level]" — deliberately NOT reading the host's own PSR-4 map,
     * reasoned as "more surface than this gate needs". ROUND 2 reverses
     * that: widening containment to the whole host root, with only a
     * single-level vendor/ exclusion, admits ground `hostPsr4Directories()`
     * (G5) never scans — a node inside ANOTHER local path-repository
     * package (`packages/acme/other/src/...`), inside a NESTED vendor/
     * directory (`packages/foo/vendor/bar/pkg/src/...`), inside
     * `storage/framework/cache/...`, or already inside the extraction's own
     * TARGET package all passed G2 and then went completely unvetted by
     * G5, because none of those directories were ever roots G5 scans. The
     * invariant this gate must hold is not merely "inside the host, not
     * vendor" — it is G2 MUST NOT ADMIT GROUND G5 CANNOT SCAN. A gate that
     * passes on unvettable territory is worse than one that refuses too
     * much.
     *
     * THE RULE NOW: the file must sit under a directory the host's OWN
     * composer.json maps via `autoload` or `autoload-dev` PSR-4
     * (`hostPsr4Directories()`), and must not sit under a `vendor/`
     * SEGMENT AT ANY DEPTH (`underVendorAtAnyDepth()` — not merely a
     * top-level `vendor/`, because a nested package's own vendor/ is
     * exactly what the exclusion exists to stop, and a host mapping a
     * broad root like `packages/` would otherwise treat a nested vendor/
     * as "contained" and therefore fine).
     *
     * `hostPsr4Directories()` is the SAME method G5 unions into its own
     * scan roots below — not two lists that happen to agree, but one
     * shared source of truth both gates consume, the identical reasoning
     * behind `rewritableSpans()` being the one thing both this class's own
     * G5 and Task 9's moves are required to call. A host mapping its own
     * root namespace onto `src/` (or anywhere else) still works: its own
     * composer.json says so, and this gate reads exactly that, rather than
     * assuming `app/` — reading the PSR-4 map is what makes both the DX
     * win (any legitimately mapped location works) and the safety
     * property (nothing else does) true at once.
     *
     * Then E47: the file must declare exactly one top-level named symbol.
     * M2 (Task 9) rewrites the file's namespace, which moves EVERY
     * declaration the file contains, while NodeReferenceScanner (G5) only
     * ever looks for references to the NODE — so a companion trait,
     * interface, enum, function, or constant would move silently and break
     * any host code that still uses it under its old name.
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

        if ($this->underVendorAtAnyDepth($file, $hostBasePath)) {
            return "[{$class}]'s file [{$file}] lives under a [".self::VENDOR_DIR.'/] segment (E51), at '
                .'some depth under the host root. A class already shipped as part of another Composer '
                .'package — the host\'s own, or a nested one belonging to a different local package — is '
                .'not the host\'s own source and cannot be extracted from it.';
        }

        $psr4Directories = $this->hostPsr4Directories($hostBasePath);

        if (! $this->isUnderAnyMappedRoot($file, $psr4Directories)) {
            return "[{$class}]'s file [{$file}] is not inside any directory the host's own composer.json "
                .'maps via autoload or autoload-dev PSR-4 (E51). A location the host itself does not claim '
                .'as its own source is exactly the ground NodeReferenceScanner (G5) cannot be trusted to '
                .'scan — it would let extraction proceed over territory nothing has vetted.';
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
     * Whether $file lives under a `vendor/` PATH SEGMENT at any depth below
     * the host root — not merely a top-level `vendor/`. Segment-wise, over
     * the portion of the canonical path strictly BELOW the host root, so a
     * host root whose own ANCESTOR directory happens to be named "vendor"
     * (unlikely, but this is exactly the class of substring-shaped mistake
     * this codebase keeps re-finding) is never mistaken for containment.
     */
    private function underVendorAtAnyDepth(string $file, string $hostBasePath): bool
    {
        $canonicalFile = realpath($file);

        if ($canonicalFile === false) {
            return false;
        }

        try {
            $hostRoot = HostPath::root($hostBasePath);
        } catch (InvalidArgumentException) {
            return false;
        }

        $rootSegments = HostPath::segments($hostRoot->basePath());
        $fileSegments = HostPath::segments($canonicalFile);

        if (count($fileSegments) < count($rootSegments)) {
            return false;
        }

        $relativeSegments = array_slice($fileSegments, count($rootSegments));

        return in_array(self::VENDOR_DIR, $relativeSegments, true);
    }

    /**
     * Every directory the host's own composer.json maps via
     * `autoload.psr-4` or `autoload-dev.psr-4`, resolved to an absolute
     * path and kept only if it actually exists on disk AND resolves
     * canonically inside the host root — the SAME set both G2
     * (containment) and G5 (scan roots) consume, so the two gates agree
     * about what counts as "the host's own source" by construction rather
     * than by two independently-written lists happening to match.
     *
     * THE ROOT-CONTAINMENT CHECK (this method's own reason for existing in
     * its current shape) closes a case its own predecessor reopened: a
     * PSR-4 value of `"./"`, `"."`, or `"/"` maps the ENTIRE host root, at
     * which point `storage/framework/cache/...` (Important A's case (c),
     * supposedly closed) is "mapped" again, because it is a subdirectory
     * of "the whole project". A value of `"../"` is worse: resolved
     * against `$hostBasePath` it points OUTSIDE the host entirely, which
     * would hand G5 a scan root outside the tree it was ever told to
     * cover. Both are refused before ever becoming a candidate root:
     * `HostPath::segments()` deliberately KEEPS a `..` segment (its own
     * docblock explains why — a caller must be able to SEE a climb-out in
     * order to refuse it), so `in_array('..', $segments, true)` catches
     * `../` at any position, and an empty segment list catches `.`, `/`,
     * and `./` (all three normalise to no segments at all). The SEPARATE
     * `$hostRoot->contains($absolute)` check, after that, is defence in
     * depth against a symlink-based escape a syntactic segment check alone
     * cannot see — the same reason `HostPath::resolveWithin()` checks both.
     *
     * A non-existent mapped directory (declared in composer.json but never
     * created) is silently dropped rather than refused: an absent
     * directory contains nothing, so it can neither admit a node G2 should
     * accept nor hide a reference G5 should have scanned. Dropping it here
     * — rather than letting it become a G5 scan root — is also what keeps
     * `NodeReferenceScanner::scan()` from receiving a root
     * `HostPath::root()` would throw `InvalidArgumentException` on; gate5()
     * only catches `RuntimeException`, so an unfiltered non-existent root
     * would crash the command instead of refusing cleanly.
     *
     * Composer's own PSR-4 value may be a single directory string or an
     * array of several for one namespace prefix; both are handled.
     *
     * STATED LIMITS, both fail-safe (each one REFUSES a node rather than
     * mishandling it, so neither is a correctness bug, only a coverage
     * gap a future reader should not mistake for one):
     *   - Only `autoload`/`autoload-dev` PSR-4 entries are read. A host
     *     whose own node classes are reachable only through `classmap` or
     *     the legacy PSR-0 style is refused outright by G2 (nothing it
     *     declares ever becomes a mapped root) — vanishingly rare for a
     *     modern Laravel application (Laravel's own skeleton, and every
     *     generator in this package, both use PSR-4).
     *   - `"App\": ""` — Composer's own legal shorthand for "the package
     *     root itself" — is treated the same as any other empty or
     *     blank-after-trim value and dropped, never accepted as a mapped
     *     root. A host relying on that exact shorthand to map its node
     *     classes to its own project root is refused; mapping to `"./"`
     *     is refused for an unrelated reason (Important N2: it would map
     *     the ENTIRE host, which this method must never allow), so there
     *     is no alternate spelling of "map the whole root" this method
     *     will ever accept, by design.
     *
     * DEFENSIVE, CURRENTLY UNREACHABLE: the `try`/`catch` around
     * `HostPath::root($hostBasePath)` immediately below cannot actually
     * fire through any call path this class has today. `gate2()` already
     * resolves and refuses on `HostPath::root($hostBasePath)` itself,
     * before ever reaching either of its own two calls into this method
     * (once directly, once via `gate5()`, both later in the SAME
     * `handle()` invocation) — so by the time this method's own `try`
     * runs, the identical call has already succeeded once. Kept anyway,
     * the same reasoning as `gate6()`'s own currently-unreachable
     * existence/parse checks: the dependency is on ANOTHER method
     * (`gate2()`) staying exactly as strict as it is today, and if this
     * method is ever called from a path that does not go through `gate2()`
     * first — Task 9's moves, say — this keeps its own protection rather
     * than letting an unresolvable host root reach `$hostRoot->contains()`
     * uncaught.
     *
     * @return list<string>
     */
    private function hostPsr4Directories(string $hostBasePath): array
    {
        $path = $hostBasePath.'/composer.json';

        if (! $this->files->exists($path)) {
            return [];
        }

        $decoded = json_decode($this->files->get($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        try {
            $hostRoot = HostPath::root($hostBasePath);
        } catch (InvalidArgumentException) {
            return [];
        }

        $directories = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            $psr4 = $decoded[$section]['psr-4'] ?? null;

            if (! is_array($psr4)) {
                continue;
            }

            foreach ($psr4 as $mapped) {
                foreach (is_array($mapped) ? $mapped : [$mapped] as $directory) {
                    if (! is_string($directory) || $directory === '') {
                        continue;
                    }

                    $segments = HostPath::segments($directory);

                    if ($segments === [] || in_array('..', $segments, true)) {
                        continue;
                    }

                    $absolute = $hostBasePath.'/'.implode('/', $segments);

                    if (! $this->files->isDirectory($absolute) || ! $hostRoot->contains($absolute)) {
                        continue;
                    }

                    $directories[] = $absolute;
                }
            }
        }

        return array_values(array_unique($directories));
    }

    /** @param  list<string>  $psr4Directories */
    private function isUnderAnyMappedRoot(string $file, array $psr4Directories): bool
    {
        foreach ($psr4Directories as $directory) {
            try {
                $root = HostPath::root($directory);
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($root->contains($file)) {
                return true;
            }
        }

        return false;
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
     * G5 — scans `sharedScanRoots()` (E46) for every reference to $class,
     * then subtracts exactly the spans `rewritableSpans()` proves the
     * extraction will itself transform (E45). Any survivor refuses, named
     * as `file:line`. `NodeReferenceScanner::scan()` throws a
     * `RuntimeException` naming a file with more than one `namespace` block
     * — allowed to propagate into a clean refusal here rather than caught
     * and re-wrapped, since it already names the file and the reason.
     *
     * `sharedScanRoots()`/`scanSharedRoots()` are the SAME methods M6a's own
     * `rescanPostMoveTree()` calls — not two independently derived
     * root sets that happen to agree, the same reasoning
     * `rewritableSpans()`'s own docblock gives for why the gate and the
     * moves share ONE definition of what counts as covered ground.
     */
    private function gate5(string $class, string $hostBasePath): ?string
    {
        try {
            $found = $this->scanSharedRoots($class, $hostBasePath);
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
     *
     * CURRENTLY UNREACHABLE, KEPT ANYWAY: the existence and parse checks
     * immediately below can no longer actually fire by the time this gate
     * runs. G2's own `hostPsr4Directories()` already reads and decodes this
     * SAME file, on this SAME `$hostBasePath`, through this SAME
     * `$this->files`, earlier in the SAME `handle()` call — and already
     * refuses (via "not inside any PSR-4-mapped directory") whenever that
     * file is missing or does not decode to a JSON object, since an empty
     * PSR-4 directory list can never contain anything. Nothing writes to
     * the file in between (every gate is read-only), so a second decode
     * here cannot fail differently. Confirmed by execution, not assumed:
     * the covering test for this exact scenario was rewritten once this
     * stopped holding, and its own comment records what it demonstrates
     * now instead. These two checks are kept — rather than deleted and the
     * two lines below trusted to always succeed — because the dependency
     * is on ANOTHER gate's OWN internal method staying exactly as strict
     * as it is today; if `hostPsr4Directories()` ever relaxes (an escape
     * hatch added to G2, a recursive/internal call bypassing it), this
     * gate keeps its own, independent protection rather than silently
     * treating a missing or corrupt composer.json as "nothing required,
     * nothing discovered".
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
     * ONE `fnmatch()` call, with `FNM_PATHNAME`, handles both a literal
     * url and a genuine glob — there is no separate equality arm. Two
     * earlier revisions each kept ONE piece of now-redundant scaffolding
     * around this single check, for two different reasons, and both were
     * removed only once EXECUTION proved them redundant rather than by
     * reasoning about it in the abstract:
     *
     *   1. A `preg_match('/[*?\[]/', $url)` guard used to gate whether
     *      `fnmatch()` ran at all, on the theory that a literal url
     *      needed a SEPARATE `HostPath::segments($url) === $targetSegments`
     *      equality arm instead. Deleting the guard changed no test's
     *      outcome: `HostPath::segments()` already normalises a literal
     *      url's own backslashes before this point, so a metachar-free
     *      pattern is already an exact match under `fnmatch()` with no
     *      guard needed at all.
     *   2. Once that guard was gone, the equality arm became redundant
     *      TOO, confirmed the same way — `fnmatch('packages/acme/widgets',
     *      'packages/acme/widgets', FNM_PATHNAME)` is `true` on its own.
     *
     * THE ORDER THIS HAPPENED IN MATTERS, and a stale claim about it was
     * corrected here: it is NOT true that "removing either piece alone
     * changes nothing" — measured with BOTH the guard and the equality
     * arm still present, deleting the equality arm ALONE broke the
     * literal-match case, because the guard was still gating `fnmatch()`
     * away from literal urls entirely. Only once the guard was ALSO gone
     * did the equality arm become provably redundant. Each removal was
     * measured against the code as it stood at THAT point, not against
     * some later, already-simplified state — the two facts are sequential,
     * not simultaneous, and this docblock previously described the guard
     * as though it still existed after the code had already moved past
     * it, which is its own instance of the defect class this note exists
     * to warn against: a comment describing behaviour the code no longer
     * has is what let a Critical ship here once already (C2). `*` cannot
     * cross a `/` under `FNM_PATHNAME`, which is exactly what makes
     * Composer's own idiomatic monorepo form (`"packages/*"`, ONE wildcard
     * segment) fail to match a TWO-segment target
     * (`packages/acme/widgets`) the same way Composer's own path
     * repository resolution would.
     */
    private function requiredFromMatchingPathRepository(array $decoded, string $targetRelativePath): bool
    {
        $repositories = $decoded['repositories'] ?? [];

        if (! is_array($repositories)) {
            return false;
        }

        $normalisedTarget = implode('/', HostPath::segments($targetRelativePath));

        foreach ($repositories as $repository) {
            if (! is_array($repository) || ($repository['type'] ?? null) !== 'path') {
                continue;
            }

            $url = $repository['url'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            // FNM_PATHNAME so a glob's '*' cannot cross a '/' — Composer's
            // own idiomatic "packages/*" covers exactly one path segment,
            // not "packages/acme/widgets" (two segments), the same way
            // Composer's own path-repository resolution would not treat it
            // as a match.
            if (fnmatch(implode('/', HostPath::segments($url)), $normalisedTarget, FNM_PATHNAME)) {
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
