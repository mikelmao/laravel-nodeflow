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
    // An entry spliced one character off lands outside the array and the host's
    // provider stops loading at all.
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

it('recognises a class listed without a leading backslash', function () {
    // The counterfactual: search for '\'.$class.'::class' (with the backslash, as
    // this did) and this fails — the writer reports Appended and adds a second
    // entry for a class already there. The leading backslash is optional in PHP,
    // and only entries this writer wrote itself carry one; a hand-written provider,
    // or one a future nodeflow:install generated, need not. Idempotency is the
    // whole point of this check, so it cannot depend on who wrote the line.
    $path = providerWithAnchor('        App\Nodeflow\Nodes\SendSms::class,');

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::AlreadyPresent);
    expect(substr_count(file_get_contents($path), 'SendSms::class'))->toBe(1);
});

it('does not mistake a longer class name for one already registered', function () {
    // The `::class` suffix is what makes the unprefixed needle safe. The
    // counterfactual: search for the class name alone and this fails, because
    // SendSms is a prefix of SendSmsExtra and the real node would never be added.
    $path = providerWithAnchor('        \App\Nodeflow\Nodes\SendSmsExtra::class,');

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);
    expect(file_get_contents($path))->toContain('\App\Nodeflow\Nodes\SendSms::class,');
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

/**
 * A provider with all three registration homes, as `nodeflow:install` generates
 * it. Returns the *path*, matching this file's existing providerWithAnchor().
 */
function providerWithThreeHomes(?string $body = null): string
{
    return writeProviderFixture($body ?? threeHomesSource());
}

function threeHomesSource(): string
{
    return <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;
    use Nodeflow\Nodeflow;
    use Nodeflow\Schema\SubjectAttributeRegistry;
    use Nodeflow\Triggers\TriggerRegistry;

    class NodeflowServiceProvider extends ServiceProvider
    {
        protected array $nodes = [
        ];

        protected array $triggers = [
        ];

        public function boot(): void
        {
            Nodeflow::register($this->nodes);

            app(TriggerRegistry::class)->register(...$this->triggers);

            app(SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());
        }

        /** @return \Nodeflow\Schema\SubjectAttribute[] */
        protected function subjectAttributes(): array
        {
            return [
            ];
        }
    }
    PHP;
}

it('appends a trigger class through the trigger anchor', function () {
    // Counterfactual: point TRIGGER_ANCHOR at the $nodes anchor and this fails,
    // because the trigger lands in the node array — where NodeRegistry::register()
    // would reject it for implementing neither cardinality interface.
    $path = providerWithThreeHomes();

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::TRIGGER_ANCHOR,
        'App\Nodeflow\Triggers\OrderPlaced::class',
        '\App\Nodeflow\Triggers\OrderPlaced::class',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);

    $contents = file_get_contents($path);

    // In the $triggers array, and provably not in the $nodes one.
    expect($contents)->toContain("protected array \$triggers = [\n        \\App\Nodeflow\Triggers\OrderPlaced::class,");
    expect($contents)->toContain("protected array \$nodes = [\n    ];");
});

it('appends a subject attribute inside the method body, at the method indent', function () {
    // Counterfactual: return the end of the anchor as the insertion point (the
    // rule the two array anchors use) and this fails — the entry lands in the
    // method signature line rather than inside its return array, which does not
    // parse.
    $path = providerWithThreeHomes();

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        "SubjectAttribute::make('clicked'",
        "\Nodeflow\Schema\SubjectAttribute::make('clicked', 'Clicked', 'boolean', fn (\$subject) => null)",
        '            ',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);

    $contents = file_get_contents($path);

    expect($contents)->toContain("        return [\n            \\Nodeflow\Schema\SubjectAttribute::make('clicked', 'Clicked', 'boolean', fn (\$subject) => null),");

    // The whole point of appending into a method: the result must still parse.
    expectParseablePhp($path);
});

it('recognises an attribute already present by its key alone', function () {
    // Counterfactual: make the presence needle the whole entry and this fails —
    // a re-run with a different label appends a second entry under the same key,
    // and SubjectAttributeRegistry::register() keys by $attribute->key, so the
    // second silently replaces the first.
    $path = providerWithThreeHomes(str_replace(
        "        return [\n",
        "        return [\n            \\Nodeflow\Schema\SubjectAttribute::make('clicked', 'Old label', 'boolean', fn (\$subject) => null),\n",
        threeHomesSource(),
    ));

    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        "SubjectAttribute::make('clicked'",
        "\Nodeflow\Schema\SubjectAttribute::make('clicked', 'New label', 'boolean', fn (\$subject) => null)",
        '            ',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::AlreadyPresent);
    expect(file_get_contents($path))->toBe($before);
});

it('refuses an attribute method whose body is not a bare return array', function () {
    // The bounded-window rule. Counterfactual: search the whole remainder of the
    // file for `return [` and this fails — the entry lands in some unrelated
    // later method's return array, silently, in host code.
    $path = providerWithThreeHomes(str_replace(
        "    protected function subjectAttributes(): array\n    {\n        return [\n        ];\n    }",
        "    protected function subjectAttributes(): array\n    {\n        \$extra = \$this->somethingElse();\n\n        \$more = \$this->andAnother();\n\n        \$yetMore = \$this->stillGoing();\n\n        return [\n        ];\n    }",
        threeHomesSource(),
    ));

    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        "SubjectAttribute::make('clicked'",
        "\Nodeflow\Schema\SubjectAttribute::make('clicked', 'Clicked', 'boolean', fn (\$subject) => null)",
        '            ',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::AnchorMissing);
    expect(file_get_contents($path))->toBe($before);
});

it('refuses a duplicated trigger anchor and writes nothing', function () {
    // Counterfactual: drop the >1 check and this fails — two $triggers arrays
    // means the writer cannot know which one the boot() call spreads.
    $path = providerWithThreeHomes(threeHomesSource().PHP_EOL.'// protected array $triggers = [');

    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::TRIGGER_ANCHOR,
        'App\Nodeflow\Triggers\OrderPlaced::class',
        '\App\Nodeflow\Triggers\OrderPlaced::class',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::AnchorAmbiguous);
    expect(file_get_contents($path))->toBe($before);
});
