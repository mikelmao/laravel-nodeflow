# Open issues

Everything known-but-unfixed, in one place, to be reviewed before the six-plan effort is called
done. Each entry records where it came from, so a reader can judge how well-established it is —
most were proven by probe during a review rather than suspected.

**Status key:** `DECISION` needs a human call · `DEFECT` is proven and unfixed · `GAP` is missing
coverage or missing code · `DRIFT` is documentation disagreeing with code.

Last updated 2026-08-21 after Plan 4 was merged and accepted (branch `8e79b99..f36f54f`, merged
as `f5b2e31`, acceptance recorded in `627cdf2`): **160 package Vitest
tests, 358 package Pest tests (5832 assertions)** passing, silent package `tsc`. Demo integration
has landed: **49 demo Pest tests (191 assertions)**, silent demo `tsc`, a successful demo
`npm run build`. Real-browser acceptance for Plan 4 has since run and passed — see "Plan 4
acceptance evidence" below for the six checks. (A final whole-branch fix wave landed after this
entry; see the fix-wave report for its own totals and commits.)

<!-- TASK-17-TODO: Plan 5 has since merged and closed F-1, F-2, G-2 and G-4, cut G-3 (E26),
     verified R-2, and added the demo tenant-switcher and MakeNodeCommand/MakeTriggerCommand
     caching-bug entries. Update this "Last updated" paragraph with Plan 5's merge commit, its
     accepted-branch range, and its own measured package Pest/Vitest counts once Task 17 has run
     and measured them on merged main. Do not guess the numbers here in the meantime. -->

---

## Decisions and scheduled follow-ups

### D-1 · `nodeflow.tenancy` default should probably be inferred, not asked
**Status:** ✅ **DECIDED 2026-08-20 — adopt inference.** Spec amended as **E2a**; implemented as Plan 3a's first task. Kept here for the reasoning. · **Raised by:** Plan 2 whole-branch review

> **Outcome:** `auto` becomes the default and infers from whether the container holds the package's
> own `NoTenancyResolver` or a host-bound one. `disabled` and `resolver` remain as explicit overrides.
> Measured before adopting: with every null return throwing, the suite is **259/259** — no test does a
> tenant-scoped read with a null tenant, so the change costs no test churn. Control-probed with a
> bogus mode, which correctly fails 9 tests, confirming the probe was real rather than a config that
> never took effect.

**Follow-up decision, 2026-08-20:** preserve the shipped `auto` inference and strengthen its
observability: record or diagnose when the host's tenancy binding caused `auto` to choose resolver
mode. The strengthening is decided in favour, unimplemented, and belongs with D-2 in a dedicated
security-hardening plan after Plan 3b. E2a's `auto` inference itself already shipped in Plan 3a.

**Untouched by Plan 4.** The run view added nothing to the durable execution path — E13 chose a
reader-side derivation over any change there — so this diagnostic follow-up still belongs entirely
to the post-3b security-hardening plan.

Spec decision **E2** defaults the mode to `disabled`, so a multi-tenant host that binds a
`TenantResolver` and never sets `resolver` keeps today's silent unscoped read whenever their resolver
returns `null` — every queue job, every console command, every pre-auth request. The docs now tell
them to set it, so the hole is closed by documentation only.

The reviewer's argument, which I agree with: the package can infer the answer instead of asking. It
binds its own fallback resolver with `bindIf` (`src/NodeflowServiceProvider.php`), so extracting that
anonymous class to a named `Nodeflow\Tenancy\NoTenancyResolver` and defaulting the mode to `auto`
would let a null mean "no tenancy" when our fallback is in the container and "unresolved" when the
host bound their own. That preserves E2's intent exactly — the engine-only host still works
untouched — while removing the docs dependency for everyone else.

Not taken unilaterally because E2 is an approved spec decision and this introduces a new mechanism
and a new default. **Free now; a breaking change once hosts exist.**

### D-2 · Foundation spec §9 layer 2 does not exist in code
**Status:** ✅ **DECIDED 2026-08-20 — implement the assertion; unimplemented and assigned to the
dedicated security-hardening plan after Plan 3b.** · **Raised by:** Plan 2 whole-branch review

Foundation spec §9 claims runs denormalise `tenant_id` and that `RunNodeActivity` asserts it matches
before executing. `src/Workflows/Activities/RunNodeActivity.php` contains no such assertion.

