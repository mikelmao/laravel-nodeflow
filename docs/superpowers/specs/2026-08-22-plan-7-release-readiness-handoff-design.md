# Plan 7 release-readiness handoff design

**Date:** 2026-08-22  
**Status:** Approved handoff design; production implementation has not started

## Purpose

Create one context-rich prompt for a fresh session to design and plan the release-readiness work that
follows the completed six-plan Nodeflow roadmap. The prompt must preserve enough measured evidence and
project history that the next session does not reopen settled Plan 6 decisions or mistake deferred
architectural work for small cleanup.

The next session is a planning session first. It verifies current state, writes a Plan 7 design spec,
asks for approval, and only then writes an implementation plan. It does not begin production changes
from the handoff prompt alone.

## Starting state carried into the prompt

- Package repository: `/Users/mikelmao/Projects/laravel-nodeflow`, merged `main` at `8b51a3d`.
- Demo repository: `/Users/mikelmao/Sites/test-workflow`, `main` at `e15e5bd`.
- The demo's ignored `vendor/atram/laravel-nodeflow` link resolves to the merged package checkout.
- Final package acceptance: Pest 904 tests / 7,469 assertions, Vitest 160/160, silent TypeScript,
  valid Composer metadata.
- Final demo acceptance: Pest 56 tests / 223 assertions, silent TypeScript, passing production build,
  valid Composer metadata and lock file, and Pint passing on every PHP file changed by Plan 6's demo
  work.
- Repository-wide demo Pint still reports unrelated pre-existing formatting drift. Plan 7 must not
  broaden into formatting those files without a separate decision.
- The completed Plan 6 evidence and all 62 execution rulings live in
  `docs/superpowers/plans/2026-08-21-node-packaging-execution-record.md`.
- The ignored SDD journal was preserved under
  `.superpowers/sdd/2026-08-21-node-packaging/` in the merged package checkout.
- The old `worktree-plan-6-packaging` branch/worktree may still be visible because the Claude host
  locked that worktree. It is already fully merged and is operational cleanup, not Plan 7 work.

## Plan 7 scope

Plan 7 is a bounded release-readiness plan with three parts.

### 1. Reconcile public and project documentation

- Correct the README's obsolete claims that `nodeflow:install` and the packaging commands do not
  exist.
- Replace obsolete suite counts and queue-worker statements with measured current facts.
- Refresh the open-issues header and Plan 6 acceptance section with the final remediation commit and
  904 / 7,469 result.
- Do not mark an issue resolved until its implementation and acceptance evidence exist.

### 2. Complete G-5 browser acceptance

Run the missing Plan 5 browser checks against the real demo application:

1. Browser console remains clean through the exercised interactions.
2. The editor and run view render through the real compiled host wiring.
3. The two demo actions work through `runs/{run}/subjects/{subject}/...` URLs.
4. Authentication and logout behavior work in-browser.

Chrome 151 requires the user-controlled **Allow remote debugging** toggle. The next session must state
the exact manual action when it reaches that gate, continue all non-blocked work meanwhile, and report
the acceptance honestly if the toggle is unavailable. It must not replace browser observation with
unit tests or source inspection.

### 3. Fix five bounded tooling gaps

- **G-7:** Bind Vite alias verification to the actual `@nodeflow/editor` alias entry instead of
  accepting two unrelated facts elsewhere in the file.
- **G-8:** Make `nodeflow:install --check` treat unpublished optional config consistently with the
  package's `mergeConfigFrom` behavior and the migration step's optional-publication semantics.
- **G-9:** Print fully qualified registry names in generator paste instructions so the snippet works
  without host imports.
- **G-11:** Correct E20's writer/verifier arithmetic in the binding design document; code and current
  integration guidance already follow the correct step list.
- **G-12:** Resolve Vite configuration candidates in Vite's own precedence order when both JavaScript
  and TypeScript configs exist.

Every production fix uses strict red-green-refactor TDD. Each test must name and execute a production
counterfactual that makes it fail, and the counterfactual must be restored immediately. Documentation
drift needs exact source-to-runtime comparison rather than prose-only confidence.

## Explicitly deferred

The handoff must keep these outside Plan 7:

- **D-1, D-2 and G-3:** the dedicated tenancy/security-hardening plan.
- **C-1 through C-6:** durable execution state, reached-history, scaling, database-matrix and real
  queue-worker CI work.
- **G-13:** the accepted limitation for dynamic and database-stored class references during
  extraction.
- Release tagging or publication, new generators, new editor features, and unrelated demo formatting.

These items remain visible in the prompt so the next session does not lose them, but they are not
implementation stretch goals.

## Required workflow in the new session

1. Read repository instructions, inspect clean status and verify both starting commits and the demo
   symlink before trusting supplied counts.
2. Read the Plan 6 execution record, `docs/superpowers/open-issues.md`, the Plan 5 execution record,
   and the source/tests directly involved in G-7 through G-12.
3. Reproduce each claimed tooling gap read-only before selecting a design.
4. Present two or three Plan 7 design approaches with trade-offs and obtain user approval.
5. Write and commit the Plan 7 design spec; self-review it for placeholders, contradictions,
   ambiguity and scope creep; ask the user to review it.
6. After approval, use the writing-plans workflow to produce the implementation plan with isolated
   commits, explicit gates, browser coordination and independent review checkpoints.
7. Do not implement Plan 7 until that written implementation plan is approved for execution.

## Acceptance for the handoff prompt

The prompt is complete when a new session can answer, without relying on this conversation:

- What shipped in Plans 5 and 6, and what evidence makes that trustworthy?
- What exactly belongs to Plan 7, and what is deliberately deferred?
- Which browser observations cannot be replaced by automated tests?
- What are the five tooling gaps and their expected behavioral corrections?
- Which repositories, commits, documents and commands establish the baseline?
- What approval gates must occur before production work begins?

The prompt should be self-contained but should cite authoritative local documents instead of copying
their full contents. It should lead with the immediate next action and preserve measured numbers,
known caveats and stop conditions.
