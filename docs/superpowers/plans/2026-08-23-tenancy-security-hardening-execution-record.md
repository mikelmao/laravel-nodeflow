# Plan 8 tenancy security hardening execution record

## Starting state

- Package main and Plan 8 base: `3ba3599b95e62978b5f057fcf1b03b7e43396bf9`.
- Plan 8 branch/worktree: `plan-8-tenancy-security-hardening` at `/Users/mikelmao/Projects/laravel-nodeflow/.claude/worktrees/plan-8-tenancy-security-hardening`.
- Demo: clean `main` at `e15e5bd912fee2e248654861b826d9e1458707dc`; its package link resolved to `/Users/mikelmao/Projects/laravel-nodeflow` and was not repointed.
- Locked Plan 6 worktree: clean and unchanged at `8b51a3d850c99f3f41a24726b31f6ad94a058890`.
- Remote: `origin` exists; Plan 8 does not mutate it.
- JavaScript dependencies: `node_modules` links to `/Users/mikelmao/Projects/laravel-nodeflow/node_modules`.
- Composer dependency isolation: the initially prescribed `vendor` symlink made Composer map `Nodeflow\\` and `Tests\\` into package main while Pest discovered files in the Plan 8 worktree. The first package baseline therefore failed with 497 failures, 20 warnings and 420 passes. After verifying the exact symlink target, it was replaced with an offline worktree-local `composer install`; reflection then resolved both namespaces into the Plan 8 worktree. A focused `tests/Feature/TenancyTest.php` run passed 15 tests / 24 assertions, confirming the setup correction.
- Package baseline: Pest passed 937 tests / 7,538 assertions in 107.24s; Vitest passed 160 tests across 17 files in 2.73s; TypeScript was silent; Composer metadata was valid; `git diff --check` and status were clean.
- Demo baseline: Pest passed 56 tests / 223 assertions in 68.136s; TypeScript was silent; Vite built 2,497 modules in 2.48s; Composer metadata was valid with only the two known unbound local-package warnings; status was clean.

## Task 1 — D-1 tenancy decisions

Completed.

- RED command: `vendor/bin/pest tests/Feature/TenancyDecisionTest.php tests/Feature/InstallCommandTest.php --compact`
  failed as intended: six decision tests raised `BindingResolutionException` for missing
  `Nodeflow\\Tenancy\\TenancyDecisionResolver`; both updated installer reports failed their
  stale output expectations. Result: 8 failed, 13 passed, 68 assertions. RED commit:
  `caa530314ab460c47ecaf8cc9dff959661cf3c44` (`test: specify inspectable tenancy decisions`).
- GREEN focused tenancy command: `vendor/bin/pest tests/Feature/TenancyDecisionTest.php
  tests/Feature/TenancyModeTest.php tests/Feature/TenancyAutoModeTest.php
  tests/Feature/TenancyTest.php tests/Feature/InstallCommandTest.php --compact` passed:
  54 tests, 141 assertions. This includes the existing disabled-plus-non-null-resolver scope
  case and the invalid-mode-with-non-null-resolver refusal case.
- Counterfactual: temporarily made the `auto` branch always choose the package-fallback
  decision, then ran `vendor/bin/pest tests/Feature/TenancyDecisionTest.php
  tests/Feature/TenancyAutoModeTest.php --filter="host" --compact`. It failed as required:
  the host decision reported `disabled` instead of `resolver`, and the host-null scope no
  longer threw `TenancyUnresolvedException` (2 failed, 2 passed, 5 assertions). The exact
  branch was restored with `apply_patch`; the focused tenancy command then passed again
  (54 tests, 141 assertions).
- Formatting: the required Pint `--test` initially identified style changes. After running
  the identical file list through Pint, inspecting the diff, and rerunning the focused suite,
  `pint --test` passed and `git diff --check` was clean.
- Full package gate: `vendor/bin/pest --compact` completed successfully with exit code 0.

## Task 2 — Flow version-reference guard

Completed.

- RED command: `vendor/bin/pest tests/Feature/FlowVersionReferenceGuardTest.php --compact`
  failed as intended: missing and cross-tenant `current_version_id` writes raised no exception,
  suspended contradictory writes raised no exception, and no FlowVersion lookup occurred on a
  current-version change. Result: 4 failed, 2 passed, 8 assertions. RED commit:
  `fc08c8d0be250b4ee44feaa09e38252c5569d916` (`test: specify flow version-reference guards`).
- GREEN focused command: `vendor/bin/pest tests/Feature/FlowVersionReferenceGuardTest.php
  tests/Feature/FlowVersionTenancyTest.php tests/Feature/PublishFlowTest.php --compact` passed:
  24 tests, 52 assertions. The query-count probe observed zero FlowVersion queries for a
  name-only update and exactly one for a `current_version_id` update.
- Counterfactual: temporarily removed Flow's creating and updating listeners, then ran
  `vendor/bin/pest tests/Feature/FlowVersionReferenceGuardTest.php --filter="missing current|cross-tenant current|guard suspension" --compact`.
  It failed as required: all three unsafe-write cases threw no exception (3 failed, 3 assertions).
  Both listeners were restored with `apply_patch`, and the complete focused GREEN command passed
  again (24 tests, 52 assertions).
- Formatting: required scoped Pint `--test` passed; `git diff --check` passed.
- Full package gate: the controller ran `vendor/bin/pest --compact` at this GREEN HEAD. It exited
  0 with 950 tests passed and 7,592 assertions in 114.21s.

