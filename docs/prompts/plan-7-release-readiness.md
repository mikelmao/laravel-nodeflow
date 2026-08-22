# Plan 7 — release readiness: new-session handoff prompt

Paste the whole of this file as the opening message of a new session. It is self-contained for the
next planning session, but deliberately points to the repository's execution records instead of
copying their full contents.

---

We're continuing work on `laravel-nodeflow`. The original six-plan roadmap is implemented and merged.
The next job is to **design and plan Plan 7: release readiness**. Do not begin production edits from
this prompt alone.

## Immediate next action

Before proposing a design or changing any tracked file:

1. Verify the package and demo repositories against the starting state below.
2. Check each repository for an `almanac/` directory and consult CodeAlmanac if one exists. There was
   no almanac at Plan 6 close, but current filesystem truth wins.
3. Read the authoritative documents in the stated order.
4. Inspect and reproduce G-7, G-8, G-9 and G-12 read-only against current code. Verify G-11 directly
   against the design's step list. Do not merely trust the issue prose.
5. Use the brainstorming workflow to present two or three Plan 7 design approaches and obtain user
   approval. Then write and commit the Plan 7 design spec, self-review it, and ask the user to review
   it. Only after approval should you use the writing-plans workflow.

The handoff prompt is for design and planning. **Do not implement Plan 7 until the resulting written
implementation plan is separately approved for execution.**

## Starting state

### Package repository

- Path: `/Users/mikelmao/Projects/laravel-nodeflow`
- Branch: `main`
- Plan 6 implementation and execution record merged through `8b51a3d`
- This handoff's approved design is commit `49bcf7b`; the prompt commit itself may be the only commit
  after it when the next session starts. Verify rather than assuming.
- Final measured Plan 6 gates:
  - Pest: **904 tests / 7,469 assertions**
  - Vitest: **160/160** across 17 files
  - `npx tsc --noEmit`: silent
  - `composer validate --no-check-publish`: valid
- The repository has no configured remote. Do not invent an `origin`, force-push, or base work on a
  nonexistent remote branch.

### Demo repository

- Path: `/Users/mikelmao/Sites/test-workflow`
- Branch: `main`
- Expected HEAD: `e15e5bd`
- Its ignored `vendor/atram/laravel-nodeflow` link should resolve exactly to
  `/Users/mikelmao/Projects/laravel-nodeflow`.
- Final measured Plan 6 gates:
  - Pest: **56 tests / 223 assertions**
  - `npx tsc --noEmit`: silent
  - `npm run build`: passes
  - Composer metadata and lock: valid and consistent
  - Pint: passes for every PHP file changed by the Plan 6 demo work
- A repository-wide `vendor/bin/pint --test` reports pre-existing drift in unrelated published
  workflow migrations/config and scaffold tests. Do not absorb that formatting into Plan 7 without a
  separate decision.
- Local application URL: `http://test-workflow.test/`.
- Never run `migrate:fresh` against this demo. It contains the developer's real local account,
  passkeys and persisted workflow evidence.

### Workspace residue

The fully merged `worktree-plan-6-packaging` branch/worktree may still appear under
`.claude/worktrees/plan-6-packaging`. The Claude host locked it during the previous session. It is not
unmerged work. Do not double-force its removal, treat it as Plan 7 scope, or branch from it. If the
host has released the lock, ordinary cleanup is fine; otherwise leave it alone.

The complete ignored Plan 6 SDD journal was preserved at
`.superpowers/sdd/2026-08-21-node-packaging/` in the package main checkout.

If any branch, commit, link, status or baseline differs, stop and report the exact difference before
silently adapting. A higher test count caused by this prompt/spec documentation is not itself a
problem; an unexplained production or demo delta is.

## Read these documents in order

1. `docs/superpowers/specs/2026-08-22-plan-7-release-readiness-handoff-design.md` — the approved scope
   and workflow for this handoff.
2. `docs/superpowers/plans/2026-08-21-node-packaging-execution-record.md` — Plan 6's actual execution,
   including all 62 rulings and their costs. This is more authoritative than Plan 6's original plan
   wherever execution corrected it.
3. `docs/superpowers/plans/2026-08-21-nodeflow-remaining-tooling-execution-record.md` — Plan 5's
   execution corrections and the origin of the tooling residuals.
4. `docs/superpowers/open-issues.md` — read the whole file, then focus on G-5, G-7, G-8, G-9, G-11
   and G-12. Its Plan 6 headline/counts are themselves stale and are part of this plan's documentation
   reconciliation.
5. `docs/superpowers/specs/2026-08-21-remaining-tooling-design.md` — especially §3.2 and E19, E20,
   E22 and E25. Current code and §3.2 beat E20's known arithmetic typo.
6. `docs/02-integration.md`, `docs/08-editor-client.md`, `docs/09-packaging-nodes.md` and `README.md`
   — the public contract. The README is visibly stale; do not propagate its current status claims.

The old plan prompts are historical context, not current authority. Code is runtime truth, tests are
enforced truth, and execution records explain why unusual behavior exists.

## Plan 7 goal

Bring the completed six-plan product to an honest release-ready baseline by:

