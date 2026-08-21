# Plan 5 — remaining tooling: session handoff prompt

Paste the whole of this file as the opening message of a new session. It is written to be
self-contained: everything a fresh session needs that is not already in the repository.

---

We're continuing work on laravel-nodeflow. Plan 4 is merged and accepted. The next piece is Plan 5.

## Current state

**Package** — `~/Projects/laravel-nodeflow`, branch `main`, expected HEAD
`b096442a1f1fb178c12d0aa3fe91d943cc9b6a5a`. Clean baseline:
- 358 Pest tests, 5,832 assertions
- 160 Vitest tests
- `npx tsc --noEmit` silent

**Demo application** — `~/Sites/test-workflow`, branch `main`, expected HEAD
`bb0f7d87baa4cb454f71476bdda691498564520e`. Clean baseline:
- 49 Pest tests, 191 assertions
- `npx tsc --noEmit` silent
- `npm run build` passes
- `vendor/atram/laravel-nodeflow` symlinks to `~/Projects/laravel-nodeflow`
- Local domain: http://test-workflow.test/

Plans 1 through 4 are delivered: the node generator, the security floor, the React `FlowEditor`, and
the read-only `FlowRun` run view with its overlay and subject drill-down. Plan 4's browser acceptance
passed all six checks against the real demo.

**Before designing or changing anything:**

1. Verify both repositories' branches, HEADs, cleanliness, the symlink, and the baseline counts. If
   reality differs, report it rather than silently adapting.
