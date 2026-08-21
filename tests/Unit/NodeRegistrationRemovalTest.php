<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\NodeRegistrationWriter;
use Nodeflow\Console\NodeRemovalOutcome;

function removalFixtureDirectory(): string
{
    return sys_get_temp_dir().'/nodeflow-removal-fixtures-'.getmypid();
}

function providerForRemoval(string $entries, string $uses = '', string $namespace = 'App\Providers'): string
{
    $directory = removalFixtureDirectory();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = $directory.'/provider-'.bin2hex(random_bytes(6)).'.php';
    $namespaceLine = $namespace === '' ? '' : "namespace {$namespace};";

    file_put_contents($path, <<<PHP
    <?php

    {$namespaceLine}

    use Illuminate\Support\ServiceProvider;
    {$uses}

    class NodeflowServiceProvider extends ServiceProvider
    {
        protected array \$nodes = [
    {$entries}
        ];
    }
    PHP);

    return $path;
}

function remove(string $path, string $class): NodeRemovalOutcome
{
    return (new NodeRegistrationWriter(new Filesystem))
        ->removeFrom($path, NodeRegistrationWriter::ANCHOR, $class);
}

afterEach(function () {
    foreach (glob(removalFixtureDirectory().'/*.php') ?: [] as $path) {
        unlink($path);
    }

    if (is_dir(removalFixtureDirectory())) {
        rmdir(removalFixtureDirectory());
    }
});

it('removes a fully-qualified entry and leaves the file parseable', function () {
    $path = providerForRemoval('        \App\Nodeflow\Nodes\SendMessage::class,');

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::Removed);
    expect(file_get_contents($path))->not->toContain('SendMessage');
    expectParseablePhp($path);
});

it('removes a bare short-name entry behind an import', function () {
    // G-10's form, and the demo's own shape after its migration to $nodes. This
    // is the path the real-host run exercises.
    $path = providerForRemoval(
        '        SendMessage::class,',
        'use App\Nodeflow\Nodes\SendMessage;',
    );

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::Removed);
    expect(file_get_contents($path))->not->toContain('SendMessage::class');
    expectParseablePhp($path);
});

it('removes an ALIASED entry rather than reporting it absent', function () {
    // The first design draft's lexical three-form table missed this entirely:
    // a live registration read NotPresent, extraction proceeded, and the host
    // was left fatal. Counterfactual: match on the target's short name as a
    // string and this fails, because the file never contains "SendMessage::class".
    $path = providerForRemoval(
        '        Sender::class,',
        'use App\Nodeflow\Nodes\SendMessage as Sender;',
    );

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::Removed);
    expect(file_get_contents($path))->not->toContain('Sender::class');
    expectParseablePhp($path);
});

it('does not remove a longer sibling whose name merely contains the target', function () {
    $path = providerForRemoval(
        "        SendSms::class,\n        SendSmsExtra::class,",
        "use App\Nodeflow\Nodes\SendSms;\nuse App\Nodeflow\Nodes\SendSmsExtra;",
    );

    expect(remove($path, 'App\Nodeflow\Nodes\SendSms'))->toBe(NodeRemovalOutcome::Removed);

    $contents = file_get_contents($path);

    expect($contents)->toContain('SendSmsExtra::class');
    expect($contents)->not->toContain("\n        SendSms::class,");
    expectParseablePhp($path);
});

it('reports NotPresent and changes nothing when only a longer sibling is listed', function () {
    // The inverse bound. Proves the match is bounded in BOTH directions.
    $path = providerForRemoval(
        '        SendSmsExtra::class,',
        'use App\Nodeflow\Nodes\SendSmsExtra;',
    );

    $before = file_get_contents($path);

    expect(remove($path, 'App\Nodeflow\Nodes\SendSms'))->toBe(NodeRemovalOutcome::NotPresent);
    expect(file_get_contents($path))->toBe($before);
});

it('does not treat a qualified entry as the target when the namespace makes it another class', function () {
    // PHP's rule, verified by probe: inside namespace App\Providers, the entry
    // below IS App\Providers\App\Nodeflow\Nodes\SendMessage. Removing it would
    // delete a line naming a DIFFERENT class.
    $path = providerForRemoval('        App\Nodeflow\Nodes\SendMessage::class,');

    $before = file_get_contents($path);

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::NotPresent);
    expect(file_get_contents($path))->toBe($before);
});

it('DOES treat that same spelling as the target when the file declares no namespace', function () {
    $path = providerForRemoval('        App\Nodeflow\Nodes\SendMessage::class,', '', '');

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::Removed);
    expect(file_get_contents($path))->not->toContain('SendMessage');
    expectParseablePhp($path);
});

