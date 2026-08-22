<?php

use Nodeflow\Console\NodeReferenceScanner;

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/nodeflow-reference-scanner-*') as $dir) {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

/**
 * Writes each path => contents pair under a fresh temp root and returns the
 * root, standing in for a host application's directory tree.
 */
function hostWith(array $files): string
{
    $root = sys_get_temp_dir().'/nodeflow-reference-scanner-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);

    foreach ($files as $path => $contents) {
        $full = $root.'/'.$path;

        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }

        file_put_contents($full, $contents);
    }

    return $root;
}

function target(): string
{
    return 'App\Nodeflow\Nodes\SendMessage';
}

it('accepts a target FQCN argument written with a leading backslash', function () {
    // round-3 review, IMPORTANT: `ltrim($fqcn, '\\')` on scan()'s OWN
    // target argument, not the string-literal comparison value tested
    // elsewhere. A caller writing `scan('\App\Nodeflow\Nodes\SendMessage',
    // ...)` -- a common way to spell a class name, e.g. copied straight
    // from a `::class` constant's own leading backslash -- must be
    // stripped the same way the target passed to string/name comparisons
    // already is internally, or NOTHING would ever match: every FQCN this
    // scanner computes internally (from PhpNameResolver::resolve(), from
    // an import lookup) never carries a leading backslash, so an
    // unstripped target with one could never equal any of them.
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        class Foo
        {
            public function bar(): string
            {
                return \App\Nodeflow\Nodes\SendMessage::class;
            }
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan('\App\Nodeflow\Nodes\SendMessage', [$root.'/app']);

    expect($found)->toHaveCount(1);
});

it('finds a fully-qualified reference', function () {
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        class Foo
        {
            public function bar(): string
            {
                return \App\Nodeflow\Nodes\SendMessage::class;
            }
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('class_constant');
    expect($found[0]->line)->toBe(7);
});

it('finds a bare short name behind an import', function () {
    // BRIEF CONTRADICTION, resolved in favour of the mandatory Step 4
    // counterfactual (see the E45 test below): that test's provider fixture
    // has a PLAIN, unaliased `use App\Nodeflow\Nodes\SendMessage;` import
    // AND expects it to count as its own reference (its comment says so
    // explicitly: "the import, the $nodes entry, and the legacy register()
    // entry: three distinct spans"). The brief's own table states "1
    // reference" for this row's identical shape (plain import + matching
    // usage), which cannot both be true under one consistent rule. Kept
    // consistent with the mandatory, literally-executed Step 4 snippet:
    // every `use` statement whose FQCN equals the target is its own
    // `import` reference, so this fixture reports 2 (import + constant),
    // not 1.
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\SendMessage;

        class Foo
        {
            public function bar(): string
            {
                return SendMessage::class;
            }
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->kind, $found))->toEqualCanonicalizing(['import', 'class_constant']);
});

it('finds a member of a group import', function () {
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\{SendMessage, TagUser};

        class Foo
        {
            public function bar(): string
            {
                return SendMessage::class;
            }
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->kind, $found))->toEqualCanonicalizing(['import', 'class_constant']);
});

it('isolates a group members own byte range, not the groups shared prefix', function () {
    // Mutation-found gap: discarding the shared prefix on a group's `{`
    // doesn't change WHICH statement matches (the lookup key is only ever
    // the last path segment, prefix-invariant either way), so no earlier
    // test caught removing it. It DOES change the recorded byte range:
    // without discarding, byteStart stays at the start of the prefix, and
    // the isolated text becomes 'App\Nodeflow\Nodes\{SendMessage' — the
    // literal `{` included — instead of just the member's own name.
    $contents = <<<'PHP'
    <?php

    namespace App\Providers;

    use App\Nodeflow\Nodes\{SendMessage, TagUser};
    PHP;

    $root = hostWith(['app/Foo.php' => $contents]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(1);
    $isolated = substr($contents, $found[0]->byteStart, $found[0]->byteEnd - $found[0]->byteStart);
    expect($isolated)->toBe('SendMessage');
});

it('finds an aliased usage', function () {
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\SendMessage as Sender;

        class Foo
        {
            public function bar(): string
            {
                return Sender::class;
            }
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->kind, $found))->toEqualCanonicalizing(['import', 'class_constant']);
});

