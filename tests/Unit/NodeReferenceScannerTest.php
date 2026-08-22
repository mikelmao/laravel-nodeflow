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

it('does not scan through a symlink whose target escapes the root', function () {
    // Counterfactual for the HostPath::contains() filter: remove it and this
    // fails, because phpFilesUnder() itself follows the symlinked directory
    // (is_dir() returns true for it) to find the file inside.
    $root = hostWith(['app/.keep' => '']);
    $outside = hostWith([
        'Escaped.php' => <<<'PHP'
        <?php

        return \App\Nodeflow\Nodes\SendMessage::class;
        PHP,
    ]);

    symlink($outside, $root.'/app/escape');

    $found = NodeReferenceScanner::scan(target(), [$root.'/app']);

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

// --- Round-2 review fixes below: Critical 1 (braced-namespace brace-kind
// bug), Critical 2 (universal detection), and the four surviving mutants. ---

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
