# Remaining tooling (Plan 5) — Design

Plan 5 of the six in `2026-08-19-editor-and-node-tooling-design.md` §3. It implements that spec's
§7.1 and §7.3, closes the defects and gaps Plans 1 through 4 recorded against the generator and the
host-wiring story, and fixes one host-code security bug in the demo application.

This document is the binding authority for Plan 5. Where it disagrees with §7.1 or §7.3 of the
editor spec, this document is the truth and says so explicitly — those two sections were written
before Plans 1 through 4 existed and three of their premises have since become false.

- **Date:** 2026-08-21
- **Package baseline:** `a83cf94` — 358 Pest tests (5,832 assertions), 160 Vitest tests, silent `tsc`
- **Demo baseline:** `~/Sites/test-workflow` at `bb0f7d8` — 49 Pest tests (191 assertions), silent
  `tsc`, passing `npm run build`, `vendor/atram/laravel-nodeflow` symlinked to the package
- Neither repository contains an `almanac/` directory.

---

## 1. Context

`nodeflow:install` is the last unbuilt piece of the install story and the only one whose job is to
*verify* rather than to generate. Everything it verifies was proven necessary by a real host: the
demo application at `~/Sites/test-workflow` is the only installed host that exists, and every
host-wiring requirement in §3 below is one that host got wrong at least once.

### 1.1 Premises of §7.1 that measurement falsified

Four claims in the editor spec are no longer true, and each one changes a design choice. All four
were measured against the repository and the framework source, not inferred.

**§5.6's "four things" is five.** `docs/08-editor-client.md` already documents the fifth, Vite
`resolve.dedupe` for `react`, `react-dom` and `@xyflow/react` (**G-4**), discovered by Plan 3's
real-app acceptance. Three of the five fail quietly.

**It is really six.** A generated `app/Providers/NodeflowServiceProvider.php` does nothing at all
unless it is listed in `bootstrap/providers.php`. Laravel 12 discovers application providers from
that array alone. An `install` that writes the provider and stops would produce a host whose nodes
silently never register — the precise failure class §7.1 exists to prevent, and it is not among the
five client requirements because it is not a client requirement. It is the sixth wiring step.

**§5.1's "nothing is installed anywhere" is false, and the divergence it caused is structural.**
`Illuminate\Database\Console\Migrations\BaseCommand::getMigrationPaths()` returns
`array_merge($this->migrator->paths(), [$this->getMigrationPath()])` — package-registered paths
first, the host's `database/migrations` **last** — and `Migrator::getMigrationFiles()` reduces that
list with `->keyBy(fn ($file) => $this->getMigrationName($file))`. `keyBy` overwrites on collision.
So a published copy of `2026_08_18_000001_create_nodeflow_tables.php` **silently shadows the
package's own file for every `migrate` run, permanently, with no warning**. Plan 4's in-place edit
diverging from the demo's published copy was not carelessness; it is what this mechanism does every
time. The two copies are byte-identical today only because the demo hand-fixed it in `bb0f7d8`
(`fix: re-publish the nodeflow migration after Plan 4's in-place edit`). Nothing detects the next one.

**§5.6's wiring cannot all be written.** Measured on the accepted host: `json_decode` on the demo's
`tsconfig.json` returns `null` with *Syntax error* — it is the Laravel React starter kit's JSONC,
roughly ninety lines of `//`-commented option documentation. Any JSON round-trip destroys that
block. `vite.config.ts` needs a TypeScript AST to edit honestly, which PHP does not have.
`package.json` does parse as strict JSON, but writing a dependency into it without running
`npm install` leaves the manifest, the lockfile and `node_modules` disagreeing — a worse state than
the one before the edit.

### 1.2 Premises of §7.3 that measurement falsified

**Subject attributes cannot live in a property.** §7.3 says `make-subject-attribute` "appends a
`SubjectAttribute::make()` through the same anchor mechanism". The same anchor is
`NodeRegistrationWriter::ANCHOR`, `'protected array $nodes = ['`. A `SubjectAttribute` carries a
`Closure`, and PHP refuses a closure in a property default: `class T { protected array $x = [fn () => 1]; }`
fails with *Constant expression contains invalid operations*. Attributes therefore need a method,
not a property, and therefore a different anchor. See **E23**.

