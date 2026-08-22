# Plan 7 execution record

This records what happened while executing
`docs/superpowers/plans/2026-08-22-plan-7-release-readiness.md`.

## Starting state

- Package branch point: `f9dea76d8b0e1b1a341bf1071557eeefc351afb2` on local `main`; the repository has no remote.
- Implementation worktree: `/Users/mikelmao/Projects/laravel-nodeflow/.claude/worktrees/plan-7-release-readiness` on branch `plan-7-release-readiness`.
- The locked Plan 6 worktree remained untouched.
- Demo commit: `e15e5bd912fee2e248654861b826d9e1458707dc` on `main`.
- Demo package link: `/Users/mikelmao/Sites/test-workflow/vendor/atram/laravel-nodeflow` resolved exactly to `/Users/mikelmao/Projects/laravel-nodeflow`.
- Package baseline: Pest 904 tests / 7,469 assertions; Vitest 160 tests across 17 files; TypeScript silent; Composer metadata valid.
- Demo baseline: Pest 56 tests / 223 assertions; TypeScript silent; production build passed; Composer metadata and lock consistency valid with the known unbound local-package warnings; worktree clean.

## Counterfactuals

### Task 1: G-12 — installed-Vite config precedence

- RED (before production change): `vendor/bin/pest tests/Feature/Install/ViteStepsTest.php --compact` produced 3 failures, 8 passes, and 20 assertions. In the multi-candidate fixture, installed Vite selected `vite.config.js` while PHP read the earlier `vite.config.ts`, yielding `CannotWire` instead of `AlreadyPresent`; standalone `vite.config.cjs` and `vite.config.cts` fixtures also yielded `CannotWire` because PHP omitted both candidates.
- GREEN: after applying the Vite 8.2.2 candidate order, `vendor/bin/pest tests/Feature/Install/ViteStepsTest.php --compact` passed 11 tests with 23 assertions (rerun after counterfactual restoration with the same result). `php -l src/Console/Install/ViteConfigStep.php` and `php -l tests/Feature/Install/ViteStepsTest.php` both reported no syntax errors.
- Counterfactual A: temporarily moving `vite.config.ts` before `vite.config.js`, then running `vendor/bin/pest tests/Feature/Install/ViteStepsTest.php --compact --filter='inspects the same config file Vite loads when candidates coexist'`, produced the expected single failure (3 assertions): Vite selected `.js` while PHP accepted the wrong `.ts` and returned `CannotWire`. The exact installed order was restored immediately.
- Counterfactual B (`.cjs`): temporarily removing `vite.config.cjs`, then running `vendor/bin/pest tests/Feature/Install/ViteStepsTest.php --compact --filter='cjs'`, produced the expected single failure (3 assertions): the PHP step returned `CannotWire`. The candidate was restored immediately.
- Counterfactual B (`.cts`): temporarily removing `vite.config.cts`, then running `vendor/bin/pest tests/Feature/Install/ViteStepsTest.php --compact --filter='dataset "cts"'`, produced the expected single failure (3 assertions): the PHP step returned `CannotWire`. The candidate was restored immediately.
- Formatting: `vendor/bin/pint` is unavailable in this worktree and is not declared in `composer.json`; only the two changed PHP files were manually kept in project style and syntax-checked.

### Task 2: G-7 — bind the package path to the alias entry

