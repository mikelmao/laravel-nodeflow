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
- Broader gates: `vendor/bin/pest tests/Unit --compact` passed 331 tests / 5,672 assertions.
  The complete package command is longer than this runner's approximately 30-second foreground
  cap, so its truncated progress output was not recorded as a full-suite pass. Completed feature
  partitions passed: the early feature group 44 tests / 139 assertions, ExtractNodeGates 71 / 162,
  and ExtractNodeMoves 54 / 220. The unchanged long-running ExtractNodeVerification suite exceeds
  that cap alone; a full package gate remains for the integrator environment.

## Task 3 — Run version-reference guard

Pending.

## Task 4 — D-2 durable execution assertion

Pending.

## Documentation

Pending.

## Whole-branch reviews and remediation

Pending.

## Final gates and integration

Pending.