### 1.3 What the demo proved about host code

`~/Sites/test-workflow`'s `NodeflowDemoController` route-binds `RunSubject` directly in `convert()`
and `click()`. `RunSubject` carries no `tenant_id` and no tenant scope by design (foundation spec
E1), so implicit model binding resolves it by primary key alone; the run is then re-fetched with
`Run::withoutTenancy()`. `docs/02-integration.md:355` documents exactly this prohibition, and the
demo is the host that did it anyway.

Measured while scoping the fix, and worse than previously recorded:

- `php artisan route:list -v` shows `POST nodeflow/subjects/{subject}/convert` and `/click` carry
  **`web` only — no `auth`**. The whole demo route group is anonymous, including `reseed`, which
  runs `db:seed`.
- `switchTenant` puts any posted `tenant_id` string into the session unvalidated, and
  `SessionTenantResolver` reads it. So the ambient tenant is attacker-chosen.
- **No demo test exercises any of it.** Nothing covers `convert`, `click`, `switchTenant`,
  `fireAlert`, `runFlow` or `reseed`. That is why the bug survived, and it means adding middleware
  costs no test churn.

### 1.4 The scanner's blind spot that let it through

The request-context scanner (**E18**) strips comments and treats either table name appearing
anywhere in request-context code as a violation. Its docblock names three forms it still cannot
see; the demo's bug is the third — a type-hinted route-bound parameter. The scanner is a package
test and does not scan host code at all, so nothing was ever going to catch this. It is fixed here
as host code, not by extending the scanner.

