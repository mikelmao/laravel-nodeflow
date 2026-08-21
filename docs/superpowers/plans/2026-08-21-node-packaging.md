# Node packaging (Plan 6) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `nodeflow:make-node-package` and `nodeflow:extract-node`, so a host can package a workflow node for sharing and move an existing node into that package without ever changing the node's `type()` or leaving the host in a broken state.

**Architecture:** Five new pure units (`HostPath`, `NodeTypeLiteral`, `PhpNameResolver`, `NodeReferenceScanner`, `PackageScaffolder`) plus two extensions to the shipped `NodeRegistrationWriter`. Both commands are orchestration only: `extract-node` is a sequence of eight read-only gates followed by ten journaled moves, so "refuse before touching anything" is structural rather than a discipline.

**Tech Stack:** PHP 8.3+, Laravel 12/13, Pest 4 (package suite), Pest 5 (demo suite), Orchestra Testbench, `token_get_all` for all PHP source analysis, Composer CLI for dependency installation.

**Spec:** `docs/superpowers/specs/2026-08-21-node-packaging-design.md` — read it alongside this plan. Decisions are cited as **E29**–**E53**; where this plan and the spec disagree, the spec wins and the disagreement is a bug in this plan.

## Global Constraints

Copied verbatim from the spec and from Plans 1 and 5. **Every task's requirements implicitly include this section.**

- **`handle(): int` on every command.** Returning `false` from a Laravel `handle()` is cast with `(int)` and exits **0**. Map refusals to `self::FAILURE` explicitly. (**F-3**, editor-spec §7.2)
- **Reset instance-cached state at the top of `handle()`.** Symfony resolves one command object per name and keeps it for the process lifetime; two `Artisan::call()`s in one process share the instance. This shipped as a real bug twice. (**F-3**)
- **Never use `str_replace` with array arguments for stub rendering — use `strtr`.** `str_replace` is sequential and re-substitutes inside its own output. (**F-1**)
- **Never use a substring test where a structural test is meant.** `str_starts_with`, `str_contains` and `ltrim($v, './')` for path or name comparison have produced eight defects in this codebase. Compare segments, or resolve names.
- **Comment-strip before matching.** Use `Nodeflow\Console\SourceText::withoutPhpComments()`; do not write a fourth stripper. (**E22**)
- **Every write is anchor-asserted then re-verified by re-reading.** (**E11**)
- **`--group`-style human text is escaped; identifiers are validated and refused.** Read `MakeNodeCommand` before inventing validation.
- **Every production guard ships with a persisted covering test.** Proving a guard in a throwaway and deleting the evidence is **F-2**'s defect class.
- **Test arithmetic:** each task states "previous measured total + this task's new tests". Never trust a table. Record **measured** assertion counts. Baseline entering Task 1: **488 Pest tests / 6,152 assertions**, **160 Vitest**, silent `tsc`. Demo baseline: **56 Pest / 223 assertions**.
- **Node type pattern** (from `MakeNodeCommand::TYPE_PATTERN`): `/^[a-z0-9]+(?:[._][a-z0-9]+)*$/`. Reserved prefix `core.`.
- **Existing anchors, byte-exact:** `protected array $nodes = [`, `protected array $triggers = [`, `protected function subjectAttributes(): array`.

---

## File Structure

**New production files** (all under `src/Console/` unless noted):

| File | Responsibility |
|---|---|
| `HostPath.php` | Canonical containment, segment splitting, relative depth. The single home for path arithmetic (**E41**, **E51**) |
| `NodeTypeLiteral.php` | **E36**'s guard: prove `type()` returns a fixed string |
| `NodeTypeResult.php` | Value object: the proven literal, or a refusal reason |
| `PhpNameResolver.php` | Per-file `namespace` + import table; resolves a written name to an FQCN |
| `NodeReference.php` | Value object: file, line, byte range, kind |
| `NodeReferenceScanner.php` | **E45**/**E46**'s scan over host roots |
| `NodeRemovalOutcome.php` | Removal's own outcome enum (**E38**) |
| `PackageScaffolder.php` | Emit the package tree; parse every rendered stub (**E52**) |
| `MakeNodePackageCommand.php` | §4's command |
| `ExtractNodeCommand.php` | §5's command — orchestration only |
| `Extract/ExtractJournal.php` | Records every mutation; restores in reverse |
| `Extract/ComposerRunner.php` | Injectable wrapper over the `composer` CLI (**E48**) and the host-boot probe (**E49**) |

**New stubs** under `stubs/package/`: `composer.json.stub`, `provider.stub`, `README.md.stub`, `test.stub`, `index.ts.stub`, `package.json.stub`, `tsconfig.json.stub`.

**Modified:** `NodeRegistrationWriter.php` (gains `removeFrom()`; presence check becomes array-bounded), `src/NodeflowServiceProvider.php` (registers two commands), `Install/TailwindSourceStep.php`, `Install/TsconfigPathsStep.php`, `Install/ViteAliasStep.php` (adopt `HostPath`, collapse `PACKAGE_SOURCE`).

**Test files** mirror production paths under `tests/Unit/` for pure units and `tests/Feature/` for commands.

---

## Task 1: `HostPath`

**Files:**
- Create: `src/Console/HostPath.php`
- Create: `tests/Unit/HostPathTest.php`
- Modify: `src/Console/Install/TailwindSourceStep.php` (delete its private `segments()` and `relativePath()` arithmetic, delegate to `HostPath`)
- Modify: `src/Console/Install/TsconfigPathsStep.php` (delete its private static `segments()`)
- Modify: `src/Console/Install/ViteAliasStep.php` (`PACKAGE_SOURCE` becomes the full `vendor/atram/laravel-nodeflow/resources/js` form)

**Interfaces:**
- Consumes: nothing.
- Produces: `Nodeflow\Console\HostPath` with `public static function root(string $path): self`, `public function contains(string $candidate): bool`, `public function relativeDepth(string $directory): int`, `public function resolveWithin(string $relative): string`, `public function basePath(): string`, `public static function segments(string $path): array` (returns `list<string>`, drops `''` and `'.'`, **keeps** `'..'` so callers can refuse it).

**Why this is first:** `extract-node` moves files, and two of Plan 5's fix-round defects were path-arithmetic bugs in classes that never shared their logic (**G-6**). Sharing it before writing any new path code is the structural fix. The install steps' 488 tests are the evidence the refactor changed nothing.

- [ ] **Step 1: Write the failing containment tests**

Create `tests/Unit/HostPathTest.php`:

```php
<?php

use Nodeflow\Console\HostPath;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/nodeflow-hostpath-'.bin2hex(random_bytes(6));
    mkdir($this->base.'/app/Nodeflow/Nodes', 0777, true);
    mkdir($this->base.'/packages', 0777, true);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->base));
    exec('rm -rf '.escapeshellarg($this->base.'-evil'));
});

it('splits a path into segments, dropping empties and dots but keeping dot-dot', function () {
    // Counterfactual: filter out '..' as well (which TsconfigPathsStep's sibling
    // helper deliberately does NOT) and the third expectation fails — callers
    // could no longer refuse a path that climbs out.
    expect(HostPath::segments('/a//b/./c'))->toBe(['a', 'b', 'c']);
    expect(HostPath::segments('a/b/'))->toBe(['a', 'b']);
    expect(HostPath::segments('a/../b'))->toBe(['a', '..', 'b']);
});

it('does not treat a sibling directory with a shared prefix as inside the root', function () {
    // THE bug this class exists to prevent, and the fifth appearance of its
    // class in this codebase. Counterfactual: implement contains() as
    // str_starts_with($candidate, $this->root) and this passes the wrong way.
    mkdir($this->base.'-evil/app', 0777, true);
    touch($this->base.'-evil/app/Foo.php');

    expect(HostPath::root($this->base)->contains($this->base.'-evil/app/Foo.php'))->toBeFalse();
});

it('treats a path under the root as inside it', function () {
    touch($this->base.'/app/Nodeflow/Nodes/SendMessage.php');

    expect(HostPath::root($this->base)->contains($this->base.'/app/Nodeflow/Nodes/SendMessage.php'))->toBeTrue();
});

it('refuses a path that climbs out of the root', function () {
    // R12/R13's shape: ltrim($v, './') collapsed '../vendor/...' into a match.
    expect(HostPath::root($this->base)->contains($this->base.'/../elsewhere/Foo.php'))->toBeFalse();
});

it('refuses a symlink inside the root whose target escapes it', function () {
    // E51. Counterfactual: compare raw segments without resolving, and this
    // passes as "contained" while a scaffold write lands outside the repository.
    $outside = sys_get_temp_dir().'/nodeflow-hostpath-outside-'.bin2hex(random_bytes(6));
    mkdir($outside, 0777, true);
    symlink($outside, $this->base.'/packages/escape');

    expect(HostPath::root($this->base)->contains($this->base.'/packages/escape/pkg'))->toBeFalse();

    exec('rm -rf '.escapeshellarg($outside));
});

it('counts relative depth without stripping a repeated inner segment', function () {
    // R15: str_replace($basePath, '', $entry) strips the basePath's text WHEREVER
    // it occurs. Build a directory that repeats the project's own last segment.
    $project = sys_get_temp_dir().'/nodeflow-hostpath-project-'.bin2hex(random_bytes(6));
    mkdir($project.'/resources/'.basename($project).'/css', 0777, true);

    expect(HostPath::root($project)->relativeDepth($project.'/resources/'.basename($project).'/css'))
        ->toBe(3);

    exec('rm -rf '.escapeshellarg($project));
});

it('resolves a relative path inside the root and refuses one that escapes', function () {
    $host = HostPath::root($this->base);

    expect($host->resolveWithin('packages/acme/sms'))->toBe($this->base.'/packages/acme/sms');
    expect(fn () => $host->resolveWithin('../outside'))
        ->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `./vendor/bin/pest tests/Unit/HostPathTest.php`
Expected: FAIL — `Class "Nodeflow\Console\HostPath" not found`.

- [ ] **Step 3: Implement `HostPath`**

Create `src/Console/HostPath.php`:

```php
<?php

namespace Nodeflow\Console;

/**
 * The one place path arithmetic lives.
 *
 * WHY. Two of Plan 5's fix-round defects (R13, R15) were path bugs in separate
 * install steps whose logic was copy-pasted rather than shared (G-6), and the
 * characteristic bug of this codebase is a substring test standing in for real
 * path handling — it has now appeared eight times. Every comparison here is
 * segment-wise, and containment is CANONICAL (E51): an in-host symlink whose
 * target escapes the root is not "inside" it, because E29 requires a scaffolded
 * package to be committed with the host.
 */