it('reports NotPresent for an entry that only exists inside a comment', function () {
    $path = providerForRemoval('        // SendMessage::class,', 'use App\Nodeflow\Nodes\SendMessage;');

    $before = file_get_contents($path);

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::NotPresent);
    expect(file_get_contents($path))->toBe($before);
});

it('empties the array body when the target is its only entry', function () {
    $path = providerForRemoval('        \App\Nodeflow\Nodes\SendMessage::class,');

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::Removed);
    expectParseablePhp($path);

    $contents = file_get_contents($path);

    expect($contents)->not->toContain('SendMessage');
    expect($contents)->toContain('protected array $nodes = [');
});

it('removes the last entry when it carries no trailing comma', function () {
    $path = providerForRemoval("        TagUser::class,\n        SendMessage::class",
        "use App\Nodeflow\Nodes\TagUser;\nuse App\Nodeflow\Nodes\SendMessage;");

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::Removed);
    expectParseablePhp($path);

    $contents = file_get_contents($path);

    expect($contents)->not->toContain('SendMessage::class');
    expect($contents)->toContain('TagUser::class');
});

it('leaves no orphan blank line where a middle entry used to be', function () {
    // M5: entryDeletionRange()'s "own line" deletion must consume the entry's
    // OWN trailing newline (the `+ 1` on rawEnd), not stop one byte short —
    // otherwise the entry's text is gone but an empty line survives where it
    // used to be. That defect makes the file no less valid PHP and leaves no
    // resolved reference behind, so neither parses() nor the remaining-
    // reference check would ever catch it; only asserting the surviving
    // file's exact shape does. Counterfactual: drop the `+ 1` and this fails
    // — the array reads "TagUser::class,\n\n        UserTagged::class," with
    // a blank line where SendMessage::class used to be.
    $path = providerForRemoval(
        "        TagUser::class,\n        SendMessage::class,\n        UserTagged::class,",
        "use App\Nodeflow\Nodes\TagUser;\nuse App\Nodeflow\Nodes\SendMessage;\nuse App\Nodeflow\Nodes\UserTagged;",
    );

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::Removed);
    expectParseablePhp($path);

    $contents = file_get_contents($path);

    expect($contents)->not->toContain('SendMessage::class');
    expect($contents)->toContain("TagUser::class,\n        UserTagged::class,");
    expect($contents)->not->toContain("TagUser::class,\n\n");
});

it('removes an entry carrying a trailing same-line comment', function () {
    $path = providerForRemoval('        SendMessage::class, // the sms node',
        'use App\Nodeflow\Nodes\SendMessage;');

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::Removed);
    expect(file_get_contents($path))->not->toContain('the sms node');
    expectParseablePhp($path);
});

it('refuses as EntryAmbiguous when the target shares a line with a sibling', function () {
    // E39: deleting from inside a shared line means preserving that line's other
    // content byte-exactly, which is where this codebase's substring bug would
    // live for the ninth time. Refusing loudly beats character surgery.
    $path = providerForRemoval('        SendMessage::class, TagUser::class,',
        "use App\Nodeflow\Nodes\SendMessage;\nuse App\Nodeflow\Nodes\TagUser;");

    $before = file_get_contents($path);

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::EntryAmbiguous);
    expect(file_get_contents($path))->toBe($before);
});

it('refuses and changes nothing when a second anchor is commented out', function () {
    $directory = removalFixtureDirectory();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = $directory.'/ambiguous-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($path, <<<'PHP'
    <?php

    namespace App\Providers;

    class NodeflowServiceProvider
    {
        // protected array $nodes = [
        protected array $nodes = [
            \App\Nodeflow\Nodes\SendMessage::class,
        ];
    }
    PHP);

    $before = file_get_contents($path);

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::AnchorAmbiguous);
    expect(file_get_contents($path))->toBe($before);
});

it('restores the original bytes and reports WriteFailed when the file never parsed to begin with', function () {
    // Rule 8's post-write re-verification. token_get_all() WITHOUT TOKEN_PARSE
    // (what codeWithOffsets()/arraySpan() use to locate the array) is lenient
    // and tokenizes this file fine despite the unterminated string in boot(),
    // so removeFrom() finds the anchor, the span, and the target entry, and
    // attempts the deletion. Only the post-write check — which parses with
    // TOKEN_PARSE, as parses() always has — catches that the file was never
    // valid PHP. Counterfactual: skip that re-verification and this reports
    // Removed for a file that still does not parse, with the target entry
    // actually deleted rather than the original bytes restored.
    $directory = removalFixtureDirectory();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = $directory.'/unparseable-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($path, <<<'PHP'
    <?php

    namespace App\Providers;

    class NodeflowServiceProvider
    {
        protected array $nodes = [
            \App\Nodeflow\Nodes\SendMessage::class,
        ];

        public function boot(): void
        {
            $x = "unterminated
        }
    }
    PHP);

    $before = file_get_contents($path);

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::WriteFailed);
    expect(file_get_contents($path))->toBe($before);
});