---

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| E19 | **`install` publishes config only. Migrations are opt-in via `--publish-migrations`, and any already-published copy is hashed against the package's — drift is a non-zero exit, with `--force-migrations` as the one-flag fix.** `docs/02-integration.md` Step 1 stops teaching the migration publish as a required step and states the shadowing consequence | Publishing a migration the package also loads means the host's copy silently wins forever (§1.1). Removing the publish entirely would take away a legitimate escape hatch; making it an informed choice, and making drift loud at the moment CI runs, keeps the hatch and kills the silence |
| E20 | **`install` writes four things and verifies three.** Writes: the provider, `bootstrap/providers.php`, `config/nodeflow.php`, the Tailwind `@source` line. Verifies and instructs: the Vite alias, Vite `resolve.dedupe`, the tsconfig `paths` mappings, and `@xyflow/react` | E11 permits only an edit whose success can be re-verified. For `vite.config.ts` and `tsconfig.json` a passing re-read would not prove the edit landed in the active exported config, and a JSON round-trip destroys the starter kit's comment block (§1.1). The Tailwind line is written because it is line-oriented CSS, provably insertable, and `docs/08-editor-client.md` calls it "quiet, and the worst of the five" |
| E21 | **Every step yields `AlreadyPresent`, `Wired` or `CannotWire`. Exit is non-zero iff any step ends `CannotWire`.** Every `check()` runs before any `apply()`. The two reports — undefined gates, resolved tenancy mode — **never** affect the exit code. `handle(): int` | §7.1's exit requirement is a CI contract, and `false` from a Laravel `handle()` exits 0. Checking before writing is what stops a mid-run failure half-wiring a host. An undefined gate is the correct state immediately after install; failing on it would make the first run always red and train hosts to ignore the exit code |
| E22 | **Verification is comment-stripped, and structural where a parse is possible.** One shared stripper serves the tsconfig parse and both Vite text checks. tsconfig is asserted structurally: both `paths` mappings must **resolve inside the package's `resources/js`**, not match a byte string | `tests/Support/RequestContextScanner.php:134` set this precedent for exactly this reason — a commented-out line must not read as present. Structural matters concretely: the accepted host's value is `…/resources/js/index.ts` while `docs/08-editor-client.md` prints `…/resources/js`. Both are correct, and a byte-match would call the accepted host broken |
| E23 | **The generated provider carries three registration homes**: `protected array $nodes = [`, `protected array $triggers = [`, and `protected function subjectAttributes(): array` returning a literal array. `NodeRegistrationWriter` generalises to take an anchor plus a presence needle; its `ANCHOR` constant and behaviour stay byte-identical | PHP refuses a closure in a property default (§1.2), so attributes cannot be a property. One mechanism with three anchors beats three mechanisms. Keeping `ANCHOR` and its semantics unchanged is what lets Plan 1's existing writer tests pass untouched, which is the evidence that the refactor changed nothing |
| E24 | **`nodeflow:make-trigger` registers what it generates**, through the `$triggers` anchor. This extends §7.3, which describes registration for `make-subject-attribute` only | `docs/02-integration.md` warns that `TriggerRegistry::register()` attaches the listener at the moment of registration, so a trigger registered lazily "never fires". A generator that emits an unregistered trigger ships that documented failure as its default output. Recorded as a stated extension rather than absorbed silently |
| E25 | **`install` additively edits an existing provider that lacks the anchors**, inserting only what is missing — up to three anchors and their three `boot()` calls — and leaving any existing `Nodeflow::register([...])` call alone. No `boot()` at all falls back to reporting `CannotWire` with the snippet | This is where §7.1's constraint 2 is discharged: a host who followed `docs/02-integration.md` and wrote `Nodeflow::register([...])` in a provider's `boot()` currently gets `ProviderMissing` from `make-node`. The two coexist correctly — `register()` is idempotent by type and every registry is a container singleton — so the additive edit needs to rewrite nothing. Each insertion is independently anchor-asserted and skipped when already present |
| E26 | **G-3 is cut and reassigned** to the dedicated security-hardening plan alongside D-1 and D-2. The documented invariant and the three relation comments stand | The open issue's own cut condition is "cut it if the migration decision goes another way". E19 keeps in-place editing of that migration legitimate, so G-3 is no longer the same file — it is a model change. See §7 for the two enforcement mechanisms measured and rejected, and the one that survives but belongs with D-2 |
| E27 | **The demo fix includes the missing `auth` middleware and `switchTenant` validation**, not only the route-binding fix | The assigned bug is reachable *because* an unvalidated string becomes the ambient tenant and no route requires a session. Fixing the binding while leaving those open fixes the symptom. Measured cost: zero test churn, because no demo test exercises the routes (§1.3) |
| E28 | **`--check` verifies everything and writes nothing**, under the same exit contract | §7.1's exit codes are a CI contract; the thing CI wants on every build is "is this host still wired", not "install it again". Nearly free under E21, since every step already separates `check()` from `apply()` |

---

## 3. `nodeflow:install`

### 3.1 Shape

`Nodeflow\Console\InstallCommand`, `nodeflow:install`, `handle(): int`. It drives a list of step
objects; the command itself holds no wiring knowledge. Each step exposes:

- `describe(): string` — the human name used in output
- `check(): Outcome` — read-only
- `apply(): Outcome` — only called when `check()` returned neither `AlreadyPresent` nor `CannotWire`
- `snippet(): ?string` — the exact text a host must add when the step cannot be written

`InstallOutcome` is an enum: `AlreadyPresent`, `Wired`, `CannotWire`. Per E21 the command runs
every `check()` first, prints the resulting table, then applies. Options: `--check`,
`--publish-migrations`, `--force-migrations`.

Each step is a separate class so it is testable without the command, following
`NodeRegistrationWriter`'s precedent that the riskiest edits get tests that do not involve running a
generator.

### 3.2 The nine steps

