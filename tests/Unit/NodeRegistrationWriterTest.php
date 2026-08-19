<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\NodeRegistrationOutcome;
use Nodeflow\Console\NodeRegistrationWriter;

/**
 * Fixture providers live in one directory per PHP process, so afterEach can delete
 * them without a parallel run's temp files being in reach.
 */
function providerFixtureDirectory(): string
{
    return sys_get_temp_dir().'/nodeflow-provider-fixtures-'.getmypid();
}

function writeProviderFixture(string $body): string
{
    $directory = providerFixtureDirectory();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = $directory.'/provider-'.bin2hex(random_bytes(6)).'.php';

    file_put_contents($path, $body);

    return $path;
}

afterEach(function () {
    // Without this, every test in this file left a provider behind in the system
    // temp directory forever.
    foreach (glob(providerFixtureDirectory().'/*.php') ?: [] as $path) {
        unlink($path);
    }

    if (is_dir(providerFixtureDirectory())) {
        rmdir(providerFixtureDirectory());
    }
});

/**
 * Asserts the file is still parseable PHP. The writer edits a file it did not
 * write, so "the entry is in there somewhere" is not the property that matters —
 * an entry spliced one character off lands outside the array and the host's
 * provider stops loading at all.
 */
function expectParseablePhp(string $path): void
{
    // Reset per call: exec() appends to $output rather than replacing it.
    $output = [];

    exec('php -l '.escapeshellarg($path).' 2>&1', $output, $exitCode);

    expect($exitCode)->toBe(0, "php -l failed for {$path}: ".implode(PHP_EOL, $output));
}

function providerWithAnchor(string $entries = '        //'): string
{
    return writeProviderFixture(<<<PHP
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;
    use Nodeflow\Nodeflow;

    class NodeflowServiceProvider extends ServiceProvider
    {
        protected array \$nodes = [
    {$entries}
        ];

        public function boot(): void
        {
            Nodeflow::register(\$this->nodes);
        }
    }
    PHP);
}

it('appends the node class inside the nodes array', function () {
    $path = providerWithAnchor();

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);
    expect(file_get_contents($path))
        ->toContain('\App\Nodeflow\Nodes\SendSms::class,');
});

it('appends between the anchor and the closing bracket of its own array', function () {
    // The counterfactual this replaced an ordering assertion for: drop
    // `+ strlen(self::ANCHOR)` from the insertion position and the entry lands
    // *before* `protected array $nodes = [` instead of inside it. A
    // `toContain('SendSms::class,')` check and a "before public function boot"
    // ordering check both still pass on that output, which is a parse error.
    //
    // Bracketing the entry between the end of the anchor and the `];` that closes
    // that same array is what makes the position itself the assertion: too early
    // fails the lower bound, and appending to a later array or to the end of the
    // file fails the upper bound.
    $path = providerWithAnchor();

    (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    $contents = file_get_contents($path);

    $anchorEnds = strpos($contents, NodeRegistrationWriter::ANCHOR) + strlen(NodeRegistrationWriter::ANCHOR);
    $arrayCloses = strpos($contents, '];', $anchorEnds);

    expect(strpos($contents, '\App\Nodeflow\Nodes\SendSms::class,'))
        ->toBeGreaterThan($anchorEnds)
        ->toBeLessThan($arrayCloses);
});

it('leaves the edited provider as parseable PHP', function () {
    // The one operation this class exists for is splicing text into a file the
    // package does not own, and nothing else in the suite checks the result still
    // parses. The counterfactual: drop `+ strlen(self::ANCHOR)` from the insertion
    // position and this fails, while every substring assertion in this file passes.
    $path = providerWithAnchor();

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);

    expectParseablePhp($path);
});

it('does not add a second entry for a class already registered', function () {
    $path = providerWithAnchor('        \App\Nodeflow\Nodes\SendSms::class,');

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::AlreadyPresent);
    expect(substr_count(file_get_contents($path), 'SendSms::class'))->toBe(1);
});

it('reports a missing provider without creating one', function () {
    $path = sys_get_temp_dir().'/nodeflow-absent-'.bin2hex(random_bytes(6)).'.php';

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::ProviderMissing);
    expect($path)->not->toBeFile();
});

it('refuses to guess when the anchor is absent', function () {
    // This is the trap the project has already paid for: an edit that applies
    // cleanly and changes nothing. The counterfactual: skip the anchor check and
    // this returns Appended while the file is untouched.
    $path = writeProviderFixture("<?php\n\nclass Whatever {}\n");
    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::AnchorMissing);
    expect(file_get_contents($path))->toBe($before);
});

it('refuses to guess when the anchor is ambiguous', function () {
    $path = writeProviderFixture(<<<'PHP'
    <?php

    class Two
    {
        protected array $nodes = [
        ];

        protected array $nodes = [
        ];
    }
    PHP);
    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::AnchorAmbiguous);
    expect(file_get_contents($path))->toBe($before);
});
