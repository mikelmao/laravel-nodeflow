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

it('restores the provider and reports failure after a short write', function () {
    $path = providerWithAnchor();
    $before = file_get_contents($path);
    $files = new class extends Filesystem
    {
        private bool $truncate = true;

        public function put($path, $contents, $lock = false)
        {
            if ($this->truncate && str_contains($contents, 'SendSms::class')) {
                $this->truncate = false;
                parent::put($path, substr($contents, 0, -1), $lock);

                return strlen($contents) - 1;
            }

            return parent::put($path, $contents, $lock);
        }
    };

    $outcome = (new NodeRegistrationWriter($files))->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::WriteFailed)
        ->and(file_get_contents($path))->toBe($before);
});

it('leaves the original provider and no sibling temp when atomic rename fails', function () {
    $path = providerWithAnchor();
    $before = file_get_contents($path);
    $files = new class extends Filesystem
    {
        public function move($path, $target)
        {
            if (str_contains($path, '.nodeflow-tmp-')) return false;

            return parent::move($path, $target);
        }
    };

    $outcome = (new NodeRegistrationWriter($files, [providerFixtureDirectory()]))
        ->register($path, 'App\Nodeflow\Nodes\RenameFailure');

    expect($outcome)->toBe(NodeRegistrationOutcome::WriteFailed)
        ->and(file_get_contents($path))->toBe($before)
        ->and(glob(providerFixtureDirectory().'/*.nodeflow-tmp-*') ?: [])->toBe([]);
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

it('appends rather than reporting AlreadyPresent when the namespace makes the written entry a different class', function () {
    // E50. This test used to assert AlreadyPresent for this exact fixture, which
    // was wrong: providerWithAnchor() declares `namespace App\Providers;`, so by
    // PHP's own name-resolution rule (verified by probe, and what PhpNameResolver
    // implements), the unqualified entry `App\Nodeflow\Nodes\SendSms::class`
    // resolves to `App\Providers\App\Nodeflow\Nodes\SendSms` — NOT
    // `App\Nodeflow\Nodes\SendSms`, the class actually being registered. Matching
    // must not diverge between appendTo() and removeFrom() (a divergence of
    // exactly that kind produced execution-record C1), so this now expects
    // Appended, and a second entry is added rather than the mismatched one being
    // mistaken for the target.
    $path = providerWithAnchor('        App\Nodeflow\Nodes\SendSms::class,');

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);
    expect(substr_count(file_get_contents($path), 'SendSms::class'))->toBe(2);
});

it('recognises a class listed without a leading backslash when the file declares no namespace', function () {
    // The companion the rewritten test above owes: the case the original test
    // was reaching for. With no `namespace` declaration, PhpNameResolver
    // resolves the unqualified entry `App\Nodeflow\Nodes\SendSms::class` to
    // itself, `App\Nodeflow\Nodes\SendSms` — which IS the target — so this must
    // report AlreadyPresent and add nothing.
    $path = writeProviderFixture(<<<'PHP'
    <?php

    use Illuminate\Support\ServiceProvider;
    use Nodeflow\Nodeflow;

    class NodeflowServiceProvider extends ServiceProvider
    {
        protected array $nodes = [
            App\Nodeflow\Nodes\SendSms::class,
        ];

        public function boot(): void
        {
            Nodeflow::register($this->nodes);
        }
    }
    PHP);

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::AlreadyPresent);
    expect(substr_count(file_get_contents($path), 'SendSms::class'))->toBe(1);
});