it('finds a namespace-relative name', function () {
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Nodeflow;

        class Foo
        {
            public function bar(): string
            {
                return Nodes\SendMessage::class;
            }
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('class_constant');
});

it('ignores another class with the same short name', function () {
    // THE load-bearing test: what stops the scan refusing legitimate work in
    // any codebase that happens to contain a second SendMessage. Both the
    // import and the usage below resolve to App\Sms\SendMessage, never to
    // the target App\Nodeflow\Nodes\SendMessage — a substring match on
    // "SendMessage::class" would wrongly find this; resolver-based matching
    // does not.
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Sms;

        use App\Sms\SendMessage;

        class Foo
        {
            public function bar(): string
            {
                return SendMessage::class;
            }
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(0);
});

it('ignores a bare name that resolves to the files own namespace', function () {
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Other;

        class Foo
        {
            public function bar(): string
            {
                return SendMessage::class;
            }
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(0);
});

it('finds the FQCN inside a string literal', function () {
    // THE other load-bearing test: a reference that genuinely breaks on the
    // move (a class name stored as a plain string) and that a `::class`-only
    // scan would miss entirely.
    $root = hostWith([
        'config/nodeflow.php' => <<<'PHP'
        <?php

        return [
            'node' => 'App\Nodeflow\Nodes\SendMessage',
        ];
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/config']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('string_literal');
});

it('unescapes a single-quoted literal before matching, not merely stripping quotes', function () {
    // Mutation-found gap: returning the string's inner bytes unchanged
    // (skipping the `\\'` / `\\\\` unescape) left this class's suite green
    // for the plain-backslash row above, because that fixture never
    // contains a DOUBLE backslash. A host may still write the escaped form
    // — `'App\\Nodeflow\\Nodes\\SendMessage'`, each `\\` unescaping to one
    // `\` — and this must resolve to the same target.
    $root = hostWith([
        'config/nodeflow.php' => <<<'PHP'
        <?php

        return [
            'node' => 'App\\Nodeflow\\Nodes\\SendMessage',
        ];
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/config']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('string_literal');
});

it('finds a class_alias target as a string literal, not a dedicated kind', function () {
    // Rule 4: class_alias()'s first argument is a string literal, already
    // caught by the generic string_literal scan. Asserted here rather than
    // special-cased — there is no separate `class_alias` kind.
    $root = hostWith([
        'app/legacy.php' => <<<'PHP'
        <?php

        class_alias('App\Nodeflow\Nodes\SendMessage', 'Legacy');
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('string_literal');
});

it('finds an extends behind an import', function () {
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\SendMessage;

        class Special extends SendMessage
        {
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->kind, $found))->toEqualCanonicalizing(['import', 'extends']);
});

it('ignores a reference inside a comment', function () {
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        // \App\Nodeflow\Nodes\SendMessage::class
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(0);
});

it('ignores a reference inside a docblock', function () {
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        /** @see \App\Nodeflow\Nodes\SendMessage */
        class Foo
        {
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(0);
});

it('scans every configured root, not just app', function () {
    $reference = <<<'PHP'
    <?php

    return \App\Nodeflow\Nodes\SendMessage::class;
    PHP;

    $root = hostWith([
        'config/nodeflow.php' => $reference,
        'routes/web.php' => $reference,
        'database/seeders/NodeSeeder.php' => $reference,
        'bootstrap/providers.php' => $reference,
        'resources/nodeflow.php' => $reference,
    ]);

    $found = NodeReferenceScanner::scan(target(), [
        $root.'/config',
        $root.'/routes',
        $root.'/database',
        $root.'/bootstrap',
        $root.'/resources',
    ]);

    expect($found)->toHaveCount(5);
});

it('records a byte range that isolates the reference', function () {
    $contents = <<<'PHP'
    <?php

    class Foo
    {
        public function bar(): string
        {
            return \App\Nodeflow\Nodes\SendMessage::class;
        }
    }
    PHP;

    $root = hostWith(['app/Foo.php' => $contents]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(1);

    $reference = $found[0];
    $isolated = substr($contents, $reference->byteStart, $reference->byteEnd - $reference->byteStart);

    expect($isolated)->toBe('\App\Nodeflow\Nodes\SendMessage');
});

it('records a byte range that isolates a bounded-substring match, not the whole token', function () {
    // round-4 review, G2. E45's ENTIRE mechanism -- exemption per SPAN, not
    // per file -- depends on the byte range actually isolating the
    // matched text. Both `$token['start'] + $pos` -> `$token['start']` and
    // `$token['start'] + $after` -> `$token['end']` survive the full suite
    // otherwise, because every prior Blade/heredoc fixture put its match
    // at the very start of the token or let it run to the token's very
    // end. Real text surrounds the match on BOTH sides here specifically
    // to make either mutation observable.
    $contents = "<div>prefix text {{ \\App\\Nodeflow\\Nodes\\SendMessage::class }} suffix text</div>\n";

    $root = hostWith(['resources/view.blade.php' => $contents]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/resources']);

    expect($found)->toHaveCount(1);

    $reference = $found[0];
    $isolated = substr($contents, $reference->byteStart, $reference->byteEnd - $reference->byteStart);

    expect($isolated)->toBe('App\Nodeflow\Nodes\SendMessage');
});

it('still finds a class_constant reference across a comment sitting between the name and ::class', function () {
    // Mutation-found gap: dropping T_COMMENT from IGNORED_TOKEN_IDS left the
    // whole suite green, because a comment's own token id never matches
    // NAME_TOKEN_IDS/T_DOUBLE_COLON/T_CLASS regardless of whether it is
    // filtered — nothing before this test exercised a comment sitting
    // BETWEEN the pieces of a `<name>::class` expression, where an
    // unfiltered comment token breaks the adjacency check that looks at
    // "the token right after the name" and "the token right after that".
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        class Foo
        {
            public function bar(): string
            {
                return \App\Nodeflow\Nodes\SendMessage /* trailing */ ::class;
            }
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('class_constant');
});

it('still finds a class_constant reference across a docblock sitting between :: and class', function () {
    // Same gap, the T_DOC_COMMENT half of it: a docblock (not a plain
    // T_COMMENT) sitting between `::` and `class` must not break the check
    // either.
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        class Foo
        {
            public function bar(): string
            {
                return \App\Nodeflow\Nodes\SendMessage:: /** @phpstan-ignore-next-line */ class;
            }
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('class_constant');
});

it('ignores a use function import even when its path matches the target', function () {
    // `use function` and `use const` import a symbol, not a class — the
    // scanner must skip the whole statement, not treat its path as a class
    // import.
    //
    // Round-2 mutation sweep found this row alone does NOT discriminate the
    // T_FUNCTION/T_CONST skip once matching moved to a PhpNameResolver::
    // imports() lookup (see parseUseStatement()'s docblock): with the skip
    // REMOVED, this fixture still resolves to 0 references, because
    // PhpNameResolver's OWN readImports() already excludes `use function`
    // from its map, and nothing else in THIS file collides with the key
    // "sendmessage". The next test below, with a REAL class import present
    // under the same short name, is what actually catches the guard's
    // removal — kept here anyway as the more readable, single-purpose
    // statement of the rule.
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use function App\Nodeflow\Nodes\SendMessage;

        class Foo
        {
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(0);
});

it('ignores a use const import even when its path matches the target', function () {
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use const App\Nodeflow\Nodes\SendMessage;

        class Foo
        {
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(0);
});

it('does not let a same-named use function statement borrow a real class imports match', function () {
    // THE test that actually discriminates the T_FUNCTION/T_CONST skip.
    // `use App\Nodeflow\Nodes\SendMessage;` (a real class import) and `use
    // function Foo\Bar\SendMessage;` (an unrelated function) are both legal
    // in the same file — PHP keeps class and function `use` imports in
    // separate symbol tables, so the short names may collide. Without the
    // skip, parseUseStatement() walks the function import's own tokens too,
    // computes the SAME lookup key ("sendmessage"), and finds it in
    // PhpNameResolver::imports() -- populated by the REAL class import --
    // producing a bogus `import` reference whose byte range points at
    // `Foo\Bar\SendMessage`, not at the actual import at all.
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\SendMessage;
        use function Foo\Bar\SendMessage;

        function test()
        {
            return SendMessage::class;
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    // The real import and the class_constant fetch -- nothing from the
    // unrelated `use function` statement.
    expect($found)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->kind, $found))->toEqualCanonicalizing(['import', 'class_constant']);
});

it('refuses a file declaring two namespaces', function () {
    // Task 3's stated limit: PhpNameResolver reads only the first namespace
    // block and merges imports() into one flat map across blocks, which is
    // wrong for a file with more than one. Refusing here, rather than
    // resolving against the wrong block's imports, is what keeps that
    // resolver correct in every case this scanner ever hands it.
    $root = hostWith([
        'app/TwoNamespaces.php' => <<<'PHP'
        <?php

        namespace A {
        }

        namespace B {
        }
        PHP,
    ]);

    expect(fn () => NodeReferenceScanner::scan(target(), [$root.'/app']))
        ->toThrow(RuntimeException::class);

    try {
        NodeReferenceScanner::scan(target(), [$root.'/app']);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain($root.'/app/TwoNamespaces.php');
    }
});

it('DOES scan through a symlink nested inside a scan root, finding a reference in its target (review round 4, item B)', function () {
    // Reversed from an earlier design: this test used to assert the
    // OPPOSITE (skipping such a symlink entirely), on the theory that a
    // symlink escaping the root should never be trusted. The review round
    // 4 found the real failure that design caused: `app/Linked` symlinked
    // to a directory outside the host, declaring
    // `App\Linked\Consumer` and referencing the node under extraction, is
    // genuinely autoloadable by the host (PSR-4: `App\` -> `app/`), but
    // was invisible to both G5 and M6a under the old filter -- extraction
    // would delete the original and leave the host loading a class that
    // no longer exists. A TOP-LEVEL scan root that is itself an escaping
    // symlink is still refused before it ever reaches this class
    // (ExtractNodeCommand's own sharedScanRoots()/hostPsr4Directories());
    // this test is about a symlink NESTED inside an otherwise legitimate
    // root, which this scanner must now follow.
    $root = hostWith(['app/.keep' => '']);
    $outside = hostWith([
        'Escaped.php' => <<<'PHP'
        <?php

        return \App\Nodeflow\Nodes\SendMessage::class;
        PHP,
    ]);

    symlink($outside, $root.'/app/escape');

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(1);
    expect($found[0]->file)->toBe($root.'/app/escape/Escaped.php');
});

it('refuses loudly, naming the path, when a symlink forms a cycle rather than recursing forever', function () {
    $root = hostWith(['app/.keep' => '']);

    // app/loop -> app itself: following it would walk app/, then
    // app/loop/, then app/loop/loop/, forever.
    symlink($root.'/app', $root.'/app/loop');

    expect(fn () => NodeReferenceScanner::scan(target(), [$root.'/app']))
        ->toThrow(RuntimeException::class);

    try {
        NodeReferenceScanner::scan(target(), [$root.'/app']);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain($root.'/app/loop');
    }
});

it('refuses loudly, naming the path, when a symlink target cannot be resolved at all', function () {
    $root = hostWith(['app/.keep' => '']);

    symlink($root.'/app/does-not-exist', $root.'/app/broken');

    expect(fn () => NodeReferenceScanner::scan(target(), [$root.'/app']))
        ->toThrow(RuntimeException::class);

    try {
        NodeReferenceScanner::scan(target(), [$root.'/app']);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain($root.'/app/broken');
    }
});

it('accepts a single FILE as a scan root, not only a directory (review round 4, item A)', function () {
    // A loose *.php file at the host root (e.g. rector.php) has no
    // containing directory this class was ever told to walk -- accepting
    // a file root directly is what lets ExtractNodeCommand's own
    // sharedScanRoots() cover it.
    $root = hostWith([
        'rector.php' => <<<'PHP'
        <?php

        return \App\Nodeflow\Nodes\SendMessage::class;
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/rector.php']);

    expect($found)->toHaveCount(1);
    expect($found[0]->file)->toBe($root.'/rector.php');
});

it('ignores a FILE root whose extension is not one of the scannable ones', function () {
    $root = hostWith([
        'rector.json' => 'return \App\Nodeflow\Nodes\SendMessage::class;',
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/rector.json']);

    expect($found)->toHaveCount(0);
});

it('reports a legacy register() call in the provider as a reference the provider rewrite does not cover', function () {
    // E45. The first design draft exempted whole FILES the command rewrites. The
    // provider IS such a file (M5 edits $nodes and the import), so this reference
    // was exempted rather than refused — the exact case the scan existed to catch,
    // and it leaves the host fatal at boot.
    //
    // Counterfactual, and it must be EXECUTED: filter the scan's results by
    // "is this file in the rewrite set" and this test passes while the host
    // breaks. Filter by "is this BYTE RANGE in the rewrite set" and it fails
    // correctly.
    $root = hostWith([
        'app/Providers/NodeflowServiceProvider.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\SendMessage;
        use Nodeflow\Nodeflow;

        class NodeflowServiceProvider
        {
            protected array $nodes = [
                SendMessage::class,
            ];

            public function boot(): void
            {
                Nodeflow::register([
                    SendMessage::class,
                ]);
            }
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan('App\Nodeflow\Nodes\SendMessage', [$root.'/app']);

    // The import, the $nodes entry, and the legacy register() entry: three
    // distinct spans in ONE file.
    expect($found)->toHaveCount(3);
    expect(array_unique(array_map(fn ($r) => $r->file, $found)))->toHaveCount(1);
});

// --- Round-3 review fix: Critical 1, a closure capture list clobbering a
// real import (shared root cause with PhpNameResolverTest.php). ---

it('does not let a closure capture list swallow a real import and the reference after it', function () {
    // round-3 review, Critical 1. Two lint-clean, idiomatic routes/web.php
    // shapes, both given by the reviewer verbatim. Before the fix,
    // parseUseStatement() read the closure's captured variable ($router,
    // $prefix) as if it were importing an alias, and because that read
    // shares the SAME lookup map as the real `use
    // App\Nodeflow\Nodes\SendMessage;` import, it silently clobbered that
    // real entry -- confirmed: this fixture returned 0 references before
    // the fix, on `routes/`, one of the scanned roots, which is exactly
    // the miss-then-boot-fatal path the gate exists to prevent.
    $root = hostWith([
        'routes/web1.php' => <<<'PHP'
        <?php

        use App\Nodeflow\Nodes\SendMessage;

        Route::get('/x', function () use ($router) { return new SendMessage(); });
        PHP,
        'routes/web2.php' => <<<'PHP'
        <?php

        use App\Nodeflow\Nodes\SendMessage;

        Route::middleware(['web'])->group(function () use ($prefix) {
            Route::get('/', [SendMessage::class, 'handle']);
        });
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/routes']);

    expect($found)->toHaveCount(4);
    expect(array_map(fn ($r) => $r->kind, $found))
        ->toEqualCanonicalizing(['import', 'reference', 'import', 'class_constant']);
});

// --- Round-2 review fixes below: Critical 1 (braced-namespace brace-kind
// bug), Critical 2 (universal detection), and the four surviving mutants. ---

it('pops the brace-kind stack when a function body closes, so a later top-level use is still an import', function () {
    // round-3 review, IMPORTANT: `array_pop($braceKinds)` on `}`. Without
    // it, an empty function body's `{` pushes 'other' and it is NEVER
    // popped, so braceKinds stays ['other'] for the REST of the file --
    // and a top-level `use` declared AFTER that function is wrongly read
    // as if it were inside a class body, so parseUseStatement() is never
    // called for it and its own `import` reference is lost. The later
    // `SendMessage::class` usage still resolves correctly regardless,
    // because it goes through PhpNameResolver's OWN, separately-correct
    // import tracking, not this scanner's -- which is exactly why the
    // count alone (2 either way once resolve() rescues one occurrence
    // through the universal name-run scan in the no-namespace case) is
    // not always enough to catch this; asserting the KIND is.
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        function g()
        {
        }

        use App\Nodeflow\Nodes\SendMessage;

        $x = SendMessage::class;
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->kind, $found))->toEqualCanonicalizing(['import', 'class_constant']);
});

it('finds a use import inside a braced namespace, not just a bracket-free one', function () {
    // round-2 review, Critical 1. namespaceBraceIndexes() used
    // `($meaningful[$j]['id'] ?? false) === null`, and `??` only yields its
    // right-hand side when the LEFT is null -- which a `{` token's `id`
    // always is, so the condition could never be true. That made the
    // helper always return [], which made every namespace brace classify
    // as 'other', which made scanImports() (as it was then) discard every
    // `use` inside a braced namespace. Verified against the reviewer's own
    // example.
    $root = hostWith([
        'app/Braced.php' => <<<'PHP'
        <?php

        namespace App\Providers {
            use App\Nodeflow\Nodes\SendMessage;
            class P
            {
                public function boot(): void
                {
                    (new SendMessage())->handle();
                }
            }
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->kind, $found))->toEqualCanonicalizing(['import', 'reference']);
});

it('catches new and a static call in the same file that only ::class and the import were caught before', function () {
    // round-2 review, Critical 2 -- "the case that matters". The first cut
    // of this scanner detected exactly four syntactic shapes and missed
    // every other bare use of a class name. A host whose provider carries
    // the import, the $nodes entry, AND a legacy `new SendMessage();
    // SendMessage::warmUp();` pair reported only 2 references before this
    // fix -- both already inside the rewrite set -- so the gate passed
    // while the host still fatals on `new SendMessage()` after the move.
    // Detection is now universal: every name-run that resolves to the
    // target is a reference, regardless of what syntax surrounds it.
    $root = hostWith([
        'app/Providers/NodeflowServiceProvider.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\SendMessage;

        class NodeflowServiceProvider
        {
            protected array $nodes = [
                SendMessage::class,
            ];

            public function boot(): void
            {
                $p = new SendMessage();
                SendMessage::warmUp();
            }
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(4);
    expect(array_map(fn ($r) => $r->kind, $found))
        ->toEqualCanonicalizing(['import', 'class_constant', 'reference', 'reference']);
});

it('finds a bare name behind an instanceof check', function () {
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\SendMessage;

        function check($x)
        {
            return $x instanceof SendMessage;
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->kind, $found))->toEqualCanonicalizing(['import', 'reference']);
});

it('finds a bare name behind a catch clause', function () {
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\SendMessage;

        function run()
        {
            try {
                // ...
            } catch (SendMessage $e) {
                // ...
            }
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->kind, $found))->toEqualCanonicalizing(['import', 'reference']);
});

it('finds a bare name behind a typed property', function () {
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\SendMessage;

        class Holder
        {
            public SendMessage $node;
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->kind, $found))->toEqualCanonicalizing(['import', 'reference']);
});

it('finds a bare name behind a parameter type and a return type', function () {
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\SendMessage;

        function handle(SendMessage $node): SendMessage
        {
            return $node;
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    // Two SendMessage occurrences (the parameter type and the return type),
    // plus the import.
    expect($found)->toHaveCount(3);
    expect(array_map(fn ($r) => $r->kind, $found))->toEqualCanonicalizing(['import', 'reference', 'reference']);
});

it('finds a bare name behind implements, labelled the generic kind, not extends', function () {
    // round-2 ruling: implements is subsumed by the universal rule, not
    // given its own kind.
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\SendMessage;

        class Foo implements SendMessage
        {
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->kind, $found))->toEqualCanonicalizing(['import', 'reference']);
});

it('resets the extends clause before a later implements, so implements is not mislabelled extends', function () {
    // Proves the clause-tracking state machine correctly hands off from an
    // extends list to an implements list rather than leaking 'extends'
    // across the boundary.
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\SendMessage;

        class Base
        {
        }

        class Foo extends Base implements SendMessage
        {
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->kind, $found))->toEqualCanonicalizing(['import', 'reference']);
});

it('does not leak the extends clause past the extends list that ended it, with no implements at all', function () {
    // Mutation-found gap: removing the "next token isn't a comma" reset
    // left every existing test green, because every OTHER fixture with an
    // extends clause is followed by either `implements` (which the
    // DEDICATED T_IMPLEMENTS branch also resets on its own, masking this
    // one) or nothing later in the file that would expose a leak. Without
    // this reset, `clauseKind` stays 'extends' PERMANENTLY once set — so a
    // completely unrelated `new SendMessage()` appearing later in the same
    // file, after `class Foo extends Base {}` has already closed, would be
    // mislabelled `extends` instead of the generic `reference` kind.
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\SendMessage;

        class Base
        {
        }

        class Foo extends Base
        {
        }

        function make()
        {
            return new SendMessage();
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(2);
    expect(array_map(fn ($r) => $r->kind, $found))->toEqualCanonicalizing(['import', 'reference']);
});

it('resets the extends/implements clause even when extends names nothing at all', function () {
    // Mutation-found gap, defensive rather than reachable through valid
    // PHP: `php -l` rejects `class A extends implements Target {}` (no
    // name after `extends`), but token_get_all() is a lenient lexer that
    // tokenises it anyway -- the same lenient posture PhpNameResolver's own
    // docblock claims ("must degrade gracefully rather than throwing on
    // adversarial or slightly-off input"). With NO name-run between
    // `extends` and `implements`, the ordinary "next-token-isn't-a-comma"
    // reset never fires, so ONLY the dedicated reset inside the
    // T_IMPLEMENTS branch stops `Target` from being mislabelled `extends`.
    $root = hostWith([
        'app/Malformed.php' => <<<'PHP'
        <?php

        class A extends implements \App\Nodeflow\Nodes\SendMessage
        {
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('reference');
});

it('does not classify a static method call as class_constant', function () {
    // The IMPORTANT mutation the round-2 review named directly: removing
    // the `$class['id'] !== T_CLASS` guard was previously untested because
    // nothing exercised `Name::method()` at all. Under universal detection
    // this is no longer merely "untested" -- a removed guard would
    // misclassify a real, now-caught reference, not just miss one.
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\SendMessage;

        function warm()
        {
            SendMessage::warmUp();
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(2);
    $kinds = array_map(fn ($r) => $r->kind, $found);
    expect($kinds)->toEqualCanonicalizing(['import', 'reference']);
    expect($kinds)->not->toContain('class_constant');
});

it('does not classify an instanceof check followed by an unrelated class declaration as class_constant', function () {
    // round-3 review, IMPORTANT: the T_DOUBLE_COLON half of classify()'s
    // guard, discriminated from the T_CLASS half above. `$y instanceof
    // SendMessage;` is immediately followed by `class D {}` -- with only
    // the T_CLASS half checked (ignoring what the token right after the
    // name-run actually is), the statement's own terminating `;` is
    // accepted as if it were `::`, and the `class` keyword starting the
    // NEXT, unrelated declaration is accepted as `::class`'s own `class`.
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Nodeflow\Nodes\SendMessage;

        $b = $y instanceof SendMessage;

        class D
        {
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(2);
    $kinds = array_map(fn ($r) => $r->kind, $found);
    expect($kinds)->toEqualCanonicalizing(['import', 'reference']);
    expect($kinds)->not->toContain('class_constant');
});

it('finds a target that is not first in a comma-separated extends list', function () {
    // IMPORTANT mutation #4: the old scanExtends()'s comma-walk survived a
    // `break` mutation because nothing put the target second in the list.
    // Detection is universal now (this is no longer a special-cased walk
    // that could "break" early at all), but the row is re-derived and kept
    // as its own persisted proof per the reviewer's instruction.
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace App\Providers;

        interface I extends \Other\T, \App\Nodeflow\Nodes\SendMessage
        {
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('extends');
});

it('excludes the target class own declaration name from being treated as a reference', function () {
    // Universal detection means the scanner sees this file whenever it
    // scans the class's OWN declaring file -- which normally lives under
    // app/, one of the roots this scanner is handed, so this is close to
    // guaranteed on every real invocation, not a corner case. Without
    // excluding a class/interface/trait/enum's own declared name, `class
    // SendMessage extends Base` would resolve to the target FQCN itself
    // and get flagged as a `reference` in the very file being moved.
    $root = hostWith([
        'app/Nodeflow/Nodes/SendMessage.php' => <<<'PHP'
        <?php

        namespace App\Nodeflow\Nodes;

        class SendMessage extends Base
        {
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(0);
});

it('excludes a namespace declarations own name from being treated as a reference', function () {
    // Mutation-found gap: removing the namespace-name skip left every
    // OTHER test green, because resolve() on a namespace's own written
    // name doubles it against ITSELF ("Foo" resolves to "Foo\Foo" inside
    // `namespace Foo;`), which only accidentally equals a REAL target FQCN
    // when the class's short name matches its own single-segment
    // namespace -- an unusual but entirely legal name to extract.
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        namespace SendMessage;

        class Other
        {
        }
        PHP,
    ]);

    $found = NodeReferenceScanner::scan('SendMessage\SendMessage', [$root.'/app']);

    expect($found)->toHaveCount(0);
});

it('finds the FQCN inside a heredoc body', function () {
    $root = hostWith([
        'config/nodeflow.php' => <<<'PHP'
        <?php

        return [
            'node' => <<<TXT
        App\Nodeflow\Nodes\SendMessage
        TXT,
        ];
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/config']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('string_literal');
});

it('finds the FQCN inside a nowdoc body', function () {
    $root = hostWith([
        'config/nodeflow.php' => <<<'PHP'
        <?php

        return [
            'node' => <<<'TXT'
        App\Nodeflow\Nodes\SendMessage
        TXT,
        ];
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/config']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('string_literal');
});

it('finds a double-quoted string literal written with escaped backslashes', function () {
    // IMPORTANT mutation #2: unquote()'s double-quoted branch surviving an
    // "always empty" mutation, because every previous fixture used a
    // single-quoted literal.
    $root = hostWith([
        'config/nodeflow.php' => <<<'PHP'
        <?php

        return [
            'node' => "App\\Nodeflow\\Nodes\\SendMessage",
        ];
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/config']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('string_literal');
});

it('finds a double-quoted string literal written with plain, unescaped backslashes', function () {
    // A REAL bug, not merely a mutation-testing gap, found while
    // investigating why the round-2 mutation sweep's heredoc-decoding
    // mutant went uncaught. unquote()'s double-quoted branch used
    // stripcslashes(), which strips the backslash from ANY unrecognised
    // escape, not only recognised ones:
    // `stripcslashes('App\Nodeflow\Nodes\SendMessage')` returns
    // `'AppNodeflowNodesSendMessage'` — every namespace separator deleted.
    // Real PHP does the opposite (verified by executing `$x =
    // "App\Nodeflow\Nodes\SendMessage"; var_dump($x);`, which prints the
    // string completely unchanged, since `\N` is not a recognised
    // double-quoted escape). This is the MORE natural way to write a
    // class-name string in double quotes — plain backslashes, no
    // doubling — and it was silently never found at all before this fix
    // (confirmed: the scanner returned zero references against exactly
    // this fixture beforehand).
    $root = hostWith([
        'config/nodeflow.php' => <<<'PHP'
        <?php

        return [
            'node' => "App\Nodeflow\Nodes\SendMessage",
        ];
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/config']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('string_literal');
});

it('finds a heredoc body written with escaped backslashes', function () {
    // The heredoc analogue of the bug above: a heredoc (unlike a nowdoc)
    // escapes like a double-quoted string, so `\\` folds to one literal
    // backslash. foldEscapedBackslash() must be the one doing that
    // folding, not stripcslashes(), for the same reason.
    $root = hostWith([
        'config/nodeflow.php' => <<<'PHP'
        <?php

        return [
            'node' => <<<TXT
        App\\Nodeflow\\Nodes\\SendMessage
        TXT,
        ];
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/config']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('string_literal');
});

it('finds a string literal with a leading backslash', function () {
    // IMPORTANT mutation #1: `ltrim($value, '\\')` -> `$value` surviving,
    // because every previous string-literal fixture omitted the leading
    // backslash.
    $root = hostWith([
        'config/nodeflow.php' => <<<'PHP'
        <?php

        return [
            'node' => '\App\Nodeflow\Nodes\SendMessage',
        ];
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/config']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('string_literal');
});

it('scans .phtml and .inc files, not just .php', function () {
    $root = hostWith([
        'resources/view.phtml' => "<?php\n\nreturn \\App\\Nodeflow\\Nodes\\SendMessage::class;\n",
        'app/legacy.inc' => "<?php\n\nreturn \\App\\Nodeflow\\Nodes\\SendMessage::class;\n",
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/resources', $root.'/app']);

    expect($found)->toHaveCount(2);
});

it('does not scan a file whose extension is outside php, blade.php, phtml, and inc', function () {
    // E46-adjacent stated limit: a reference spelled out only inside a
    // file with some other extension is out of reach, by design, not by
    // oversight.
    $root = hostWith([
        'app/notes.txt' => "<?php\n\nreturn \\App\\Nodeflow\\Nodes\\SendMessage::class;\n",
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(0);
});


// --- Round-3 review fix: Critical 2, Blade support via bounded substring
// matching, which also resolves the heredoc/nowdoc Important raised
// separately (an exact-body-equality rule missed the FQCN appearing inside
// a LARGER heredoc body). ---

it('records the correct line for a Blade match that is not on the tokens own first line', function () {
    // Mutation-found gap: using the T_INLINE_HTML token's own starting
    // line unconditionally (instead of adding the newline count before
    // the match's own position within that token's text) left every
    // existing Blade test green, because none of them put the match
    // anywhere but the token's first line.
    $root = hostWith([
        'resources/view.blade.php' => "<div>\n<p>\n<span>{{ \\App\\Nodeflow\\Nodes\\SendMessage::class }}</span>\n</p>\n</div>\n",
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/resources']);

    expect($found)->toHaveCount(1);
    expect($found[0]->line)->toBe(3);
});

it('finds an FQCN inside Blade double-curly output, with no <?php tag at all', function () {
    // round-3 review, Critical 2. A pure Blade template has no `<?php`
    // tag, so PHP's own tokeniser reads the WHOLE file as one
    // T_INLINE_HTML token -- verified directly against token_get_all()
    // before writing this test.
    $root = hostWith([
        'resources/view.blade.php' => "<div>{{ \\App\\Nodeflow\\Nodes\\SendMessage::class }}</div>\n",
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/resources']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('string_literal');
});

it('finds an FQCN inside an inline @php(...) Blade directive', function () {
    $root = hostWith([
        'resources/view.blade.php' => "<div>\n@php(\$x = \\App\\Nodeflow\\Nodes\\SendMessage::class)\n</div>\n",
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/resources']);

    expect($found)->toHaveCount(1);
});

it('finds an FQCN inside an @php ... @endphp Blade block', function () {
    $root = hostWith([
        'resources/view.blade.php' => "<div>\n@php\n\$x = \\App\\Nodeflow\\Nodes\\SendMessage::class;\n@endphp\n</div>\n",
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/resources']);

    expect($found)->toHaveCount(1);
});

it('finds an escaped-backslash FQCN spelling inside a Blade @php block', function () {
    // round-4 review, G1. T_INLINE_HTML IS PHP inside {{ ... }} and
    // @php(...)/@php...@endphp -- it carries ordinary PHP string escaping,
    // so a class name passed through app('App\\Nodeflow\\Nodes\\
    // SendMessage') (a real, idiomatic way to resolve a class by name)
    // must be found the same way the identical line already is inside a
    // real <?php block. Before this fix, scanBoundedText() searched
    // T_INLINE_HTML with the un-escaped needle alone and this fixture
    // returned 0.
    $root = hostWith([
        'resources/view.blade.php' => "<div>\n@php \$n = app('App\\\\Nodeflow\\\\Nodes\\\\SendMessage'); @endphp\n</div>\n",
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/resources']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('string_literal');
});

it('does not match a Blade FQCN that is a prefix of a longer name', function () {
    // The bounded half of "bounded substring": App\Nodeflow\Nodes\
    // SendMessage must not match inside ...SendMessageExtra.
    $root = hostWith([
        'resources/view.blade.php' => "<div>{{ \\App\\Nodeflow\\Nodes\\SendMessageExtra::class }}</div>\n",
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/resources']);

    expect($found)->toHaveCount(0);
});

it('does not match a Blade FQCN followed by an underscore', function () {
    $root = hostWith([
        'resources/view.blade.php' => "<div>{{ \\App\\Nodeflow\\Nodes\\SendMessage_Extra::class }}</div>\n",
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/resources']);

    expect($found)->toHaveCount(0);
});

it('does not match a Blade FQCN that is a prefix of a deeper, unrelated symbol', function () {
    // Mutation-found gap: dropping the `$afterChar === '\\'` half of the
    // boundary check (keeping only the identifier-character half) left
    // every existing test green, because none of them put a FURTHER
    // namespace separator right after the match. Without it,
    // App\Nodeflow\Nodes\SendMessage wrongly matches inside
    // App\Nodeflow\Nodes\SendMessage\Sub -- a different, deeper symbol,
    // not the target.
    $root = hostWith([
        'resources/view.blade.php' => "<div>{{ \\App\\Nodeflow\\Nodes\\SendMessage\\Sub::class }}</div>\n",
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/resources']);

    expect($found)->toHaveCount(0);
});

it('does not match an unrelated FQCN sharing the target short name in Blade', function () {
    $root = hostWith([
        'resources/view.blade.php' => "<div>{{ \\App\\Sms\\SendMessage::class }}</div>\n",
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/resources']);

    expect($found)->toHaveCount(0);
});

it('ignores a Blade reference written as a bare short name, a stated limit', function () {
    // Blade has no `use`/import mechanism this scanner could resolve a
    // short name against -- documented in the class docblock alongside
    // E46's dynamic-and-database-stored-names limit, not silently missed.
    $root = hostWith([
        'resources/view.blade.php' => "<div>{{ SendMessage::class }}</div>\n",
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/resources']);

    expect($found)->toHaveCount(0);
});

it('finds the FQCN as a substring inside a larger heredoc body, not only when the body equals it exactly', function () {
    // The Important the reviewer raised separately, resolved by the SAME
    // substring rule as Blade: `<<<PHP\nuse App\…\SendMessage;\nPHP`
    // returned 0 under the old exact-body-equality rule.
    $root = hostWith([
        'app/Foo.php' => <<<'PHP'
        <?php

        $template = <<<CODE
        use App\Nodeflow\Nodes\SendMessage;
        CODE;
        PHP,
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(1);
    expect($found[0]->kind)->toBe('string_literal');
});

// --- $excludedTopLevelNames (review round 3, mutation survivor 1) ---------

it('excludes a directory NAME only when it sits directly inside the scanned root, not at any deeper nesting', function () {
    // The central claim of the $excludedTopLevelNames parameter: deleting
    // the `$directory === $scanRoot` guard in scannableFilesUnder() would
    // apply the exclusion at EVERY depth, not just immediately inside the
    // root scan() was actually handed. A nested storage/foo/framework/
    // must still be scanned; only the TOP-LEVEL storage/framework/ (a
    // direct child of the root) is skipped.
    $root = hostWith([
        'storage/framework/Compiled.php' => "<?php\nnew \\App\\Nodeflow\\Nodes\\SendMessage();\n",
        'storage/foo/framework/Nested.php' => "<?php\nnew \\App\\Nodeflow\\Nodes\\SendMessage();\n",
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/storage'], ['framework']);

    expect($found)->toHaveCount(1);
    expect($found[0]->file)->toBe($root.'/storage/foo/framework/Nested.php');
});

it('excludes a directory NAME only for the root it was passed against, never a same-named directory reached via a DIFFERENT root', function () {
    // A second call, scanning app/framework/ through a SEPARATE root with
    // NO exclusion list of its own -- proving $excludedTopLevelNames is
    // scoped per scan() call, not a global name-based filter.
    $root = hostWith([
        'storage/framework/Compiled.php' => "<?php\nnew \\App\\Nodeflow\\Nodes\\SendMessage();\n",
        'app/framework/Real.php' => "<?php\nnew \\App\\Nodeflow\\Nodes\\SendMessage();\n",
    ]);

    $storageFound = NodeReferenceScanner::scan(target(), [$root.'/storage'], ['framework']);
    $appFound = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($storageFound)->toHaveCount(0);
    expect($appFound)->toHaveCount(1);
    expect($appFound[0]->file)->toBe($root.'/app/framework/Real.php');
});

it('defaults $excludedTopLevelNames to an empty list, so every existing scan() call site is unaffected', function () {
    $root = hostWith([
        'app/framework/Real.php' => "<?php\nnew \\App\\Nodeflow\\Nodes\\SendMessage();\n",
    ]);

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

    expect($found)->toHaveCount(1);
});