| Step | Writes | Anchor or check |
|---|---|---|
| `PublishConfigStep` | `config/nodeflow.php` | file presence |
| `MigrationStep` | nothing by default | `sha1_file` of any published copy against the package's; drift → `CannotWire` (§3.2.1) |
| `ProviderStep` | `app/Providers/NodeflowServiceProvider.php` | creates from stub, or additively edits (§4) |
| `ProviderRegistrationStep` | `bootstrap/providers.php` | anchor `return [`, asserted present and unique |
| `TailwindSourceStep` | the host's CSS entry | anchor `@import 'tailwindcss'`; `@source` path **computed** relative to that entry |
| `ViteAliasStep` | — | comment-stripped source contains `@nodeflow/editor` and the package path segment |
| `ViteDedupeStep` | — | comment-stripped source contains `dedupe` with all three of `react`, `react-dom`, `@xyflow/react` |
| `TsconfigPathsStep` | — | comment-stripped, trailing-comma-tolerant parse; both mappings resolve inside the package's `resources/js` |
| `XyflowDependencyStep` | — | strict-JSON parse of `package.json`; `@xyflow/react` in `dependencies` or `devDependencies` |

#### 3.2.1 The migration step's four states

| State | Outcome |
|---|---|
| No published copy, no `--publish-migrations` | `AlreadyPresent` — nothing to do; the package's own file is loaded and `migrate` finds it |
| No published copy, `--publish-migrations` | `Wired` — publishes, and prints the shadowing consequence |
| Published copy, hash matches | `AlreadyPresent` |
| Published copy, hash differs | `CannotWire`, naming both paths; `--force-migrations` re-publishes over it |

`--force-migrations` implies `--publish-migrations`. Under `--check` neither flag writes anything: a
divergent copy is reported and the exit is non-zero.

Naming the first state `AlreadyPresent` rather than inventing a fourth outcome is deliberate — "the
host has no published copy" is the state E19 wants hosts to be in, so it must not read as a problem.

**The Tailwind path is computed, not literal.** `'../../vendor/atram/laravel-nodeflow/resources/js'`
is correct only for a CSS entry at `resources/css/app.css`. The entry is located by that convention
first, then by globbing `resources/css/*.css` for the one file containing `@import 'tailwindcss'`.
Zero or more than one match is `CannotWire` with the snippet — the command does not guess which
file is the entry.

**The Vite checks are heuristics and their docblocks say so.** Comment-stripping removes the
commented-out false positive, but text matching cannot prove the alias sits in the config actually
exported rather than in a dead branch or a second `defineConfig`. F-1's lesson is that a wrong
comment outlives the bug it describes, so the limit is stated in the source rather than left for a
reader to infer a guarantee that is not there.

### 3.3 The two reports

After the steps, and never affecting the exit code:

- **Gates.** `Gate::has()` for each of `nodeflow.viewAny`, `nodeflow.update`, `nodeflow.publish`,
  `nodeflow.runManually`, listing the undefined ones with the note that the shipped policies deny
  when a gate is absent.
- **Tenancy.** The resolved `nodeflow.tenancy` value, and under `auto` which resolver is bound —
  the package's own `NoTenancyResolver` or a host-bound one — because that is precisely what `auto`
  reads to decide what a null tenant means.

### 3.4 Idempotency

A second run reports `AlreadyPresent` for every step and exits 0. The claim does **not** rest on
`NodeRegistrationWriter`'s presence check, whose documented gap is that a provider importing a class
and listing a bare `SendSms::class` is not recognised, so a duplicate is appended. `install` writes
class entries only into a provider it just created with empty arrays, where that gap cannot fire;
for an existing provider it inserts anchors and `boot()` calls, whose presence checks are exact
strings the writer does not participate in.

---

## 4. The provider

### 4.1 Generated shape

```php
class NodeflowServiceProvider extends ServiceProvider
{
    protected array $nodes = [
    ];

    protected array $triggers = [
    ];

    public function boot(): void
    {
        Nodeflow::register($this->nodes);

        app(TriggerRegistry::class)->register(...$this->triggers);

        app(SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());
    }

    /** @return \Nodeflow\Schema\SubjectAttribute[] */
    protected function subjectAttributes(): array
    {
        return [
        ];
    }
}
```

`protected array $nodes = [` appears **exactly once**, which is what
`NodeRegistrationWriter::ANCHOR` requires — the writer refuses on zero and on more than one,
leaving the file byte-identical. The generated provider also carries commented stubs for the four
`Gate::define()` calls and the two contract bindings, so the file `install` creates is a complete
map of what the host still owes.

