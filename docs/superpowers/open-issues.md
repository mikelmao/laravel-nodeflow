# Open issues

Everything known-but-unfixed, in one place, to be reviewed before the six-plan effort is called
done. Each entry records where it came from, so a reader can judge how well-established it is —
most were proven by probe during a review rather than suspected.

**Status key:** `DECISION` needs a human call · `DEFECT` is proven and unfixed · `GAP` is missing
coverage or missing code · `DRIFT` is documentation disagreeing with code.

Last updated 2026-08-21 after Plan 4 package work (`8e79b99..4192275`): **156 package Vitest
tests, 356 package Pest tests (5820 assertions)** passing, silent package `tsc`. Demo integration
and real-browser acceptance for Plan 4 have not run yet — see "Plan 4 acceptance evidence" below,
which is a placeholder pending Task 13. Demo Pest tests remain **44**, from Plan 3's demo
acceptance (`549fe42`), unchanged by Plan 4's package-only work.

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

**PLACEHOLDER — not yet filled in. Do not read this section as accepted.** Plan 4's real-browser
acceptance against the symlinked demo, and the demo integration it depends on, are Task 13's work
and have not run as of this entry. This section exists now so Task 13 has a fixed place to record
that evidence rather than inventing one later; it will be replaced wholesale once the six checks in
`docs/superpowers/specs/2026-08-21-run-view-design.md` §9 have actually been run.

Package-only gates already measured, ahead of that browser pass: **356 package Pest tests (5820
assertions)**, **156 package Vitest tests** (the design predicted 151; six extra tests were added
during execution, each covering a guard that otherwise had none), and a silent package `tsc`.

---

## Proven defects

### F-1 · `--group='{{ outputs }}'` renders an unparseable file and exits 0
**Status:** DEFECT · **Raised by:** Plan 1 fix-wave re-review, reproduced by execution · **Cost:** ~2 lines

`MakeNodeCommand::buildClass()` substitutes `{{ group }}` at array index 2, before `{{ outputs }}`
and `{{ firstOutput }}`, and `str_replace` with array arguments is sequential. So a `--group` value
containing a later placeholder is re-substituted: `->group(''sent', 'failed'')`, which fails `php -l`
while the command reports success.

Worse than the bug: `paletteGroup()`'s docblock claims a backslash and a single quote "are the only
two characters that can end it early", which is now demonstrably false. **Fix the comment first** — a
wrong comment outlives the bug it describes.

Fix: render `{{ group }}` last in `buildClass()`, or reject `{{` in `paletteGroup()`.

### F-2 · Nothing but `php -l` watches `stubs/node.both.stub`
**Status:** GAP · **Raised by:** Plan 1 fix-wave re-review, proven by mutation · **Cost:** ~15 lines

Renaming `->help(` to `->helpText(` in `node.both.stub` alone leaves **all 203 tests green** while
that stub fatals in every host that generates from it. The three stubs are three independent copies
of the same call chain; the `require`-and-execute tests cover the subject and audience stubs only.

Fix: a third require-and-execute test mirroring the `SendBlast` one, under a fourth distinct class
name (two generated classes of the same name in one process fatals).

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
**Status:** GAP · **Raised by:** Plan 2 fix-wave re-review

`tenant_id` is now immutable on update for `Flow`, `FlowVersion`, `Run` and `Template`, and nothing in
`docs/` says so. A host doing `$flow->update($request->all())` with a changed `tenant_id`, or
promoting a global `Template` into a tenant's, meets a `CrossTenantWriteException` with no
integration-doc coverage.

Separately, the guard is an `updating` model hook, so `Flow::withoutTenancy()->where(...)->update([...])`
fires no model events and bypasses it entirely. Inherent to the approach, and the codebase already
uses query-builder updates for status writes (`CompleteRunActivity`) — so it's a pattern a future
author may copy. Worth stating in the guard's own comment.

### G-3 · The FK invariant behind the unscoped relations is documented, not enforced
**Status:** GAP · **Raised by:** Plan 2 whole-branch review, proven by probe

`Run::flowVersion()`, `Flow::currentVersion()` and `Flow::versions()` are unscoped on the reasoning
that reaching a `Run` or `Flow` already proves entitlement. That holds only while `flow_version_id`
and `current_version_id` point inside the parent's tenant. Plan 2 added an `updating` guard for
`tenant_id` and wrote the invariant into all three relation comments, but there is still no composite
FK constraint and both models have `$guarded = []`.

**Plan 3's controllers must never accept `current_version_id` or `flow_version_id` from request
input.** That is the whole mitigation.

### G-4 · Host wiring omits Vite dependency deduplication
**Status:** GAP · **Raised by:** Plan 3 real-app acceptance, proven by the symlinked demo

Spec §5.6 lists four host wiring requirements, but the accepted app proved there are five. Vite must
set `resolve.dedupe` for `react`, `react-dom`, and `@xyflow/react`; otherwise a symlinked source
package can load duplicate client runtimes and fail at runtime with an invalid hook call even while
the build succeeds. Plan 5's `nodeflow:install` must verify this fifth requirement with the other
four.

**Untouched by Plan 4.** `FlowRun` shares the same five host-wiring requirements as `FlowEditor`
and adds no sixth; it relies on the `dedupe` setting the demo already carries rather than needing a
new one. Verifying all five, including this one, remains `nodeflow:install`'s Plan 5 work.

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
**Status:** DRIFT · **Raised by:** Plan 2 fix-wave re-review

`docs/02-integration.md` warns that on a path where the resolver returns null "the read is **not**
scoped and the row comes back". True under `disabled`, but under `resolver` those paths throw. It
over-warns rather than under-warns, so it errs safe, but it's imprecise about the mode the docs have
just told the reader to select.

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