That layer is what would catch a mis-tenanted run at execution time, and Plan 2's relation-unscoping
ruling leans on the same edge (see G-3). Either implement it or correct the spec — a documented
defence-in-depth layer that isn't there is worse than two honest layers. Implementing an assertion on
the durable execution path deserves its own plan rather than a drive-by commit.

**Untouched by Plan 4.** The run view reads through `$run->nodeExecutions()` and `$run->subjects()`
and writes nothing; it adds no code to `RunNodeActivity` or any other point on the durable execution
path. This gap remains exactly as described, assigned to the same post-Plan-3b plan as D-1.

---

## Plan 3 acceptance evidence

Both mandatory jsdom composition tests shipped: `Canvas` mounts `NodeCard`, and the assembled
`FlowEditor` carries host renderers and package-owned option handling through that canvas. Task 10's
real-browser acceptance then passed in the symlinked demo at `549fe42`: lazy options made exactly one
request and returned four attributes; `wait1` was added, rewired and positioned; version 2 froze the
graph with `1 minute`; clearing the duration left version 2 unchanged while the broken draft advanced
to revision 4 with a null duration; and reload displayed that draft over the last successful version.
The supporting gates were 123 package Vitest tests, 325 package Pest tests (5754 assertions), 44 demo
Pest tests (171 assertions), silent package and demo `tsc`, a successful demo Vite build, and package
Tailwind output containing `min-h-[32rem]`.

---

## Plan 4 acceptance evidence

All gates below are measured on merged `main`. Package (`f5b2e31`): **358 Pest tests (5832
assertions)**, **160 Vitest tests**, silent `npx tsc --noEmit`. Demo (`~/Sites/test-workflow`,
`main` at `bb0f7d8`): **49 Pest tests (191 assertions)**, silent `tsc`, a passing Vite build.

Three further checks ran against the demo's *compiled* bundle, not its source, because a
host-wiring or build-pipeline regression is invisible to both suites above: the failure badge
ships as `label: "errors"`, never `label: "failed"` — a deliberate override of spec §4.2, forced
by the demo's own `demo.send` node declaring an output literally named `failed`, which without
the override would render two badges both reading "failed" for two different meanings;
`opacity-40` appears in the built JS **and is emitted in the built CSS**, which is the Tailwind
`@source` host-wiring requirement actually verified rather than assumed; `hasOwnProperty.call`
appears three times, the collision-hardened read path intact through the bundler; and exactly one
chunk carries React's client-internals marker despite two `node_modules/react` trees on disk,
proving `resolve.dedupe` (G-4) holds in the built artifact and not only in `vite.config.ts`.

Real-browser acceptance then ran against `http://test-workflow.test/`, with a real queue worker
running: six checks, all passed.

1. **Pinned version.** Run 1 rendered 10 nodes — exactly v1 — while its flow had advanced to v2
   (11 nodes, including `v2only`) and its draft held 12 (including `draftonly`). Neither appeared.
   Run 4, started after the advance, correctly pinned v2 and does render `v2only`. This single
   observation excludes both wrong implementations at once: reading `draft_graph`, and reading
   `flow->currentVersion`.
2. **Overlay refresh.** Zero requests on page load — the initial snapshot travels as an Inertia
   prop, not a fetch — then exactly one request per 5000 ms, matching the configured interval. The
   sidebar status moved from `running` to `completed` with no reload.
3. **Reached-zero versus never-reached, rendered.** `segment` undimmed with badges `matched 4` and
   `unmatched 0`; `unmatchedexit`, `upgrade`, `done` and `v2only` all dimmed with no badge at all.
   Separately, a run parked at a one-day wait rendered `wait1d` undimmed with a `waiting 4` badge
   while holding the entire audience and having **no** `node_executions` row — the on-screen proof
   that deriving `reached` from row existence alone would dim the single most important state this
   view exists to show. **C-5 is visible on screen here:** `done` renders dimmed even though every
   subject exited through it.
4. **Subject drill-down.** Clicking `wait1d` listed `user #30` and `user #31` behind a "Load more"
   control; activating it appended `user #32` and `user #33`, and the control disappeared. Two
   requests were issued — the base URL, then `?cursor=…` — so sentinel substitution and cursor
   paging both work through the host's own route prefix. Nobody repeated, nobody lost.
