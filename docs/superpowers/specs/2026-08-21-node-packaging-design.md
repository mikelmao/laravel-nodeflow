# Node packaging (Plan 6) — Design

The sixth and final plan of the effort described in
`docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md`. That document's **§8** is
this design's requirement; its **E9** and **E10** are the two decisions it inherits and must not
weaken.

Written 2026-08-21, against package `a04741f` (488 Pest tests / 6,152 assertions, 160 Vitest,
silent `tsc`) and demo `58cd733` (56 Pest tests / 223 assertions, silent `tsc`, passing
`npm run build`), both measured rather than assumed.

Read `docs/superpowers/plans/2026-08-21-nodeflow-remaining-tooling-execution-record.md` before
this. Its headline finding governs everything below: **every significant defect in Plan 5 was found
by execution, none by reading**, and the characteristic bug of this codebase is a substring test
standing in for real checking — four separate appearances in Plan 5 alone. This plan does path and
namespace surgery, so that bug is assumed present until probes prove otherwise.

---

## 1. Context

### 1.1 What the two commands are for

A host writes a node, finds it useful beyond one application, and wants to share it. **E9** already
settled the shape: an ordinary Composer package, no manifest, no build step, no discovery
mechanism. `nodeflow:make-node-package` scaffolds that package. `nodeflow:extract-node` scaffolds it
and then moves an existing node into it.

### 1.2 The premise §8 does not state, and it is the hard part

`extract-node` must **remove** an entry from the host provider's `$nodes` array.
`NodeRegistrationWriter` only appends, and removal is materially harder: it must not match a longer
sibling name, must recognise every form a host may have written, must not match inside a comment,
and must leave the file byte-identical when it refuses.

It is also **the first destructive edit this package makes to host code.** Everything shipped
through Plan 5 appends or creates. A bug in removal deletes a host's line. That asymmetry is why
§6's verification rules are non-negotiable rather than belt-and-braces.

### 1.3 Three premises measured before designing

**The only real host is 100% the legacy registration shape.** `~/Sites/test-workflow`'s
`NodeflowServiceProvider` has `protected array $nodes = [` **empty**, and registers all three demo
nodes through a `Nodeflow::register([SendMessage::class, TagUser::class, SegmentUsers::class])`
literal in `boot()` — as bare short names behind `use` imports. So the shape §8's removal was never
specified for is the only shape that exists, in exactly the short-name form that produced Plan 5's
worst defect (G-10 / execution-record C1).

**All three demo node types appear in seeded published `flow_versions.graph` rows**, `demo.send`
most heavily — it is in both demo flows and it is what runs execute. That makes E10's `type()`
continuity guarantee testable against real persisted data rather than a fixture.

**The demo has no `tests/Feature/Nodeflow/` directory.** It therefore cannot exercise the
"move the class's test too" path, and this design does not claim it does.

### 1.4 The measurement that shapes E36 and E37

§8.2 requires asserting `type()` is unchanged after the move. That check alone is **not sufficient**,
and the reason was measured rather than reasoned:

```
strtolower(class_basename('App\Nodeflow\Nodes\SendMessage'))  -> sendmessage
strtolower(class_basename('Vendor\Pkg\Nodes\SendMessage'))    -> sendmessage
```

A `type()` derived from the class *basename* returns the identical string before and after a
namespace move. The empirical check passes; the type is still derived from the class name, which the
foundation spec §5 forbids, and the author's next class rename orphans every published version.
So the static refusal E10 asks for is **load-bearing, not defence-in-depth** — it catches a class of
danger observation provably cannot see.

---

## 2. Decisions

Continuing the numbering from Plan 5's **E28**.

