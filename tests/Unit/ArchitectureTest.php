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

        if (preg_match('/\buse\s+(function\s+)?Workflow\\\\/', file_get_contents($file->getPathname()))) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});