final class HostPath
{
    private function __construct(private readonly string $root) {}

    /** @throws \InvalidArgumentException when the root does not exist */
    public static function root(string $path): self
    {
        $real = realpath($path);

        if ($real === false) {
            throw new \InvalidArgumentException("Host root [{$path}] does not exist.");
        }

        return new self($real);
    }

    /**
     * Segments, dropping '' and '.' but KEEPING '..'.
     *
     * Keeping '..' is deliberate and matches TsconfigPathsStep's rule rather
     * than TailwindSourceStep's: a caller must be able to see a climb-out in
     * order to refuse it. Dropping it is how R12 turned '../vendor/…' into a
     * match.
     *
     * @return list<string>
     */
    public static function segments(string $path): array
    {
        return array_values(array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));
    }

    /** True only when $candidate canonically resolves inside this root. */
    public function contains(string $candidate): bool
    {
        $resolved = self::canonicalise($candidate);

        if ($resolved === null || in_array('..', self::segments($resolved), true)) {
            return false;
        }

        $root = self::segments($this->root);
        $path = self::segments($resolved);

        // Segment-wise prefix compare, never str_starts_with(): the raw string
        // '/Users/me/project' IS a prefix of '/Users/me/project-evil/app/Foo.php'.
        return count($path) >= count($root)
            && array_slice($path, 0, count($root)) === $root;
    }

    /** How many '../' it takes to get from $directory back to this root. */
    public function relativeDepth(string $directory): int
    {
        $root = self::segments($this->root);
        $path = self::segments(self::canonicalise($directory) ?? $directory);

        $matched = 0;

        while ($matched < count($root) && ($path[$matched] ?? null) === $root[$matched]) {
            $matched++;
        }

        // Only a full prefix match gives a boundary to strip; otherwise the whole
        // directory counts, which over-counts rather than under-counts.
        return $matched === count($root) ? count($path) - $matched : count($path);
    }

    /** @throws \InvalidArgumentException when $relative would land outside the root */
    public function resolveWithin(string $relative): string
    {
        $segments = self::segments($relative);

        if (in_array('..', $segments, true) || str_starts_with($relative, '/')) {
            throw new \InvalidArgumentException(
                "[{$relative}] must be a relative path inside the project and may not contain '..'."
            );
        }

        $candidate = $this->root.'/'.implode('/', $segments);

        if (! $this->contains($candidate)) {
            throw new \InvalidArgumentException(
                "[{$relative}] resolves outside the project root, most likely through a symlink."
            );
        }

        return $candidate;
    }

    public function basePath(): string
    {
        return $this->root;
    }

    /**
     * Absolute, symlink-resolved form of $path, whether or not it exists yet.
     *
     * A path that does not exist is resolved by canonicalising its nearest
     * existing ancestor and re-appending the remaining segments — which is why
     * '..' in the non-existent tail is refused by the caller rather than
     * silently collapsed here.
     */
    private static function canonicalise(string $path): ?string
    {
        $real = realpath($path);

        if ($real !== false) {
            return $real;
        }

        $trailing = [];
        $probe = $path;

        while (true) {
            $parent = dirname($probe);

            if ($parent === $probe) {
                return null;
            }

            array_unshift($trailing, basename($probe));

            $realParent = realpath($parent);

            if ($realParent !== false) {
                return rtrim($realParent, '/').'/'.implode('/', $trailing);
            }

            $probe = $parent;
        }
    }
}
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Unit/HostPathTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Execute the counterfactuals named in the test comments**

For each of the three named counterfactuals, make the change, run the one test, confirm it fails, revert:
1. Add `&& $segment !== '..'` to `segments()`'s filter → the dot-dot expectation and the climb-out test must fail.
2. Replace `contains()`'s segment compare with `str_starts_with($resolved, $this->root)` → the `-evil` test must fail.
3. Remove `canonicalise()`'s `realpath` and compare raw → the symlink test must fail.

Record each observed failure message in the commit body. A counterfactual that does **not** fail is information: report it and replace it rather than claiming a match.

- [ ] **Step 6: Migrate the install steps onto `HostPath`**

In `TailwindSourceStep`, delete the private `segments()` and replace `relativePath()`'s body with `str_repeat('../', $this->host->relativeDepth(dirname($entry))).self::PACKAGE_SOURCE;`, injecting `HostPath::root($this->basePath)`. In `TsconfigPathsStep`, delete the private static `segments()` and call `HostPath::segments()`. In `ViteAliasStep`, change `PACKAGE_SOURCE` from `'atram/laravel-nodeflow/resources/js'` to `'vendor/atram/laravel-nodeflow/resources/js'`.

The `ViteAliasStep` change is the corrected **E41**: the full string already tolerates a `./vendor/…` prefix because `str_contains('./vendor/x', 'vendor/x')` is true, so the shorter constant bought no tolerance and only matched paths like `/tmp/packages/atram/…` that the full form correctly rejects.

- [ ] **Step 7: Run the whole suite — the untouched-tests gate**

Run: `./vendor/bin/pest`
Expected: **488 passed**, and the assertion count at or above 6,152 plus this task's 7 new tests' assertions. If any pre-existing install-step test fails, the refactor changed behaviour and must be reworked — that gate is the entire evidence for this task.

Run: `npx tsc --noEmit` → silent.

- [ ] **Step 8: Commit**

```bash
git add src/Console/HostPath.php tests/Unit/HostPathTest.php src/Console/Install/
git commit -m "feat: add HostPath and share the install steps' path arithmetic

Closes G-6 by unifying the arithmetic AND the PACKAGE_SOURCE string per the
corrected E41. Containment is canonical (E51): an in-host symlink whose target
escapes the root is not inside it.

Executed counterfactuals: <paste the three observed failures>"
```

---

## Task 2: `NodeTypeLiteral`

**Files:**
- Create: `src/Console/NodeTypeResult.php`
- Create: `src/Console/NodeTypeLiteral.php`
- Create: `tests/Unit/NodeTypeLiteralTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Nodeflow\Console\NodeTypeLiteral::resolve(string $source, string $shortClassName): NodeTypeResult`, and `Nodeflow\Console\NodeTypeResult` with `public readonly ?string $type`, `public readonly ?string $reason`, `public function ok(): bool`, plus static factories `NodeTypeResult::proven(string $type)` and `NodeTypeResult::refused(string $reason)`.

**Why this guard is the sharpest in the plan (E36, E10).** `type()` is what immutable published graph versions and live mid-wait runs resolve through. A `type()` derived from the class name silently changes identity when the namespace moves, orphaning every published version — the one failure re-running the command cannot repair. And the empirical after-the-move check **cannot** substitute for this: `strtolower(class_basename(static::class))` returns `sendmessage` both before and after a namespace move, measured in the spec's §1.4.

- [ ] **Step 1: Write the failing whitelist audit**

Create `tests/Unit/NodeTypeLiteralTest.php`:

```php
<?php

use Nodeflow\Console\NodeTypeLiteral;

function nodeSource(string $body, string $extra = ''): string
{
    return <<<PHP
    <?php

    namespace App\Nodeflow\Nodes;

    class SendMessage
    {
    {$extra}
        public static function type(): string
        {
    {$body}
        }
    }
    PHP;
}

it('proves a single-quoted inline literal', function () {
    $result = NodeTypeLiteral::resolve(nodeSource("        return 'demo.send';"), 'SendMessage');

    expect($result->ok())->toBeTrue();
    expect($result->type)->toBe('demo.send');
});

it('proves a double-quoted literal with no interpolation', function () {
    // A double-quoted string with no variables is still ONE
    // T_CONSTANT_ENCAPSED_STRING token, verified by probe. Refusing it would
    // reject an ordinary, provably safe shape.
    $result = NodeTypeLiteral::resolve(nodeSource('        return "demo.send";'), 'SendMessage');

    expect($result->ok())->toBeTrue();
    expect($result->type)->toBe('demo.send');
});

it('proves a literal past a leading comment in the body', function () {
    // E36 requires matching on the COMMENT-STRIPPED token stream. Counterfactual:
    // match the raw token sequence and this fails, refusing every node whose
    // author explained their type — a probe confirmed the body emits T_COMMENT.
    $result = NodeTypeLiteral::resolve(
        nodeSource("        // Published versions resolve through this forever.\n        return 'demo.send';"),
        'SendMessage',
    );

    expect($result->ok())->toBeTrue();
    expect($result->type)->toBe('demo.send');
});

it('proves a same-class constant whose initialiser is a literal', function () {
    $result = NodeTypeLiteral::resolve(
        nodeSource('        return self::TYPE;', "    public const TYPE = 'demo.send';\n"),
        'SendMessage',
    );

    expect($result->ok())->toBeTrue();
    expect($result->type)->toBe('demo.send');
});

it('proves a same-class constant reached through static::', function () {
    $result = NodeTypeLiteral::resolve(
        nodeSource('        return static::TYPE;', "    public const TYPE = 'demo.send';\n"),
        'SendMessage',
    );

    expect($result->ok())->toBeTrue();
    expect($result->type)->toBe('demo.send');
});

it('refuses a concatenation even of two literals', function () {
    // Two T_CONSTANT_ENCAPSED_STRING tokens, not one. Accepting concatenation
    // opens the door to 'x' . static::class, which is the exact orphaning shape
    // E10 exists to refuse.
    $result = NodeTypeLiteral::resolve(nodeSource("        return 'demo' . '.send';"), 'SendMessage');

    expect($result->ok())->toBeFalse();
    expect($result->reason)->toContain('concatenation');
});

it('refuses an interpolated string', function () {
    $result = NodeTypeLiteral::resolve(
        nodeSource('        $suffix = "send";'."\n".'        return "demo.{$suffix}";'),
        'SendMessage',
    );

    expect($result->ok())->toBeFalse();
});

it('refuses a heredoc', function () {
    $result = NodeTypeLiteral::resolve(
        nodeSource("        return <<<T\ndemo.send\nT;"),
        'SendMessage',
    );

    expect($result->ok())->toBeFalse();
});

it('refuses a type derived from the class name', function () {
    // The shape the whole guard exists for. Measured: this returns the SAME
    // string before and after a namespace move, so the empirical check at M9
    // cannot see it.
    $result = NodeTypeLiteral::resolve(
        nodeSource('        return strtolower(class_basename(static::class));'),
        'SendMessage',
    );

    expect($result->ok())->toBeFalse();
});

it('refuses a constant inherited from a parent rather than declared here', function () {
    // This is the probe that proves "same class body" is really enforced and the
    // tokeniser is not reaching through a parent or a trait. Counterfactual:
    // look up the constant with reflection instead of in this file's tokens and
    // this passes, accepting a value the moved file does not contain.
    $source = <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    class SendMessage extends BaseNode
    {
        public static function type(): string
        {
            return self::TYPE;
        }
    }
    PHP;

    $result = NodeTypeLiteral::resolve($source, 'SendMessage');

    expect($result->ok())->toBeFalse();
    expect($result->reason)->toContain('TYPE');
});

it('refuses a type() supplied by a trait', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    class SendMessage
    {
        use HasNodeType;
    }
    PHP;

    $result = NodeTypeLiteral::resolve($source, 'SendMessage');

    expect($result->ok())->toBeFalse();
    expect($result->reason)->toContain('no type() method');
});

it('does not match a literal that only appears in a comment', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    class SendMessage
    {
        // e.g. return 'fake.type';
        public static function type(): string
        {
            return strtolower(static::class);
        }
    }
    PHP;

    $result = NodeTypeLiteral::resolve($source, 'SendMessage');

    expect($result->ok())->toBeFalse();
    expect($result->type)->toBeNull();
});

it('refuses a literal containing a backslash', function () {
    // Unquoting stops at stripping the outer quotes; a node type cannot contain
    // an escape under TYPE_PATTERN, so refusing beats writing an escape parser
    // inside the one unrecoverable guard.
    $result = NodeTypeLiteral::resolve(nodeSource("        return 'demo\\\\send';"), 'SendMessage');

    expect($result->ok())->toBeFalse();
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Unit/NodeTypeLiteralTest.php`
Expected: FAIL — `Class "Nodeflow\Console\NodeTypeLiteral" not found`.