| # | Decision | Rationale |
|---|---|---|
| E29 | Packages scaffold to **`packages/{vendor}/{name}/`** under the host base path, and the emitted Composer repository uses a **relative** `url`. `--path=` overrides, asserted contained by `HostPath` | Relative is what survives the host being rebuilt with `composer install` on another machine. The demo's existing absolute `/Users/mikelmao/Projects/laravel-nodeflow` repository entry is a wart not to reproduce. An extracted package must live inside the host repo and be committed, or a rebuild cannot resolve it |
| E30 | **`--namespace=` is a first-class option**, not a nicety. Default is `vendor/name` → `Vendor\Name` | Our own package disproves the default: `atram/laravel-nodeflow` is namespaced `Nodeflow\`. A Composer name that does not match the PHP namespace is the common case |
| E31 | The scaffolded provider carries **`$nodes` only** — not `$triggers`, not `subjectAttributes()` | A trigger's `event()` returns a **host** event class and a subject attribute's resolver closure takes a **host** model. Neither travels in a shared package. The README states this as a decision with its reason so it reads as considered, and documents that adding either anchor by hand works and the generators find it |
| E32 | **`--js` is opt-in**, and emits `resources/js/index.ts` **plus** the minimal `package.json` and `tsconfig.json` needed for a host `tsc` to resolve it. Host-side Vite alias and tsconfig `paths` are **printed, never written** | Emitting TypeScript nobody can typecheck is the quiet-failure class this project keeps paying for. Not writing the host's two config files is **E20**'s reason unchanged: a passing re-read would not prove the edit landed in the active exported config |
| E33 | The scaffolded package's `require` constraint on `atram/laravel-nodeflow` **mirrors the host's own** | The package is unreleased, so `^1.0` would be a lie and a hardcoded `@dev` would be wrong the day it releases. The host must already require it — the node came from there — so mirroring is correct today, correct after release, and self-maintaining |
| E34 | `extract-node` **refuses** a class referenced anywhere but the files it will itself rewrite, via a **general reference scan** rather than a legacy-`Nodeflow::register()` special case | After the move the old FQCN still resolves as a `::class` *string*, but `NodeRegistry::register()` autoloads through `is_a()` — so a stale reference is a fatal in the host's provider `boot()`, on every request. A legacy literal is one instance of "a reference this command will not rewrite"; making the scan the mechanism means the literal needs no special case, and removal never has to do argument-list surgery. **E25 is unchanged** — `install` still leaves legacy calls alone, because *appending* alongside them is harmless and removal is not appending |
| E35 | The reference scan applies **PHP's own name-resolution rule**, resolving `use` statements and aliases per file | A bare `SendMessage` refers to the target only under that rule. This reverses `ProviderRegistrationStep`'s deliberate declination to parse use-statements, **scoped to this command only**, because the stakes inverted: there a false positive was harmless and the shape unseen; here a false negative leaves the host fatal and a false positive blocks legitimate work in any codebase containing another `SendMessage`. It is the language's rule, not a heuristic |
| E36 | `type()` is accepted in **exactly two shapes**: an inline quoted literal, or a `self::`/`static::` **constant declared in the same class body** whose initialiser is a quoted literal. A constant on any other class is refused, naming both fixes | The guarantee is identical in both accepted shapes: a const initialised to a quoted string cannot vary with namespace or class name. Refusing the same-class const would tell a developer whose good practice exposed `SendMessage::TYPE` to their tests and config to make their code worse. Cross-class resolution needs import, alias, inheritance and interface-constant handling — the use-statement resolution E35 permits *only* for the reference scan, deliberately not extended to the one unrecoverable guard |
| E37 | The empirical `type()` comparison runs in a **fresh subprocess** after `composer dump-autoload`, and is an independent second gate that **cannot substitute for E36** | In-process verification proves nothing: the old class is resident and Composer's classmap is cached in memory, so `class_exists` on the new FQCN can pass against a stale map and `type()` can be read off the old class. That is "a test passing against the very bug it names." And per §1.4, a basename-derived type survives the move unchanged, so this gate is blind to a danger E36 sees. Both ship. There is no `--no-verify` |
| E38 | Removal gets its **own** `NodeRemovalOutcome` enum. `NodeRegistrationOutcome` stays at six cases, untouched | One enum where `Appended` is meaningless for a removal and `Removed` is meaningless for an append is two jobs in one weak type, and growing it would put an `UnhandledMatchError` risk into every call site Plans 1 and 5 shipped. Leaving it untouched keeps every existing `match` compiling and all 16 writer tests passing unchanged — the same "untouched tests are the evidence" argument **E23** used |
| E39 | Removal is **line-wise**. Three accepted layouts: the entry alone on its line; the entry as the sole content between the brackets; and a shared line **refused** as `EntryAmbiguous` | Deleting from inside a line shared with a sibling entry means preserving that line's other content byte-exactly, which is where this codebase's substring bug would live for the fifth time. This refuses a form spec §4.1 and **R6** explicitly permit — accepted cost, because a loud refusal naming the fix beats character surgery on the riskiest edit in the plan |
| E40 | **G-10 is closed in code**, not re-documented. The bounded short-name matcher built for removal is shared with `appendTo()`'s presence check | Measured free: no existing test asserts the short-name duplicate behaviour, so all 16 writer tests stay untouched. The gap's documentation was deleted in Plan 5's Task 3 and spec §3.4 still cites it; closing the gap makes the citation correct to delete rather than restate |
| E41 | **G-6 unifies the arithmetic, not the strings.** One segment representation, one relative-depth calculation, one containment rule; the two `PACKAGE_SOURCE` string variants stay | `ViteAliasStep` uses the shorter `atram/laravel-nodeflow/resources/js` deliberately, so its substring check matches whether the host wrote `vendor/…` or `./vendor/…`. Collapsing all three onto one value would tighten that check and break a host the current code accepts. Reading G-6 as "make the three constants one value" is the wrong fix, recorded here before anyone attempts it |
| E42 | The demo's three nodes **migrate into `$nodes`**, and one node is then extracted and the extraction **kept permanently** | The demo is a development fixture, expendable and re-creatable; no host exists. So the question is not how to protect it but what is most informative to do to it. A standing packaged node makes the demo's own 56 tests permanent regression evidence that a packaged node works end to end against `flow_versions.graph` rows already saying `demo.send`. Migrating to `$nodes` also makes the demo match what `install` generates and what the docs teach — and keeping short names in `$nodes` points the real-host run at E40's short-name path rather than the easy fully-qualified one |
| E43 | An occupied target package path is **accepted** when its `composer.json` name matches `--package`, and refused only when occupied by something else. `--force` means "overwrite a foreign directory" | Extracting three related nodes into one package is the common case; refusing an occupied path would break it. This is a correction to an earlier draft of this design that refused any occupied path |
| E44 | **No** `--allow-references`, **no** `--no-verify`, **no** un-extract, **no** class rename during extraction, and **one node per invocation** | Each removes a guard or adds a path with no covering evidence. A rename is the sharpest: it changes `class_basename`, which is precisely the derivation E37 provably cannot see, so allowing it reopens the hole E36 exists to close |

---

## 3. Component boundaries

Four new units, plus one extension. Both commands are **orchestration only** — every decision is
delegated to a unit testable without invoking a command.

| Unit | Responsibility | Depends on |
|---|---|---|
| `HostPath` | One normalised segment representation; relative depth; containment; `..` rejection | — |
| `NodeTypeLiteral` | E36's guard. Given a class file's source and a class name, return the literal type or a typed refusal reason. No filesystem, no autoload | — |
| `NodeReferenceScanner` | E35's scan. Given a class and a set of roots, return every reference with file and line | `HostPath` |
| `PackageScaffolder` | Emit the package tree from stubs | `HostPath` |
| `NodeRegistrationWriter::removeFrom()` | §6's removal, returning `NodeRemovalOutcome` | — |

The seam that matters: `ExtractNodeCommand` is **a sequence of gates, then a sequence of moves**,
and every gate is pure or read-only. That is what makes "refuse before touching anything" the
default rather than a discipline — §5.1's seven gates all complete before the first byte is written.

---

## 4. `nodeflow:make-node-package {vendor/name}`

```
nodeflow:make-node-package {vendor/name} [--namespace=] [--path=] [--js] [--force]
```

**Validation.** `vendor/name` is checked against **Composer's own** name pattern rather than one
invented here — it is rendered into `composer.json` *and* into a filesystem path, so it is
validated, not escaped, unlike `MakeNodeCommand`'s `--group`. That pattern also forecloses `..`
traversal through the name. `--path` has no such pattern and therefore carries its own `HostPath`
containment assertion: the resolved target must be inside the host base path, compared by segment,
never by string prefix.

**Emitted tree** (`--js` parts marked):

```
packages/{vendor}/{name}/
├── composer.json
├── README.md
├── src/
│   ├── {Studly}ServiceProvider.php
│   └── Nodes/
├── tests/
├── package.json          (--js)
├── tsconfig.json         (--js)
└── resources/js/index.ts (--js)
```

The provider's `$nodes` anchor is **byte-identical to `NodeRegistrationWriter::ANCHOR`**, so
`nodeflow:make-node` can be run inside the package later and register into it.

`composer.json` carries `extra.laravel.providers` (E9's loading mechanism), a PSR-4 autoload for
`src/`, an `autoload-dev` for `tests/`, and the `require` constraint E33 mirrors from the host.

`handle(): int`, and instance-cached state reset at the top of `handle()` per **F-3**.

---

## 5. `nodeflow:extract-node {FQCN} --package=vendor/name`

```
nodeflow:extract-node {FQCN} --package=vendor/name [--namespace=] [--path=] [--force]
```

### 5.1 Seven gates, all read-only, all before the first byte

| | Gate | Refuses when |
|---|---|---|
| G1 | Class resolves and is a node | Not found, or fails the same `is_a`/cardinality rules `NodeRegistry::register()` applies — reusing those messages |
| G2 | Source file locatable, inside the host, single-class | Under `vendor/`, outside the project, or the file declares more than one class |
| G3 | `type()` provable per E36 | Any shape but the two accepted. Records the literal for M9 |
| G4 | Type ownership | `NodeRegistry` resolves this type to a **different** class. **Unregistered is not a refusal** — a freshly generated node is legitimately unwired, and removal reports `NotPresent` |
| G5 | Reference scan (E34, E35) | Any reference outside the files this command will itself rewrite |
| G6 | Host `composer.json` present, parseable, no conflicting requirement | The package name is already required from a different source |
| G7 | Target package path absent, empty, or a package whose name matches `--package` (E43) | Occupied by something else, unless `--force` |

**G2 is where `str_starts_with` would be wrong.** "Is this file inside the host?" by string prefix
accepts `/Users/me/project-evil/app/Foo.php` for base path `/Users/me/project`. It is a `HostPath`
segment comparison. This is the fifth place that bug class would appear.

**G5's classification rule**, which is PHP's own:

| Observation | Verdict |
|---|---|
| The FQCN appears anywhere in code | Reference |
| Bare short name **and** the file carries `use <oldFQCN>;`, or an alias of it | Reference |
| Bare short name, file imports a **different** `…\SendMessage` | Not a reference |
| Bare short name, no import, different namespace | Not a reference — PHP resolves it to the file's own namespace |
| The FQCN inside a **string literal** (a config array, a class-string) | Reference — it genuinely breaks on the move |
| Either form inside a comment | Not a reference |

### 5.2 Nine moves, journaled for reverse restore

| | Move |
|---|---|
| M1 | Scaffold the package (`PackageScaffolder`) — pure creation |
| M2 | Write the class in, rewriting **the `namespace` declaration only** |
| M3 | Move its test, if one exists, with the same rewrite |
| M4 | Register it in the **package** provider's `$nodes` |
| M5 | Remove it from the **host** provider's `$nodes`, and the now-unused `use` **only** when the short name appears nowhere else in that file |
| M6 | Add the relative path repository and `require` to the host `composer.json` |
| M7 | Delete the originals |
| M8 | `composer dump-autoload` |
| M9 | Verify in a fresh subprocess (E37): the new FQCN resolves, `NodeRegistry::register()` accepts it, and `type()` is byte-identical to G3's recorded literal |

**M2's rewrite replaces the `namespace` declaration, not every occurrence of the old namespace
string.** A global `str_replace` through the file is **F-1**'s sequential-substitution mistake again
and would silently rewrite a string literal or a docblock that legitimately names the old location.

**M7 precedes M8** deliberately: leaving the original means the old FQCN still resolves and G5's
guarantee is moot.

Any failure in M1–M9 restores in reverse from the journal, **re-runs `dump-autoload`** — or the host
is left with an autoloader describing a state that no longer exists — and exits non-zero.

### 5.3 The `type()` guard

`NodeTypeLiteral` tokenises the class file and accepts exactly:

- `return '<literal>';` — including a double-quoted string with no interpolation, which is still one
  `T_CONSTANT_ENCAPSED_STRING`.
- `return self::TYPE;` or `return static::TYPE;` where `const TYPE = '<literal>';` is declared **in
  the same class body**.

Everything else refuses with the shape named: concatenation (even of two literals — accepting it
opens the door to `'x' . static::class`), interpolation, a cross-class constant, an inherited
constant, a `type()` supplied by a trait, and a `match` or any other expression.

---

## 6. The removal machinery

`NodeRegistrationWriter::removeFrom()`, returning `NodeRemovalOutcome`:
`Removed | NotPresent | ProviderMissing | AnchorMissing | AnchorAmbiguous | EntryAmbiguous |
WriteFailed`.

**Three entry forms**, each matched bounded so `SendSmsExtra::class` can never match
`SendSms::class`:

| Form | Origin |
|---|---|
| `\App\Nodeflow\Nodes\SendMessage::class,` | what `register()` writes |
| `App\Nodeflow\Nodes\SendMessage::class,` | hand-written |
| `SendMessage::class,` | hand-written behind a `use` — G-10's form, and the demo's after E42 |

**Three layouts**, per E39: entry alone on its line (modulo whitespace and a trailing `//` comment)
→ the line goes; entry as the sole content between the brackets, one-line array included → the body
empties; entry sharing a line with a sibling → `EntryAmbiguous`, refused, naming the line and the
fix.