1. reconciling stale public/project documentation;
2. completing the missing real-browser acceptance for Plan 5; and
3. fixing five bounded tooling gaps left after Plans 5 and 6.

Keep this as one bounded plan. Do not convert it into a general security, runtime or release-
publication project.

## Scope A — documentation reconciliation

The README currently says `nodeflow:install` and the packaging commands remain unbuilt, says there is
no `nodeflow:install`, reports 358 PHP tests, and says the interpreter has never been exercised
against a real queue worker. Those statements predate Plans 5 and 6. Correct them using measured
current evidence and preserve the narrower truthful limitation: real-queue execution is not yet part
of CI. Add the shipped `docs/09-packaging-nodes.md` guide to the README documentation table if it is
still absent.

Also refresh `docs/superpowers/open-issues.md`:

- its header and Plan 6 acceptance section still stop at package commit `b7a1772` and 891 / 7,438;
- Plan 6 ultimately reached `8b51a3d`, with implementation remediation at `31e070a` and final Pest
  acceptance 904 / 7,469;
- issue statuses must change only after the corresponding implementation and acceptance exist;
- keep G-13 recorded as an accepted residual, not a defect Plan 7 promises to solve.

Documentation closure comes after measured implementation/browser acceptance, so it cannot describe
an intended result as delivered.

## Scope B — G-5 real-browser acceptance

Plan 5 merged without observing four behaviors in the real demo browser. Unit tests, source reading
and build inspection do not substitute for these checks:

1. The browser console stays clean through the exercised interactions.
2. The editor and run view actually render through the demo's compiled host wiring.
3. The demo's convert and click actions work from the real client through
   `runs/{run}/subjects/{subject}/...` URLs.
4. Authentication protects the demo and logout closes the authenticated experience in-browser.

Chrome 151 requires a manual **Allow remote debugging** toggle even with a throwaway profile. At the
browser gate:

```bash
/Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome \
  --remote-debugging-port=9222 \
  --user-data-dir=/tmp/nodeflow-chrome
```

In that Chrome window, the user must visit `chrome://inspect/#remote-debugging` and enable the toggle.
Chrome binds the endpoint to IPv6 loopback here; use `http://[::1]:9222/json/version`, not
`127.0.0.1`.

Start the real demo queue worker when acceptance requires it. Keep the worker lifecycle controlled
and report any rows or fixtures created. Never reset the demo database. If the manual toggle is
unavailable, continue every independent task and report G-5 as blocked rather than claiming browser
acceptance from automated evidence.

The Plan 7 design must specify exact browser setup, test data, interaction sequence, expected visible
results, console/network observations, queue-worker cleanup and evidence recording before execution.

## Scope C — five bounded tooling corrections

### G-7 — bind Vite alias facts to one alias entry

Current surface:

- `src/Console/Install/ViteAliasStep.php`
- `src/Console/Install/ViteConfigStep.php`
- `tests/Feature/Install/ViteStepsTest.php`

Current false accept: `check()` can see `@nodeflow/editor` mapped to the wrong directory and separately
see the correct package path elsewhere in the file, then report `AlreadyPresent`.

Required behavior: the actual `@nodeflow/editor` alias entry must point to the accepted package source
path. Preserve comment stripping and the existing accepted lexical/path variants. Reproduce the
false accept before choosing whether a bounded parser, token scan or another structural technique is
appropriate. Do not replace it with another whole-file substring conjunction.

### G-8 — make optional config semantics consistent

Current surface:

- `src/Console/Install/PublishConfigStep.php`
- `src/Console/Install/MigrationStep.php`
- `src/Console/InstallCommand.php`
- `tests/Feature/Install/PublishConfigStepTest.php`
- `tests/Feature/Install/MigrationStepTest.php`
- `tests/Feature/InstallCommandTest.php`

Current inconsistency: missing published migrations are accepted because publication is optional,
while missing `config/nodeflow.php` is reported `Writable`, making `install --check` fail even though
the provider's `mergeConfigFrom` supplies working defaults.

Required behavior: a healthy host that intentionally relies on merged default config must pass
`nodeflow:install --check`. The design must preserve detection of a published-but-drifted config and
must state whether any explicit publication path remains. Reproduce both the healthy-unpublished and
drifted-published states before changing the contract.

### G-9 — make pasted generator snippets resolve without imports

Current surface:

- `src/Console/MakeTriggerCommand.php`
- `src/Console/MakeSubjectAttributeCommand.php`
- `tests/Feature/MakeTriggerCommandTest.php`
- `tests/Feature/MakeSubjectAttributeCommandTest.php`

Current output tells a host to paste `app(TriggerRegistry::class)` or
`app(SubjectAttributeRegistry::class)` into a provider that may import neither class.

Required behavior: printed paste instructions use the fully qualified registry class names, matching
the already-shipped additive provider edits. Tests must prove the emitted snippets parse and resolve
inside a provider with no corresponding imports, not merely contain a longer string.

### G-11 — correct the binding spec's arithmetic

Current surface:

- `docs/superpowers/specs/2026-08-21-remaining-tooling-design.md`, E20 and §3.2