5. **Polling stops at a terminal run.** A fast run watched for 45 seconds issued requests 1 through
   7 at five-second intervals while `running`; status flipped to `completed` at t+35s; the count
   held at 7 through t+40s and t+45s. A run already terminal when the page opened issued **zero**
   requests.
6. **Console clean.** No errors of any kind across every interaction — no "Invalid hook call", no
   unhandled rejection.

Both empty-state sentences were read live and confirmed distinct and individually true: the
never-reached branch names `core.exit` as the caveat; the reached-then-empty branch says the
card's counts are the only record and "not always a complete one". Worth recording in the same
breath: this copy took **four** review rounds to land — each of the first three fixed one false
sentence and introduced another, and the fourth was caught only because a reviewer was told to
assume a fourth existed.

**One caveat, stated rather than buried:** browser acceptance ran in an isolated Chrome instance —
a temporary profile with remote debugging enabled — rather than the developer's day-to-day
browser, because enabling remote debugging on that browser needs a manual toggle. Everything
exercised was the real merged app over HTTP with a real queue worker; only the browser profile was
disposable.

---

## Proven defects

### F-1 · `--group='{{ outputs }}'` renders an unparseable file and exits 0
**Status:** ✅ **RESOLVED, Plan 5.** · **Raised by:** Plan 1 fix-wave re-review, reproduced by execution · **Cost:** ~2 lines

`MakeNodeCommand::buildClass()` substituted `{{ group }}` at array index 2, before `{{ outputs }}`
and `{{ firstOutput }}`, and `str_replace` with array arguments is sequential. So a `--group` value
containing a later placeholder was re-substituted: `->group(''sent', 'failed'')`, which failed
`php -l` while the command reported success.

Worse than the bug: `paletteGroup()`'s docblock claimed a backslash and a single quote "are the only
two characters that can end it early", which was demonstrably false.

**Fix.** Both renderers (`MakeNodeCommand::buildClass()` and `MakeTriggerCommand::buildClass()`) now
substitute with `strtr()`, which replaces all keys in one simultaneous pass rather than sequentially —
so a value containing another placeholder's literal text can no longer be re-substituted. The
`paletteGroup()` docblock is corrected to describe what `strtr()` actually guarantees, rather than the
disproved "only two characters" claim.

### F-2 · Nothing but `php -l` watches `stubs/node.both.stub`
**Status:** ✅ **RESOLVED, Plan 5.** · **Raised by:** Plan 1 fix-wave re-review, proven by mutation · **Cost:** ~15 lines

Renaming `->help(` to `->helpText(` in `node.both.stub` alone left **all 203 tests green** while
that stub fatalled in every host that generated from it. The three stubs are three independent copies
of the same call chain; the `require`-and-execute tests covered the subject and audience stubs only.

**Fix, and the measurement that proves it closes the gap.** A third require-and-execute test now
mirrors the `SendBlast` one, under a fourth distinct class name (`SendDigest` — two generated classes
of the same name in one process fatals). Re-running the same mutation — renaming `->help(` to
`->helpText(` in `node.both.stub` alone — with the new test in place: the `SendSms` and `SendBlast`
execute tests both still pass, and only the new `SendDigest` test fails. That is the coverage gap
closing exactly where it was open.

### F-3 · `--type` leaked across reused generator command instances
**Status:** ✅ **RESOLVED, Plan 5 (ruling R16).** · **Raised by:** scope-expansion probe during Plan 5
· **Cost:** ~30 lines each, across two commands

Symfony's `Application` resolves one command object per command name and keeps it for the process's
lifetime, so two `Artisan::call('nodeflow:make-node', ...)` invocations in one process reuse the exact
same `MakeNodeCommand` instance rather than a fresh one. `handle()` never reset `$resolvedType`
between calls, so `nodeType()`'s memoized-not-null guard returned the **first** call's already-
validated type on the **second** call — skipping validation, including the `NodeRegistry` collision
check, entirely. Reproduced by probe: two invocations in one process, the second passing a distinct,
explicit `--type`, generated a class whose `type()` still returned the first call's type, at **exit
code 0**. Published flow versions resolve through that string forever, so the leaked value is a
permanent defect, not a cosmetic one.

**Fix.** `MakeNodeCommand::handle()` now resets `$resolvedType` at its start, mirroring a fix already
shipped in `MakeTriggerCommand::handle()` — the same class of bug (`$resolvedType` / `$resolvedEvent`
surviving across reused invocations) was found and fixed there in this same plan, for the same reason.
Both commands ship a persisted test asserting that two invocations in one process, with different
`--type` (or `--type`/`--event`), each produce their own file with their own value and never the
other's — `MakeNodeCommandTest`'s "validates each invocation independently, even when the command
instance is reused" and its `MakeTriggerCommandTest` counterpart.