2. Check whether either repository contains an `almanac/` directory and consult it if present. (At
   Plan 4's close, neither did.)
3. Read the authoritative documents:
   - `README.md`
   - `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md` — read the **"as built"**
     blocks in §4, §5, §6 and §7.2 before relying on any prose below them. Then study §3, §7.1, §7.3,
     §9, §10, §11 and §12.
   - `docs/superpowers/specs/2026-08-21-run-view-design.md` — Plan 4's design, decisions **E13–E18**.
     E18 matters to you: it is the request-context scanner's current rule.
   - `docs/superpowers/open-issues.md` — the whole file. Plan 5 owns more of it than any plan so far.
   - `docs/02-integration.md` — this is the document `nodeflow:install` has to make true.
   - `docs/08-editor-client.md` — the five host-wiring requirements `install` must verify.
4. `docs/prompts/plan-3b-and-beyond.md` and `docs/prompts/editor-and-node-tooling.md` are useful
   history and are now stale. Do not treat their counts or state as authoritative.

Use `superpowers:brainstorming` first. §7.1 and §7.3 are outlines, not implementation designs.
Inspect the existing console commands, the shared registration writer, the stubs, the published
migration, the demo's wiring, and the tests before proposing anything.

Raise only decisions that materially affect the public contract, security, or scope. Use your
judgement on ordinary implementation details. Do not work around contradictions or blockers — say so.

Once the design is approved: `superpowers:writing-plans`, then review the plan rigorously, then
`superpowers:using-git-worktrees`, then execute with `superpowers:subagent-driven-development` under
strict TDD, requesting code-quality and spec-compliance review after each meaningful task, then
`superpowers:verification-before-completion` and `superpowers:finishing-a-development-branch`. Do not
claim completion without testing the merged `main` branches and performing browser acceptance through
http://test-workflow.test/.

## Plan 5 scope and contract

### `nodeflow:install` (§7.1)

Publishes config and migrations, creates `app/Providers/NodeflowServiceProvider.php` with an explicit
`$nodes = []` registration home, and wires the host's client requirements. Then it **verifies**:
re-reads each file and asserts the wiring is present, reports which of the four authorization gates
are undefined, reports the resolved `nodeflow.tenancy` mode, and **exits non-zero when it could not
wire something**. Idempotent — a second run reports "already wired" and never duplicates a line.
Every edit asserts its anchor exists and is unique before writing (**E11**).

**Five host-wiring requirements, not four.** §5.6 lists four; Plan 3's real-app acceptance proved
there are five, and `docs/08-editor-client.md` documents all five. `install` must verify: the Vite
alias, the tsconfig path mapping, the Tailwind `@source` line, host-installed `@xyflow/react`, and
Vite `resolve.dedupe` for `react`, `react-dom` and `@xyflow/react`. **Three of the five fail
quietly** — that is why verification is the point of this command, not a nicety. This closes **G-4**.

**Also verify the published migration matches the package's.** Plan 4 discovered this the hard way:
the demo had published `2026_08_18_000001_create_nodeflow_tables.php`, Plan 4 edited the package's
copy in place to add a fourth index column, and the demo's copy silently kept the three-column
index. No demo test could catch it, because the index assertion lives in the package's own suite
while the demo's tests run against the demo's copy. Spec §5.1's premise — "nothing is installed
anywhere" — is now false, and every future in-place edit diverges the same way. Decide whether
`install` verifies the copy, whether the package stops editing that migration in place, or both.

**Three constraints Plan 1 already fixed, so `install` has no free choice:**
- The anchor is a live constant: `Nodeflow\Console\NodeRegistrationWriter::ANCHOR` is
  `'protected array $nodes = ['`. The generated provider must contain that line **exactly once** or
  `make-node` cannot register into it. The writer refuses on zero and on more than one.
- `docs/02-integration.md` teaches `Nodeflow::register([...])` in any provider's `boot()`, which is
  **not** what the writer looks for. A host who followed the docs gets `ProviderMissing`. `install`
  is where those two stories have to become one.
- `install` must use `handle(): int`. Returning `false` from a Laravel command's `handle()` exits
  **0**, which would silently defeat this section's own non-zero-exit requirement.

One known gap in the shared writer, documented in its source: a provider that imports the class and
lists a bare `SendSms::class` is not recognised as already present, so a duplicate is appended.
Harmless at runtime; `install`'s idempotency claim must not rest on it.

### `nodeflow:make-trigger` and `nodeflow:make-subject-attribute` (§7.3)

`make-trigger` (`--event=`, `--type=`) emits the four abstract methods plus `idempotencyKey()` and
`matchesConfig()` as commented overrides. It earns its place because `event()` returning a host event
class is the most confusable part of the trigger contract.

`make-subject-attribute` appends a `SubjectAttribute::make()` through the same anchor mechanism. Thin,
but conditions are the non-technical author's main tool under D13 and the attribute registry is the
least discoverable part of the package.

**Not built:** `make-flow` and `make-field-control`, with reasons in §7.3. Do not add them.

### Also in scope

- **F-1** — `--group='{{ outputs }}'` renders an unparseable file and exits 0, because
  `buildClass()` substitutes `{{ group }}` before `{{ outputs }}` and `str_replace` is sequential.
  **Fix the docblock first:** `paletteGroup()` claims a backslash and a single quote "are the only
  two characters that can end it early", which is demonstrably false. A wrong comment outlives the
  bug it describes.
- **F-2** — nothing but `php -l` watches `stubs/node.both.stub`. Renaming `->help(` to `->helpText(`
  in that stub alone left every test green while the stub fataled in every host. Needs a
  require-and-execute test under a fourth distinct class name (two generated classes of the same
  name in one process fatals).
- **The demo's cross-tenant write.** `app/Http/Controllers/NodeflowDemoController.php` route-binds
  `RunSubject` directly in `convert()` and `click()`. `RunSubject` carries no `tenant_id` and no
  tenant scope, and the run is then re-fetched with `withoutTenancy()` — so an authenticated user of
  one organisation can force-exit another organisation's subject and write to their `User` row. It
  predates Plan 4 and is host code. Plan 4 added the missing guidance to `docs/02-integration.md`
  but deliberately did not touch the controller.
- **G-2** — `tenant_id` immutability is undocumented, and query-builder updates bypass the
  `updating` hook entirely. Worth stating in the guard's own comment as well as the docs.
- **R-2** — `docs/02-integration.md` over-warns about `disabled` versus `resolver`.

### Judgement calls to make explicitly, not by default

- **G-3** — the FK invariant behind the unscoped relations is documented but not enforced: no
  composite foreign key, and `$guarded = []` on both models. Enforcing it is a schema change, which
  now means the published-migration problem above. It is in scope *because it is the same file*, not
  because it is tooling. Cut it if the migration decision goes another way.
- Whether the demo fix belongs here at all. It was assigned to Plan 5 by explicit instruction; a
  case was made that it fits a security-hardening plan better, and that case was not adopted. It is
  in scope.

### Explicitly out of scope

- **D-1** (diagnose when `auto` inference selected resolver mode) and **D-2** (foundation spec §9's
  layer 2, the tenant assertion in `RunNodeActivity`). Both approved, both unimplemented, both on the
  durable execution path, and both reserved for a dedicated security-hardening plan. **Do not absorb
  them.**
- **Plan 6** — `make-node-package` and `extract-node`. Genuinely blocked on Plan 5: `extract-node`
  rewrites the `$nodes` array in the provider `install` creates, through the same anchor.
- Everything §11 of the editor spec lists as out of scope, and the C-series limitations: C-1 through
  C-6. **C-5** and **C-6** are Plan 4's two honest `reached` limitations — preserve them, do not
  redesign `reached`, and note that closing either means writing to the durable execution path.

## Environment traps, all paid for already

- **A fresh git worktree has no dependencies.** `vendor/`, `node_modules/`, `.env` and
  `public/build/` are all gitignored. The demo needs all four before any gate runs, and 15 of its
  tests render Blade through `@vite`, so a missing build fails them with a Vite-manifest error rather
  than anything informative.
- **The demo's `composer.json` hardcodes a path repository at `~/Projects/laravel-nodeflow`.** Any
  `composer install` regenerates `vendor/atram/laravel-nodeflow` pointing at **main**, not your
  worktree. Re-point the symlink afterwards and assert `readlink -f` before trusting any demo gate —
  otherwise the demo silently tests the wrong package.
- **`npm install` in a differently-named worktree rewrites `package-lock.json`'s `name` field.** Do
  not commit it.
- **The demo's test suite cannot start a run under `QUEUE_CONNECTION=sync`.** The durable engine
  throws `UnsupportedBackendCapabilitiesException` — `sync` cannot provide the worker/lease boundary.
  Plan 4's demo run tests switch `queue.default` to `database` and drain a real worker with
  `Artisan::call('queue:work', ['--stop-when-empty' => true])`. That path is the first place in this
  project that exercises the interpreter against a real queue worker, which bears on **C-4** without
  formally closing it (C-4 is scoped to the package's own suite).
- **The demo's dev SQLite can predate in-place migration edits.** If seeding throws on a missing
  column, rebuild only the `nodeflow_*` tables — back up the file first, and never `migrate:fresh`:
  it destroys the developer's own login account and passkeys for the sake of demo data the seeder
  recreates anyway.
- **Browser acceptance:** the browser harness needs Chrome remote debugging, which requires a manual
  toggle. Launching a separate Chrome with `--remote-debugging-port=9222 --user-data-dir=/tmp/...`
  and pointing the harness at it via `BU_CDP_WS` avoids asking, leaves the developer's profile
  untouched, and forces a real login through the UI. Password-manager overlays can intercept clicks —
  keyboard activation works.
- React StrictMode replays effect setup, so anything that must survive a replay cannot live in a
  variable cleanup clears. Same-render state transitions must be decided from functional-updater
  state, not a render closure. Stateful controls need node-and-field identity; a field key alone
  leaks state across nodes. Serialized duration amount 1 uses singular units ("1 minute") while
  plural internal unit names remain part of the TypeScript API. Prototype keys — `constructor`,
  `toString`, `__proto__` — must not resolve inherited registry entries; note that `JSON.parse`
  creates `__proto__` as a genuine own property while an object literal does not, so a literal
  fixture tests nothing.
- The package sets `noUncheckedIndexedAccess: true`. Indexing a `Record<string, T>` yields
  `T | undefined`.
- Worktrees must ultimately be merged and retested on `main`. No remote, push or PR is configured.

## Testing discipline — the load-bearing lesson from Plan 4

Spec §9 states the rule: for every test, name the production change that would make it fail. Plan 4
proved that stating it is not enough.

**Four tests specified in Plan 4's own plan document passed against the very bug they named**, and a
proposed fix would have added a fifth. Separately, **three user-facing or comment claims asserted
things the code could not support** — the worst survived three review rounds because the falsehood
was *documented* rather than corrected: the run view told operators "no subject has ever been here"
about `core.exit`, the node every subject exits through on every completed run.

Every one was caught the same way, and only that way: **remove the guard, run the test, watch it
fail.** So for Plan 5:

- Every test ships with its counterfactual **executed** — the guard removed, the failure output
  captured, the guard restored. A claimed counterfactual you cannot reproduce is worth less than no
  claim.
- Every production guard has a covering test that ships. Proving a guard load-bearing in a throwaway
  and deleting the evidence leaves code anyone can delete on a green suite. **F-2** is this exact
  defect class, which is why it is in scope.
- Every user-facing string and every comment asserting a fact about system behaviour gets tested for
  truthfulness in the states that can actually occur, not just the happy one.
- Keep exact test arithmetic throughout. State expected counts per task and record measured
  assertion counts rather than predicting them. Plan 4 shipped 160 Vitest tests against a predicted
  151 — every increase a guard that had no test. Do not pad, and do not trim to hit a number.
- `install` is scripted by CI, so its exit codes are a contract. Test the non-zero paths, not just
  the happy wiring.

Also worth knowing: the request-context scanner (E18) now strips comments and treats either table
name appearing anywhere in code as a violation, with `src/Models/RunSubject.php` and
`src/Models/NodeExecution.php` path-allowlisted. Its docblock names three forms it still cannot
see — `(new RunSubject)->newQuery()`, `app(RunSubject::class)->newQuery()`, and a type-hinted
route-bound parameter. The demo's cross-tenant bug is the third form, which is how it survived.