Empty-array-on-its-own-line is deliberate: `protected array $nodes = [];` also matches the anchor
but yields valid-and-ugly output when appended into.

### 4.2 The generalised writer

`NodeRegistrationWriter` gains an anchor and a presence needle as parameters. Its `ANCHOR` constant,
its `register()` signature and every one of its five outcomes stay exactly as they are, so
`tests/Unit/NodeRegistrationWriterTest.php` passes unchanged — that untouched file is the evidence
the refactor changed no behaviour.

| Target | Anchor | Presence needle |
|---|---|---|
| Nodes | `protected array $nodes = [` | `Foo::class` |
| Triggers | `protected array $triggers = [` | `Foo::class` |
| Subject attributes | `protected function subjectAttributes(): array`, then the first `return [` after it | `SubjectAttribute::make('key'` |

The attribute anchor is two-stage because `return [` alone is not unique in a provider: the method
signature is asserted present and unique, then the first `return [` after that offset is located and
asserted to be within a short distance of it. A method body shaped differently from the generated
one is `AnchorMissing`, not a guess.

### 4.3 Editing an existing provider (E25)

For each of the three homes independently: if its anchor is present, nothing is written. If absent,
the insertion is anchor-asserted against the class declaration line (for a property) or the
`public function boot(): void` line (for a registration call), each asserted unique. Any existing
`Nodeflow::register([...])` call is left untouched; both mechanisms registering is harmless because
`register()` is idempotent by type and the registries are container singletons. A provider with no
`boot()` method is `CannotWire` with the snippet — `install` does not synthesise a method.

---

## 5. `make-trigger` and `make-subject-attribute`

### 5.1 `nodeflow:make-trigger {name} --event= --type=`

One file, `app/Nodeflow/Triggers/{Name}.php`, from `stubs/trigger.stub`. It emits the four abstract
methods of `Nodeflow\Triggers\Trigger` — `type()`, `event()`, `definition()`, `resolve()` — and
`idempotencyKey()` and `matchesConfig()` as commented overrides with the reason each exists.

`--type` reuses `MakeNodeCommand`'s three rules verbatim: the lowercase pattern, the reserved
`core.` prefix, and collision with an already-registered type resolved through registry aliases. It
also reuses the derived-type warning when `--type` is omitted non-interactively. `handle(): int`,
mapping `parent::handle() === false` to `FAILURE`, for the same reason `make-node` does.

`--event` is **warned about, not rejected, when the class does not exist.** Generating the trigger
before writing the event is a normal order of work. The warning names the class, because
`event()` returning a host event class is the most confusable part of the trigger contract and a
silent typo there produces a trigger that simply never fires.

Registration appends to `$triggers` (E24), with `make-node`'s exact fallback behaviour on any
anchor problem: print the line, say why, write nothing.

### 5.2 `nodeflow:make-subject-attribute {key} --label= --type=`

Writes no file. It appends one `SubjectAttribute::make('key', 'Label', 'type', fn ($subject) => null)`
entry through the method anchor, with a `// TODO` on the resolver.

`{key}` is validated against `MakeNodeCommand::OUTPUT_PATTERN`'s shape — lowercase, digits,
underscores — because the key is what a published graph's condition node stores and resolves
through. `--type` is validated against exactly `boolean`, `text`, `number`: those are the three the
registry's comparisons coerce, and a fourth value produces an attribute whose comparisons behave
arbitrarily.

Presence is checked on the key, `SubjectAttribute::make('clicked'`, not on the whole line — a
re-run with a different label must be recognised as already present rather than appended twice
under one key, because `SubjectAttributeRegistry::register()` keys by `$attribute->key` and the
second silently replaces the first.

---

## 6. F-1, F-2, G-2, R-2

### 6.1 F-1 — sequential substitution

**The docblock is corrected first.** `paletteGroup()` claims a backslash and a single quote "are the
only two characters that can end it early", which is demonstrably false: `--group='{{ outputs }}'`
ends it early with neither. The claim is deleted and replaced with what is true.