---

## Coverage and enforcement gaps

### G-1 · The request-context scanner misses aliased and joined forms
**Status:** ✅ **RESOLVED, Plan 4 (E18).** · **Raised by:** Plan 2 fix-wave re-review, probed
directly

`RunSubject` and `NodeExecution` carry no tenant scope by design (spec E1), so
`tests/Support/RequestContextScanner.php` is their entire defence. It previously caught only
`DB::table('nodeflow_run_subjects')` and the connection-prefixed form; these four forms evaded it,
and Plan 4's own `runs/{run}/nodes/{node}/subjects` drill-down was exactly where one would have
been written:

- `DB::table('nodeflow_run_subjects as rs')`
- `->join('nodeflow_run_subjects', ...)`
- `->from('nodeflow_run_subjects')`
- Raw SQL, e.g. `DB::select('... from nodeflow_node_executions ...')`

**Fix.** The rule became "request-context code never names these tables", matched over
comment-stripped source: `tests/Support/RequestContextScanner.php` now runs `token_get_all()` and
drops `T_COMMENT`/`T_DOC_COMMENT` before matching, then treats the forbidden table name appearing
*anywhere* in what remains as a violation — one bright line instead of an ever-growing list of
builder methods, and it catches raw SQL that no method list could. Comment-stripping is what lets
`GraphValidator`'s comment about the table's unique constraint keep naming the table without
tripping the rule.

The cost is two legitimate call sites: `src/Models/RunSubject.php` and
`src/Models/NodeExecution.php` declare `protected $table`, so both are path-allowlisted in
`tests/Unit/ArchitectureTest.php`. That is not a reopened hole — a scope method added to either
model still has to be *called* somewhere, and the existing `Model::` rule catches the call site,
not the declaration.

`tests/Unit/RequestContextScannerTest.php` gained coverage for the four evasion forms above, plus
the over-matching guard: a comment or docblock naming the model or the table is asserted **not** to
be a violation, so the fix cannot be "solved" by deleting a comment worth keeping.

### G-2 · `tenant_id` immutability is undocumented, and query-builder updates bypass it
**Status:** ✅ **RESOLVED, Plan 5.** · **Raised by:** Plan 2 fix-wave re-review

`tenant_id` is immutable on update for `Flow`, `FlowVersion`, `Run` and `Template`, and until now
nothing in `docs/` said so. A host doing `$flow->update($request->all())` with a changed `tenant_id`,
or promoting a global `Template` into a tenant's, meets a `CrossTenantWriteException` with no
integration-doc coverage.

Separately, the guard is an `updating` model hook, so `Flow::withoutTenancy()->where(...)->update([...])`
fires no model events and bypasses it entirely. Inherent to the approach, and the codebase already
uses query-builder updates for status writes (`CompleteRunActivity`) — so it's a pattern a future
author may copy.

**Fix.** `docs/02-integration.md` gained the "`tenant_id` is fixed at creation" subsection, right
after "Tenant isolation and your gate": it names `CrossTenantWriteException`, shows both accidental
triggers (`$flow->update($request->all())` and promoting a global `Template`), and states the
query-builder bypass explicitly rather than leaving it to be discovered. The bypass itself is
pinned by a covering test asserting `Flow::withoutTenancy()->where(...)->update(['tenant_id' => ...])`
changes the row with no exception thrown — the guard's blind spot, proven rather than only described.

### G-3 · The FK invariant behind the unscoped relations is documented, not enforced
**Status:** DECISION — **cut and reassigned, Plan 5 (E26).** · **Raised by:** Plan 2 whole-branch
review, proven by probe

`Run::flowVersion()`, `Flow::currentVersion()` and `Flow::versions()` are unscoped on the reasoning
that reaching a `Run` or `Flow` already proves entitlement. That holds only while `flow_version_id`
and `current_version_id` point inside the parent's tenant. Plan 2 added an `updating` guard for
`tenant_id` and wrote the invariant into all three relation comments, but there is still no composite
FK constraint and both models have `$guarded = []`.