**Verification is `appendTo()`'s inverted.** Re-read; the result must still parse; and the needle
must now be **absent** from the comment-stripped text. Either failure restores the original bytes
and returns `WriteFailed`. An entry inside a comment never matches, so a commented-out registration
is `NotPresent` — not a silent no-op reported as success.

---

## 7. Testing

Spec §9's rule stands: for every test, name the production change that would make it fail, and
**execute it**. Test arithmetic travels in each dispatch as "previous measured total plus this
task's new tests", never a table (**R11**), and assertion counts are recorded as measured.

### 7.1 Adversarial probes, ordered explicitly

Plan 5 found three real defects only because an implementer was told to break their own rule and
given specific angles. Free-form review found none of them.

**`HostPath`** — inherits four proven bug shapes:
- `/Users/me/project-evil/app/Foo.php` against base `/Users/me/project` must read **outside**.
- `/Users/me/project/../other/app/Foo.php` must read outside, not collapse into a match (**R12**, **R13**).
- Base `…/project`, entry `…/project/resources/project/css/app.css` — depth must be 3 (**R15**).
- Symlinks: the demo's `vendor/atram/laravel-nodeflow` *is* one, so containment must state whether
  it compares raw or resolved paths, and be tested both ways.

**`NodeTypeLiteral`** — a whitelist audit:
- `return 'a' . 'b';` → refuse.
- `return "demo.send";` → accept. `return "demo.{$x}";` → refuse.
- `return self::TYPE;` with `TYPE` **inherited from a parent** → refuse. This is the probe that
  proves "same class body" is enforced and that the tokeniser is not reaching through a trait or a
  parent.
