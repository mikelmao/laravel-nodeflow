<?php

use Nodeflow\Console\PhpNameResolver;

it('resolves a leading-backslash name to itself', function () {
    $r = PhpNameResolver::forSource("<?php\nnamespace App\\Providers;\n");

    expect($r->resolve('\App\Nodeflow\Nodes\SendMessage'))->toBe('App\Nodeflow\Nodes\SendMessage');
});

it('resolves a name with no leading backslash RELATIVE to the current namespace', function () {
    // THE finding that falsified the first draft's entry-form table, and it is
    // PHP's actual rule. Verified by probe:
    //   inside namespace App\Providers, App\Nodeflow\Nodes\SendMessage::class
    //   === 'App\Providers\App\Nodeflow\Nodes\SendMessage'
    // Counterfactual: return the written name unchanged and this fails — which
    // is exactly the bug that made removeFrom() delete the wrong entry.
    $r = PhpNameResolver::forSource("<?php\nnamespace App\\Providers;\n");

    expect($r->resolve('App\Nodeflow\Nodes\SendMessage'))
        ->toBe('App\Providers\App\Nodeflow\Nodes\SendMessage');
});

it('resolves a bare short name through a plain import', function () {
    $source = "<?php\nnamespace App\\Providers;\nuse App\\Nodeflow\\Nodes\\SendMessage;\n";

    expect(PhpNameResolver::forSource($source)->resolve('SendMessage'))
        ->toBe('App\Nodeflow\Nodes\SendMessage');
});

it('resolves an aliased import', function () {
    // The live-registration form the first draft's three-form table missed
    // entirely, so it read NotPresent and let extraction proceed to a fatal host.
    $source = "<?php\nnamespace App\\Providers;\nuse App\\Nodeflow\\Nodes\\SendMessage as Sender;\n";

    expect(PhpNameResolver::forSource($source)->resolve('Sender'))
        ->toBe('App\Nodeflow\Nodes\SendMessage');
});

it('resolves every member of a group import, aliases included', function () {
    $source = "<?php\nnamespace App\\Providers;\n"
        ."use App\\Nodeflow\\Nodes\\{SendMessage, TagUser as Tagger};\n";

    $r = PhpNameResolver::forSource($source);

    expect($r->resolve('SendMessage'))->toBe('App\Nodeflow\Nodes\SendMessage');
    expect($r->resolve('Tagger'))->toBe('App\Nodeflow\Nodes\TagUser');
});

it('resolves a qualified name whose first segment is an import', function () {
    $source = "<?php\nnamespace App\\Providers;\nuse App\\Nodeflow;\n";

    expect(PhpNameResolver::forSource($source)->resolve('Nodeflow\Nodes\SendMessage'))
        ->toBe('App\Nodeflow\Nodes\SendMessage');
});

it('resolves relative to the namespace when nothing is imported', function () {
    $source = "<?php\nnamespace App\\Nodeflow;\n";

    expect(PhpNameResolver::forSource($source)->resolve('Nodes\SendMessage'))
        ->toBe('App\Nodeflow\Nodes\SendMessage');
});

it('resolves against the global namespace when the file declares none', function () {
    // This is the case the shipped writer test was really reaching for: with no
    // namespace declaration, App\Nodeflow\Nodes\SendMessage IS the target.
    expect(PhpNameResolver::forSource("<?php\n")->resolve('App\Nodeflow\Nodes\SendMessage'))
        ->toBe('App\Nodeflow\Nodes\SendMessage');
});

it('ignores a use statement inside a closure', function () {
    // `function () use ($x)` is not an import. Counterfactual: match on T_USE
    // alone and this resolves a garbage alias.
    $source = "<?php\nnamespace App\\Providers;\n\$f = function () use (\$x) { return \$x; };\n";

    expect(PhpNameResolver::forSource($source)->imports())->toBe([]);
});