**Two enforcement mechanisms were considered for Plan 5 and both die on measurement.** A composite
foreign key is unverifiable in this suite: `tests/TestCase.php` sets no `foreign_key_constraints` key
on its SQLite connection, so `SQLiteConnector` never issues `pragma foreign_keys`. Probed directly,
`pragma foreign_keys` returns `0`, and `Run::create(['flow_version_id' => 999999, …])` **succeeds** —
no foreign key in this package is enforced by any test today, so a composite FK would ship as an
invariant the suite cannot exercise. A `$guarded` mass-assignment block fails silently at scale
instead: measured cost is four production call sites (`PublishFlow.php:40,67`, `StartRun.php:59,60`)
plus 27 call sites across 16 test files, and `Model::preventSilentlyDiscardingAttributes()` is off by
default — so `fill()` *silently discards* a guarded attribute rather than throwing, meaning those 27
sites would start writing null foreign keys without any test noticing, and since foreign keys are not
enforced the rows insert cleanly anyway. A change whose failure mode is 27 silently-broken fixtures is
not a safety improvement.

**What survives belongs with D-2, not this plan.** A `saving` guard on `Flow.current_version_id` and
`Run.flow_version_id` that resolves the target `FlowVersion` unscoped and throws
`CrossTenantWriteException` on a tenant mismatch is testable and cheap — one query per publish and
per run creation — but it is a tenant assertion on a write path, the same family as D-2's assertion in
`RunNodeActivity`. Plan 5's handoff explicitly forbids absorbing that work; splitting one coherent
piece of security hardening across a tooling plan and a security plan would produce two half-defences.

So G-3 stays open, its documented invariant and three relation comments stand unchanged, and it is
reassigned to the security-hardening plan alongside **D-1** and **D-2**.

### G-4 · Host wiring omits Vite dependency deduplication
**Status:** ✅ **RESOLVED, Plan 5, by `ViteDedupeStep`.** · **Raised by:** Plan 3 real-app acceptance,
proven by the symlinked demo

Spec §5.6 lists four host wiring requirements, but the accepted app proved there are five. Vite must
set `resolve.dedupe` for `react`, `react-dom`, and `@xyflow/react`; otherwise a symlinked source
package can load duplicate client runtimes and fail at runtime with an invalid hook call even while
the build succeeds.

**Untouched by Plan 4.** `FlowRun` shares the same five host-wiring requirements as `FlowEditor`
and adds no sixth; it relies on the `dedupe` setting the demo already carries rather than needing a
new one.

**Fix.** `nodeflow:install` (and `--check`) now verifies this fifth requirement alongside the other
four, via `src/Console/Install/ViteDedupeStep.php`. The check is bounded to the `dedupe` array's own
text, not a whole-file search: every React application's `vite.config.ts` mentions `react` somewhere
— a `@vitejs/plugin-react` import, an `optimizeDeps.include` list — so a whole-file match would report
essentially every host as already wired. Matching only inside the `dedupe` array's own substring is
what makes the check mean anything.

---

## Documentation drift

### R-1 · The spec does not record Plan 2 as delivered
**Status:** DRIFT · **Raised by:** self-review after merge · **Cost:** small

`docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md` marks Plan 1 delivered with an
"as built" block on §7.2, but §4 has no equivalent and the §3 plan table doesn't mark Plan 2
delivered. Plan 2's rulings changed §4 substantially — relation-level unscoping, the inheritance
hook, the `updating` guard, the unconditional parent-child check, mode validation — and **Plan 3's
author reads §4 as the binding authority.**

Fixed in the same commit that created this file; kept here as the record of why.

### R-2 · Minor docs imprecision on `disabled` vs `resolver`
**Status:** ✅ **RESOLVED — already corrected before Plan 5, verified during Plan 5.**
· **Raised by:** Plan 2 fix-wave re-review

`docs/02-integration.md` warned that on a path where the resolver returns null "the read is **not**
scoped and the row comes back". True under `disabled`, but under `resolver` those paths throw — the
concern was that the sentence over-warned without naming which mode it meant.

