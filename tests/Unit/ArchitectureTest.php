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