- `type()` supplied by a **trait** → refuse; the method is not in the file.
- A comment containing `return 'fake.type';` above the real method → must not match.
- Two `type()` methods in one file (an anonymous class inside the node) → pick the right one or
  refuse; checks that G2 and G3 agree.

**`removeFrom()`**:
- Remove `SendSms::class` from an array also holding `SendSmsExtra::class`; the survivor is
  byte-identical.
- Inverse: only `SendSmsExtra::class` present → `NotPresent`, byte-identical. Proves the bound both
  directions.
- Entry inside `//`, and inside a docblock example → `NotPresent`, byte-identical.
- A commented-out second `$nodes` anchor → `AnchorAmbiguous`, byte-identical.
- Last entry without a trailing comma; sole entry; two entries sharing a line; entry with a trailing
  same-line comment.
- **False-pass probe:** break `removeFrom()` to a no-op that still returns `Removed`. Any test that
  passes was asserting the enum instead of the file, and gets rewritten.

**`NodeReferenceScanner`** — probed in both directions, because a false positive blocks legitimate
work and a false negative leaves the host fatal: another class legitimately named `SendMessage`
imported elsewhere (not a reference); `use … as Sender;` with `Sender::class` (reference); bare
`SendMessage::class` in the same namespace without an import (reference); bare `SendMessage` in a
different namespace without an import (not a reference); the FQCN in a string literal (reference);
either form in a comment (not a reference).