it('does not let a later closure capture list overwrite a real import sharing its short name', function () {
    // round-2/round-3 review, Critical 1. The 'other'-brace guard cannot see
    // a capture list, because a closure's `use` PRECEDES its own `{` --
    // at the point readImports() sees this `use`, it may not be inside any
    // brace at all (it can be inside a function call's parens instead, the
    // exact shape of `Route::get('/x', function () use ($router) { ... })`).
    // Before the fix, readOneUseStatement() read the closure's captured
    // variable as if it were importing an alias named "sendmessage" (the
    // variable's own name, lowercased) and OVERWROTE the real import's
    // entry in the SAME map -- confirmed: imports() came back empty
    // instead of containing the real import at all.
    $source = "<?php\nuse App\\Nodeflow\\Nodes\\SendMessage;\n"
        ."Route::get('/x', function () use (\$router) { return new SendMessage(); });\n";

    expect(PhpNameResolver::forSource($source)->imports())
        ->toBe(['sendmessage' => 'App\Nodeflow\Nodes\SendMessage']);
});

it('ignores a function import and a constant import', function () {
    $source = "<?php\nnamespace App\\Providers;\n"
        ."use function App\\Helpers\\send;\nuse const App\\Limits\\MAX;\n";

    expect(PhpNameResolver::forSource($source)->imports())->toBe([]);
});

it('ignores a trait use inside a class body', function () {
    $source = "<?php\nnamespace App\\Nodeflow\\Nodes;\nclass SendMessage { use HasNodeType; }\n";

    expect(PhpNameResolver::forSource($source)->imports())->toBe([]);
});

// --- Step 5 adversarial probes, persisted per F-2 ---

it('resolves a group import containing a single member', function () {
    // Probe 1: a group of one must still go through the group-parsing path.
    $source = "<?php\nnamespace App\\Providers;\nuse App\\Nodeflow\\Nodes\\{SendMessage};\n";

    expect(PhpNameResolver::forSource($source)->resolve('SendMessage'))
        ->toBe('App\Nodeflow\Nodes\SendMessage');
});

it('resolves a leading-backslash import to the same target as one without', function () {
    // Probe 2: `use \App\Nodeflow\Nodes\SendMessage;` — the leading backslash in
    // the import itself must not become part of the recorded FQCN.
    $source = "<?php\nnamespace App\\Providers;\nuse \\App\\Nodeflow\\Nodes\\SendMessage;\n";

    expect(PhpNameResolver::forSource($source)->resolve('SendMessage'))
        ->toBe('App\Nodeflow\Nodes\SendMessage');
});

it('lets the later of two case-differing aliases win, because alias lookup is case-insensitive', function () {
    // Probe 3: `Sender` and `sender` collide under the lowercased alias table.
    // This is NOT a real PHP semantic being documented: `php -l` fatals on this
    // exact file ("Cannot use X as sender because the name is already in use"),
    // whether the two aliases target the same class or different ones — PHP
    // itself refuses the collision at compile time, it does not pick a winner.
    // What this test documents is this resolver's own DEFENSIVE behaviour on
    // input that is not valid PHP: it must not crash or throw on adversarial or
    // malformed source, so the later declaration silently overwrites the
    // earlier one in the lowercased alias table. A file that actually reaches
    // this resolver in valid form will never exercise this path.
    $source = "<?php\nnamespace App\\Providers;\n"
        ."use App\\Nodeflow\\Nodes\\SendMessage as Sender;\n"
        ."use App\\Nodeflow\\Nodes\\TagUser as sender;\n";

    $r = PhpNameResolver::forSource($source);

    expect($r->resolve('Sender'))->toBe('App\Nodeflow\Nodes\TagUser');
    expect($r->resolve('sender'))->toBe('App\Nodeflow\Nodes\TagUser');
    expect($r->imports())->toHaveCount(1);
});

it('reads only the first namespace of a file with two namespace blocks, a stated limit', function () {
    // Probe 4: multi-namespace files are a stated limit, not a bug to fix here.
    // NodeReferenceScanner must refuse such a file outright rather than rely on
    // this resolver to handle it — this test pins the actual (first-namespace)
    // behaviour so a future change to that limit is visible.
    $source = "<?php\nnamespace A { }\nnamespace B { }\n";

    expect(PhpNameResolver::forSource($source)->namespaceName())->toBe('A');
});