it('does not read a mention outside the nodes array as already registered', function () {
    // Pre-existing shipped defect, found by this plan's external review:
    // appendTo() ran str_contains over the WHOLE comment-stripped file, so any
    // mention anywhere read AlreadyPresent and the entry was never added.
    // Counterfactual: restore the whole-file str_contains and this fails.
    $path = writeProviderFixture(<<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;

    class NodeflowServiceProvider extends ServiceProvider
    {
        protected array $nodes = [
        ];

        public function boot(): void
        {
            $documentation = 'see \App\Nodeflow\Nodes\SendSms::class for an example';
        }
    }
    PHP);

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);
    expectParseablePhp($path);
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

    class NodeflowServiceProvider
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

    class NodeflowServiceProvider extends ServiceProvider
    {
        protected array $nodes = [
        ];

        protected array $triggerSources = [
        ];

        public function boot(): void
        {
            Nodeflow::register($this->nodes);

            Nodeflow::registerTriggerSources($this->triggerSources);

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
    // Counterfactual: point TRIGGER_SOURCE_ANCHOR at the $nodes anchor and this fails,
    // because the trigger lands in the node array — where NodeRegistry::register()
    // would reject it for implementing neither cardinality interface.
    $path = providerWithThreeHomes();

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR,
        'App\Nodeflow\Triggers\OrderPlaced::class',
        '\App\Nodeflow\Triggers\OrderPlaced::class',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);

    $contents = file_get_contents($path);

    // In the $triggerSources array, and provably not in the $nodes one.
    expect($contents)->toContain("protected array \$triggerSources = [\n        \\App\Nodeflow\Triggers\OrderPlaced::class,");
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

it('ignores a trigger anchor mentioned only in a comment', function () {
    $path = providerWithThreeHomes(threeHomesSource().PHP_EOL.'// protected array $triggerSources = [');

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR,
        'App\Nodeflow\Triggers\OrderPlaced::class',
        '\App\Nodeflow\Triggers\OrderPlaced::class',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);
    expect(substr_count(file_get_contents($path), 'OrderPlaced::class'))->toBe(1);
});

it('only treats a unique real provider-owned array property as a registration home', function (string $body, NodeRegistrationOutcome $expected) {
    $path = writeProviderFixture($body);
    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR,
        'App\\Nodeflow\\Triggers\\OrderPlaced::class',
        '\\App\\Nodeflow\\Triggers\\OrderPlaced::class',
    );

    expect($outcome)->toBe($expected)
        ->and(file_get_contents($path))->toBe($before);
})->with([
    'string literal' => [<<<'PHP'
        <?php
        class NodeflowServiceProvider
        {
            public string $example = 'protected array $triggerSources = [';
        }
        PHP, NodeRegistrationOutcome::AnchorMissing],
    'decoy class' => [<<<'PHP'
        <?php
        class DecoyProvider
        {
            protected array $triggerSources = [];
        }
        class NodeflowServiceProvider {}
        PHP, NodeRegistrationOutcome::AnchorMissing],
    'trait property' => [<<<'PHP'
        <?php
        trait TriggerHomes
        {
            protected array $triggerSources = [];
        }
        class NodeflowServiceProvider
        {
            use TriggerHomes;
        }
        PHP, NodeRegistrationOutcome::AnchorMissing],
    'wrong property type' => [<<<'PHP'
        <?php
        class NodeflowServiceProvider
        {
            protected string $triggerSources = 'protected array $triggerSources = [';
        }
        PHP, NodeRegistrationOutcome::AnchorAmbiguous],
    'duplicate real properties' => [<<<'PHP'
        <?php
        class NodeflowServiceProvider
        {
            protected array $triggerSources = [];
            protected array $triggerSources = [];
        }
        PHP, NodeRegistrationOutcome::AnchorAmbiguous],
]);

it('does not select a helper property over the unique differently named package service provider', function () {
    $path = writeProviderFixture(<<<'PHP'
        <?php

        namespace Vendor\Package;

        use Illuminate\Support\ServiceProvider;

        class RegistrationHelper
        {
            protected array $triggerSources = [];
        }

        class PackageServiceProvider extends ServiceProvider
        {
        }
        PHP);
    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR,
        'Vendor\\Package\\OrderPlaced::class',
        '\\Vendor\\Package\\OrderPlaced::class',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::AnchorMissing)
        ->and(file_get_contents($path))->toBe($before);
});

it('refuses an ambiguous package file containing multiple service providers', function () {
    $path = writeProviderFixture(<<<'PHP'
        <?php

        namespace Vendor\Package;

        use Illuminate\Support\ServiceProvider;

        class FirstServiceProvider extends ServiceProvider
        {
            protected array $triggerSources = [];
        }

        class SecondServiceProvider extends ServiceProvider
        {
        }
        PHP);
    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR,
        'Vendor\\Package\\OrderPlaced::class',
        '\\Vendor\\Package\\OrderPlaced::class',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::AnchorAmbiguous)
        ->and(file_get_contents($path))->toBe($before);
});

it('inserts into the structural provider array without changing CRLF formatting', function () {
    $path = providerWithThreeHomes(str_replace("\n", "\r\n", threeHomesSource()));

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR,
        'App\\Nodeflow\\Triggers\\OrderPlaced::class',
        '\\App\\Nodeflow\\Triggers\\OrderPlaced::class',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended)
        ->and(preg_match('/(?<!\r)\n/', file_get_contents($path)))->toBe(0);
});

