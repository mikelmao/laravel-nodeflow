# Node packaging (Plan 6) — Design

The sixth and final plan of the effort described in
`docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md`. That document's **§8** is
this design's requirement; its **E9** and **E10** are the two decisions it inherits.

Written 2026-08-21 against package `a04741f` (488 Pest tests / 6,152 assertions, 160 Vitest, silent
`tsc`) and demo `58cd733` (56 Pest tests / 223 assertions, silent `tsc`, passing `npm run build`),
both measured rather than assumed.

**Revised 2026-08-21 after an adversarial external review** (Codex, dispatched with instructions to
construct and execute counterexamples rather than read). It returned fourteen findings; five were
Critical and three of those were structural to the move sequence. Four of its findings contradicted
claims in the first draft and **all four were reproduced locally before being accepted**. The
amendments are **E45** through **E53**, plus a corrected **E41**. §1.5 records what the review
changed and what survived it, because the surviving claims are evidence too.

Read `docs/superpowers/plans/2026-08-21-nodeflow-remaining-tooling-execution-record.md` before this.
Its headline finding governs everything below: **every significant defect in Plan 5 was found by
execution, none by reading**, and the characteristic bug of this codebase is a substring test
standing in for real checking — four appearances in that one plan. This plan does path and namespace
surgery, so that bug is assumed present until probes prove otherwise. The review's own results are
the fifth through eighth appearances.

---

## 1. Context

### 1.1 What the two commands are for

A host writes a node, finds it useful beyond one application, and wants to share it. **E9** settled
the shape: an ordinary Composer package, no manifest, no build step, no discovery mechanism.
`nodeflow:make-node-package` scaffolds that package. `nodeflow:extract-node` scaffolds it and then
moves an existing node into it.

### 1.2 The premise §8 does not state, and it is the hard part

`extract-node` must **remove** an entry from the host provider's `$nodes` array.
`NodeRegistrationWriter` only appends, and removal is materially harder: it must not match a longer
sibling name, must recognise every form a host may have written, must not match inside a comment,
and must leave the file byte-identical when it refuses.

It is also **the first destructive edit this package makes to host code.** Everything shipped through
Plan 5 appends or creates. A bug in removal deletes a host's line. That asymmetry is why §6's
verification rules are non-negotiable rather than belt-and-braces.

### 1.3 Three premises measured before designing

**The only real host is 100% the legacy registration shape.** `~/Sites/test-workflow`'s
`NodeflowServiceProvider` has `protected array $nodes = [` **empty**, and registers all three demo
nodes through a `Nodeflow::register([SendMessage::class, TagUser::class, SegmentUsers::class])`
literal in `boot()` — as bare short names behind `use` imports. The shape §8's removal was never
specified for is the only shape that exists, in exactly the short-name form that produced Plan 5's
worst defect (G-10 / execution-record C1).

**All three demo node types appear in seeded published `flow_versions.graph` rows**, `demo.send` most
heavily — it is in both demo flows and it is what runs execute. That makes E10's `type()` continuity
guarantee testable against real persisted data rather than a fixture.

**The demo has no `tests/Feature/Nodeflow/` directory.** It cannot exercise the "move the class's
test too" path, and this design does not claim it does.

### 1.4 The measurement that shapes E36 and E37

§8.2 requires asserting `type()` is unchanged after the move. That check alone is **not sufficient**:

```
strtolower(class_basename('App\Nodeflow\Nodes\SendMessage'))  -> sendmessage
strtolower(class_basename('Vendor\Pkg\Nodes\SendMessage'))    -> sendmessage
```

A `type()` derived from the class *basename* returns the identical string before and after a
namespace move. The empirical check passes; the type is still derived from the class name, which the
foundation spec §5 forbids, and the author's next class rename orphans every published version. So
the static refusal E10 asks for is **load-bearing, not defence-in-depth** — it catches a class of
danger observation provably cannot see. Independently reproduced by the external review.

### 1.5 What the external review changed, and what survived it

**Five Critical findings, all accepted.** Three were structural: the reference scan's exemption unit
was wrong (**E45**), `composer dump-autoload` cannot install a newly required path package
(**E48**), and M9's verification was a false oracle for provider discovery (**E49**). Two were
completeness gaps in gates that read as thorough: G2's single-class rule let companion symbols move
(**E47**), and removal's `NotPresent` conflated absence with a live registration it could not edit
(**E50**).

**Four contradicted the first draft and were reproduced locally before acceptance:**

| Claim | Reproduction |
|---|---|
| `composer dump-autoload` does not install a newly required path package | Minimal fixture with a real path repository and `require`: `Generated autoload files`, then `class_exists(...) => false`. The scoped remedy additionally needs a lock present |
| The generated test stub has no `namespace` declaration | `stubs/node.test.stub` opens `<?php` then `use {{ namespacedClass }};`. A namespace-declaration rewrite is a **no-op** on it |
| A qualified name without a leading backslash is not the target FQCN | Inside `namespace App\Providers;`, `App\Nodeflow\Nodes\SendMessage::class` resolves to `App\Providers\App\Nodeflow\Nodes\SendMessage` |
| E41's rationale was false | `str_contains('./vendor/atram/…', 'vendor/atram/…')` is **true**, so the full string already tolerates the `./` prefix and the shorter constant buys nothing but false accepts |

**What survived, which is evidence in its own right.** The review attacked **E36** with eight
constructed and executed cases and **could not produce a pass-but-drift**: four accepted forms stayed
byte-identical, two refused forms were provably safe, two refused forms genuinely drifted. It
independently reproduced §1.4. It verified the six-case `NodeRegistrationOutcome`, the 16 writer
tests, that `NodeRegistry::register()` really autoloads through `is_a()`, and every measurement in
§1.3. E36 and E37 stand as first drafted.