**Verified during Plan 5** by reading `docs/02-integration.md` ("Tenant isolation and your gate") as
it stands. It is already mode-precise: "Under the shipped default (`auto`), a null tenant is unscoped
only if you never bound a `TenantResolver`; once you bind one, `auto` throws on a null instead of
reading every tenant's rows. The gap opens only if you explicitly set `nodeflow.tenancy` to `disabled`
while a resolver is bound — a queue-dispatched preview, an API token that has not selected an
organisation yet, a console command — the read is **not** scoped and the row comes back." That names
`disabled` explicitly as the mode where the row comes back unscoped, and says nothing that would apply
to `resolver` (which the surrounding text elsewhere says always throws) — so the imprecision this
issue named is gone. `git log -p --follow docs/02-integration.md` traces the sentence to commit
`2962427c3ae2c59318389e4bf593ccbeb4588e4c` ("docs: document the editor routes, drafts and option
sources", 2026-08-20), which is Plan 3a's docs pass — corrected there and never struck through since.

---

## Carried forward from the foundation work

Recorded in the foundation spec's known-limitations and still open. None are in the editor's path.

- **C-1** `runs.status` never reaches a failure state — only `running` and `completed` are written.
  Plan 4's overlay polling treats "terminal" as `completed` only, so a dead run leaves a client
  polling until the page closes.
- **C-5** (new, Plan 4, spec decision E13) A node that ran and released nobody writes no
  `node_executions` row at all: `NodeRunner::advance()` inserts one row per named output plus one
  for failures, and `NodeResult::empty()` — what `core.exit` returns — produces neither. The run
  view's overlay derives `reached` from row existence *or* an active subject sitting at the node,
  so once every subject has moved past a node like `core.exit`, it reads as never reached even
  though the run genuinely executed it. Per-output counts elsewhere are unaffected; only that one
  node's dimming misleads. **Sibling to C-1, not the same defect:** C-1 is why polling stops only
  on `completed`; this is why `core.exit` reads as never reached. Both are honest limitations of
  what the run view can show given what the durable execution path records today, and closing
  either means writing to that path — deliberately out of scope for Plan 4, which only reads it.
  See also **C-6**, discovered after Plan 4 shipped, which shares this exact root cause from the
  other direction.
- **C-6** (new, found after Plan 4 shipped, spec decision E13) `reached` can also flip from `true`
  back to `false` on a later poll, not just start `false` and stay there. `RunOverlay`'s
  `reached = (rows !== null || waiting > 0)`: if a subject was active at a node — the only thing
  making that node `reached` — and is then cancelled via `SubjectExiter::exit()` without ever
  producing an output or a failure row there, the next poll reports `reached: false`, even though
  the node genuinely held that subject. `FlowRun`'s drill-down panel says "this node was never
  reached by this run, so no subject has ever been here" in that state, which is false. **Same root
  cause as C-5, not a separate defect:** `reached` is derived from currently observable state
  rather than durable history, so any path that erases its own evidence also erases the fact that
  it happened — `core.exit` writing no row (C-5) and a cancellation-only visit leaving nothing
  behind (this) are the same gap approached from two directions. Closing either means writing a row
  on the durable execution path, which Plan 4 deliberately does not touch.
- **C-2** `ownsSubject()` is called once per subject; a set-shaped contract is wanted before
  six-figure use.
- **C-3** The suite is SQLite-only.
- **C-4** The interpreter has never run against a real queue worker in CI.

---

## Deliberately accepted, not issues

Listed so they aren't re-litigated:

- `FlowVersion::booted()` costs one extra `Flow` lookup per version insert. One row per publish.
- `--force` overwrites both the generated node class and its test file, with the coupling documented
  rather than split into a second flag.
- The guest-deny policy test carries no independent signal; its companion allow test is the sharp one.
- `CrossTenantWriteException`'s constructor is private — clean for an unreleased package.
- A host `Gate::before` hook returning true overrides default deny for every package ability. Laravel
  semantics, the host's explicit choice, now documented.
- **The demo's tenant switcher lets an authenticated user act as another organisation, on purpose.**
  The next task (Task 16, E27) fixes the demo's route binding — `runs/{run}/subjects/{subject}/...`
  bound through the tenant-scoped `Run` so a cross-tenant id is a 404, `Run::withoutTenancy()` deleted
  from both actions, the `User` write scoped to `$run->tenant_id`, the route group gaining
  `->middleware(['auth'])`, and `switchTenant` validating the posted `tenant_id` against `Organization`
  before it reaches the session. None of that removes the switcher itself: any authenticated demo user
  can still `POST /nodeflow/tenant` and deliberately switch to another organisation, because letting a
  demo visitor explore every tenant's flows is the switcher's entire purpose. This is not a closed
  hole and must not be read as one — it is the demo behaving as designed.