**M9 — the sharpest probe in the plan.** Construct the exact false pass: old class resident,
`dump-autoload` run, verify in-process → passes; verify by subprocess → fails. If the probe turns
out not to discriminate, say so plainly and replace it rather than fabricate a match (**R21**'s
precedent). And per **F-2**, M9's guard ships with a **persisted** test that bypasses G3 to feed it
a genuinely drifting `type()`, proving the second gate independently rather than proving the first
shadows it.

**Atomicity** — injected failure at each of M2 through M8, asserting the host tree byte-identical
including `composer.json` and the absence of the package directory. M8 is the nastiest: restore must
re-run `dump-autoload`.

**Stubs** — per **F-2**, every new stub is rendered and asserted **structurally**, not merely parsed.
Nothing but `php -l` watched `stubs/node.both.stub` and that is a live open issue.

### 7.2 What the demo run can and cannot prove

**Can:** short-name removal against a real hand-written provider with real `use` imports (E40's
path); a working relative path repository in a real `composer.json`; `dump-autoload` genuinely
resolving the new FQCN; **`type()` continuity against real persisted `flow_versions.graph` rows
already saying `demo.send`** — E10's guarantee against real data; and the demo's own 56 tests still
passing with a packaged node, meaning palette, editor and run execution all survive, plus a passing
`npm run build`.