**One finding was first reframed, then accepted in full when writing the implementation plan.** The
review held that `tests/Unit/NodeRegistrationWriterTest.php`'s "recognises a class listed without a
leading backslash" test asserts incorrect PHP semantics. This design's first revision disputed that,
arguing the test asserts a deliberately lenient needle. **That rebuttal was wrong, and the review was
right.** The fixture declares `namespace App\Providers;` (`:48`), so the entry the test writes
resolves as:

```
entry as written resolves to: App\Providers\App\Nodeflow\Nodes\SendSms
the intended target is      : App\Nodeflow\Nodes\SendSms
```

The test therefore asserts `AlreadyPresent` for an entry that is **a different class**. Consequences,
both carried by **E50**: that one test's expectation legitimately changes, so **E40**'s "all 16
writer tests stay untouched" holds for fifteen of them and not the sixteenth; and the underlying
shipped defect is real and worse than the review stated — `appendTo()`'s presence check runs
`str_contains` over the whole comment-stripped file rather than the target array, so a mention
anywhere reads `AlreadyPresent`. `open-issues.md` records both as found here.

---

## 2. Decisions

Continuing from Plan 5's **E28**. **E45**–**E53** are the review amendments; where an amended
decision's original text still explains the reasoning it is kept and marked, following the **E2 /
E2a** convention this project already uses.