**Then `str_replace` becomes `strtr`, in both `buildClass()` and `writeTest()`.** `strtr` with an
array never re-scans replaced text, so it kills the whole sequential-substitution class rather than
the single ordering that exposed it. Both renderers change, because `writeTest()` has the same
shape and the same exposure.

### 6.2 F-2 — the unwatched stub

`stubs/node.both.stub` gets a require-and-execute test under a fourth distinct class name,
`SendDigest`. Two generated classes sharing an FQCN in one process fatals with "class already
declared", which is why the name must be new. It asserts the registry accepts the class, that
`definition()` runs (which exercises the whole `Field::text()->label()->help()->required()` chain as
a side effect of being called), and that **both** `forSubject()` and `forAudience()` route to the
first declared output in test mode.

### 6.3 G-2 — `tenant_id` immutability

`CrossTenantWriteException` appears in no user-facing document today, verified by grep across
`docs/`. `docs/02-integration.md` gains a subsection stating that `tenant_id` is fixed at creation
for `Flow`, `FlowVersion`, `Run` and `Template`, naming the exception, and covering both
`$flow->update($request->all())` with a changed `tenant_id` and promoting a global `Template` into a
tenant's.

The `updating` guard in `src/Models/Concerns/BelongsToTenant.php` gains a comment recording that
query-builder updates fire no model events and bypass it entirely, and that `CompleteRunActivity`
already uses that form — so it is a pattern a future author may copy.

**That comment gets a test that pins the limitation**: a query-builder
`update(['tenant_id' => …])` succeeds without throwing. The test documents the honest boundary and
fails the day someone silently changes the subject of the comment.

### 6.4 R-2 — docs imprecision on `disabled` versus `resolver`

R-2 records that `docs/02-integration.md` over-warns. The current text is mode-precise: it states
that under `auto` a null throws once a resolver is bound, and that the unscoped read arises only
under an explicit `disabled` with a resolver bound. The task is to verify this against the Plan 3a
diff that introduced it and, if confirmed, **close R-2 in `open-issues.md` with that evidence rather
than edit the documentation**. If the verification fails, the sentence is corrected instead.

---

## 7. G-3, and why it is cut (E26)

Three enforcement mechanisms were considered. Two die on measurement.

**A composite foreign key is unverifiable in this suite.** `tests/TestCase.php` sets no
`foreign_key_constraints` key on its connection, and
`Illuminate\Database\Connectors\SQLiteConnector` issues `pragma foreign_keys` only when that key is
*set*. Probed directly: `pragma foreign_keys` returns `0`, and
`Run::create(['flow_version_id' => 999999, …])` **succeeds**. No foreign key in this package is
enforced by any test today. A composite FK would ship as an invariant the suite cannot exercise —
the defect class §9 of the editor spec exists to prevent — and it would add a circular
`flows ↔ flow_versions` constraint on top.

**A `$guarded` mass-assignment block fails silently at scale.** Measured cost: four production call
sites (`PublishFlow.php:40,67`, `StartRun.php:59,60`) plus 27 call sites across 16 test files.
`Model::preventSilentlyDiscardingAttributes()` is off by default, so `fill()` *silently discards* a
guarded attribute — those 27 sites would begin writing null foreign keys without throwing, and
since foreign keys are not enforced the rows would insert cleanly. A change whose failure mode is 27
silently-broken fixtures is not a safety improvement.

**What survives belongs with D-2.** A `saving` guard on `Flow.current_version_id` and
`Run.flow_version_id` that resolves the target `FlowVersion` unscoped and throws
`CrossTenantWriteException` on a tenant mismatch is testable, its counterfactual is trivially
executable, and it costs one query per publish and per run creation. But it is a tenant assertion on
a write path — the same family as D-2's assertion in `RunNodeActivity`, which the plan handoff
explicitly forbids absorbing. Splitting one coherent piece of security work across a tooling plan
and a security plan produces two half-defences.

So G-3 stays open, its documented invariant and three relation comments stand, and it is reassigned
to the security-hardening plan alongside D-1 and D-2. `open-issues.md` records this reasoning.