## Task 3 — Run version-reference guard

Completed.

- RED command: `vendor/bin/pest tests/Feature/FlowVersionReferenceGuardTest.php --filter="run" --compact`
  failed as intended: a null `flow_version_id` reached SQLite's NOT NULL constraint instead of
  `InvalidFlowVersionReferenceException`, cross-tenant and suspended contradictory writes raised
  no exception, and a version-reference update issued no FlowVersion lookup. Result: 4 failed,
  2 passed, 7 assertions. RED commit: `daded3e67f872262f3b79ce913e060f4491e54da`
  (`test: specify run version-reference guards`).
- GREEN focused writer regression command: `vendor/bin/pest
  tests/Feature/FlowVersionReferenceGuardTest.php tests/Feature/StartRunTest.php
  tests/Feature/SubFlowStarterTest.php tests/Feature/FlowVersionTenancyTest.php
  tests/Feature/PublishFlowTest.php --compact` passed: 43 tests, 98 assertions. This includes
  StartRun's intentionally suspended cross-tenant system write and SubFlowStarter.
- Query-count probe: the Run guard issued zero FlowVersion queries for a status-only update and
  exactly one when `flow_version_id` changed.
- Counterfactual: temporarily removed Run's creating and updating listeners, then ran
  `vendor/bin/pest tests/Feature/FlowVersionReferenceGuardTest.php --filter="null and missing run|cross-tenant run|guard suspension create a contradictory run" --compact`.
  It failed as required: the null insert reached SQLite's NOT NULL constraint and the two
  cross-tenant writes raised no exception (3 failed, 3 assertions). The listeners were restored
  with `apply_patch`, and the complete focused writer regression command passed again (43 tests,
  98 assertions).
- Formatting: required scoped Pint `--test` initially found only test style normalization; scoped
  Pint was applied, its `--test` rerun passed, and `git diff --check` passed.
- Full package gate: one controlled `vendor/bin/pest --compact` session was started and polled to
  completion. It exited 0 with 956 tests passed and 7,605 assertions in 117.67s.

## Task 4 — D-2 durable execution assertion

Completed.

- RED command: `vendor/bin/pest tests/Feature/RunNodeActivityTest.php --compact` failed as
  intended before the production guard existed: persisted tenant corruption threw no
  `CrossTenantExecutionException`, and a missing pinned version reached a null `graph` property.
  Result: 2 failed, 1 passed, 5 assertions. RED commit:
  `5c0c067b7f1883ff192998d576b5f974cac1f4f9` (`test: specify durable tenant assertion`).
- GREEN focused command: `vendor/bin/pest tests/Feature/RunNodeActivityTest.php --compact`
  passed 3 tests / 11 assertions. A mismatched persisted run/version pair now throws before
  incrementing `steps_taken` or invoking `NodeRunner`; a missing version throws
  `ModelNotFoundException` before either action; a matching pair executes once with a null
  ambient tenant.
- Integration regression: `vendor/bin/pest tests/Feature/RunNodeActivityTest.php
  tests/Feature/FlowVersionTenancyTest.php --compact` passed 13 tests / 29 assertions, including
  the unscoped durable relation resolution under a different ambient tenant.
- Counterfactual A: temporarily moved `increment('steps_taken')` above the tenant comparison and
  ran the mismatch test. It failed as required: `steps_taken` became 8 rather than remaining 7
  (1 failed, 3 assertions). The exact order was restored with `apply_patch`.
- Counterfactual B: temporarily removed the tenant comparison and ran the mismatch test. It
  failed as required because `CrossTenantExecutionException` was not thrown (1 failed, 1
  assertion); the recording runner is the only executable boundary following the increment. The
  comparison was restored with `apply_patch`.
- Formatting: the required scoped Pint `--test` initially found one `concat_space` normalization
  in the exception. Scoped Pint was applied; its `--test` rerun passed, the focused activity
  suite passed again (3 tests / 11 assertions), and `git diff --check` passed.
- Full package gate: one controlled `vendor/bin/pest --compact` session was started and polled to
  terminal completion. It exited 0 with 959 tests passed and 7,616 assertions in 117.34s.

## Documentation

## Task 5 — Public documentation and issue reconciliation

Completed.

- Contradiction search: `rg -n "application code must preserve|unimplemented|security-hardening plan|saving guard|parent-child tenant invariants|D-1|D-2|G-3|current_version_id|flow_version_id" README.md docs/gitbook docs/documentation-changes.md docs/superpowers/open-issues.md` found the resolved D-1/D-2/G-3 ledger entries, the Plan 8 applied handoff, current pointer guidance, and clearly time-scoped historical Plan 6/Plan 5 records. It found no current public claim that these three gaps remain unimplemented or wholly host-enforced. `git diff --check` passed.
- Focused documentation behavior gate: `vendor/bin/pest tests/Feature/TenancyDecisionTest.php tests/Feature/InstallCommandTest.php tests/Feature/FlowVersionReferenceGuardTest.php tests/Feature/RunNodeActivityTest.php --compact` passed 36 tests / 127 assertions.
- Scope check: the documentation diff is limited to `README.md`, the merged GitBook tenancy and known-limitations pages, `docs/documentation-changes.md`, `docs/superpowers/open-issues.md`, and this execution record. The updated guidance preserves historical Plan 3–7 counts and does not claim same-Flow identity enforcement or an audit of existing rows.

## Whole-branch reviews and remediation

Pending.

## Final gates and integration

Pending.