| # | Decision | Rationale |
|---|---|---|
| E29 | Packages scaffold to **`packages/{vendor}/{name}/`** under the host base path, and the emitted Composer repository uses a **relative** `url`. `--path=` overrides, asserted contained per **E51** | Relative survives the host being rebuilt with `composer install` on another machine. The demo's existing absolute repository entry is a wart not to reproduce. An extracted package must live inside the host repo and be committed, or a rebuild cannot resolve it |
| E30 | **`--namespace=` is a first-class option.** Default `vendor/name` → `Vendor\Name`, validated per **E52** | Our own package disproves the default: `atram/laravel-nodeflow` is namespaced `Nodeflow\`. A Composer name that does not match the PHP namespace is the common case |
| E31 | The scaffolded provider carries **`$nodes` only** — not `$triggers`, not `subjectAttributes()` | A trigger's `event()` returns a **host** event class and a subject attribute's resolver closure takes a **host** model. Neither travels in a shared package. The README states this with its reason and documents that adding either anchor by hand works |
| E32 | **`--js` is opt-in**, and emits `resources/js/index.ts` **plus** the minimal `package.json` and `tsconfig.json` a host `tsc` needs. Host-side Vite alias and tsconfig `paths` are **printed, never written** | Emitting TypeScript nobody can typecheck is the quiet-failure class this project keeps paying for. Not writing the host's two config files is **E20**'s reason unchanged |
| E33 | The scaffolded package's `require` constraint on `atram/laravel-nodeflow` **mirrors the host's own** | The package is unreleased, so `^1.0` would be a lie and a hardcoded `@dev` wrong the day it releases. The host must already require it — the node came from there |
| E34 | `extract-node` **refuses** a class referenced anywhere it will not itself rewrite, via a **general reference scan** rather than a legacy-`Nodeflow::register()` special case. **Amended by E45 — read E45 for the operative rule** | After the move the old FQCN still resolves as a `::class` *string*, but `NodeRegistry::register()` autoloads through `is_a()` — so a stale reference is a fatal in the host's provider `boot()`, on every request. Making the scan the mechanism means the legacy literal needs no special case and removal never does argument-list surgery. **E25 is unchanged** — `install` still leaves legacy calls alone, because *appending* alongside them is harmless and removal is not appending |
| E35 | The reference scan applies **PHP's own name-resolution rule**, resolving `use` statements and aliases per file. **Amended by E46** for roots and forms | A bare `SendMessage` refers to the target only under that rule. This reverses `ProviderRegistrationStep`'s declination to parse use-statements, **scoped to this command only**, because the stakes inverted: there a false positive was harmless; here a false negative leaves the host fatal and a false positive blocks legitimate work in any codebase containing another `SendMessage` |
| E36 | `type()` is accepted in **exactly two shapes**: an inline quoted literal, or a `self::`/`static::` **constant declared in the same class body** whose initialiser is a quoted literal. Anything else refuses. Matching runs on the **comment-stripped token stream**, requires the method body to be a single `return` statement, and requires **exactly one** `T_CONSTANT_ENCAPSED_STRING` token — `'a' . 'b'` yields two and is refused. **This is an explicit amendment to E10**, whose wording admits only an inline literal | The guarantee is identical in both accepted shapes: a const initialised to a quoted string cannot vary with namespace or class name. Refusing the same-class const would tell a developer whose good practice exposed `SendMessage::TYPE` to their tests to make their code worse. Cross-class resolution needs import, alias, inheritance and interface-constant handling — deliberately not extended to the one unrecoverable guard. Comment-stripping and the token count are stated because a probe showed a leading `// comment` in the body emits `T_COMMENT`, and literal concatenation is otherwise indistinguishable from one literal |
| E37 | The empirical `type()` comparison runs in a **fresh subprocess** and **cannot substitute for E36** | In-process verification proves nothing: the old class is resident and Composer's classmap cached, so `class_exists` can pass against a stale map and `type()` be read off the old class. And per §1.4 a basename-derived type survives the move unchanged, so this gate is blind to a danger E36 sees. Both ship. There is no `--no-verify` |
| E38 | Removal gets its **own** `NodeRemovalOutcome` enum. `NodeRegistrationOutcome` stays at six cases, untouched | One enum where `Appended` is meaningless for a removal and `Removed` for an append is two jobs in one weak type, and growing it would put an `UnhandledMatchError` risk into every call site Plans 1 and 5 shipped. Leaving it untouched keeps every existing `match` compiling — the same "untouched tests are the evidence" argument **E23** used |
| E39 | Removal is **line-wise**. Three accepted layouts: entry alone on its line; entry as sole content between the brackets; shared line **refused** as `EntryAmbiguous` | Deleting from a line shared with a sibling means preserving that line's other content byte-exactly, which is where this codebase's substring bug would live again. This refuses a form spec §4.1 and **R6** permit — accepted cost, because a loud refusal naming the fix beats character surgery on the riskiest edit in the plan |
| E40 | **G-10 is closed in code**, not re-documented. The bounded short-name matcher built for removal is shared with `appendTo()`'s presence check | Measured: no existing test asserts the short-name duplicate behaviour, so closing *this* gap costs no test churn. **The first revision then over-claimed "all 16 writer tests stay untouched" — see §1.5 and E50: fifteen stay untouched and the sixteenth is rewritten**, for an unrelated reason found while writing the plan. The gap's documentation was deleted in Plan 5's Task 3 and spec §3.4 still cites it; closing it makes the citation correct to delete rather than restate |
| E41 | **G-6 unifies the arithmetic AND the string.** One segment representation, one relative-depth calculation, one containment rule, and the three `PACKAGE_SOURCE` constants collapse onto the **full** `vendor/atram/laravel-nodeflow/resources/js` form. **Corrected 2026-08-21** | The first draft claimed `ViteAliasStep`'s shorter constant was a deliberate tolerance for hosts writing `./vendor/…`. That is **false**, reproduced locally: `str_contains('./vendor/atram/…', 'vendor/atram/…')` is true, so the full string already tolerates the prefix. The shorter constant buys no tolerance and only widens false accepts — it matches `/tmp/packages/atram/…`, which the full form correctly rejects. Collapsing onto the full form is therefore the fix, not the hazard the first draft warned against. **G-7** remains open: bounding the match to the alias entry is a separate change |
| E42 | The demo's three nodes **migrate into `$nodes`**, and one node is extracted with the extraction **kept permanently** | The demo is a development fixture, expendable and re-creatable; no host exists. So the question is not how to protect it but what is most informative to do to it. A standing packaged node makes the demo's own 56 tests permanent regression evidence that a packaged node works end to end against `flow_versions.graph` rows already saying `demo.send`. Keeping short names in `$nodes` points the real-host run at E40's short-name path |
| E43 | An occupied target package path is **accepted** when its `composer.json` name matches `--package`, refused when occupied by something else. `--force` means "overwrite a foreign directory" | Extracting three related nodes into one package is the common case. Correction to an earlier draft that refused any occupied path |
| E44 | **No** `--allow-references`, **no** `--no-verify`, **no** un-extract, **no** class rename during extraction, **one node per invocation** | Each removes a guard or adds a path with no covering evidence. A rename is sharpest: it changes `class_basename`, precisely the derivation E37 cannot see, so allowing it reopens the hole E36 closes |
| **E45** | **Amends E34.** The scan exempts **proven rewrite spans, not files.** Every reference is recorded with its file, line and byte range; a reference is permitted only when a specific move step will transform *that span*. After M6 and before M7 the scan **re-runs semantically** over the post-move tree, and any surviving unresolved reference aborts | The review's sharpest finding, and it falsified E34's central claim. The host provider is a file M5 rewrites, but M5 edits only `$nodes` and the import — so a legacy `Nodeflow::register([SendMessage::class])` **in that same file** was exempted rather than refused, which is the exact case E34 was designed to catch. The same hole let a reference inside the moved class's own body survive: the review moved a node whose method constructed its old FQCN, saw M9 pass, and then saw `Error: Class "App\Nodeflow\Nodes\SendMessage" not found` at execution |
| **E46** | **Amends E35.** Roots widen to every host-owned source and config root — `app/`, `tests/`, `config/`, `routes/`, `database/`, `bootstrap/`, `resources/` — and the classification adds **group imports** (`use App\Nodeflow\Nodes\{SendMessage, TagUser};`), **namespace-relative qualified names**, and **`class_alias()`**. The guarantee is **explicitly narrowed**: a class name assembled by concatenation at runtime, or stored in a database, is undetectable by any static scan and is stated as out of reach rather than implied covered | The review put the class in `config/nodeflow.php` and the scan found nothing while the host failed to autoload it. Concatenated, grouped, aliased and namespace-relative registrations each produced `autoload failed for App\Nodeflow\Nodes\SendMessage`. The dynamic and database cases are real and unfixable statically; **E49**'s host boot is the only mechanism that observes them, and it observes them only if the code path runs |
| **E47** | **Tightens G2.** The source file must declare **exactly one top-level named symbol** — the target class. A named trait, interface, enum, function or constant alongside it is **refused** | M2 rewrites the file's namespace, which moves every declaration in it, while the scan only looks for references to the *node*. The review built one class plus one trait: the node resolved, `type()` held, M9 passed, and a host class using the trait died with `Trait "App\Nodeflow\Nodes\FormatsMessage" not found`. Refusing is correct over migrating: a companion symbol's own references are a second extraction problem, not this one |
| **E48** | **Replaces M8.** The dependency is installed with a **scoped `composer update vendor/name --no-scripts`** when `composer.lock` exists, and a full install when it does not — never `composer dump-autoload` alone. `composer.lock`, `vendor/composer/installed.json` and the generated autoload files are **journaled**. `--no-scripts` is not optional | Reproduced locally: a fresh root with a correct path repository and `require`, after `composer dump-autoload`, reported `Generated autoload files` and `class_exists(...) => false`. `dump-autoload` regenerates from installed state and never installs anything, so **every first extraction would have reached M9 with an unloadable class and rolled back** — the command could never succeed. `--no-scripts` is required separately because `post-autoload-dump` runs arbitrary host scripts whose side effects are outside the journal; the demo's own `composer.json` runs `package:discover` there, and the review demonstrated a script failing on both the forward run and the restore |
| **E49** | **Replaces M9's oracle.** Verification **boots the host application** in a fresh process and asserts the registry **already** maps the recorded type to the new FQCN, *before* any manual `register()` call. A `dont-discover` entry covering the new package is a separate explicit refusal | Calling `NodeRegistry::register(New::class)` ourselves proves the class is valid, not that the package's provider was discovered and ran. A host with `extra.laravel.dont-discover` — including `"*"` — loses its only registration at M5 and M9 still reports success. This is "a test passing against the very bug it names" applied to the plan's own verification step. Booting also gives the only observation of **E46**'s dynamic and config-derived references that actually execute |
| **E50** | **Removal resolves identity, not spelling.** `::class` expressions in `$nodes` are resolved under the provider's own `namespace` and import table before comparison; matching is **bounded to the target array**, not the file; and a resolved target the remover cannot safely edit yields a new **`EntryUnsupported`** outcome. `NotPresent` means **no resolved reference exists in that array**. The same bounded, array-scoped matching replaces `appendTo()`'s whole-file presence check | Three failures in one. Inside `namespace App\Providers;`, `App\Nodeflow\Nodes\SendMessage::class` resolves to `App\Providers\App\Nodeflow\Nodes\SendMessage` — reproduced locally — so the first draft's second entry form was not the target at all. An aliased `use … as Sender;` with `Sender::class` is a *working* registration none of the three forms matched, so it read `NotPresent` and extraction proceeded to a fatal host. And `appendTo()`'s presence needle already scans the whole file, so a mention anywhere reads `AlreadyPresent` — a **pre-existing shipped defect**, fixed here because E40 is already in this code. **One existing test changes as a result** (§1.5): `NodeRegistrationWriterTest`'s "recognises a class listed without a leading backslash" asserts `AlreadyPresent` for an entry that resolves to a *different* class under the fixture's own `namespace App\Providers;`. It is rewritten to assert the strict outcome, and a companion test covers the non-namespaced provider where that spelling **is** the target. Matching must not diverge between `appendTo()` and `removeFrom()` — a divergence of exactly that kind produced execution-record **C1** |
| **E51** | **`HostPath` containment is canonical, and the decision is made here rather than left to implementation.** Resolve the host root and the nearest existing ancestor of the target, reject a symlink that escapes the root, then append only validated non-`..` leaf segments | The first draft required a probe "both ways" and never decided, which is a spec defect. Raw segment containment lets an in-host symlink direct scaffold writes outside the repository — reproduced: `raw_segment_contained=true` while the resolved parent sat outside the base — contradicting **E29**'s premise that the package is committed with the host. Segment comparison remains correct for the `/project` versus `/project-evil` case; only the symlink boundary needed deciding |
| **E52** | **Composer name validation does not validate PHP identifiers.** Every derived or supplied namespace and class segment is validated against PHP identifier rules **separately**, and every rendered PHP stub is parsed before the command reports success | Composer accepts `123vendor/456pkg` — `./composer.json is valid` — while `namespace 123Vendor\456Pkg;` is `Parse error: syntax error, unexpected integer "123"`. Validating only the Composer name emits unparseable PHP at exit 0, which is **F-1**'s failure mode in a new place |
| **E53** | **The claim that `nodeflow:make-node` can be run "inside the package" is withdrawn.** The scaffolded provider still carries a byte-identical `ANCHOR` so a future change can target it, but this plan makes no such claim and adds no such option | `MakeNodeCommand` hardcodes `basePath('app/Providers/NodeflowServiceProvider.php')` and the host root namespace, and an ordinary Composer package has no `artisan` — the review got `Could not open input file: artisan`. Adding package path/namespace/provider options to `make-node` is a real feature and belongs to its own plan, not smuggled in as a scaffolding side effect |