it('appends after a docblock example that itself contains "return [", not into it', function () {
    // C2. insertionPoint()'s method-body search used to be a plain substring
    // match over the raw window, so a docblock example like this one — placed
    // before the REAL return statement, well within reach either way — matched
    // first and the entry landed inside the comment. Counterfactual: search the
    // raw window instead of the comment-stripped one and this fails, with the
    // entry appearing before "return [" rather than after it and the file
    // failing php -l.
    $path = providerWithThreeHomes(str_replace(
        "    protected function subjectAttributes(): array\n    {\n        return [\n        ];\n    }",
        "    protected function subjectAttributes(): array\n    {\n        // e.g. return [ SubjectAttribute::make(...) ];\n        return [\n        ];\n    }",
        threeHomesSource(),
    ));

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        "SubjectAttribute::make('clicked'",
        "\Nodeflow\Schema\SubjectAttribute::make('clicked', 'Clicked', 'boolean', fn (\$subject) => null)",
        '            ',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);

    $contents = file_get_contents($path);

    // The comment survives untouched, and the entry sits in the REAL return
    // array that follows it, not inside the comment.
    expect($contents)->toContain('// e.g. return [ SubjectAttribute::make(...) ];');

    $commentEnd = strpos($contents, '// e.g. return [ SubjectAttribute::make(...) ];')
        + strlen('// e.g. return [ SubjectAttribute::make(...) ];');
    $entryPos = strpos($contents, "\Nodeflow\Schema\SubjectAttribute::make('clicked'");

    expect($entryPos)->toBeGreaterThan($commentEnd);

    expectParseablePhp($path);
});

it('ignores a commented-out anchor and reports the real provider home missing', function () {
    // C2 / E11. The `$nodes` home's own declaration line is commented out, so
    // ANCHOR still matches once, raw, and the insertion point still looks
    // valid right up until the result is read back — the array it appears to
    // open was never actually declared. Counterfactual: skip the post-write
    // re-verification and this fails, reporting Appended for a file that does
    // not parse.
    $path = writeProviderFixture(<<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;
    use Nodeflow\Nodeflow;

    class NodeflowServiceProvider extends ServiceProvider
    {
        // protected array $nodes = [
        ];

        public function boot(): void
        {
            Nodeflow::register($this->nodes);
        }
    }
    PHP);

    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::AnchorAmbiguous);
    expect($outcome)->not->toBe(NodeRegistrationOutcome::Appended);
    expect(file_get_contents($path))->toBe($before);
});

it('treats a class hidden behind an unsupported element as already registered, refusing to add a duplicate', function () {
    // The append-direction mirror of removeFrom()'s EntryUnsupported design
    // decision: isAlreadyPresent()'s ::class branch treats an element it
    // cannot classify (here, `self::SMS` aliasing SendSms through a class
    // constant) as PRESENT, not absent — a false "not present" would let
    // register() append a second, duplicate entry for a class that is, in
    // fact, already registered under a spelling this writer cannot see
    // through. Counterfactual: treat an unclassifiable element as though it
    // simply were not there (skip it and keep scanning) and this reports
    // Appended, leaving \App\Nodeflow\Nodes\SendSms registered twice under
    // two different spellings.
    $path = writeProviderFixture(<<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;
    use App\Nodeflow\Nodes\SendSms;

    class NodeflowServiceProvider extends ServiceProvider
    {
        const SMS = SendSms::class;

        protected array $nodes = [
            self::SMS,
        ];
    }
    PHP);

    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::AlreadyPresent);
    expect(file_get_contents($path))->toBe($before);
});

it('does not let an earlier entry whose string argument contains "]" truncate the attribute return array', function () {
    // Important finding: the same root cause (a `]` inside a string literal
    // ending the span early under a character-based scan) regressed
    // appendTo()'s attribute-presence branch too. The decoy entry's key
    // contains a literal `]` and sits BEFORE the real 'clicked' entry. Under
    // a character-based arraySpan(), that `]` was mistaken for the return
    // array's own closing bracket, so the presence search never reached the
    // real 'clicked' entry — appendTo() would report it "not present" and
    // append a SECOND 'clicked' entry, which
    // SubjectAttributeRegistry::register() (keyed by attribute key) would
    // have made silently replace the first at runtime. The token-based span
    // treats the decoy's whole string as ONE token, so its internal `]`
    // never closes anything.
    $path = providerWithThreeHomes(str_replace(
        "        return [\n        ];",
        "        return [\n"
            ."            \Nodeflow\Schema\SubjectAttribute::make('other]key', 'Decoy', 'boolean', fn (\$subject) => null),\n"
            ."            \Nodeflow\Schema\SubjectAttribute::make('clicked', 'Already here', 'boolean', fn (\$subject) => null),\n"
            .'        ];',
        threeHomesSource(),
    ));

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        "SubjectAttribute::make('clicked'",
        "\Nodeflow\Schema\SubjectAttribute::make('clicked', 'New label', 'boolean', fn (\$subject) => null)",
        '            ',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::AlreadyPresent);
    expect(substr_count(file_get_contents($path), "make('clicked',"))->toBe(1);
});