- [ ] **Step 3: Implement `NodeTypeResult`**

Create `src/Console/NodeTypeResult.php`:

```php
<?php

namespace Nodeflow\Console;

/**
 * What NodeTypeLiteral could prove about a node's type() method.
 *
 * A result object rather than a nullable string because the refusal message is
 * the product: E36 refuses several distinct shapes and each must name itself, so
 * an author can see which rule they hit and what to change.
 */
final class NodeTypeResult
{
    private function __construct(
        public readonly ?string $type,
        public readonly ?string $reason,
    ) {}

    public static function proven(string $type): self
    {
        return new self($type, null);
    }

    public static function refused(string $reason): self
    {
        return new self(null, $reason);
    }

    public function ok(): bool
    {
        return $this->type !== null;
    }
}
```

- [ ] **Step 4: Implement `NodeTypeLiteral`**

Create `src/Console/NodeTypeLiteral.php`:

```php
<?php

namespace Nodeflow\Console;

/**
 * Proves that a node's type() returns a fixed string, or refuses (E36, E10).
 *
 * WHY THIS IS A WHITELIST. type() is the identifier immutable published graph
 * versions and live mid-wait runs resolve through forever. A blacklist of known
 * dangerous shapes is the substring-test mistake in another costume: it accepts
 * everything nobody thought of. So exactly two shapes pass and everything else
 * is refused by name.
 *
 * WHY A STATIC CHECK AT ALL, given extract-node also compares type() before and
 * after the move. Because the comparison is blind to the commonest dangerous
 * shape. Measured:
 *
 *   strtolower(class_basename('App\Nodeflow\Nodes\SendMessage')) -> sendmessage
 *   strtolower(class_basename('Vendor\Pkg\Nodes\SendMessage'))   -> sendmessage
 *
 * A basename-derived type survives a namespace move byte-identical, so the
 * empirical gate passes while the type is still derived from the class name and
 * the author's next rename orphans every published version.
 */
final class NodeTypeLiteral
{
    public static function resolve(string $source, string $shortClassName): NodeTypeResult
    {
        $tokens = self::significantTokens($source);

        $body = self::methodBody($tokens, 'type');

        if ($body === null) {
            return NodeTypeResult::refused(
                "[{$shortClassName}] declares no type() method in its own class body. A type() "
                .'inherited from a parent or supplied by a trait cannot be proven from this file, '
                ."so extraction refuses it. Declare type() on {$shortClassName} itself."
            );
        }

        // Shape A: return '<literal>';
        if (count($body) === 3
            && $body[0][0] === T_RETURN
            && $body[1][0] === T_CONSTANT_ENCAPSED_STRING
            && $body[2][1] === ';') {
            return self::unquote($body[1][1]);
        }

        // Shape B: return self::CONST; / return static::CONST;
        if (count($body) === 5
            && $body[0][0] === T_RETURN
            && in_array(strtolower($body[1][1]), ['self', 'static'], true)
            && $body[2][0] === T_DOUBLE_COLON
            && $body[3][0] === T_STRING
            && $body[4][1] === ';') {
            return self::sameClassConstant($tokens, $body[3][1], $shortClassName);
        }

        $literals = array_filter($body, fn (array $t) => $t[0] === T_CONSTANT_ENCAPSED_STRING);

        if (count($literals) > 1) {
            return NodeTypeResult::refused(
                'type() concatenates string literals. Even a concatenation of two constants is '
                .'refused, because accepting it would also accept a value built from '
                .'static::class. Inline the finished type as a single literal.'
            );
        }

        return NodeTypeResult::refused(
            'type() does not return a plain string literal or a same-class constant. Published '
            .'flow versions and runs sitting mid-wait resolve through this string forever, so '
            .'extraction refuses anything whose value this command cannot prove is fixed. Either '
            ."inline the literal, or declare a constant on the class and return it."
        );
    }

    /**
     * Comment- and whitespace-free tokens, each normalised to [id, text].
     *
     * Comments are dropped because E36 matches on the stripped stream: a probe
     * confirmed that a body opening with a `//` line emits T_COMMENT, so an
     * exact raw-sequence match would refuse every node whose author explained
     * their type. Whitespace is dropped so the shape match is about syntax
     * rather than formatting.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function significantTokens(string $source): array
    {
        $out = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    continue;
                }

                $out[] = [$token[0], $token[1]];

                continue;
            }

            $out[] = [-1, $token];
        }

        return $out;
    }

    /**
     * The token list strictly inside the named method's braces, or null.
     *
     * Brace-matched rather than searched for a closing pattern: an unbalanced
     * scan is how an edit lands in the wrong block, and this class refuses
     * rather than guesses.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     * @return list<array{0: int, 1: string}>|null
     */
    private static function methodBody(array $tokens, string $method): ?array
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            if (($tokens[$i + 1][0] ?? null) !== T_STRING
                || strtolower($tokens[$i + 1][1]) !== $method) {
                continue;
            }

            $open = null;

            for ($j = $i + 2; $j < $count; $j++) {
                if ($tokens[$j][1] === '{') {
                    $open = $j;

                    break;
                }

                // A ';' before any '{' means an abstract or interface method.
                if ($tokens[$j][1] === ';') {
                    return null;
                }
            }

            if ($open === null) {
                return null;
            }

            $depth = 0;

            for ($j = $open; $j < $count; $j++) {
                if ($tokens[$j][1] === '{') {
                    $depth++;
                }

                if ($tokens[$j][1] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        return array_values(array_slice($tokens, $open + 1, $j - $open - 1));
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{0: int, 1: string}>  $tokens
     */
    private static function sameClassConstant(array $tokens, string $name, string $shortClassName): NodeTypeResult
    {
        $count = count($tokens);

        for ($i = 0; $i < $count - 3; $i++) {
            if ($tokens[$i][0] !== T_CONST) {
                continue;
            }

            if (($tokens[$i + 1][0] ?? null) !== T_STRING || $tokens[$i + 1][1] !== $name) {
                continue;
            }

            if ($tokens[$i + 2][1] !== '=') {
                continue;
            }

            if (($tokens[$i + 3][0] ?? null) !== T_CONSTANT_ENCAPSED_STRING) {
                break;
            }

            return self::unquote($tokens[$i + 3][1]);
        }

        return NodeTypeResult::refused(
            "type() returns [{$name}], which is not declared as a literal constant in "
            ."[{$shortClassName}]'s own class body. A constant inherited from a parent, reached "
            .'through an interface, or defined on another class cannot be proven from this file. '
            ."Declare `const {$name} = '<your.type>';` on {$shortClassName}, or inline the literal."
        );
    }

    /**
     * Strips the outer quotes and refuses anything carrying a backslash.
     *
     * A node type matches MakeNodeCommand::TYPE_PATTERN — lowercase segments
     * joined by dots or underscores — so it can contain no escape sequence at
     * all. Refusing a backslash is therefore free, and it keeps an escape parser
     * out of the one guard whose failure is unrecoverable.
     */
    private static function unquote(string $literal): NodeTypeResult
    {
        $value = substr($literal, 1, -1);

        if (str_contains($value, '\\')) {
            return NodeTypeResult::refused(
                "type() returns a literal containing a backslash ([{$value}]). A node type is "
                .'lowercase segments joined by dots or underscores, so this cannot be a valid '
                .'type and extraction will not guess at its escape sequences.'
            );
        }

        return NodeTypeResult::proven($value);
    }
}
```

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Unit/NodeTypeLiteralTest.php`
Expected: PASS, 13 tests.

- [ ] **Step 6: Execute the two named counterfactuals**

1. Remove `T_COMMENT`/`T_DOC_COMMENT` from `significantTokens()`'s skip list → "proves a literal past a leading comment" must fail.
2. Replace `sameClassConstant()` with a `constant($class.'::'.$name)` reflection lookup → "refuses a constant inherited from a parent" must fail.

Revert both. Record the observed messages. If either does not discriminate, say so and replace it.

- [ ] **Step 7: Run the full suite and commit**

Run: `./vendor/bin/pest` → expect **495 passed** (488 + Task 1's 7), then **508** after this task's 13. Record the measured assertion count rather than predicting it.

```bash
git add src/Console/NodeTypeLiteral.php src/Console/NodeTypeResult.php tests/Unit/NodeTypeLiteralTest.php
git commit -m "feat: prove a node's type() is a fixed string, or refuse (E36)

Whitelist, not blacklist: exactly two shapes pass. The static check cannot be
replaced by comparing type() before and after the move, because a
basename-derived type survives a namespace move byte-identical.

Executed counterfactuals: <paste both observed failures>"
```

---

> **A note on this plan's own code blocks, from Plan 5's execution record.** That plan's *prose* held
> up under execution; its *code blocks* did not, at roughly one defect per two tasks. Treat every
> implementation block below as guidance and every test block as the requirement. Where a block is
> wrong, fix it and record the correction as a ruling — that is expected, not a failure.

---

## Task 3: `PhpNameResolver`

**Files:**
- Create: `src/Console/PhpNameResolver.php`
- Create: `tests/Unit/PhpNameResolverTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Nodeflow\Console\PhpNameResolver` with `public static function forSource(string $source): self`, `public function resolve(string $writtenName): string` (returns an FQCN with no leading backslash), `public function namespaceName(): string`, `public function imports(): array` (alias ⇒ FQCN).

**Why this is its own task.** Three amendments depend on the same question — "what class does this written name mean in this file?" **E46**'s classification, **E50**'s identity resolution, and **E45**'s post-move rescan. Writing that answer three times is how the substring bug reaches its ninth appearance. It is also the unit that reverses `ProviderRegistrationStep`'s deliberate refusal to parse `use` statements, scoped to this command only (**E35**).

- [ ] **Step 1: Write the failing resolution tests**

Create `tests/Unit/PhpNameResolverTest.php`:

```php
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
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Unit/PhpNameResolverTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `PhpNameResolver`**

Create `src/Console/PhpNameResolver.php`:

```php
<?php

namespace Nodeflow\Console;

/**
 * Answers "what class does this written name mean in this file?" using PHP's own
 * name-resolution rule.
 *
 * WHY THIS EXISTS, given ProviderRegistrationStep deliberately declined to parse
 * use statements. The stakes inverted (E35). There, a false positive was harmless
 * and the shape unseen. Here a false NEGATIVE leaves the host fatal after a move —
 * NodeRegistry::register() autoloads through is_a(), so a stale FQCN throws in the
 * host's provider boot() on every request — and a false POSITIVE refuses
 * legitimate work in any codebase that happens to contain another SendMessage.
 *
 * The rule implemented is the language's, not a heuristic:
 *   \A\B\C          -> A\B\C, always
 *   Alias           -> the import's target, when Alias is imported
 *   Alias\D\E       -> the import's target for Alias, then \D\E
 *   A\B\C           -> <current namespace>\A\B\C, when A is not imported
 *
 * That last line is the one the first draft of this plan's design got wrong, and
 * it is why removeFrom() must resolve rather than string-match. Verified:
 * inside `namespace App\Providers;`, `App\Nodeflow\Nodes\SendMessage::class`
 * evaluates to `App\Providers\App\Nodeflow\Nodes\SendMessage`.
 */
final class PhpNameResolver
{
    /** @param array<string, string> $imports alias (lowercased) => FQCN */
    private function __construct(
        private readonly string $namespace,
        private readonly array $imports,
    ) {}

    public static function forSource(string $source): self
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t) => ! is_array($t)
                || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
        ));

        return new self(
            self::readNamespace($tokens),
            self::readImports($tokens),
        );
    }

    public function namespaceName(): string
    {
        return $this->namespace;
    }

    /** @return array<string, string> */
    public function imports(): array
    {
        return $this->imports;
    }

    public function resolve(string $writtenName): string
    {
        $name = ltrim($writtenName, '\\');

        // A leading backslash is a fully-qualified name and resolves to itself.
        if (str_starts_with($writtenName, '\\')) {
            return $name;
        }

        $segments = explode('\\', $name);
        $first = strtolower($segments[0]);

        if (isset($this->imports[$first])) {
            $rest = array_slice($segments, 1);

            return $rest === []
                ? $this->imports[$first]
                : $this->imports[$first].'\\'.implode('\\', $rest);
        }

        return $this->namespace === '' ? $name : $this->namespace.'\\'.$name;
    }

    /** @param list<array{0:int,1:string}|string> $tokens */
    private static function readNamespace(array $tokens): string
    {
        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            $parts = [];

            for ($i = $index + 1; $i < count($tokens); $i++) {
                $next = $tokens[$i];

                if (! is_array($next)) {
                    break;
                }

                // PHP 8 emits T_NAME_QUALIFIED for A\B; older shapes emit
                // T_STRING + T_NS_SEPARATOR. Accept both so this does not depend
                // on the tokeniser's version-specific grouping.
                if (in_array($next[0], [T_NAME_QUALIFIED, T_STRING, T_NS_SEPARATOR], true)) {
                    $parts[] = $next[1];

                    continue;
                }

                break;
            }

            return trim(implode('', $parts), '\\');
        }

        return '';
    }

    /** @return array<string, string> */
    private static function readImports(array $tokens): array
    {
        $imports = [];
        $count = count($tokens);
        $braceDepth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                if ($token === '{') {
                    $braceDepth++;
                }

                if ($token === '}') {
                    $braceDepth--;
                }

                continue;
            }

            if ($token[0] !== T_USE) {
                continue;
            }

            // A `use` inside any brace is either a trait use in a class body or a
            // closure's captured-variable list. Neither is an import, and reading
            // one as an import produces a garbage alias.
            if ($braceDepth > 0) {
                continue;
            }

            // `use function …` and `use const …` import symbols, not classes.
            $following = $tokens[$i + 1] ?? null;

            if (is_array($following) && in_array($following[0], [T_FUNCTION, T_CONST], true)) {
                continue;
            }

            $i = self::readOneUseStatement($tokens, $i + 1, $imports);
        }

        return $imports;
    }

    /**
     * Consumes one `use` statement, handling both the plain and group forms, and
     * returns the index of its terminating ';'.
     *
     * @param  array<string, string>  $imports
     */
    private static function readOneUseStatement(array $tokens, int $start, array &$imports): int
    {
        $count = count($tokens);
        $prefix = '';
        $current = '';
        $alias = null;
        $inGroup = false;
        $expectAlias = false;

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if ($token[0] === T_AS) {
                    $expectAlias = true;

                    continue;
                }

                if ($expectAlias) {
                    $alias = $token[1];
                    $expectAlias = false;

                    continue;
                }

                if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING, T_NS_SEPARATOR], true)) {
                    $current .= $token[1];
                }

                continue;
            }

            if ($token === '{') {
                $inGroup = true;
                $prefix = rtrim($current, '\\').'\\';
                $current = '';

                continue;
            }

            if ($token === ',' || $token === '}' || $token === ';') {
                if ($current !== '') {
                    $fqcn = trim(($inGroup ? $prefix : '').$current, '\\');
                    $short = $alias ?? self::lastSegment($fqcn);
                    $imports[strtolower($short)] = $fqcn;
                }

                $current = '';
                $alias = null;

                if ($token === ';') {
                    return $i;
                }

                if ($token === '}') {
                    $inGroup = false;
                }
            }
        }

        return $count - 1;
    }

    private static function lastSegment(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
```

- [ ] **Step 4: Run and confirm the tests pass**

Run: `./vendor/bin/pest tests/Unit/PhpNameResolverTest.php`
Expected: PASS, 11 tests.

- [ ] **Step 5: Adversarial probe — before you commit, try to break your own rule**

Construct and run each of these; each must behave as stated, and any that does not is a defect to fix now:
1. `use App\Nodeflow\Nodes\{SendMessage};` — a group of one.
2. `use \App\Nodeflow\Nodes\SendMessage;` — a leading backslash in the import itself.
3. Two `use` statements aliasing *different* classes to names differing only in case (`Sender`, `sender`) — document which wins; PHP class names are case-insensitive, which is why the alias table is keyed lowercased.
4. A file with two `namespace` blocks (`namespace A { } namespace B { }`) — this resolver reads the first. Confirm it, and record it as a stated limit: `NodeReferenceScanner` must refuse a multi-namespace file rather than resolve it wrongly.

Add a persisted test for each behaviour you confirm. Per **F-2**, do not prove one in a throwaway and delete it.

- [ ] **Step 6: Commit**

```bash
git add src/Console/PhpNameResolver.php tests/Unit/PhpNameResolverTest.php
git commit -m "feat: resolve written PHP names to FQCNs using the language's own rule

Reverses ProviderRegistrationStep's declination to parse use statements, scoped
to extract-node (E35), because here a false negative leaves the host fatal.

The rule the first design draft got wrong: inside namespace App\\Providers,
App\\Nodeflow\\Nodes\\SendMessage resolves to
App\\Providers\\App\\Nodeflow\\Nodes\\SendMessage.

Stated limits: <paste what the four probes established>"
```

---

## Task 4: `removeFrom()`, `NodeRemovalOutcome`, and `appendTo()`'s presence fix

**Files:**
- Create: `src/Console/NodeRemovalOutcome.php`
- Modify: `src/Console/NodeRegistrationWriter.php`
- Modify: `tests/Unit/NodeRegistrationWriterTest.php` (one existing test rewritten — see Step 6)
- Create: `tests/Unit/NodeRegistrationRemovalTest.php`

**Interfaces:**
- Consumes: `PhpNameResolver` (Task 3).
- Produces: `NodeRegistrationWriter::removeFrom(string $providerPath, string $anchor, string $nodeClass): NodeRemovalOutcome`, and the enum `Nodeflow\Console\NodeRemovalOutcome` with cases `Removed`, `NotPresent`, `EntryUnsupported`, `ProviderMissing`, `AnchorMissing`, `AnchorAmbiguous`, `EntryAmbiguous`, `WriteFailed`.

**This is the highest-risk task in the plan.** Everything the package has shipped so far appends or creates. This is the first code that *deletes a line from a file the package does not own*. `NodeRegistrationOutcome` is **not** touched — removal gets its own enum (**E38**) so every existing `match` keeps compiling and no `UnhandledMatchError` risk enters Plan 1 and Plan 5's call sites.

- [ ] **Step 1: Write the failing removal tests**

Create `tests/Unit/NodeRegistrationRemovalTest.php`. Use the existing file's fixture helpers as the model — a per-process temp directory with an `afterEach` cleanup — but define your own so the two files do not share mutable state:

```php
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
    expect(file_get_contents($path))->toContain('protected array $nodes = [');
});

