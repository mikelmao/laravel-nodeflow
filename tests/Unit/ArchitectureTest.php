<?php

it('confines the engine dependency to src/Engine and src/Workflows', function () {
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src'));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        if (str_contains($path, '/src/Engine/') || str_contains($path, '/src/Workflows/')) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        // Catches both `use Workflow\...` / `use function Workflow\...` imports and an
        // inline fully-qualified reference like `\Workflow\V2\WorkflowStub::make()` used
        // without an import. Deliberately does not match `Nodeflow\Workflows\...` (the
        // package's own sub-namespace): that requires "Workflow" to be followed
        // immediately by a backslash, which "Workflows" (plural) never is.
        if (preg_match('/\buse\s+(function\s+)?Workflow\\\\|\\\\Workflow\\\\/', $contents)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps RunSubject and NodeExecution out of everything but the execution internals', function () {
    // Spec E1: these two carry no tenant_id — they are the six-figure tables and
    // are only reachable through a Run, which is scoped. So the isolation is
    // structural, and this is the thing that keeps it structural once Plan 3
    // adds controllers. The allowlist is the set of places that legitimately
    // query them today: the interpreter internals and the prune command, which
    // is explicitly a cross-tenant system operation.
    //
    // Counterfactual: add `RunSubject::where(...)` or
    // `DB::table('nodeflow_run_subjects')` to any file in src/ outside the
    // allowlist and this fails, naming the file.
    $src = __DIR__.'/../../src';

    // violations() returns [] for a root that is not a directory, which is the
    // right answer for a caller passing a temp dir it did not create — and a
    // silent pass here if this path ever stops resolving (a move, a rename, a
    // composer layout change). Asserted rather than assumed: an architecture
    // guard that scans nothing is worse than no guard, because it reports green.
    expect(is_dir($src))->toBeTrue("scanner root does not resolve to a directory: {$src}");

    $violations = Tests\Support\RequestContextScanner::violations(
        $src,
        [
            '/src/Execution/',
            '/src/Console/PruneCommand.php',
            // E18's bright-line rule matches a table name anywhere in code, and
            // these two files legitimately declare `protected $table` for the
            // very models the rule protects. Allowlisting the files is not a
            // hole: a scope method added to either still has to be *called*
            // somewhere, and the `Model::` rule catches that call site.
            '/src/Models/RunSubject.php',
            '/src/Models/NodeExecution.php',
        ],
    );

    expect($violations)->toBe([]);
});