it('refuses to guess when the anchor is absent from an existing file', function () {
    $path = removalFixtureDirectory();

    if (! is_dir($path)) {
        mkdir($path, 0777, true);
    }

    $path .= '/no-anchor-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($path, "<?php\n\nclass Whatever {}\n");

    $before = file_get_contents($path);

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::AnchorMissing);
    expect(file_get_contents($path))->toBe($before);
});

it('removes the target when the caller passes it with a leading backslash', function () {
    // M7: removeFrom()'s $nodeClass argument is ltrim'd of a leading `\`
    // before comparison, matching PhpNameResolver::resolve()'s own contract
    // (no leading backslash in its return value) and register()'s existing
    // convention of accepting either spelling. Counterfactual: drop that
    // ltrim and this fails, because `\App\Nodeflow\Nodes\SendMessage` (with
    // the caller's leading backslash) never equals the resolver's
    // backslash-free `App\Nodeflow\Nodes\SendMessage`.
    $path = providerForRemoval('        \App\Nodeflow\Nodes\SendMessage::class,');

    expect(remove($path, '\App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::Removed);
    expect(file_get_contents($path))->not->toContain('SendMessage');
    expectParseablePhp($path);
});

it('reports ProviderMissing for a path that does not exist', function () {
    expect(remove('/nonexistent/Provider.php', 'App\Nodeflow\Nodes\SendMessage'))
        ->toBe(NodeRemovalOutcome::ProviderMissing);
});

it('removes every matching entry when the class is listed twice', function () {
    // Verification requires that NO resolved reference survives, so a duplicate
    // cannot be left behind. Counterfactual: return after the first removal and
    // the second expectation fails.
    $path = providerForRemoval(
        "        SendMessage::class,\n        \App\Nodeflow\Nodes\SendMessage::class,",
        'use App\Nodeflow\Nodes\SendMessage;',
    );

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::Removed);
    expect(substr_count(file_get_contents($path), 'SendMessage::class'))->toBe(0);
    expectParseablePhp($path);
});

it('does not end the array span early at a nested array literal that closes before the target', function () {
    // Rule 3: the span must be found by brace/bracket MATCHING from the
    // anchor's own `[` to its partner, not by searching for the next `]` —
    // a nested array closes its own `]` well before the real one. Mutation
    // probe: replace the depth-tracking loop with a bare "first ']' wins"
    // scan and every other test in this file still passes, because none of
    // them puts a nested array ahead of the target — this is the one that
    // catches it. With the bug, the span is truncated at `['unused']`'s own
    // `]`, the target entry (which comes after) falls outside the
    // (wrongly narrowed) span, and this reports NotPresent.
    //
    // Once the span is correctly found to extend past the nested array, that
    // element itself is not `<name>::class` shaped — a nested array is not a
    // class reference — so the CORRECT outcome is EntryUnsupported, not
    // Removed: this writer cannot certify the target absent (or present) from
    // an array it does not fully understand, so it refuses the whole
    // operation rather than remove the one entry it does recognise and stay
    // silent about the one it does not. The file must be untouched either way.
    $path = providerForRemoval(
        "        ['unused'],\n        \App\Nodeflow\Nodes\SendMessage::class,",
    );

    $before = file_get_contents($path);

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::EntryUnsupported);
    expect(file_get_contents($path))->toBe($before);
});

it('clears the body of a single-line array where the entry is not on its own line', function () {
    // Rule 7's "or empty the body when it is the sole content" branch. Here the
    // entry's own physical line ALSO carries the anchor and the closing `];`, so
    // the per-line equality check in rule 6 cannot pass — deleting "its own
    // line" would delete the anchor too. Because the entry is nonetheless the
    // array's entire content, the writer clears just the span between the
    // brackets instead of refusing. Counterfactual: drop this fallback and rely
    // on the per-line check alone, and this reports EntryAmbiguous against a
    // file with no sibling at all.
    $directory = removalFixtureDirectory();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = $directory.'/inline-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($path, <<<'PHP'
    <?php

    namespace App\Providers;

    class NodeflowServiceProvider
    {
        protected array $nodes = [\App\Nodeflow\Nodes\SendMessage::class];
    }
    PHP);

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::Removed);

    $contents = file_get_contents($path);

    expect($contents)->not->toContain('SendMessage');
    expect($contents)->toContain('protected array $nodes = [];');
    expectParseablePhp($path);
});