---

## 3. Component boundaries

Five new units, plus two extensions. Both commands are **orchestration only** — every decision is
delegated to a unit testable without invoking a command.

| Unit | Responsibility | Depends on |
|---|---|---|
| `HostPath` | Canonical containment (**E51**); one segment representation; relative depth; `..` rejection | — |
| `NodeTypeLiteral` | **E36**'s guard. Given a class file's source and a class name, return the literal type or a typed refusal. No filesystem, no autoload | — |
| `PhpNameResolver` | Per-file `namespace` + import table, including group and aliased imports. Resolves a `::class` expression or bare name to an FQCN | — |
| `NodeReferenceScanner` | **E45**/**E46**'s scan. Returns every reference with file, line and byte range | `HostPath`, `PhpNameResolver` |
| `PackageScaffolder` | Emit the package tree from stubs; parse every rendered stub (**E52**) | `HostPath` |
| `NodeRegistrationWriter::removeFrom()` | §6's removal, returning `NodeRemovalOutcome` | `PhpNameResolver` |
| `NodeRegistrationWriter::appendTo()` | Presence check becomes array-bounded and identity-resolving (**E50**) | `PhpNameResolver` |

`PhpNameResolver` is new since the first draft and is the unit three amendments depend on — **E46**'s
classification, **E50**'s identity resolution, and **E45**'s post-move rescan all need the same
"what does this name mean in this file" answer. Writing it three times is how the substring bug gets
in for the ninth time.

The seam that matters: `ExtractNodeCommand` is **a sequence of gates, then a sequence of moves**, and
every gate is pure or read-only. That makes "refuse before touching anything" the default rather than
a discipline.

---

## 4. `nodeflow:make-node-package {vendor/name}`

```
nodeflow:make-node-package {vendor/name} [--namespace=] [--path=] [--js] [--force]
```

**Validation, in two independent layers (E52).** `vendor/name` is checked against **Composer's own**
name pattern — it is rendered into `composer.json` and into a filesystem path, so it is validated,
not escaped, unlike `MakeNodeCommand`'s `--group`, and that pattern forecloses `..` traversal through
the name. Separately, every derived or supplied PHP namespace and class segment is validated against
PHP identifier rules, because Composer accepts names PHP cannot express. `--path` carries **E51**'s
canonical containment assertion. Every rendered PHP stub is parsed before success is reported.

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

The provider's `$nodes` anchor is byte-identical to `NodeRegistrationWriter::ANCHOR` so a future
change can target it — but per **E53** this plan claims no ability to run `make-node` against it.

`composer.json` carries `extra.laravel.providers` (E9's loading mechanism), a PSR-4 autoload for
`src/`, an `autoload-dev` for `tests/`, and **E33**'s mirrored `require` constraint.

`handle(): int`, with instance-cached state reset at the top of `handle()` per **F-3**.

---

## 5. `nodeflow:extract-node {FQCN} --package=vendor/name`

```
nodeflow:extract-node {FQCN} --package=vendor/name [--namespace=] [--path=] [--force]
```

### 5.1 Eight gates, all read-only, all before the first byte

| | Gate | Refuses when |
|---|---|---|
| G1 | Class resolves and is a node | Not found, or fails the same `is_a`/cardinality rules `NodeRegistry::register()` applies — reusing those messages |
| G2 | Source file locatable, inside the host, **exactly one top-level named symbol** (**E47**) | Under `vendor/`, outside the host per **E51**, or the file declares any other named class, trait, interface, enum, function or constant |
| G3 | `type()` provable per **E36** | Any shape but the two accepted. Records the literal for M9 |
| G4 | Type ownership | `NodeRegistry` resolves this type to a **different** class. **Unregistered is not a refusal** — a freshly generated node is legitimately unwired |
| G5 | Reference scan, **span-exempted** (**E45**, **E46**) | Any reference whose exact span no move step will transform |
| G6 | Host `composer.json` present, parseable, no conflicting requirement, and **no `dont-discover` entry** covering the new package (**E49**) | Name already required from a different source, or discovery is suppressed |
| G7 | Target package path absent, empty, or a package whose name matches `--package` (**E43**) | Occupied by something else, unless `--force` |
| G8 | `composer` is invocable, and a lock file's presence is determined (**E48**) | Composer is absent, so M8 could neither run nor be restored |

**G2 is where `str_starts_with` would be wrong.** "Is this file inside the host?" by string prefix
accepts `/Users/me/project-evil/app/Foo.php` for base `/Users/me/project`. It is a `HostPath`
canonical comparison per **E51**.

**G5's classification**, per **E46**, applied by `PhpNameResolver` under PHP's own rules:

| Observation | Verdict |
|---|---|
| The FQCN appears anywhere in code | Reference |
| A `::class` expression or bare name that **resolves** to the target under the file's namespace and import table — including group and aliased imports | Reference |
| A name resolving to a **different** `…\SendMessage` | Not a reference |
| The FQCN inside a **string literal** (a config array, a class-string) | Reference |
| `class_alias()` naming the target | Reference |
| Either form inside a comment | Not a reference |
| A name assembled by runtime concatenation, or stored in a database | **Out of reach — stated, not implied covered** (**E46**) |

**A reference is exempt only when a named move step will transform its exact span** (**E45**). File
membership grants nothing.

### 5.2 Nine moves, journaled for reverse restore

| | Move |
|---|---|
| M1 | Scaffold the package (`PackageScaffolder`) — pure creation |
| M2 | Write the class in, rewriting **the `namespace` declaration and every recorded reference span within it** |
| M3 | Move its test, rewriting **the resolved import or reference**, not a namespace declaration the file does not have |
| M4 | Register it in the **package** provider's `$nodes` |
| M5 | Remove it from the **host** provider's `$nodes` (**E50**), and the now-unused `use` only when the short name appears nowhere else in that file |
| M6 | Add the relative path repository and `require` to the host `composer.json` |
| M6a | **Re-run the scan semantically over the post-move tree** (**E45**). Any surviving unresolved reference aborts before anything is deleted |
| M7 | Delete the originals |
| M8 | Scoped `composer update vendor/name --no-scripts`, or a full install with no lock (**E48**) |
| M9 | **Boot the host in a fresh process** and assert the registry already maps the recorded type to the new FQCN, then that `type()` is byte-identical to G3's literal (**E37**, **E49**) |

**M2 rewrites the namespace declaration and recorded spans — never every occurrence of the old
namespace string.** A global `str_replace` is **F-1**'s sequential-substitution mistake and would
silently rewrite a docblock or a literal that legitimately names the old location. M9's comparison
against G3's recorded literal is the backstop that catches M2 corrupting a `type()` literal.

**M3's rewrite targets the import, not a namespace declaration.** Reproduced: `stubs/node.test.stub`
opens `<?php` then `use {{ namespacedClass }};` and declares no namespace at all, so a
namespace-declaration rewrite is a no-op on the very file this move exists to fix.

**M6a precedes M7** so the rescan runs while the originals still exist and restore is cheap.
**M7 precedes M8** because leaving the original means the old FQCN still resolves and G5's guarantee
is moot.

Any failure in M1–M9 restores in reverse from the journal — including `composer.lock` and
`installed.json` per **E48** — and exits non-zero.

### 5.3 The `type()` guard

`NodeTypeLiteral` tokenises the class file, strips comments, and accepts exactly:

- A method body that is a single `return` statement whose expression is **one**
  `T_CONSTANT_ENCAPSED_STRING` — including a double-quoted string with no interpolation, which is
  still one such token.
- `return self::TYPE;` or `return static::TYPE;` where `const TYPE = '<literal>';` is declared **in
  the same class body**, its initialiser matched the same way.

Everything else refuses with the shape named: concatenation (two literal tokens, and accepting it
opens the door to `'x' . static::class`), interpolation, heredoc, a cross-class or inherited
constant, a `type()` supplied by a trait, and any other expression. Comment-stripping is explicit
because a probe confirmed a leading `// comment` in the body emits `T_COMMENT` and a naive
exact-token-sequence match would refuse an ordinary commented method.

---

## 6. The removal machinery

`NodeRegistrationWriter::removeFrom()`, returning `NodeRemovalOutcome`:
`Removed | NotPresent | EntryUnsupported | ProviderMissing | AnchorMissing | AnchorAmbiguous |
EntryAmbiguous | WriteFailed`.

**Matching resolves identity, bounded to the target array** (**E50**). Each `::class` expression
between the anchor's brackets is resolved through `PhpNameResolver` under the provider's own
namespace and import table, then compared to the target FQCN. A resolved match the remover cannot
safely edit is `EntryUnsupported`, never `NotPresent`. `NotPresent` means **no resolved reference
exists in that array**.

This subsumes the first draft's lexical "three forms" table, which was wrong in two directions:
`App\Nodeflow\Nodes\SendMessage::class` inside `namespace App\Providers;` is **not** the target, and
an aliased `Sender::class` **is**.

**Three layouts**, per **E39**: entry alone on its line (modulo whitespace and a trailing `//`
comment) → the line goes; entry as the sole content between the brackets → the body empties; entry
sharing a line with a sibling → `EntryAmbiguous`, refused, naming the line and the fix.

**Verification is `appendTo()`'s inverted.** Re-read; the result must still parse; and no resolved
reference to the target may remain in the array. Either failure restores the original bytes and
returns `WriteFailed`. An entry inside a comment never matches, so a commented-out registration is
`NotPresent` — not a silent no-op reported as success.

`appendTo()`'s presence check adopts the same array-bounded identity resolution, closing the
pre-existing whole-file `str_contains` defect recorded in §1.5.

---

## 7. Testing

Spec §9's rule stands: for every test, name the production change that would make it fail, and
**execute it**. Test arithmetic travels in each dispatch as "previous measured total plus this task's
new tests", never a table (**R11**), and assertion counts are recorded as measured.

### 7.1 Adversarial probes, ordered explicitly

The external review found five Criticals that per-section reading did not. Its successful angles are
promoted to required probes below; the fact that it *failed* to break **E36** in eight attempts is
recorded in §1.5 rather than repeated as work.

**`HostPath`** — four proven bug shapes plus **E51**'s decision:
- `/Users/me/project-evil/app/Foo.php` against base `/Users/me/project` must read **outside**.
- `/Users/me/project/../other/app/Foo.php` must read outside, not collapse (**R12**, **R13**).
- Base `…/project`, entry `…/project/resources/project/css/app.css` — depth must be 3 (**R15**).
- **An in-host symlink whose resolved parent sits outside the root must be refused** — the review's
  `raw_segment_contained=true` case.

**`NodeTypeLiteral`** — the whitelist audit, plus the two shapes the review's probe surfaced:
- `return 'a' . 'b';` → refuse (two literal tokens). `return <<<T…` → refuse.
- `return "demo.send";` → accept. `return "demo.{$x}";` → refuse.
- **A body with a leading `// comment` → accept.** Naive token matching refuses it.
- `return self::TYPE;` with `TYPE` **inherited from a parent** → refuse. `type()` from a **trait** →
  refuse. A comment containing `return 'fake.type';` → must not match.
- Two `type()` methods in one file → checks G2 and G3 agree.

**`PhpNameResolver` and the scan** — every form the review broke, in both directions:
- Group import `use App\Nodeflow\Nodes\{SendMessage, TagUser};` → reference.
- Aliased `use … as Sender;` with `Sender::class` → reference.
- **`App\Nodeflow\Nodes\SendMessage::class` inside `namespace App\Providers;` → NOT the target.**
- Namespace-relative `Nodes\SendMessage` from `namespace App\Nodeflow;` → reference.
- `class_alias()` naming the target → reference.
- The FQCN in `config/nodeflow.php`, in a route file, in a Blade template → reference.
- Another class legitimately named `SendMessage` imported elsewhere → **not** a reference.
- **Span exemption:** a reference in the *provider* that M5 does not edit — a legacy
  `Nodeflow::register([SendMessage::class])` — must be **refused**, and a test must prove the
  file-level rule would have permitted it.
- **A reference inside the moved class's own body** must be rewritten or refused, never exempted.

**`removeFrom()`**:
- `SendSms::class` removed from an array also holding `SendSmsExtra::class`; survivor byte-identical.
- Only `SendSmsExtra::class` present → `NotPresent`, byte-identical.
- **Aliased `Sender::class` resolving to the target → `Removed` or `EntryUnsupported`, never
  `NotPresent`.**
- Entry inside `//` and inside a docblock → `NotPresent`, byte-identical. Commented-out second
  anchor → `AnchorAmbiguous`, byte-identical.
- Last entry without trailing comma; sole entry; two entries sharing a line; trailing same-line
  comment.
- **`appendTo()` regression:** the target named in an unrelated string elsewhere in the provider must
  **not** read `AlreadyPresent` — the shipped defect from §1.5.
- **False-pass probe:** break `removeFrom()` to a no-op returning `Removed`. Any test that passes was
  asserting the enum, and gets rewritten.

**M8 and M9 — the two the review proved non-functional:**
- **A real Composer fixture where `dump-autoload` alone leaves `class_exists() === false`**, and the
  scoped update makes it true. This test is the reason **E48** exists and must not be mocked away.
- A host `post-autoload-dump` script with a side effect: prove `--no-scripts` suppresses it on both
  the forward run and the restore.
- **`dont-discover` covering the new package → G6 refuses**, and a persisted test proves M9's boot
  would otherwise report success on a host whose registry is empty.
- M9 in-process versus subprocess: construct the false pass — old class resident, verify in-process →
  passes; subprocess → fails. If it does not discriminate, say so and replace it (**R21**'s
  precedent).
- Per **F-2**, M9 ships a **persisted** test that bypasses G3 to feed it a genuinely drifting
  `type()`.

**Atomicity** — injected failure at **M1** through M8 plus M6a, asserting the host tree
byte-identical including `composer.json`, `composer.lock`, `installed.json` and the absence of the
package directory. Target-state coverage per **E43**: absent, matching-existing, and foreign under
`--force`.

**Stubs** — per **F-2**, every new stub is rendered and asserted **structurally**, and per **E52**
parsed. Nothing but `php -l` watched `stubs/node.both.stub` and that is a live open issue.

### 7.2 What the demo run can and cannot prove

**Can:** short-name removal against a real hand-written provider with real `use` imports (**E40**,
**E50**); a working relative path repository in a real `composer.json`; a real scoped Composer
install; **`type()` continuity against real persisted `flow_versions.graph` rows already saying
`demo.send`** — E10's guarantee against real data; a real host boot proving the package provider was
discovered (**E49**); and the demo's own 56 tests still passing with a packaged node, plus a passing
`npm run build`.

**Cannot:** M3, the test-move path — no `tests/Feature/Nodeflow/` exists (§1.3), so it needs a
synthetic fixture. Nor `--js` controls rendering, which is **G-5**'s browser territory. Nor **E46**'s
dynamic and database-stored references, which no static scan reaches.

**One free oracle.** Before the real extraction, run one *deliberately failed* extraction against
the demo and let the journal restore it, then verify with `git status` that the tree is clean. The
demo's git cleanliness is an independent restore oracle no fixture test has.

---

## 8. Error handling

| Condition | Response |
|---|---|
| `vendor/name` fails Composer's pattern | Refuse, naming the pattern |
| A derived PHP namespace or class segment is not a valid identifier (**E52**) | Refuse, naming the segment |
| A rendered stub does not parse (**E52**) | Refuse; nothing reported as created |
| `--path` escapes the host canonically (**E51**) | Refuse |
| Target path occupied by a foreign directory | Refuse unless `--force` (**E43**) |
| Class not found, or not a node | Refuse, reusing `NodeRegistry::register()`'s messages |
| Source file outside the host, or declares any companion named symbol (**E47**) | Refuse, naming the symbol |
| `type()` not provable per **E36** | Refuse, naming the shape found and both fixes |
| Type registered to a different class | Refuse, naming the owner |
| Type not registered at all | Proceed |
| A reference whose span no move step transforms (**E45**) | Refuse, listing file and line |
| `dont-discover` covers the new package (**E49**) | Refuse, naming the entry |
| `composer` not invocable (**E48**) | Refuse before any write |
| A resolved entry the remover cannot safely edit | `EntryUnsupported`; file byte-identical |
| Entry shares a line with a sibling | `EntryAmbiguous`; file byte-identical |
| No resolved reference in the array | `NotPresent`; file byte-identical |
| Post-write re-read fails to parse, or a resolved reference survives | `WriteFailed`; original bytes restored |
| Post-move rescan finds a surviving reference (**E45**) | Abort at M6a, before any deletion |
| Host boot does not show the type mapped to the new FQCN (**E49**) | Abort and restore |
| Any failure in M1–M9 | Restore in reverse including lock and installed state, exit non-zero |
| Any refusal | `handle(): int` returns `FAILURE` — never `false` (**§7.2**, **F-3**) |

---

## 9. Documentation

**New page `docs/09-packaging-nodes.md`** — both commands, the scaffolded shape, why the provider
carries `$nodes` only (**E31**), the controls spread, the `--js` host-wiring snippet, **E36**'s
refusal with both fixes named, and **E46**'s stated limits so a reader knows what the scan does not
see.

**`docs/02-integration.md`** — Step 3 currently says registering in another provider's `boot()`
"still works at runtime… but the generators cannot find it there." After this plan that choice
acquires a second cost: **`extract-node` refuses outright** (G5). The docs must say so *at the place
they granted the permission*, not only on the new page.

**`docs/03-writing-nodes.md`** — one line: `type()` must be an inline literal or a same-class const
if the node is ever to be extracted.

**Spec and issues** — an "as built" block on editor-spec §8 following §7.2's convention; **E9** and
**E10** marked delivered, with **E10 explicitly amended by E36**; §3's plan table updated; Plan 5
spec §3.4's citation of the deleted writer comment corrected, since **E40** closes the gap. In
`open-issues.md`: a Plan 6 acceptance section with measured counts; **G-6** closed per the corrected
**E41**; **G-10** closed; the **`appendTo()` whole-file presence defect** recorded as found by this
plan's review and fixed by **E50**; **G-7** noted as still open and *not* closed by E41; and an
explicit statement of what was not touched.

---

## 10. Scope

### In scope

`make-node-package`; `extract-node`; `removeFrom()`, `NodeRemovalOutcome` and `appendTo()`'s presence
fix; `HostPath`, `NodeTypeLiteral`, `PhpNameResolver`, `NodeReferenceScanner`, `PackageScaffolder`;
**G-6** (corrected **E41**) and **G-10** (**E40**); the demo migration and extraction (**E42**); and
§9's documentation.

### Explicitly out of scope

- **D-1**, **D-2**, **G-3** — reserved for the dedicated security-hardening plan per **E26**.
- **G-5** — browser acceptance, still open. This plan *adds* a reason to want it, since after **E42**
  the demo renders and executes a node living in a package. Needs a manual Chrome toggle.
- **G-7** — `ViteAliasStep`'s two-facts problem. The corrected **E41** unifies the constant but does
  **not** bound the match to the alias entry.
- **G-8**, **G-9**, **G-11**, **G-12** — adjacent, not in this path. Plan 6's own emitted snippets use
  fully-qualified names so they do not repeat G-9's mistake in new code.
- **C-1** through **C-6**; **C-5** and **C-6** preserved, not redesigned.
- Editor-spec **§11**'s list; `make-flow` / `make-field-control` per **§7.3**.
- Packagist publishing, versioning, tagging; a manifest (**E9**); un-extract; class rename during
  extraction; multi-node invocation (**E44**).
- **Adding package-target options to `nodeflow:make-node`** (**E53**) — a real feature, its own plan.
- **Migrating companion symbols** found by **E47** — refused, not migrated.

### Known residuals this plan accepts

- **Dynamic and database-stored class names are out of reach of the scan** (**E46**), stated rather
  than implied covered. **E49**'s host boot observes them only if the code path runs.
- **`--js` output is unverified until the host wires it** — the same quiet-failure class as the
  original five wiring requirements. The README says so and the command prints the snippet.
- **The reference scan will refuse often in real projects**, the most likely DX complaint.
  `--allow-references` was rejected (proceeding leaves the host fatal); having the command rewrite
  arbitrary host references was rejected as the "edit files you cannot verify" move **E11** forbids.
  First thing to revisit once anyone has used it in anger — and **E45** makes it stricter, not looser.
- **E39 refuses a form spec §4.1 permits**, with the fix named in the message.
- **E47 refuses a file with a companion trait**, which is a plausible authoring shape. Migrating it
  is a second extraction problem; refusing is the honest direction.
- **After E42 the demo permanently has one node in a package and two in the host.** Deliberate, and a
  better fixture for covering both shapes, but recorded so it does not surprise.
- **`HostPath` assumes `/` separators**, as every install step already does — now the single place a
  Windows fix would land.

---

## 11. Task order

| # | Task | Gate |
|---|---|---|
| 1 | `HostPath` with **E51** canonical containment; migrate the install steps' arithmetic and collapse `PACKAGE_SOURCE` onto the full form (corrected **E41**) | All 488 pass **untouched** |
| 2 | `NodeTypeLiteral` (**E36**) | §7.1's whitelist audit including the leading-comment case |
| 3 | `PhpNameResolver` | Group, aliased, relative and namespaced-provider cases |
| 4 | `NodeRemovalOutcome`, `removeFrom()`, and `appendTo()`'s array-bounded presence fix (**E38**, **E39**, **E40**, **E50**) | **15** writer tests untouched; the sixteenth rewritten with its reason recorded (§1.5); the shipped whole-file defect proven fixed |
| 5 | `NodeReferenceScanner` with span recording (**E45**, **E46**) | Both-direction probes; the span-vs-file counterfactual |
| 6 | `PackageScaffolder` + stubs, with **E52** validation and stub parsing | Every stub asserted structurally and parsed |
| 7 | `MakeNodePackageCommand` | `handle(): int`; F-3 reset |
| 8 | `ExtractNodeCommand` gates G1–G8, refusals only | Every refusal leaves the tree byte-identical |
| 9 | Moves M1–M7 and M6a, journal and restore | Injected failure at M1 through M7 and M6a |
| 10 | M8 dependency install (**E48**) and M9 host-boot verification (**E49**) | The real Composer fixture; the `dont-discover` refusal; the in-process false pass |
| 11 | Register both commands | |
| 12 | **Demo:** migrate three nodes into `$nodes` (**E42**) | 56/223 holds before extraction starts |
| 13 | **Demo:** deliberately failed extraction restored by journal, then the real extraction, kept | `git status` clean, then 56/223, silent `tsc`, passing `npm run build` |
| 14 | Docs, spec, open-issues (§9) | |

Fourteen tasks, up from thirteen: `PhpNameResolver` is now its own task because three amendments
depend on it, and M8/M9 split from the other moves because **E48** and **E49** replaced both and each
needs a real Composer fixture rather than a fake.

---

## 12. Traceability

| Requirement | Where met |
|---|---|
| A node can be packaged and shared (editor-spec §8.1, **E9**) | §4 |
| An existing node can be extracted into a package (§8.2, **E10**) | §5 |
| `type()` is byte-identical after the move | **E36**, **E37**, §5.3, G3 + M9 |
| A computed `type()` is refused outright | **E36**, §5.3 |
| Extraction never leaves a half-moved state | §5.1's gate ordering, §5.2's journal, **E48**'s journaled lock |
| No manifest | **E9**, §4 |
| Host keeps working from the new location | **E45**, **E46**, **E49**, G5, M6a, M9 |
| The registration actually takes effect in the host | **E49** |
| `handle(): int` on every command | §8, **F-3** |
| Instance-cached state reset per invocation | §4, **F-3** |
| One shared path helper | corrected **E41**, §3 |
| One shared name resolver | **E50**, §3 |
| The writer's short-name gap closed and documented | **E40** |
| The writer's whole-file presence defect closed | **E50**, §1.5 |