**Cannot:** M3, the test-move path — the demo has no `tests/Feature/Nodeflow/` (§1.3), so that needs
a synthetic fixture and no claim is made otherwise. Nor `--js` controls actually rendering, which is
**G-5**'s browser territory.

**One free oracle.** Before the real extraction, run one *deliberately failed* extraction against
the demo and let the command's own journal restore it, then verify with `git status` that the tree
is clean. The demo's git cleanliness is an independent restore oracle no fixture test has.

---

## 8. Error handling

| Condition | Response |
|---|---|
| `vendor/name` fails Composer's pattern | Refuse, naming the pattern |
| `--path` resolves outside the host | Refuse, by segment comparison |
| Target path occupied by a foreign directory | Refuse unless `--force` (E43) |
| Class not found, or not a node | Refuse, reusing `NodeRegistry::register()`'s messages |
| Source file outside the host, or multi-class | Refuse |
| `type()` not provable per E36 | Refuse, naming the shape found and both fixes: inline the literal, or move the const onto the node |
| Type registered to a different class | Refuse, naming the owner |
| Type not registered at all | Proceed; removal reports `NotPresent` |
| A reference outside the rewritten files | Refuse, listing file and line |
| Entry shares a line with a sibling | `EntryAmbiguous`; file byte-identical |
| Entry absent, or present only in a comment | `NotPresent`; file byte-identical |
| Post-write re-read fails to parse, or the needle survives | `WriteFailed`; original bytes restored |
| Any failure in M1–M9 | Restore in reverse, re-run `dump-autoload`, exit non-zero |
| Any refusal | `handle(): int` returns `FAILURE` — never `false` (**§7.2**, **F-3**) |

---

## 9. Documentation

**New page `docs/09-packaging-nodes.md`** — both commands, the scaffolded shape, why the provider
carries `$nodes` only (E31), the controls spread, the `--js` host-wiring snippet, and E36's refusal
with both fixes named.

**`docs/02-integration.md`** — Step 3 currently says registering in another provider's `boot()`
"still works at runtime… but the generators cannot find it there." After this plan that choice
acquires a second cost: **`extract-node` refuses outright** (G5). The docs must say so *at the place
they granted the permission*, not only on the new page.

**`docs/03-writing-nodes.md`** — one line: `type()` must be an inline literal or a same-class const
if the node is ever to be extracted.

**Spec and issues** — an "as built" block on editor-spec §8 following §7.2's convention; E9, E10 and
§3's plan table marked delivered; Plan 5 spec §3.4's citation of the deleted writer comment
corrected, since E40 closes the gap rather than restating it; and in `open-issues.md`, a Plan 6
acceptance section with measured counts, G-6 and G-10 closed, and an explicit statement of what was
**not** touched.

---

## 10. Scope

### In scope

`make-node-package`; `extract-node`; `removeFrom()` and `NodeRemovalOutcome`; `HostPath`,
`NodeTypeLiteral`, `NodeReferenceScanner`, `PackageScaffolder`; **G-6** (E41) and **G-10** (E40); the
demo migration and extraction (E42); and §9's documentation.