it('removes an entry with legal whitespace around the :: operator', function () {
    // Review Critical, row 2. `SendMessage :: class` and `SendMessage::class`
    // are the SAME token sequence with T_WHITESPACE tokens interposed — PHP
    // does not care, and neither should this. Counterfactual: match by
    // trimmed string equality against a fixed "Name::class" literal instead
    // of by token sequence, and this fails.
    $path = providerForRemoval(
        '        SendMessage :: class,',
        'use App\Nodeflow\Nodes\SendMessage;',
    );

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::Removed);

    $contents = file_get_contents($path);

    // Exactly one surviving mention: the `use` import. The array entry itself
    // is gone.
    expect(substr_count($contents, 'SendMessage'))->toBe(1);
    expect($contents)->not->toContain('::');
    expectParseablePhp($path);
});

it('removes an entry with a newline between the name and ::class', function () {
    // Review Critical, row 3. Same reasoning as the spaced-colon test above,
    // with the whitespace token being a newline rather than a space.
    $path = providerForRemoval(
        "        SendMessage\n        ::class,",
        'use App\Nodeflow\Nodes\SendMessage;',
    );

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::Removed);

    $contents = file_get_contents($path);

    expect(substr_count($contents, 'SendMessage'))->toBe(1);
    expect($contents)->not->toContain('::');
    expectParseablePhp($path);
});

it('refuses rather than reporting NotPresent when a live registration is written as a class-string literal', function () {
    // Review Critical, row 1, and the asymmetry note: a class-string literal
    // `'App\…\SendMessage'` IS a real, live registration — a caller seeing
    // NotPresent here would conclude it is safe to delete the SendMessage
    // class file, and it is not. This writer's chosen resolution is to
    // REFUSE it as EntryUnsupported rather than attempt to remove it: parsing
    // what a PHP string literal actually denotes (single- vs double-quoted
    // escape rules, `\\`, interpolation) is a correctness-sensitive job
    // PhpNameResolver was never built for, and a wrong guess there is worse
    // than a refusal a human can act on manually. Counterfactual: treat
    // anything not matching `<name>::class` as simply skipped rather than
    // unsupported, and this reports NotPresent instead, silently authorising
    // the delete.
    $path = providerForRemoval("        'App\\\\Nodeflow\\\\Nodes\\\\SendMessage',");

    $before = file_get_contents($path);
    $outcome = remove($path, 'App\Nodeflow\Nodes\SendMessage');

    expect($outcome)->not->toBe(NodeRemovalOutcome::NotPresent);
    expect($outcome)->toBe(NodeRemovalOutcome::EntryUnsupported);
    expect(file_get_contents($path))->toBe($before);
});

it('refuses rather than reporting NotPresent when a live registration is aliased through a class constant', function () {
    // Review Critical, row 4. `self::SMS` is a live registration whenever
    // `const SMS = SendMessage::class;` exists — this writer cannot see
    // through a class-constant fetch (that requires evaluating the
    // constant's own initialiser, not just resolving a written name), so it
    // refuses rather than guess. Counterfactual: accept any three-significant-
    // token element as a class reference regardless of whether the LAST
    // token is literally the `class` keyword, and this reports NotPresent
    // (`SMS` is not `class`) after silently mis-treating `self::SMS` as some
    // other, wrong resolution instead of refusing.
    $directory = removalFixtureDirectory();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = $directory.'/const-alias-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($path, <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;
    use App\Nodeflow\Nodes\SendMessage;

    class NodeflowServiceProvider extends ServiceProvider
    {
        const SMS = SendMessage::class;

        protected array $nodes = [
            self::SMS,
        ];
    }
    PHP);

    $before = file_get_contents($path);
    $outcome = remove($path, 'App\Nodeflow\Nodes\SendMessage');

    expect($outcome)->not->toBe(NodeRemovalOutcome::NotPresent);
    expect($outcome)->toBe(NodeRemovalOutcome::EntryUnsupported);
    expect(file_get_contents($path))->toBe($before);
});

it('refuses rather than reporting NotPresent when the array contains a spread element', function () {
    // Review Critical, row 5. `...$more` cannot resolve to anything at all
    // without evaluating a runtime variable — refusing is the only honest
    // answer. Counterfactual: a classifier that only checks "does the element
    // end in a `class` keyword" without also requiring a `::` immediately
    // before it and a name before that would let this 2-significant-token
    // element slip through unclassified and silently skipped rather than
    // flagged.
    $path = providerForRemoval(
        "        \App\Nodeflow\Nodes\TagUser::class,\n        ...\$more,",
    );

    $before = file_get_contents($path);
    $outcome = remove($path, 'App\Nodeflow\Nodes\SendMessage');

    expect($outcome)->not->toBe(NodeRemovalOutcome::NotPresent);
    expect($outcome)->toBe(NodeRemovalOutcome::EntryUnsupported);
    expect(file_get_contents($path))->toBe($before);
});