it('removes the last entry when it carries no trailing comma', function () {
    $path = providerForRemoval("        TagUser::class,\n        SendMessage::class",
        "use App\Nodeflow\Nodes\TagUser;\nuse App\Nodeflow\Nodes\SendMessage;");

    expect(remove($path, 'App\Nodeflow\Nodes\SendMessage'))->toBe(NodeRemovalOutcome::Removed);
    expectParseablePhp($path);
    expect(file_get_contents($path))->toContain('TagUser::class');
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
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Unit/NodeRegistrationRemovalTest.php`
Expected: FAIL — `NodeRemovalOutcome` not found.

- [ ] **Step 3: Create the outcome enum**

Create `src/Console/NodeRemovalOutcome.php`:

```php
<?php

namespace Nodeflow\Console;

/**
 * What NodeRegistrationWriter::removeFrom() did.
 *
 * Deliberately NOT extra cases on NodeRegistrationOutcome (E38). `Appended` is
 * meaningless for a removal and `Removed` for an append, and growing that enum
 * would force every match() Plans 1 and 5 shipped to gain arms it can never hit
 * or throw UnhandledMatchError.
 */
enum NodeRemovalOutcome
{
    /** At least one resolved entry was removed and the result still parses. */
    case Removed;

    /**
     * No entry in the target array RESOLVES to the requested class. Not the same
     * as "the class name does not appear": a name that appears but resolves to a
     * different class under the file's own namespace and imports is NotPresent,
     * and so is one that appears only inside a comment.
     */
    case NotPresent;

    /**
     * A resolved entry was found, but its syntax is one this writer will not edit
     * — so the caller must not read NotPresent as "nothing to do". Refusing here
     * is what stops extraction proceeding to leave a host whose provider names a
     * class that no longer exists.
     */
    case EntryUnsupported;

    case ProviderMissing;
    case AnchorMissing;
    case AnchorAmbiguous;

    /** The entry shares its line with a sibling entry (E39). File untouched. */
    case EntryAmbiguous;

    /**
     * A write was attempted, but the re-read found the result either failed to
     * parse or still carried a resolved reference. Original bytes restored.
     */
    case WriteFailed;
}
```

- [ ] **Step 4: Implement `removeFrom()` on the writer**

Add to `src/Console/NodeRegistrationWriter.php`. The required behaviour, in order — implement it however reads cleanest, but every numbered rule needs to hold:

1. File missing → `ProviderMissing`.
2. `substr_count($raw, $anchor)`: `0` → `AnchorMissing`; `>1` → `AnchorAmbiguous`. Counts stay on **raw** bytes, matching `appendTo()`'s existing reasoning — a commented-out second anchor makes "which one?" unanswerable.
3. Locate the array span by **brace/bracket matching** from the anchor's `[` to its partner, over the comment-stripped-with-offsets text `codeWithOffsets()` already produces. Do not search for the next `]`; a nested array would end the span early.
4. Inside that span only, find every `<name>::class` occurrence. Resolve each `<name>` through `PhpNameResolver::forSource($raw)`. Keep those whose FQCN equals `ltrim($nodeClass, '\\')`.
5. No matches → `NotPresent`.
6. For each match, classify its line: the comment-stripped line, trimmed, must equal the entry plus an optional trailing comma. If it does not, and the entry is not the array's sole content, → `EntryAmbiguous`, file untouched.
7. Remove all matched entries — whole line including its newline, or empty the body when it is the sole content — working from the **last** match backwards so earlier byte offsets stay valid.
8. Write, then re-read: the result must parse (`token_get_all` with `TOKEN_PARSE`, as `parses()` already does) **and** no resolved reference to the target may remain in the array. Either failure → restore the original bytes, return `WriteFailed`.

Also change `appendTo()`'s presence check: instead of `str_contains(SourceText::withoutPhpComments($contents), $presenceNeedle)` over the whole file, resolve entries **inside the anchor's array span** the same way. Keep the `$presenceNeedle` parameter for the `SubjectAttribute::make('key'` case, which is not a class reference — branch on whether the needle ends in `::class`.

- [ ] **Step 5: Run the new tests and confirm they pass**

Run: `./vendor/bin/pest tests/Unit/NodeRegistrationRemovalTest.php`
Expected: PASS, 15 tests.

- [ ] **Step 6: Rewrite the one existing test that E50 changes, and add its companion**

`tests/Unit/NodeRegistrationWriterTest.php`'s `it('recognises a class listed without a leading backslash')` asserts `AlreadyPresent` for `App\Nodeflow\Nodes\SendSms::class` in a fixture that declares `namespace App\Providers;` — which resolves to `App\Providers\App\Nodeflow\Nodes\SendSms`, a different class. Verified:

```
entry as written resolves to: App\Providers\App\Nodeflow\Nodes\SendSms
the intended target is      : App\Nodeflow\Nodes\SendSms
```

Rewrite it to assert `Appended` with the reason in the test body, and add a companion asserting `AlreadyPresent` for the same spelling in a fixture with **no** namespace declaration — the case the original test was reaching for. Matching must not diverge between `appendTo()` and `removeFrom()`; a divergence of exactly that kind produced execution-record **C1**.

- [ ] **Step 7: Prove the shipped whole-file presence defect is fixed**

Add to `tests/Unit/NodeRegistrationWriterTest.php`:

```php
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
```

- [ ] **Step 8: The false-pass probe — mandatory before commit**

Break `removeFrom()` to a no-op that still returns `NodeRemovalOutcome::Removed`. Run `./vendor/bin/pest tests/Unit/NodeRegistrationRemovalTest.php`. **Every test that still passes was asserting the enum rather than the file, and must be rewritten to assert file contents.** Revert, and record in the commit body how many tests the probe caught. Zero is a good answer; report it as measured.

- [ ] **Step 9: Full suite, then commit**

Run: `./vendor/bin/pest`
Expected: previous measured total + 15 (removal) + 1 (the whole-file defect test) + 1 (the namespace companion), with one existing test rewritten rather than added. Record measured counts.

```bash
git add src/Console/NodeRemovalOutcome.php src/Console/NodeRegistrationWriter.php tests/Unit/
git commit -m "feat: remove a resolved node entry from a provider array (E38, E39, E50)

The first destructive edit this package makes to host code. Matching resolves
identity through PhpNameResolver rather than string-matching spellings, and is
bounded to the anchor's own array span.

Also fixes a pre-existing shipped defect: appendTo()'s presence check ran
str_contains over the whole file, so a mention anywhere read AlreadyPresent.

One existing writer test rewritten (E50, spec section 1.5) because its fixture's
namespace makes the entry a different class. 15 of 16 untouched.

False-pass probe caught <N> tests asserting the enum rather than the file."
```

---

> **From here on, implementation steps are stated as numbered behavioural rules rather than as code
> blocks wherever the shape is not novel.** This is deliberate, on the execution record's evidence:
> this plan's code blocks are its least reliable artifact, and a wrong code block gets copied while a
> numbered rule gets implemented. Test blocks stay literal, because those are the requirement.

---

## Task 5: `NodeReferenceScanner`

**Files:**
- Create: `src/Console/NodeReference.php`
- Create: `src/Console/NodeReferenceScanner.php`
- Create: `tests/Unit/NodeReferenceScannerTest.php`

**Interfaces:**
- Consumes: `HostPath` (Task 1), `PhpNameResolver` (Task 3).
- Produces: `NodeReference` — readonly `string $file`, `int $line`, `int $byteStart`, `int $byteEnd`, `string $kind` (one of `class_constant`, `string_literal`, `import`, `extends`, `class_alias`). And `NodeReferenceScanner::scan(string $fqcn, array $absoluteRoots): array` returning `list<NodeReference>`.

**What this gate is for (E34, as amended by E45 and E46).** After the move, a stale reference to the old FQCN is a **fatal in the host's provider `boot()` on every request**, because `NodeRegistry::register()` autoloads through `is_a()`. The scan is what makes extraction refuse instead of breaking the host. **E45** is the correction that matters: the first draft exempted whole *files* the command would rewrite, which exempted the legacy `Nodeflow::register([SendMessage::class])` in the provider — the exact case the scan existed to catch. Exemption is per **span**.

- [ ] **Step 1: Write the failing scanner tests**

Create `tests/Unit/NodeReferenceScannerTest.php` with a temp-host fixture helper `hostWith(array $files): string` that writes each `path => contents` pair under a fresh temp root and returns the root. Then one test per row, asserting on the returned `NodeReference` list:

| Test name | Fixture | Expectation |
|---|---|---|
| finds a fully-qualified reference | `app/Foo.php` containing `\App\Nodeflow\Nodes\SendMessage::class` | 1 reference, kind `class_constant`, correct line |
| finds a bare short name behind an import | `use App\Nodeflow\Nodes\SendMessage;` + `SendMessage::class` | 1 reference |
| finds a member of a group import | `use App\Nodeflow\Nodes\{SendMessage, TagUser};` + `SendMessage::class` | 2 references — the import and the constant |
| finds an aliased usage | `use … as Sender;` + `Sender::class` | 2 references |
| finds a namespace-relative name | file in `namespace App\Nodeflow;` using `Nodes\SendMessage::class` | 1 reference |
| **ignores another class with the same short name** | `namespace App\Sms;` + `use App\Sms\SendMessage;` + `SendMessage::class` | **0 references** |
| **ignores a bare name that resolves to the file's own namespace** | `namespace App\Other;` + `SendMessage::class`, no import | **0 references** |
| finds the FQCN inside a string literal | `config/nodeflow.php` returning `['node' => 'App\Nodeflow\Nodes\SendMessage']` | 1 reference, kind `string_literal` |
| finds a `class_alias` target | `class_alias('App\Nodeflow\Nodes\SendMessage', 'Legacy')` | 1 reference |
| finds an `extends` | `class Special extends SendMessage` behind an import | 2 references |
| ignores a reference inside a comment | `// \App\Nodeflow\Nodes\SendMessage::class` | 0 references |
| ignores a reference inside a docblock | `/** @see \App\…\SendMessage */` | 0 references |
| scans every configured root, not just app | the FQCN in each of `config/`, `routes/`, `database/`, `bootstrap/`, `resources/` | 5 references |
| records a byte range that isolates the reference | any single reference | `substr($contents, $byteStart, $byteEnd - $byteStart)` equals the written name |
| refuses a file declaring two namespaces | `namespace A { } namespace B { }` | throws, naming the file — Task 3's stated limit |

Two of these are the load-bearing pair and their comments must say so: **"ignores another class with the same short name"** is what stops the scan refusing legitimate work in any codebase containing a second `SendMessage`, and **"finds the FQCN inside a string literal"** is a reference that genuinely breaks on the move and that a `::class`-only scan misses.

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Unit/NodeReferenceScannerTest.php` → FAIL, class not found.

- [ ] **Step 3: Implement `NodeReference` and `NodeReferenceScanner`**

`NodeReference` is a readonly value object with a constructor and no behaviour.

`NodeReferenceScanner::scan()` must:

1. Walk each root recursively for `*.php` and `*.blade.php`, skipping any path not `contains()`ed by the `HostPath` (so a symlink out of the tree is not scanned).
2. For each file: read raw bytes, build `PhpNameResolver::forSource()`, and tokenise once. Refuse (throw) if the file declares more than one `namespace` block.
3. Emit a `class_constant` reference for each `<name>::class` whose resolved FQCN equals the target; a `extends` reference for each `extends <name>` likewise; an `import` reference for each `use` statement whose FQCN equals the target; and a `string_literal` reference for each `T_CONSTANT_ENCAPSED_STRING` whose unquoted value, with leading backslashes trimmed, equals the target.
4. Emit a `class_alias` reference when the first argument of a `class_alias(...)` call is such a literal — this is already covered by rule 3's literal case, so assert it rather than special-case it, and drop the separate `class_alias` kind if the literal rule subsumes it. **Simplify to whatever the tests prove sufficient; do not keep a kind nothing distinguishes.**
5. Skip `T_COMMENT` and `T_DOC_COMMENT` entirely.
6. Record each reference's `line` from the token's line number and its `byteStart`/`byteEnd` from a running byte offset over the token stream — the same lossless-lexer property `NodeRegistrationWriter::codeWithOffsets()` already relies on.

- [ ] **Step 4: Run the tests, then execute the span-versus-file counterfactual**

Run the suite; expect all rows passing.

Then write the test that proves **E45**'s correction is load-bearing, because this is the finding that falsified the first design draft:

```php
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
```

- [ ] **Step 5: Commit**

```bash
git add src/Console/NodeReference.php src/Console/NodeReferenceScanner.php tests/Unit/NodeReferenceScannerTest.php
git commit -m "feat: scan host roots for references to a node class (E45, E46)

References carry a byte range, not just a file, because E45 exempts proven spans
rather than files -- the file-level rule exempted the legacy register() call in
the provider, which is the case the scan exists to catch.

Roots widened past app/ and tests/ to config/, routes/, database/, bootstrap/
and resources/, each proven by a fixture. Dynamic and database-stored class
names remain out of reach and are stated as such (E46)."
```

---

## Task 6: `PackageScaffolder` and the stubs

**Files:**
- Create: `stubs/package/composer.json.stub`, `provider.stub`, `README.md.stub`, `test.stub`, `index.ts.stub`, `package.json.stub`, `tsconfig.json.stub`
- Create: `src/Console/PackageScaffolder.php`
- Create: `tests/Unit/PackageScaffolderTest.php`

**Interfaces:**
- Consumes: `HostPath` (Task 1).
- Produces: `PackageScaffolder::scaffold(PackageTarget $target): void` where `PackageTarget` is a readonly value object carrying `string $composerName`, `string $namespace`, `string $absolutePath`, `string $relativePath`, `string $providerClass`, `string $nodeflowConstraint`, `bool $withJs`. Add `PackageTarget` to this task's created files.

**Requirements:**

1. `composer.json.stub` renders `name`, `description`, `type: library`, `license: MIT`, `require` carrying `php` and `atram/laravel-nodeflow` at **the host's own constraint** (**E33**), PSR-4 `autoload` for `src/`, `autoload-dev` for `tests/`, and `extra.laravel.providers` naming the provider (**E9**'s loading mechanism).
2. `provider.stub` declares `$nodes` using **`NodeRegistrationWriter::ANCHOR` byte-for-byte** and a `boot()` calling `\Nodeflow\Nodeflow::register($this->nodes);`. **Only `$nodes`** — no `$triggers`, no `subjectAttributes()` (**E31**), with the README stating why.
3. All rendering uses `strtr`, never `str_replace` with arrays (**F-1**).
4. Every rendered `.php` file is parsed before `scaffold()` returns; a parse failure throws (**E52**).
5. `--js` files are emitted only when `$target->withJs`.
6. Host stub overrides follow the existing convention: `{$basePath}/stubs/package/{name}.stub` wins over the package's own copy, same as `MakeNodeCommand::resolveStubPath()` and `ProviderStep::stub()`.

**Tests** — `tests/Unit/PackageScaffolderTest.php`:

| Test | Assertion |
|---|---|
| emits a package whose composer.json is valid JSON | `json_decode(..., true)` is an array with the expected `name` and `extra.laravel.providers` |
| emits a provider carrying the writer's anchor exactly once | `substr_count($contents, NodeRegistrationWriter::ANCHOR) === 1` — the same gate `ProviderStepTest` uses, and for the same reason: a drifted stub ships a provider no generator can write into |
| emits a provider that parses | `expectParseablePhp()` |
| mirrors the host's nodeflow constraint | given `@dev`, the emitted `require` says `@dev`; given `^2.0`, it says `^2.0` |
| omits resources/js unless withJs | `file_exists` false, then true |
| emits package.json and tsconfig.json alongside index.ts | all three exist under `--js` (**E32**) |
| carries no triggers or subjectAttributes anchor | `TRIGGER_ANCHOR` and `ATTRIBUTE_ANCHOR` both absent (**E31**) |
| prefers a host stub override | write `{root}/stubs/package/provider.stub`, assert its marker appears |
| **throws when a rendered stub does not parse** | temporarily point at a broken host override stub; assert the throw and that nothing is left behind (**E52**, and **F-2**: this guard needs a persisted test) |

- [ ] **Step 1: Write the tests.** **Step 2:** run, confirm failure. **Step 3:** write the stubs. **Step 4:** implement `PackageTarget` and `PackageScaffolder`. **Step 5:** run, confirm pass. **Step 6:** commit.

```bash
git commit -m "feat: scaffold a shareable node package from stubs (E9, E31, E32, E33, E52)

Every rendered PHP stub is parsed before success is reported, because Composer
accepts names PHP cannot express and F-1's failure mode is a generator that
emits unparseable output at exit 0. Provider carries \$nodes only (E31)."
```

---

## Task 7: `MakeNodePackageCommand`

**Files:**
- Create: `src/Console/MakeNodePackageCommand.php`
- Create: `tests/Feature/MakeNodePackageCommandTest.php`

**Interfaces:**
- Consumes: `PackageScaffolder`, `PackageTarget`, `HostPath`.
- Produces: the `nodeflow:make-node-package` command. Signature: `{name} {--namespace=} {--path=} {--js} {--force}`.

**Requirements:**

1. `handle(): int`, resetting any memoised `PackageTarget` at the top (**F-3**).
2. Validate `{name}` against **Composer's own** name pattern: `/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/`. Refuse with the pattern named.
3. **Separately** validate every derived or supplied PHP namespace segment against `/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/` (**E52**). `123vendor/456pkg` is a valid Composer name and an invalid namespace.
4. Default namespace: StudlyCase each of the two Composer segments and join with `\`. `--namespace` overrides.
5. Default path `packages/{vendor}/{name}`; `--path` overrides. Both go through `HostPath::resolveWithin()`, so a symlink escape or a `..` is refused (**E51**).
6. Read the host's own `atram/laravel-nodeflow` constraint from its `composer.json` `require` (**E33**). Absent → refuse, telling the author to require the package first.
7. Occupied target: accepted when its `composer.json` `name` matches `{name}`; refused otherwise unless `--force` (**E43**).
8. Under `--js`, print — never write — the host's Vite alias and tsconfig `paths` snippet (**E32**, **E20**).
9. Every refusal returns `self::FAILURE`.

**Tests** — feature tests using Testbench's `artisan()` against a temp `basePath`:

| Test | Assertion |
|---|---|
| scaffolds into packages/vendor/name by default | tree exists at the expected path |
| refuses an invalid Composer name | `assertFailed()`, output names the pattern |
| **refuses a Composer-valid name that is not a valid PHP namespace** | `nodeflow:make-node-package 123vendor/456pkg` fails; nothing is written (**E52**) |
| honours `--namespace` | emitted provider declares it |
| refuses a `--path` that escapes the host | symlink fixture; `assertFailed()`, nothing written |
| refuses when the host does not require the package | `assertFailed()` naming the fix |
| accepts an existing package directory whose name matches | second run succeeds, does not duplicate |
| refuses a foreign occupied directory without `--force` | `assertFailed()`; with `--force`, succeeds |
| prints but does not write host Vite/tsconfig wiring under `--js` | output contains the snippet; host files untouched |
| **refuses at exit code 1, not 0** | `expect($exitCode)->toBe(1)` on any refusal — the F-3 / `handle(): int` contract |

- [ ] **Step 1–6:** tests → confirm failure → implement → confirm pass → run the full suite → commit.

```bash
git commit -m "feat: add nodeflow:make-node-package (E29, E30, E32, E33, E43, E52)

Two independent validation layers: Composer's name pattern, and PHP identifier
rules for every derived namespace segment. Composer accepts 123vendor/456pkg
and PHP cannot express it."
```

---

## Task 8: `ExtractNodeCommand` — the eight gates, refusals only

**Files:**
- Create: `src/Console/ExtractNodeCommand.php`
- Create: `tests/Feature/ExtractNodeGatesTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–7, plus `NodeRegistry`.
- Produces: the `nodeflow:extract-node` command. Signature: `{class} {--package=} {--namespace=} {--path=} {--force}`. This task implements `handle()` up to and including the gates, returning `self::FAILURE` on any refusal and `self::SUCCESS` with a "gates passed, nothing moved yet" notice — **Task 9 replaces that notice with the moves.**

**The eight gates, in order.** Every one is read-only; none may write. Implement each as a private method returning `?string` (a refusal message) so a test can drive them individually.

| Gate | Rule | Refusal must name |
|---|---|---|
| G1 | `class_exists`, then the same `is_a(Node::class)` and cardinality rules `NodeRegistry::register()` applies — **reuse its exception messages, do not invent new ones** | the class, and which rule failed |
| G2 | `ReflectionClass::getFileName()`; `HostPath::contains()` (**E51**); and the file declares **exactly one top-level named symbol** (**E47**) — no companion class, trait, interface, enum, function or constant | the companion symbol by name |
| G3 | `NodeTypeLiteral::resolve()` (**E36**). Record the literal on the command for Task 9's M9 | the shape found, plus both fixes |
| G4 | If `NodeRegistry` resolves this type to a **different** class, refuse. Unregistered is **not** a refusal | the owning class |
| G5 | `NodeReferenceScanner::scan()`, then subtract the spans Task 9's moves will transform (**E45**). Any survivor refuses | every survivor as `file:line` |
| G6 | Host `composer.json` parses; the package name is not already required from a different source; **and `extra.laravel.dont-discover` does not cover the new package** (**E49**) | the conflicting entry |
| G7 | Target path absent, empty, or a package whose name matches `--package` (**E43**) | what occupies it |
| G8 | `composer` is invocable (`composer --version` exits 0), and whether `composer.lock` exists is recorded for **E48** | that Composer is required, and why |

**Tests** — one refusal test per gate, each asserting **exit code 1** and that the host tree is **byte-identical** afterwards. Capture a recursive hash of the host tree before and after; that assertion is the whole point of gating before moving.

Three tests carry more weight than the others and their comments must say why:

```php
it('refuses a node whose type() is computed, and writes nothing', function () {
    // E36/E10. The one failure re-running cannot repair: type() derived from the
    // class name silently changes identity when the namespace moves, orphaning
    // every published version that references it.
    // Counterfactual: skip G3 entirely and this passes while the extraction
    // proceeds -- verify by commenting out the gate and re-running.
});

it('refuses a node still registered through a legacy Nodeflow::register() call', function () {
    // E45, and the finding that falsified the first design draft. The provider is
    // a file M5 rewrites, so a file-level exemption let this through; a span-level
    // one refuses it. After the move, NodeRegistry::register() autoloads through
    // is_a(), so the surviving entry is a fatal in boot() on every request.
    // The fixture is the demo's real shape.
});

it('refuses a node whose file also declares a trait, naming the trait', function () {
    // E47. M2 rewrites the file's namespace, which moves EVERY declaration in it,
    // while the scan only looks for references to the node. Without this gate the
    // node resolves, type() holds, verification passes, and a host class using the
    // trait dies with "Trait ... not found".
});
```

- [ ] **Step 1–5:** tests → confirm failure → implement the gates → confirm pass → commit.

- [ ] **Step 6: Ordered adversarial probes — run these before committing**

1. Point G2 at a file under `vendor/` → must refuse.
2. Give `--package` a name already required in the host from a **path** repository pointing elsewhere → G6 must refuse.
3. Set `extra.laravel.dont-discover: ["*"]` in the host → G6 must refuse, naming it.
4. Put the FQCN in `config/` only → G5 must refuse (proves **E46**'s widened roots reach it).
5. Put a *different* class with the same short name in `app/` → G5 must **not** refuse (proves the scan does not block legitimate work).

Record each result. A probe that does not discriminate is information — report it and replace it.

```bash
git commit -m "feat: add nodeflow:extract-node's eight read-only gates

Every gate is read-only and all eight run before any move, so 'refuse before
touching anything' is structural. G2 requires exactly one top-level named symbol
(E47); G5 subtracts proven SPANS rather than files (E45); G6 refuses a
dont-discover entry that would silently unregister the node (E49).

Each refusal test asserts a byte-identical host tree, not just an exit code."
```

---

## Task 9: The moves, the journal, and restore

**Files:**
- Create: `src/Console/Extract/ExtractJournal.php`
- Modify: `src/Console/ExtractNodeCommand.php`
- Create: `tests/Feature/ExtractNodeMovesTest.php`

**Interfaces:**
- Consumes: Task 8's gates; `PackageScaffolder`; `NodeRegistrationWriter::removeFrom()`.
- Produces: `ExtractJournal` with `recordWrite(string $path)`, `recordCreate(string $path)`, `recordDelete(string $path, string $contents)`, `restore(): void`. `restore()` replays in reverse: recreated deletions, restored originals, removed creations.

**Moves M1–M7 and M6a.** M8 and M9 are Task 10.

| Move | Rule |
|---|---|
| M1 | Scaffold the package. Pure creation; journal each created path |
| M2 | Write the class into the package, rewriting **the `namespace` declaration and every reference span recorded inside the file itself** — never a global `str_replace` of the old namespace (**F-1**) |
| M3 | Move the test if one exists, rewriting **the resolved import or reference**, not a namespace declaration. `stubs/node.test.stub` declares **no** namespace, so a namespace rewrite is a verified no-op on the exact file this move exists to fix |
| M4 | `NodeRegistrationWriter::register()` into the **package** provider |
| M5 | `removeFrom()` on the **host** provider; then remove the now-unused `use` **only** when the short name appears nowhere else in that file |
| M6 | Add the **relative** path repository and the `require` to the host `composer.json` (**E29**) |
| M6a | **Re-run `NodeReferenceScanner` over the post-move tree.** Any surviving unresolved reference aborts — before anything is deleted (**E45**) |
| M7 | Delete the originals |

**M6a precedes M7** so the rescan runs while the originals still exist and restore is cheap. **M7 precedes M8** because leaving the original means the old FQCN still resolves and G5's guarantee is moot.

**Tests:**

| Test | Assertion |
|---|---|
| moves the class and rewrites only its namespace declaration | new file's namespace is the package's; a docblock mentioning the old namespace is **unchanged** |
| moves the class's test and rewrites its import | the test file's `use` names the new FQCN; **no** namespace declaration was added |
| registers in the package provider and removes from the host provider | both files assert-checked; both parse |
| adds a relative path repository | the emitted `url` is `packages/...`, **not** absolute |
| removes the unused import | host provider no longer imports the class |
| keeps an import still used elsewhere in the file | fixture uses the short name in a second place; import survives |
| **aborts at M6a when a reference survives** | inject a reference the gates could not see (write it during the run); assert abort and a byte-identical host |
| **restores byte-identically on failure injected at each of M1, M2, M3, M4, M5, M6, M6a, M7** | eight tests, or one parameterised over the step; each asserts a recursive hash match and no leftover package directory |

- [ ] **Step 1–6:** tests → confirm failure → implement → confirm pass → commit. Then:

- [ ] **Step 7: The atomicity probe**

For each injected failure point, additionally assert **the absence of the package directory** — not only that tracked files match. The review found the first draft's atomicity tests assumed the package directory was absent to begin with and never covered **E43**'s matching-existing and foreign-`--force` target states. Cover all three target states at at least one failure point.

```bash
git commit -m "feat: move a node into its package, journaled and restorable

M6a re-runs the reference scan over the post-move tree BEFORE anything is
deleted (E45), so a reference the gates could not see aborts cheaply rather than
leaving a half-moved host.

M3 rewrites the test's resolved import, not a namespace declaration: verified
that stubs/node.test.stub declares no namespace, so the first draft's rewrite
was a no-op on the very file the move exists to fix."
```

---

## Task 10: M8 dependency install and M9 host-boot verification

**Files:**
- Create: `src/Console/Extract/ComposerRunner.php`
- Modify: `src/Console/ExtractNodeCommand.php`
- Create: `tests/Feature/ExtractNodeVerificationTest.php`

**Interfaces:**
- Consumes: Task 9's moves and journal.
- Produces: `ComposerRunner` with `install(string $hostPath, string $packageName): bool` and `bootAndResolve(string $hostPath, string $type): ?string` (returns the FQCN the host's registry maps `$type` to, or null). Both shell out; both are injectable so gate tests need no Composer.

**Why both of these replaced the first draft's versions.**

**M8 (E48).** `composer dump-autoload` **does not install a newly required path package** — reproduced:

```
Generating autoload files
Generated autoload files
class_exists("Probe\Pkg\Thing") => bool(false)
```

`dump-autoload` regenerates from installed state and installs nothing, so **every first extraction would have reached M9 with an unloadable class and rolled back** — the command could never have succeeded. M8 is a scoped `composer update {vendor/name} --no-scripts` when `composer.lock` exists, and a full install when it does not. `--no-scripts` is not optional: `post-autoload-dump` runs arbitrary host scripts whose side effects are outside the journal, and the demo's own `composer.json` runs `package:discover` there.

**M9 (E49).** Calling `NodeRegistry::register(New::class)` ourselves proves the class is valid, not that the package's provider was **discovered and ran**. M9 boots the host in a fresh process and asserts the registry **already** maps the recorded type to the new FQCN, before any manual registration. **E37**'s fresh-subprocess requirement stands for its own reason: in-process, the old class is resident and Composer's classmap cached, so `class_exists` can pass against a stale map.

**Journal additions:** `composer.lock`, `vendor/composer/installed.json`, and the generated autoload files.

**Tests:**

| Test | Assertion |
|---|---|
| **a real Composer fixture proves dump-autoload alone is insufficient** | build a temp Composer root with a path repository and `require`; run `dump-autoload`, assert `class_exists` false; run the scoped install, assert true. **Do not mock this** — it is the whole reason E48 exists |
| `--no-scripts` suppresses a host post-autoload-dump script | fixture script writes a marker; assert absent after both the forward run and a restore |
| M9 fails when the provider is not discovered | `dont-discover` fixture; assert abort and restore, and that the failure message names discovery |
| M9 fails when `type()` drifts | **bypass G3** to feed a `static::class`-derived type; assert abort and restore. Per **F-2** this is a persisted test, not a throwaway |
| **in-process verification gives a false pass where subprocess does not** | construct it: old class resident, `dump-autoload` run, verify in-process → passes; subprocess → fails. If this does not discriminate, **say so plainly and replace it** (**R21**'s precedent) |
| restore re-runs the install | assert the host's lock and installed.json match their pre-run bytes |

- [ ] **Step 1–6:** tests → confirm failure → implement → confirm pass → commit.

```bash
git commit -m "feat: install the dependency and verify by booting the host (E48, E49)

M8 was composer dump-autoload, which installs nothing -- reproduced with a real
Composer fixture, class_exists false. Every first extraction would have rolled
back. Now a scoped update with --no-scripts and the lock journaled.

M9 called NodeRegistry::register() itself, proving the class valid rather than
the provider discovered. Now boots the host and asserts the registry already
maps the type; a dont-discover host is refused at G6."
```

---

## Task 11: Register both commands

**Files:**
- Modify: `src/NodeflowServiceProvider.php:70-77`
- Modify: `tests/Feature/InstallCommandTest.php` or the nearest command-registration test

- [ ] **Step 1:** Write a test asserting both `nodeflow:make-node-package` and `nodeflow:extract-node` appear in `Artisan::all()`.
- [ ] **Step 2:** Run, confirm failure.
- [ ] **Step 3:** Add `\Nodeflow\Console\MakeNodePackageCommand::class` and `\Nodeflow\Console\ExtractNodeCommand::class` to the `$this->commands([...])` array, keeping alphabetical order.
- [ ] **Step 4:** Run, confirm pass. Run the full suite.
- [ ] **Step 5:** Commit.

---

## Task 12: Demo — migrate the three nodes into `$nodes`

**Files (demo repo, `~/Sites/test-workflow`):**
- Modify: `app/Providers/NodeflowServiceProvider.php`

**Why (E42).** The demo registers all three nodes through a legacy `Nodeflow::register([...])` literal, which G5 refuses. Migrating them into `$nodes` makes the demo match what `install` generates and what `docs/02-integration.md` teaches — and **keeping the short names** points the real-host run at **E40**/**E50**'s short-name removal path rather than the easy fully-qualified one.

- [ ] **Step 1:** Confirm the demo baseline first: `./vendor/bin/pest` → **56 passed / 223 assertions**; `npx tsc --noEmit` silent. Assert `readlink -f vendor/atram/laravel-nodeflow` points at the **worktree**, not `~/Projects/laravel-nodeflow` on main. If it points at main, the run tests the wrong copy.
- [ ] **Step 2:** Move `SendMessage::class`, `TagUser::class`, `SegmentUsers::class` from the `Nodeflow::register([...])` literal into `protected array $nodes = [`, as **short names**, keeping the existing `use` imports. Delete the now-empty `Nodeflow::register([...])` literal — `boot()` already calls `Nodeflow::register($this->nodes)`.
- [ ] **Step 3:** `php -l` the provider. Run `./vendor/bin/pest` → **still 56 / 223**. Run `npx tsc --noEmit` → silent. Run `npm run build` → passes.
- [ ] **Step 4:** Commit **in the demo repo** with a message recording that this aligns the demo with the documented shape and is a precondition for Plan 6's real-host run.

> **A worktree-isolated session cannot run git in another directory** — the harness refuses. Do this step after `ExitWorktree`, or dispatch a subagent for the demo's git work.

---

## Task 13: Demo — the failed extraction, then the real one

**Why this order.** The deliberately-failed run uses the demo's git cleanliness as an **independent restore oracle** that no fixture test has. Then the real run is kept permanently (**E42**), making the demo's own suite standing regression evidence that a packaged node works end to end.

- [ ] **Step 1: The failed run.** Take a restore point (`git status` clean). Run `extract-node` against `App\Nodeflow\Nodes\SendMessage` with an injected failure at M6a or M7. Assert: exit code 1, and `git status --porcelain` **empty**, and `packages/` absent. If the tree is dirty, the journal is incomplete — fix it in Task 9 and return here.
- [ ] **Step 2: The real run.** `php artisan nodeflow:extract-node "App\Nodeflow\Nodes\SendMessage" --package=atram/nodeflow-demo-nodes`. Expect exit 0.
- [ ] **Step 3: Verify what only the real host can prove.**
  - `demo.send` still resolves: `php artisan tinker` or a one-off script asserting `app(NodeRegistry::class)->resolve('demo.send')::class` is the **new** FQCN.
  - The seeded published `flow_versions.graph` rows still reference `demo.send` and still resolve — this is **E10's guarantee against real persisted data**, and it is the single most valuable piece of evidence in this plan.
  - `./vendor/bin/pest` → **56 / 223**. `npx tsc --noEmit` silent. `npm run build` passes.
  - `git status` shows the new `packages/atram/nodeflow-demo-nodes/` tree and the `composer.json` change, and **nothing else unexpected**.
- [ ] **Step 4:** Commit in the demo repo. Record the measured counts and the resolved FQCN in the message.
- [ ] **Step 5: State what this run did NOT prove.** No `tests/Feature/Nodeflow/` exists, so **M3 was not exercised** by the real host — it is covered by Task 9's synthetic fixture only. Say so in the commit body rather than letting the real-host run imply broader coverage than it has.

---

## Task 14: Documentation, spec, and open issues

**Files:**
- Create: `docs/09-packaging-nodes.md`
- Modify: `docs/02-integration.md` (Step 3)
- Modify: `docs/03-writing-nodes.md`
- Modify: `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md` (§8 "as built" block; E9/E10 delivered; §3 plan table)
- Modify: `docs/superpowers/specs/2026-08-21-remaining-tooling-design.md` (§3.4's citation of the deleted writer comment)
- Modify: `docs/superpowers/open-issues.md`

- [ ] **Step 1:** Write `docs/09-packaging-nodes.md`: both commands, the scaffolded shape, why the provider carries `$nodes` only (**E31**), the controls spread, the `--js` host-wiring snippet, **E36**'s refusal with both fixes named, and **E46**'s stated limits so a reader knows what the scan does not see.
- [ ] **Step 2:** `docs/02-integration.md` Step 3 currently says registering in another provider's `boot()` "still works at runtime… but the generators cannot find it there." Add that **`extract-node` now refuses outright** in that case — **at the place the permission was granted**, not only on the new page.
- [ ] **Step 3:** `docs/03-writing-nodes.md`: one line stating `type()` must be an inline literal or a same-class const if the node is ever to be extracted.
- [ ] **Step 4:** Editor spec: add the §8 "as built" block following §7.2's convention; mark **E9** delivered and **E10 delivered as amended by E36**; update §3's plan table.
- [ ] **Step 5:** Plan 5 spec §3.4: delete the citation of the writer comment that no longer exists, since **E40** closes the gap rather than restating it.
- [ ] **Step 6:** `open-issues.md`:
  - Plan 6 acceptance section with **measured** package and demo counts.
  - **G-6 closed** per the corrected **E41**, recording that the first design draft's rationale for the shorter `PACKAGE_SOURCE` was false and reproduced as such.
  - **G-10 closed**.
  - **New:** the `appendTo()` whole-file presence defect, recorded as found by this plan's external review and fixed by **E50**, together with the one rewritten writer test.
  - **G-7 explicitly still open** — E41 unified the constant but did **not** bound the match to the alias entry.
  - **New residual:** dynamic and database-stored class names are out of reach of the scan (**E46**).
  - **New state:** the demo now permanently has one node in a package and two in the host (**E42**).
  - What was **not** touched: G-5, G-8, G-9, G-11, G-12, D-1, D-2, G-3.
- [ ] **Step 7:** Commit.

---

## Final verification, before `finishing-a-development-branch`

- [ ] Package: `./vendor/bin/pest` — record measured tests and assertions; must exceed 488 / 6,152.
- [ ] Package: `npx vitest run` — expect **160**, unchanged; this plan touches no client code.
- [ ] Package: `npx tsc --noEmit` — silent.
- [ ] Demo: `./vendor/bin/pest` — **56 / 223**. `npx tsc --noEmit` silent. `npm run build` passes.
- [ ] Demo: `readlink -f vendor/atram/laravel-nodeflow` asserted before trusting any demo gate.
- [ ] **E44's absences are still absent.** Assert no `--allow-references`, no `--no-verify`, no
  un-extract command, no `--rename`, and no multi-class invocation has appeared. Each would remove a
  guard, and a class rename in particular reopens the hole E36 closes: it changes `class_basename`,
  which is precisely the derivation the empirical check at M9 cannot see.
- [ ] **A whole-branch review is not optional.** Sixteen per-task reviews each passed their own brief in Plan 5; the whole-branch review then found four Critical false accepts that only became visible when the steps were compared against each other and against the real host. Ask specifically: do the gates and the moves agree about what a "rewrite span" is? Do `appendTo()` and `removeFrom()` resolve names identically? Does any refusal exit 0?
- [ ] Write an execution record at `docs/superpowers/plans/2026-08-21-node-packaging-execution-record.md` covering what execution corrected, every ruling taken without asking, and the measured counts.