### Explicitly out of scope

- **D-1**, **D-2** and **G-3** — all reserved for the dedicated security-hardening plan, per **E26**.
  Not absorbed.
- **G-5** — browser acceptance, still open. This plan *adds* a reason to want it, since after E42 the
  demo renders and executes a node living in a package and no test observes that from the client. It
  needs a manual Chrome toggle and stays tracked.
- **G-7**, **G-8**, **G-9**, **G-11**, **G-12** — adjacent, not in this path. Plan 6's own emitted
  snippets use fully-qualified names so they do not repeat G-9's mistake in new code.
- **C-1** through **C-6**. **C-5** and **C-6** are Plan 4's two honest `reached` limitations —
  preserved, not redesigned.
- Everything editor-spec **§11** lists, and `make-flow` / `make-field-control` per **§7.3**.
- Packagist publishing, versioning and tagging; a manifest (**E9**); un-extract; class rename during
  extraction; multi-node invocation (**E44**).

### Known residuals this plan accepts

- **`composer dump-autoload` is a coverage boundary.** M8 and M9 shell out and the package's suite
  has no real Composer project. The verification unit is injectable so gates test without shelling
  out, which means M8/M9's *real* behaviour is proven by the demo run and one slow fixture test
  needing `composer` on PATH — not by the fast suite.
- **`--js` output is unverified until the host wires it** — the same quiet-failure class as the
  original five wiring requirements. The README says so and the command prints the snippet.
- **The reference scan will refuse often in real projects**, and that is the most likely DX
  complaint. `--allow-references` was considered and rejected (proceeding leaves the host fatal);
  having the command rewrite those references was considered and rejected as the "edit files you
  cannot verify" move **E11** forbids. First thing to revisit once anyone has used it in anger.
- **E39 refuses a form spec §4.1 permits.** Accepted, with the fix named in the message.
- **After E42 the demo permanently has one node in a package and two in the host.** Deliberate, and
  arguably a better fixture since it covers both shapes, but recorded so it does not surprise.
- **`HostPath` assumes `/` separators**, as every install step already does. Not a new risk, but it
  becomes the single place a Windows fix would land.

---

## 11. Task order

| # | Task | Gate |
|---|---|---|
| 1 | `HostPath`, and migrate the install steps' arithmetic onto it (E41) | All 488 pass **untouched** |
| 2 | `NodeTypeLiteral` (E36) | §7.1's whitelist audit |
| 3 | `NodeRemovalOutcome`, `removeFrom()`, shared short-name matcher into `appendTo()` (E38, E39, E40) | 16 writer tests untouched |
| 4 | `NodeReferenceScanner` (E35) | Both-direction probes |
| 5 | `PackageScaffolder` + stubs | Every stub asserted structurally |
| 6 | `MakeNodePackageCommand` | `handle(): int`; F-3 reset |
| 7 | `ExtractNodeCommand` gates G1–G7, refusals only | Every refusal leaves the tree byte-identical |
| 8 | Moves M1–M9, journal and restore | Injected failure at each step |
| 9 | Register both commands | |
| 10 | **Demo:** migrate three nodes into `$nodes` (E42) | 56/223 holds before extraction starts |
| 11 | **Demo:** deliberately failed extraction, restored by journal | `git status` clean |
| 12 | **Demo:** the real extraction, kept (E42) | 56/223, silent `tsc`, passing `npm run build` |
| 13 | Docs, spec, open-issues (§9) | |

---

## 12. Traceability

| Requirement | Where met |
|---|---|
| A node can be packaged and shared (editor-spec §8.1, E9) | §4 |
| An existing node can be extracted into a package (§8.2, E10) | §5 |
| `type()` is byte-identical after the move | E36, E37, §5.3, G3 + M9 |
| A computed `type()` is refused outright | E36, §5.3 |
| Extraction never leaves a half-moved state | §5.1's gate ordering, §5.2's journal |
| No manifest | E9, §4 |
| Host keeps working from the new location | E34, G5, M6, M9 |
| `handle(): int` on every command | §8, F-3 |
| Instance-cached state reset per invocation | §4, F-3 |
| One shared path helper | E41, §3 |
| The writer's short-name gap closed and documented | E40 |
