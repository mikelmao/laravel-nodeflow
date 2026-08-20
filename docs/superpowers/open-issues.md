# Open issues

Everything known-but-unfixed, in one place, to be reviewed before the six-plan effort is called
done. Each entry records where it came from, so a reader can judge how well-established it is —
most were proven by probe during a review rather than suspected.

**Status key:** `DECISION` needs a human call · `DEFECT` is proven and unfixed · `GAP` is missing
coverage or missing code · `DRIFT` is documentation disagreeing with code.

Last updated after Plan 2 merged (`62c9e66`), 259 tests passing.

---

## Decisions waiting on a human

### D-1 · `nodeflow.tenancy` default should probably be inferred, not asked
**Status:** ✅ **DECIDED 2026-08-20 — adopt inference.** Spec amended as **E2a**; implemented as Plan 3a's first task. Kept here for the reasoning. · **Raised by:** Plan 2 whole-branch review

> **Outcome:** `auto` becomes the default and infers from whether the container holds the package's
> own `NoTenancyResolver` or a host-bound one. `disabled` and `resolver` remain as explicit overrides.
> Measured before adopting: with every null return throwing, the suite is **259/259** — no test does a
> tenant-scoped read with a null tenant, so the change costs no test churn. Control-probed with a
> bogus mode, which correctly fails 9 tests, confirming the probe was real rather than a config that
> never took effect.

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
**Status:** DECISION · **Raised by:** Plan 2 whole-branch review

Foundation spec §9 claims runs denormalise `tenant_id` and that `RunNodeActivity` asserts it matches
before executing. `src/Workflows/Activities/RunNodeActivity.php` contains no such assertion.

That layer is what would catch a mis-tenanted run at execution time, and Plan 2's relation-unscoping
ruling leans on the same edge (see G-3). Either implement it or correct the spec — a documented
defence-in-depth layer that isn't there is worse than two honest layers. Implementing an assertion on
the durable execution path deserves its own plan rather than a drive-by commit.

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
**Status:** GAP · **Raised by:** Plan 2 fix-wave re-review, probed directly

`RunSubject` and `NodeExecution` carry no tenant scope by design (spec E1), so
`tests/Support/RequestContextScanner.php` is their entire defence. It now catches
`DB::table('nodeflow_run_subjects')` and the connection-prefixed form, but these still evade it:

- `DB::table('nodeflow_run_subjects as rs')`
- `->join('nodeflow_run_subjects', ...)`
- `->from('nodeflow_run_subjects')`

Plan 4's `runs/{run}/nodes/{node}/subjects` drill-down is exactly where an aliased subjects query
would get written.

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