// --- Round-2 review findings ---

it('resolves an import declared inside a braced namespace block', function () {
    // Critical review finding: `namespace App\Providers { ... }` is valid PHP
    // (confirmed by php -l and by real execution: inside it,
    // App\Nodeflow\Nodes\SendMessage::class stays App\Nodeflow\Nodes\SendMessage
    // when the class is imported). A bare brace-depth counter treated this
    // namespace's own opening brace as hiding its `use` statements, the same
    // way a class body hides a trait use — which produced imports() === [] and
    // resolve('SendMessage') falling through to the wrong,
    // namespace-relative answer 'App\Providers\SendMessage'. This is not the
    // multi-namespace limit (there is exactly one namespace here); the brace
    // kind must be told apart from a class/closure brace instead.
    $source = "<?php\nnamespace App\\Providers {\n    use App\\Nodeflow\\Nodes\\SendMessage;\n}\n";

    expect(PhpNameResolver::forSource($source)->resolve('SendMessage'))
        ->toBe('App\Nodeflow\Nodes\SendMessage');
});

it('still resolves a top-level import declared after an earlier brace has already closed', function () {
    // Guards the brace-kind stack's pop: a class body opens and closes a
    // non-namespace brace BEFORE the real import. Counterfactual: never pop
    // the stack on `}` and every use after the first closed brace is wrongly
    // treated as nested forever, so this import is silently dropped and
    // resolve() falls through to the namespace-relative (wrong) answer.
    $source = "<?php\nnamespace App\\Providers;\nclass Foo {}\nuse App\\Nodeflow\\Nodes\\SendMessage;\n";

    expect(PhpNameResolver::forSource($source)->resolve('SendMessage'))
        ->toBe('App\Nodeflow\Nodes\SendMessage');
});

it('resolves both members of a group import when the aliased member comes first', function () {
    // Guards resetting $alias back to null after each group member.
    // Counterfactual: leave $alias set after consuming "SendMessage as Sender"
    // and the next member, "TagUser", is wrongly keyed under the stale "sender"
    // alias too, dropping SendMessage's own entry entirely.
    $source = "<?php\nnamespace App\\Providers;\n"
        ."use App\\Nodeflow\\Nodes\\{SendMessage as Sender, TagUser};\n";

    $r = PhpNameResolver::forSource($source);

    expect($r->resolve('Sender'))->toBe('App\Nodeflow\Nodes\SendMessage');
    expect($r->resolve('TagUser'))->toBe('App\Nodeflow\Nodes\TagUser');
    expect($r->imports())->toHaveCount(2);
});

it('pops the innermost brace first when a braced namespace nests a class that nests a trait use', function () {
    // Re-review finding M11: the brace-kind stack must behave as a STACK
    // (pop the innermost/most-recently-opened brace), not a queue. Nothing
    // else in this suite has two simultaneously-open braces of DIFFERENT
    // kinds, so array_pop($braceKinds) (LIFO, correct) and array_shift
    // (FIFO, wrong) were indistinguishable on the rest of the suite. Here:
    // a braced namespace opens ('namespace'), a class body opens inside it
    // ('other', for its trait use), the class closes, and only THEN does a
    // real top-level use appear. Popping the innermost ('other') on the
    // class's closing brace correctly leaves 'namespace' on the stack, so
    // the later use is read as a top-level import. Shifting the outermost
    // ('namespace') instead would leave 'other' on the stack forever,
    // silently dropping every use after it.
    $source = "<?php\nnamespace App\\Providers {\n"
        ."    class Foo { use HasNodeType; }\n"
        ."    use App\\Nodeflow\\Nodes\\SendMessage;\n"
        ."}\n";

    expect(PhpNameResolver::forSource($source)->resolve('SendMessage'))
        ->toBe('App\Nodeflow\Nodes\SendMessage');
});
