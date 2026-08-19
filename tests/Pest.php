<?php

uses(Tests\TestCase::class)->in('Feature', 'Unit');

/**
 * Asserts a file is parseable PHP.
 *
 * Shared because two suites need it for the same reason: this package writes PHP
 * — generated node classes, generated tests, and an entry spliced into a file it
 * does not own — and substring assertions on generated code cannot tell working
 * output from a parse error.
 */
function expectParseablePhp(string $path): void
{
    // Reset per call: exec() appends to $output rather than replacing it, so a
    // second failure would otherwise report the first file's output too.
    $output = [];

    exec('php -l '.escapeshellarg($path).' 2>&1', $output, $exitCode);

    expect($exitCode)->toBe(0, "php -l failed for {$path}: ".implode(PHP_EOL, $output));
}