E20 says the installer writes four things and verifies three, then lists four verifiers. The shipped
nine-step design is five writers and four verifiers. Correct the contradictory headline without
rewriting historical execution evidence or changing runtime code solely for this issue.

### G-12 — follow Vite's real config precedence

Current surface:

- `src/Console/Install/ViteConfigStep.php`
- `tests/Feature/Install/ViteStepsTest.php`
- other install-step tests that inherit `ViteConfigStep`

Current candidate order starts with `vite.config.ts`, while the installed Vite resolver prefers a
JavaScript config when both exist. Before locking the complete order into the design, inspect the
installed Vite version's own resolver or official primary documentation. Persist a discriminator with
multiple candidate files and prove the selected file is the one Vite would load. Avoid a one-line
reorder with no behavioral test.

## Explicitly deferred — carry forward, do not absorb

- **D-1, D-2 and G-3:** tenancy/security hardening. These belong to one dedicated later plan because
  they affect durable write/execution paths and database invariants.
- **C-1 through C-6:** failed-run status, durable reached-history, audience-scale ownership checks,
  database matrix and real queue-worker CI.
- **G-13:** accepted extraction residual for dynamic or database-stored class references.
- Release tagging/publishing, semantic-version selection, new generators, new editor features,
  unrelated refactors and repository-wide demo formatting.

Mention these in the Plan 7 spec's deferred section so they are not lost. They are not stretch goals.

## Environment and safety constraints

- Use local `main` as the planning base; there is no remote.
- Do not work in or force-unlock the old Plan 6 worktree.
- Create a fresh isolated worktree only when the approved implementation plan is about to execute,
  using the worktree workflow and a local base.
- The demo's Composer path repository points at the package main checkout. Composer operations can
  repoint its ignored vendor link; assert `realpath vendor/atram/laravel-nodeflow` before trusting
  every demo gate.
- Do not commit machine-local `.claude/worktrees` paths.
- Do not use `migrate:fresh`, delete real demo data, or broaden a fixture cleanup target.
- Preserve unrelated dirty changes if any appear; stop if they overlap this scope.
- `COMPOSER_DISABLE_NETWORK=1` may bound test fixtures. It is not a production Composer setting.
- A broad demo Pint failure is known baseline drift. Format and verify only Plan 7's changed PHP files
  unless the user separately authorizes cleanup.

## Evidence and testing discipline

The Plan 5 and Plan 6 execution records establish the standard:

- Strict red-green-refactor TDD for every production correction.
- Persist the failing counterexample before implementation.
- Execute the named counterfactual/mutation, record the failure it causes, then restore production
  immediately. A mutation that survives means the test does not prove its claim.
- Prefer constructed runtime inputs over source-reading confidence. This codebase repeatedly shipped
  substring checks that looked right and accepted the wrong structure.
- Keep exact measured test/assertion counts; do not pad or trim to match a plan.
- Request independent spec-compliance and code-quality review after meaningful tasks, followed by one
  whole-branch adversarial review across all five gaps and documentation.
- Run final verification on merged `main`, not only in the feature worktree.
- Record G-5 as passed only from actual browser observation.

Planning baselines to remeasure before execution:

```bash
# Package
COMPOSER_DISABLE_NETWORK=1 vendor/bin/pest --compact
npx vitest run
npx tsc --noEmit
composer validate --no-check-publish

# Demo
vendor/bin/pest --compact
npx tsc --noEmit
npm run build
composer validate --no-check-publish
```

The implementation plan must name focused commands and expected red/green behavior for each task,
plus final PHP lint, scoped Pint, `git diff --check`, repository cleanliness and process/temp-residue
checks.

## Required planning deliverables

The new session must produce, in order:

1. A verified starting-state report and read-only reproduction notes for G-7, G-8, G-9, G-11 and
   G-12.
2. Two or three design approaches with trade-offs and a recommendation.
3. After user approval, a committed Plan 7 design spec under `docs/superpowers/specs/`.
4. A self-review covering placeholders, contradictions, ambiguity, scope and consistency with current
   code/tests.
5. User review of that written spec.
6. After approval, a detailed implementation plan under `docs/superpowers/plans/` using the
   writing-plans workflow, with task-sized TDD cycles, exact files/interfaces, browser coordination,
   independent reviews, documentation closure, merged-main verification and finishing choices.

Stop after delivering the implementation plan and offer the standard execution choice. Do not begin
production implementation until the user chooses an execution mode.

## The whole-task questions

Before calling the Plan 7 design complete, ask:

- Can any wrong Vite alias still pass because correct facts appear elsewhere in the file?
- Can an intentionally unpublished optional config still make `install --check` fail?
- Can either generator print a paste snippet that needs an unstated import?
- Can the installer inspect a Vite config different from the one Vite itself will load?
- Does any public document still describe Plans 5 or 6 as unbuilt or report stale acceptance?
- Is any browser claim based on source/tests rather than real browser observation?
- Did any security, durable-runtime, release-publication or unrelated-formatting work leak into scope?

If the answer to any question is uncertain, the design or plan is not ready.
