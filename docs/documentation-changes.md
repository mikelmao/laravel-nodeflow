# Remaining documentation reconciliation after Plan 7

This file now tracks only unresolved internal evidence reconciliation. The canonical public guide is
the GitBook; the numbered `docs/01-*.md` through `docs/09-*.md` guides are legacy references and are
not targets for this handoff. Apply each remaining change only when its named evidence is present.

## Measured release-readiness evidence

- Package source commit `8430d7055d1505526f6e046024ae8e08e768989e`: Pest passed 937 tests
  with 7,538 assertions; Vitest passed 160 tests across 17 files; `npx tsc --noEmit` was silent;
  `composer validate --no-check-publish` reported valid metadata; and `git diff --check` passed.
- Demo commit `e15e5bd912fee2e248654861b826d9e1458707dc`, with
  `vendor/atram/laravel-nodeflow` resolving exactly to package main: Pest passed 56 tests with 223
  assertions; `npx tsc --noEmit` was silent; the production build passed after transforming 2,497
  modules; Composer validation passed with the two known unbound local-package warnings; and the
  demo remained clean.
- The original Plan 7 G-5 attempt remained **BLOCKED**, not passed. The real browser rendered and
  exercised the editor and run view, but the persistent demo graph did not have the required
  ten-node shape, the approved Chrome surface could not prove exact action redirect statuses or a
  complete failed-request count, and a SQLite lock/recovery sequence prevented proof of mid-journey
  cancellation. Preserve the exact evidence and row IDs in the [Plan 7 execution record](superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md#task-6-g-5--real-browser-acceptance-blocked).
- A clean-fixture rerun on 2026-08-23 subsequently **PASSED G-5**. It rendered the canonical
  ten-node editor and pinned run graph, exercised click and convert/exit from the real client,
  recorded every browser request as 200 or 302, observed no console errors or unhandled
  rejections, and proved logout protected `/nodeflow`. The closure evidence is appended to the same
  execution record under `Post-Plan-8 G-5 closure rerun`.

## Exact corrective evidence

Use these commits and linked execution-record sections when reconciling the issue ledger. The RED
commits prove the former gaps; the production/remediation commits and recorded counterfactuals prove
the corrected behavior.

| Gap | Exact evidence commits | Corrected behavior and execution evidence |
|---|---|---|
| G-12 | RED `90d3bd6`; production `012a6c7` | PHP follows installed Vite's complete config precedence, including `.cjs` and `.cts`. See [Task 1: G-12](superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md#task-1-g-12--installed-vites-config-precedence). |
| G-7 | RED `313f9a5`; production `89bc0d7`; first remediation `a553cb3`; whole-branch RED `3f32dff`; whole-branch production remediation `10b982c`; CJS octal RED `1cc9970`; CJS octal production `c2fa80e` | The package path is bound to one semantic `@nodeflow/editor` key; empty, nested, duplicate, escaped-duplicate and delimiter-malformed entries are rejected while Vite-valid escaped keys and paths—including supported CJS legacy-octal escapes—remain accepted. See [Task 2: G-7](superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md#task-2-g-7--bind-the-package-path-to-the-alias-entry) and [Task 8 remediation](superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md#task-8-whole-branch-review-remediation). |
| G-8 | RED `b220805`; production `ef0d82b` | Missing or customized published config is healthy and read-only; migration publication/drift behavior is unchanged. See [Task 3: G-8](superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md#task-3-g-8--make-published-config-optional). |
| G-9 | RED `183c9dd`; production `a152244` | Both generator fallback snippets execute without registry imports because they emit fully qualified registry classes. See [Task 4: G-9](superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md#task-4-g-9--make-both-fallback-snippets-import-free). |

## docs/superpowers/open-issues.md

- Reconcile Plan 6 to `8b51a3d`, remediation `31e070a`, and 904 / 7,469.
- G-7, G-8, G-9 and G-12 may close only with their implementation commits, killed
  counterfactuals and final green gates.
- G-5 is closed by the dated browser-observed PASS appended to the Plan 7 execution record. Retain
  the original blocked attempt as historical evidence rather than rewriting it.
- G-11 closes only when E20 is actually corrected.
- Keep G-13 as ACCEPTED RESIDUAL.

## docs/superpowers/specs/2026-08-21-remaining-tooling-design.md

- Historical E20 correction: Plan 5's nine steps were five writer-capable steps and four verifiers.
- Do not rewrite Plan 5 execution evidence.
- Separately note the post-Plan-7 installer shape: four writer-capable steps, four verifiers, and
  one optional-config reporter; default execution performs three writes because migrations are
  opt-in.

Keep earlier Plan 4 and Plan 5 counts as historical snapshots. Counts in historical plans, prompts,
designs and execution records are time-scoped evidence, not current product claims, and must not be
rewritten as release totals.

## Deferred facts that must remain visible

- C-1 through C-6 durable-runtime/scaling/database/real-queue-CI work
- G-13 dynamic/database reference residual
- Release publication/versioning and unrelated formatting
