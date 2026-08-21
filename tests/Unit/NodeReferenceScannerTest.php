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
    // Mutation-found gap: `use function` and `use const` import a symbol, not
    // a class — the scanner must skip the whole statement, not treat its
    // path as a class import. Removing that skip made this fixture (a
    // function import whose fully-qualified NAME happens to spell out the
    // target class's own FQCN) wrongly report an `import` reference.
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