- RED (test-only discriminator commit `313f9a5`): `vendor/bin/pest tests/Feature/Install/ViteStepsTest.php --filter="does not combine" --compact` failed as intended with 1 failure / 1 assertion. The wrong alias plus standalone correct package path returned `AlreadyPresent` rather than `CannotWire`. The full file then showed exactly the three intended G-7 failures (wrong alias plus standalone path, duplicate live aliases, and wrong alias plus a nested other-property path), with 16 passes / 31 assertions; no fixture confounds were found.
- GREEN: after adding `ViteAliasValue::extract()` and binding `ViteAliasStep::check()` to its one extracted value, `vendor/bin/pest tests/Feature/Install/ViteStepsTest.php --compact` passed 19 tests / 31 assertions. `php -l src/Console/Install/ViteAliasValue.php` and `php -l src/Console/Install/ViteAliasStep.php` both reported no syntax errors.
- Accepted lexical cases: all four single/double-quote combinations for the `@nodeflow/editor` key and the package-path literal passed; `path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js')` passed, proving the value scanner crosses its inner comma.
- Rejected lexical cases: the existing commented correct alias remained rejected after comment stripping; the wrong alias plus a standalone correct path, duplicate live alias keys, and a wrong alias plus a correct path nested in another property all returned `CannotWire`. A missing config also remained rejected.
- Counterfactual: temporarily replacing the value-bound condition with the original whole-file `str_contains()` checks made `vendor/bin/pest tests/Feature/Install/ViteStepsTest.php --filter="does not combine" --compact` fail as intended (1 failure / 1 assertion), returning `AlreadyPresent`. `ViteAliasValue::extract()` was restored immediately, then the full file passed again (19 tests / 31 assertions).
- Formatting: `vendor/bin/pint` is unavailable and absent from `composer.json`; the two changed PHP files were retained in project style and syntax-checked. `git diff --check` passed after restoring production.
- Fix Round 1 RED: direct `ViteAliasValue::extract()` cases for values missing before a comma, an enclosing `}`, and EOF each returned `''` instead of `null`; direct and `ViteAliasStep` nested-duplicate fixtures also falsely accepted the outer value. `vendor/bin/pest tests/Feature/Install/ViteStepsTest.php --filter="no value|nested duplicate" --compact` produced 5 failures / 5 assertions, and the full file showed those same 5 intended failures with 19 passes / 36 assertions.
- Fix Round 1 GREEN: empty trimmed value spans now return `null`; after one candidate is collected, scanning resumes at its value start so nested candidate keys are counted and conservatively rejected. The focused five regressions passed; `vendor/bin/pest tests/Feature/Install/ViteStepsTest.php --compact` passed 24 tests / 36 assertions; both amended PHP syntax checks and `git diff --check` passed.

## Reviews and remediation

### Task 1: G-12 — independent spec-compliance and code-quality review

- PASS: independent read-only review of base `f9dea76`, red evidence commit `90d3bd6`, and the pending production diff found no Critical, Important, or Minor findings. It confirmed the exact Vite 8.2.2 order, resolver-probe contract, `.js`/`.ts` and `.cjs`/`.cts` coverage, recursive teardown, and complete red/green/counterfactual record. The reviewer reran the focused test file (11 tests / 23 assertions), both changed PHP syntax checks, and `git diff --check`; all passed.

### Task 2: G-7 — independent spec-compliance and code-quality review

- PASS: independent read-only review of red commit `313f9a5` and the pending bounded-scanner implementation found no Critical, Important, or Minor findings. It confirmed the exact G-7 contract and execution record. The reviewer constructed a wrong `@nodeflow/editor: 'resources/js'` entry alongside a correct package path in a different alias entry; it returned `CannotWire`. Its delimiter-in-string input, `@nodeflow/editor: 'vendor/atram/laravel-nodeflow/resources/js,})'`, returned `AlreadyPresent`, confirming delimiters inside literals do not terminate the value scanner. The reviewer reran the focused Pest file (19 tests / 31 assertions), both changed PHP syntax checks, and `git diff --check`; all passed. No remediation was required.
- Fix Round 1 remediation: subsequent review identified two Important scanner gaps: empty value spans and nested duplicate keys hidden by the post-value offset jump. Both were reproduced by direct contract tests and a `ViteAliasStep` fixture, repaired, and verified by the 24-test focused file, both PHP syntax checks, and diff check before the remediation commit.

## Browser acceptance

## Final merged-main verification
