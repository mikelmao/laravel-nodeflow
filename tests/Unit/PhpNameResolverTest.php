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
    // PHP class/alias names are case-insensitive, so this collision is real and
    // the second declaration overwrites the first — document that explicitly.
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