---

## 8. The demo fix (E27)

Host code in `~/Sites/test-workflow`, in one controller, one route file, one resolver docblock and
one React page.

**The route carries the run.** `runs/{run}/subjects/{subject}/convert` and `…/click`, with `{run}`
bound through the tenant-scoped `Run` model — so another tenant's run is a 404, not a 403, and a 404
does not confirm the row exists. The subject is resolved through `$run->subjects()->findOrFail(...)`,
which is the pattern `docs/02-integration.md:355` prescribes and the one `RunOverlay` and
`RunSubjects` use internally.

**`Run::withoutTenancy()` is deleted** from both actions. It was there only because the route bound
a subject with no run in hand.

**The `User` write is scoped** to `$run->tenant_id`, as defence in depth behind the scope — the same
reasoning the integration doc gives for comparing `$user->organization_id === $flow->tenant_id`
inside a gate.

**The route group gains `->middleware(['auth'])`** and `switchTenant` validates the posted
`tenant_id` against `Organization` before it reaches the session.
`SessionTenantResolver`'s docblock loses its "so you can flip tenants without logging in" claim,
which will no longer be true.

The client change is the two hardcoded URL strings at `resources/js/pages/nodeflow/demo.tsx:277,280`,
where `selected.id` is already the run in scope. Wayfinder output is gitignored and uncommitted, so
there is nothing generated to reconcile.

---

## 9. Documentation

`install`'s job is to make `docs/02-integration.md` true, so the document changes with it.

- **Step 1** stops teaching `vendor:publish --tag=nodeflow-migrations` as a required step, states the
  shadowing consequence of publishing (§1.1), and points at `nodeflow:install`.
- **Step 3** stops teaching "in a service provider's `boot()`" and teaches the provider `install`
  generates, with its three registration homes. This is §7.1 constraint 2 discharged: today the
  document teaches a shape the writer cannot recognise, and a host who followed it gets
  `ProviderMissing`.
- **"Verifying the install"** loses the paragraph beginning "There is no `nodeflow:install`
  command", replaced by the command, its two reports, its exit contract and `--check`.
- The **`tenant_id` immutability** subsection of §6.3 lands here.
- `docs/08-editor-client.md` gains what `install --check` reports for each of the five client
  requirements, and which three of them `install` cannot write and why.
- New commands are documented where their siblings already are: `make-trigger` in
  `docs/04-writing-triggers.md`, alongside the contract it scaffolds; `make-subject-attribute` in
  `docs/02-integration.md` Step 3, beside the attribute registry it appends to. The attribute
  registry has no page of its own, which §7.3 names as the reason the command earns its place.

---

## 10. Error handling

| Condition | Response |
|---|---|
| Any step ends `CannotWire` | Non-zero exit, snippet printed, other steps still reported |
| Published migration differs from the package's | `CannotWire`, naming both paths and `--force-migrations` |
| Provider exists, no `boot()` | `CannotWire` with the full snippet; nothing written |
| Anchor absent or duplicated | The writer's existing outcome; file byte-identical; line printed |
| CSS entry ambiguous or absent | `CannotWire` with the snippet and the computed relative path |
| `--check` with anything unwired | Non-zero, nothing written |
| Undefined gate | Reported, exit unaffected |
| `make-trigger --event` names a missing class | Warning naming the class; the file is still generated |
| `make-subject-attribute --type` not one of three | `InvalidArgumentException` → `FAILURE`, nothing written |
| Attribute key already present | Reported as already present; nothing appended |

---

## 11. Testing

The rule from editor spec §9 stands: for every test, name the production change that would make it
fail. Plan 4 proved that naming it is not enough — four tests specified in its own plan passed
against the very bug they named. So for Plan 5:

- **Every test ships with its counterfactual executed.** The guard is removed, the suite is run, the
  failure output is captured into the task record, and the guard is restored. A counterfactual that
  cannot be reproduced is worth less than no claim.
- **Every production guard has a covering test that ships.** Proving a guard load-bearing in a
  throwaway and deleting the evidence leaves code anyone can delete on a green suite. F-2 is this
  exact defect class.