it('does not read a mention outside the attribute return array as already registered', function () {
    // M8: isAlreadyPresent()'s non-::class branch must be scoped to the
    // ATTRIBUTE_ANCHOR's own return array, not the whole file — mirroring
    // the whole-file str_contains defect Step 7 fixed for the ::class
    // branch, but for the substring branch instead. Counterfactual: revert
    // to str_contains(SourceText::withoutPhpComments($contents),
    // $presenceNeedle) over the whole file, and this fails — the mention
    // inside boot()'s string is found and appendTo() wrongly reports
    // AlreadyPresent without ever adding the entry.
    $path = providerWithThreeHomes(str_replace(
        "    public function boot(): void\n    {",
        "    public function boot(): void\n    {\n"
            ."        \$documentation = \"see SubjectAttribute::make('clicked' for an example\";\n",
        threeHomesSource(),
    ));

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        "SubjectAttribute::make('clicked'",
        "\Nodeflow\Schema\SubjectAttribute::make('clicked', 'Clicked', 'boolean', fn (\$subject) => null)",
        '            ',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);
    expectParseablePhp($path);
});

it('does not let a nested array argument in an earlier call truncate the attribute return array', function () {
    // Isolates arraySpan()'s own bracket/paren DEPTH tracking specifically,
    // as distinct from token-atomicity: the decoy entry's second ARGUMENT is
    // a literal nested array `['x']`, not a string, so this exercises the
    // actual `[`/`]` depth counter rather than the "a string is one token"
    // fix. On the ::class / removeFrom() side, a decoy shaped like this
    // always ends up classified as EntryUnsupported regardless of whether
    // the span is correctly bounded — the structural classifier masks a
    // depth-tracking bug there. It does NOT mask it here: the attribute
    // branch is a plain substring search with no structural classifier, so
    // if the return array's span were truncated at the decoy's own `]`
    // (before reaching it via depth-tracking), the real 'clicked' entry
    // — which comes after the decoy — would fall outside the search and
    // this would wrongly report Appended instead of AlreadyPresent.
    $path = providerWithThreeHomes(str_replace(
        "        return [\n        ];",
        "        return [\n"
            ."            \Nodeflow\Schema\SubjectAttribute::make('decoy', ['x'], 'boolean', fn (\$subject) => null),\n"
            ."            \Nodeflow\Schema\SubjectAttribute::make('clicked', 'Already here', 'boolean', fn (\$subject) => null),\n"
            .'        ];',
        threeHomesSource(),
    ));

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        "SubjectAttribute::make('clicked'",
        "\Nodeflow\Schema\SubjectAttribute::make('clicked', 'New label', 'boolean', fn (\$subject) => null)",
        '            ',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::AlreadyPresent);
    expect(substr_count(file_get_contents($path), "make('clicked',"))->toBe(1);
});

it('does not treat a commented-out attribute registration as already present', function () {
    // Important #3 (round 3). E22's whole point: a debugged-out entry must
    // not read as live. isAlreadyPresent()'s substring branch builds its
    // search body by skipping T_COMMENT/T_DOC_COMMENT tokens specifically
    // so a commented-out `SubjectAttribute::make('clicked', ...)` cannot
    // satisfy the presence check. Counterfactual: drop that comment skip
    // and this reports AlreadyPresent for a 'clicked' attribute that was
    // never actually registered — the attribute is then silently never
    // added, forever, because every future run reads the comment as proof
    // it is already there.
    $path = providerWithThreeHomes(str_replace(
        "        return [\n        ];",
        "        return [\n"
            ."            // \Nodeflow\Schema\SubjectAttribute::make('clicked', 'Old', 'boolean', fn (\$subject) => null),\n"
            .'        ];',
        threeHomesSource(),
    ));

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        "SubjectAttribute::make('clicked'",
        "\Nodeflow\Schema\SubjectAttribute::make('clicked', 'Clicked', 'boolean', fn (\$subject) => null)",
        '            ',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);
    expect(file_get_contents($path))->toContain("make('clicked', 'Clicked'");
    expectParseablePhp($path);
});