- **Every user-facing string and every comment asserting a fact about behaviour is tested for
  truthfulness** in the states that actually occur. §6.3's query-builder-bypass test exists for this
  reason, as does §6.1's docblock correction.
- **Exact arithmetic.** Each task states its expected *test* count before it starts and records
  *measured* assertion counts afterwards. Assertion counts are never predicted. No padding, and no
  trimming to hit a number.

`install`'s non-zero paths are first-class tests, not an afterthought, because its exit codes are a
CI contract: drifted published migration; missing anchor; duplicated anchor; provider without
`boot()`; unwired Vite alias; unwired `dedupe`; unwired tsconfig paths; missing `@xyflow/react`;
ambiguous CSS entry; `--check` on a partially wired host. Idempotency is tested as a second run
asserting `AlreadyPresent` throughout, exit 0, and **byte-identical files**.

Two tests are especially easy to fake and get their counterfactual written into the test body:

1. **Comment-stripped verification.** A test that only asserts a wired host passes would also pass
   with naive text matching. It must assert that a host whose alias, `dedupe` or tsconfig mapping is
   **commented out** reports `CannotWire` — the state naive matching calls wired.
2. **Structural tsconfig verification.** A byte-match test passes while calling the accepted host
   broken. It must assert that both `…/resources/js` and `…/resources/js/index.ts` are accepted, and
   that a mapping pointing somewhere else is not.

### 11.1 Task order

1. F-1 and F-2 — smallest, and they harden the rendering mechanism every generator shares
2. The generalised writer, with `NodeRegistrationWriterTest.php` untouched as the evidence
3. `install` — steps first, then the command driving them
4. `make-trigger`, then `make-subject-attribute`
5. G-2 and R-2
6. The demo fix
7. Verification on merged `main`, then browser acceptance through `http://test-workflow.test/`

---

## 12. Scope

### In scope

`nodeflow:install` with `--check`, `--publish-migrations`, `--force-migrations`; the generated
provider and its three registration homes; the generalised registration writer;
`nodeflow:make-trigger`; `nodeflow:make-subject-attribute`; F-1; F-2; G-2; R-2's verification and
closure; G-4 closed by `install`'s fifth check; the sixth wiring step
(`bootstrap/providers.php`); the demo's cross-tenant write, its missing `auth` and its unvalidated
tenant switch; and the documentation changes of §9.

### Explicitly out of scope

- **D-1** and **D-2**, and with them **G-3** (E26). All three are tenant assertions on write or
  execution paths and belong to one dedicated security-hardening plan.
- **`make-flow`** and **`make-field-control`**, with §7.3's reasons.
- **Plan 6** — `make-node-package` and `extract-node`.
- **C-1** through **C-6**. **C-5** and **C-6** are Plan 4's two honest `reached` limitations and are
  preserved as they are; closing either means writing to the durable execution path.
- Extending the request-context scanner to host code. The demo's bug is fixed as host code (§1.4).
- Everything editor spec §11 lists as out of scope.

---

## 13. Traceability

| Requirement | Where met |
|---|---|
| One command to a wired host install (editor spec §12) | §3 |
| Artisan command to start a trigger (§12) | §5.1 |
| Artisan command for a subject attribute (§12) | §5.2 |
| G-4 — Vite `resolve.dedupe` verified | §3.2, `ViteDedupeStep` |
| The sixth wiring step | §3.2, `ProviderRegistrationStep` |
| §7.1 constraint 1 — the anchor is a live constant | §4.1 |
| §7.1 constraint 2 — reconcile the documented convention | E25, §4.3, §9 |
| §7.1 constraint 3 — `handle(): int` | E21 |
| E11 — anchor-asserted, then re-verified | E20, E21, §4.2 |
| F-1 | §6.1 |
| F-2 | §6.2 |
| G-2 | §6.3 |
| R-2 | §6.4 |
| G-3 | E26, §7 — cut and reassigned |
| The published-migration divergence | E19, §1.1 |
| The demo's cross-tenant write | E27, §8 |
