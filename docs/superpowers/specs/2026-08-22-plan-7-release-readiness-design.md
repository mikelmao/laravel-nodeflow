# Plan 7 — release readiness design

**Date:** 2026-08-22  
**Status:** Approved design; implementation has not started

## 1. Goal

Bring the completed six-plan product to an evidence-backed release-readiness checkpoint with one
bounded corrective plan:

1. fix G-7, G-8, G-9 and G-12 in production code;
2. record G-11's exact documentation correction for a separate documentation session;
3. complete G-5 through real-browser observation against the compiled demo host;
4. update the repository README from measured results; and
5. create `docs/documentation-changes.md` as the authoritative handoff for all other documentation
   changes.

This plan does not publish a release or implement the deferred security and durable-runtime work.
It does not edit the GitBook guides, the open-issues ledger or historical design documents directly.

## 2. Verified starting state

The design session verified filesystem truth before selecting an approach.

### Package repository

- Path: `/Users/mikelmao/Projects/laravel-nodeflow`
- Branch: `main`
- HEAD: `29f3543` (`docs: add Plan 7 release-readiness handoff`)
- The preceding commits are the approved handoff design at `49bcf7b` and merged Plan 6 execution
  record at `8b51a3d`.
- The worktree is clean and no remote is configured.
- No `almanac/` directory exists.

### Demo repository

- Path: `/Users/mikelmao/Sites/test-workflow`
- Branch: `main`
- HEAD: `e15e5bd`
- The worktree is clean.
- `vendor/atram/laravel-nodeflow` resolves exactly to
  `/Users/mikelmao/Projects/laravel-nodeflow`.
- No `almanac/` directory exists.

The old Plan 6 worktree is already merged residue, not a Plan 7 base or cleanup target. A fresh
isolated worktree will be created from local `main` only after the implementation plan is approved.

## 3. Read-only reproduction evidence

The current implementation, tests, installed Vite source and public documentation were inspected
directly. Temporary probes were removed after execution.

### G-7 — unrelated alias facts produce a false accept

A `vite.config.ts` was constructed with:

- `@nodeflow/editor` mapped to the wrong `resources/js` directory; and
- `vendor/atram/laravel-nodeflow/resources/js` mentioned in an unrelated constant.

`ViteAliasStep::check()` returned `AlreadyPresent`. The current implementation is a whole-file
conjunction of those two strings, so it does not bind the path to the alias entry.

### G-8 — optional config is inconsistent

Against one empty host root:

- `PublishConfigStep::check()` returned `Writable` when `config/nodeflow.php` was absent;
- `MigrationStep::check()` returned `AlreadyPresent` when package migrations were unpublished; and
- after a deliberately customized `config/nodeflow.php` was added, `PublishConfigStep::check()`
  returned `AlreadyPresent`.

`NodeflowServiceProvider::register()` calls `mergeConfigFrom`, so the absent state has working
defaults. The handoff's request to preserve config-drift detection was corrected during design:
there is no current config-drift detector to preserve, and host customization is legitimate.

### G-9 — fallback snippets resolve into the host namespace

Inside `namespace App\Providers`, the two short names emitted by the commands resolve as:

- `TriggerRegistry::class` → `App\Providers\TriggerRegistry`
- `SubjectAttributeRegistry::class` → `App\Providers\SubjectAttributeRegistry`

The intended classes are `Nodeflow\Triggers\TriggerRegistry` and
`Nodeflow\Schema\SubjectAttributeRegistry`. The additive provider writer already uses fully
qualified names, so only the fallback output is inconsistent.

### G-11 — the binding spec's arithmetic is contradictory

The binding Plan 5 design's §3.2 lists nine steps: five writer-capable steps and four verifiers.
E20 says “writes four things and verifies three,” then itself lists four verifiers. The historical
correction is therefore **five writer-capable steps and four verifiers**.

After Plan 7 makes config optional, the future installer has four writer-capable steps, four
verifiers and one optional-config reporter. Three writes occur in a default run because migrations
remain opt-in. These are separate facts and must not be collapsed into one headline.

### G-12 — PHP inspects a config Vite ignores

The installed package is Vite 8.2.2. Its own `DEFAULT_CONFIG_FILES` order is:

1. `vite.config.js`
2. `vite.config.mjs`
3. `vite.config.ts`
4. `vite.config.cjs`
5. `vite.config.mts`
6. `vite.config.cts`

The PHP step currently starts with `.ts` and omits `.cjs` and `.cts`. A fixture containing both
`vite.config.js` and `vite.config.ts`, each with a unique marker, was passed to Vite's
`resolveConfig()`. Vite selected `.js` and loaded only its marker.

## 4. Chosen approach

Plan 7 is a bounded corrective release. Each issue remains independently testable, browser
acceptance is an explicit gate, and documentation is derived from measured results at the end.

Rejected alternatives:

- A generalized installer/parser refactor creates a larger abstraction than these release gaps need
  and increases the surface for the project's recurring false-accept class.
- Minimal one-line patches do not prove the alias entry or selected Vite file is the one the host
  actually uses.

## 5. Decisions

| ID | Decision | Reason |
|---|---|---|
| P7-E1 | Keep Plan 7 bounded to four production corrections, G-5 browser acceptance, README reconciliation and a documentation handoff. | Release readiness must not become the deferred security/runtime or publication plan. |
| P7-E2 | Bind the package path to the `@nodeflow/editor` alias property's own value span with a bounded lexical scan. | Two unrelated whole-file facts are the proven G-7 false accept; a full TypeScript AST is unnecessary. |
| P7-E3 | Treat unpublished and customized published config as healthy. Keep explicit config publication only through `vendor:publish --tag=nodeflow-config`. | `mergeConfigFrom` supplies defaults, and a published config is host-owned customization rather than drift. |
| P7-E4 | Keep the optional config row as a read-only reporter rather than an installer write. | The command may explain the optional state without making `--check` or normal install depend on publication. |
| P7-E5 | Emit fully qualified registry class names in both generator fallback snippets and execute the captured snippets in tests. | A longer substring assertion does not prove the pasted code resolves without imports. |
| P7-E6 | Use Vite 8.2.2's complete config order and prove PHP selects the same file as installed Vite. | An array reorder alone can drift again or omit supported candidates. |
| P7-E7 | Record non-README documentation work in `docs/documentation-changes.md`; do not edit the target guides, issue ledger or historical spec during Plan 7. | A separate session is rebuilding the GitBook documentation and needs one evidence-backed handoff. |
| P7-E8 | G-5 passes only from actual browser observation through the compiled demo with a controlled real queue worker. | Source, unit tests and build inspection cannot prove browser rendering, console cleanliness, action URLs or logout. |

## 6. Tooling corrections

### 6.1 G-7 — alias-entry binding

`ViteAliasStep` will use a small scanner dedicated to a quoted `@nodeflow/editor` property. Starting
from the property key, it will require the colon and find that property's value span while respecting
quoted strings, template strings, escapes and balanced `()`, `[]` and `{}` delimiters. The accepted
package source path must occur inside that span.

The check remains deliberately heuristic: it cannot prove which `defineConfig` or conditional branch
is exported. Its narrower guarantee becomes truthful: unrelated source text cannot supply the path
for a wrong alias entry. The scanner refuses zero, duplicate, malformed or wrongly mapped entries
rather than guessing which duplicate wins.

Comment stripping remains shared through `ViteConfigStep`, and existing accepted quote/path lexical
forms stay accepted. The implementation must not regress the E41 full `vendor/...` prefix guard.

### 6.2 G-8 — optional config

`PublishConfigStep` stops writing. Its check treats both states as healthy:

- absent: package defaults are merged;
- present: the host owns and may customize the published file.

Its description will identify config as optional so an `already wired` result is not presented as
evidence that a file exists. `apply()` remains read-only/unreachable in the same manner as the Vite
verification steps. `InstallCommand` retains the reporting step but no longer publishes config on a
normal run.

The explicit publication path is Laravel's existing command:

```bash
php artisan vendor:publish --tag=nodeflow-config
```

No `--publish-config` option is added. Migration checking, `--publish-migrations` and
`--force-migrations` are unchanged.

### 6.3 G-9 — import-free fallback output

`MakeTriggerCommand` prints:

```php
app(\Nodeflow\Triggers\TriggerRegistry::class)->register(
    \App\Nodeflow\Triggers\ExampleTrigger::class,
);
```

`MakeSubjectAttributeCommand` uses
`app(\Nodeflow\Schema\SubjectAttributeRegistry::class)` around its existing fully qualified
attribute entry.

Tests capture the actual command output, extract the emitted snippet, place it inside a temporary
`App\Providers` provider with neither registry import, lint the result and execute it against the
real application container. The assertions cover registration behavior, not only emitted text.

### 6.4 G-11 — documentation handoff

Plan 7 does not edit the historical Plan 5 design. `docs/documentation-changes.md` records the exact
E20 correction and distinguishes the historical five/four count from Plan 7's new optional-config
shape. G-11 remains pending until the separate documentation session applies that instruction.

### 6.5 G-12 — Vite precedence

`ViteConfigStep::CONFIG_CANDIDATES` will match installed Vite's complete order:

```text
vite.config.js
vite.config.mjs
vite.config.ts
vite.config.cjs
vite.config.mts
vite.config.cts
```

Because both Vite verification steps share `configSource()`, the correction applies identically to
the alias and dedupe checks. The behavioral test creates multiple candidate files with distinct
valid configuration markers, asks installed Vite which file it loaded, and proves both PHP steps
read that same file. Separate cases cover the newly recognized CommonJS candidates.

## 7. G-5 real-browser acceptance

### 7.1 Preconditions and setup

Immediately before the gate:

1. Reconfirm both repositories are clean and the demo vendor link resolves to package `main`.
2. Query the demo read-only for two users in one tenant whose `clicked_offer_at` and
   `confirmed_interest_at` values are null.
3. Start one controlled worker from the demo:

   ```bash
   php artisan queue:work --sleep=1 --tries=1 --timeout=120
   ```

   Retain its process/session handle and capture its output.
4. Launch Chrome:

   ```bash
   /Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome \
     --remote-debugging-port=9222 \
     --user-data-dir=/tmp/nodeflow-chrome
   ```

5. In that Chrome window, the user visits `chrome://inspect/#remote-debugging`, enables **Allow
   remote debugging**, and authenticates manually with their existing demo account.
6. Connect through `http://[::1]:9222/json/version`, not IPv4 loopback.

If two suitable existing users are unavailable, stop and ask before creating fixture users. Never
reseed or reset the demo database.

### 7.2 Interaction sequence and visible outcomes

1. Open `/nodeflow`; confirm the authenticated demo, active tenant and tenant-scoped flows render.
2. Open **Fast demo (seconds)** through **open editor**; confirm the host Inertia page, `FlowEditor`,
   node canvas and configuration surface render through compiled assets.
3. Return to the demo, start one new normal Fast demo run, select it and record its run/subject IDs.
4. Click **clicked** for the first clean subject. Confirm a successful Inertia POST to
   `/nodeflow/runs/{run}/subjects/{subject}/click` and no request to the obsolete subject-only URL.
5. Click **convert (exit)** for the second clean subject. Confirm the run-qualified POST succeeds,
   the subject becomes visibly `exited`, and its action buttons disappear.
6. Allow the worker to advance the run. Confirm the clicked subject contributes to the
   `clicked → yes` path and the converted subject receives no later work.
7. Open the run's **run view**; confirm `FlowRun`, the pinned graph, status and node badges render.
8. Navigate to the standard authenticated layout, use **Log out**, then revisit `/nodeflow`.
   Confirm redirect to `/login` and absence of protected demo content.

### 7.3 Browser evidence and cleanup

Record:

- zero console errors, invalid-hook-call messages or unhandled rejections;
- zero failed asset or API requests across the sequence;
- exact action URLs, methods and response statuses;
- run/subject IDs and before/after database facts for both affected users;
- worker output and every row created by starting the acceptance run; and
- screenshots of the editor, post-action demo, run view and post-logout login page.

Interrupt the worker, confirm its process exited, and report remaining queued jobs. Preserve the new
run as acceptance evidence; do not delete it or broaden cleanup to existing records. If the remote
debugging toggle or authentication is unavailable, finish every independent task and report G-5 as
blocked. Automated evidence must not be relabelled as browser acceptance.

## 8. Test and review strategy

Every production correction follows strict red-green-refactor TDD. The failing counterexample is
committed before implementation, and its named production counterfactual is executed after green.
The counterfactual must fail the intended test and production must be restored immediately.

Required discriminators:

- **G-7:** restore the whole-file two-string conjunction; the wrong-entry/unrelated-path test fails.
  Also preserve comment stripping, accepted lexical variants and the E41 wrong-prefix rejection.
- **G-8:** restore `Writable` for absent config; both the step test and a fully otherwise-wired
  `install --check` host fail. A customized published config remains byte-identical.
- **G-9:** restore either short registry name; the import-free provider execution test resolves the
  wrong host namespace and fails.
- **G-12:** restore `.ts`-first order; the multi-candidate Vite parity test fails. Removing `.cjs` or
  `.cts` also fails a focused candidate test.

After meaningful tasks, request independent spec-compliance and code-quality review. After all
tasks, run one whole-branch adversarial review across the five gaps, browser procedure, README and
documentation handoff. Its review questions include:

- Can a wrong alias pass because the right path occurs elsewhere?
- Can unpublished optional config fail `install --check`?
- Can either generator emit a snippet requiring an unstated import?
- Can PHP inspect a different Vite config than Vite loads?
- Does README still describe Plans 5 or 6 as unbuilt or overclaim queue-worker CI coverage?
- Does the documentation handoff identify every known stale claim without marking unaccepted work
  complete?
- Is any browser claim based on non-browser evidence?
- Did deferred security, durable-runtime, publication or unrelated formatting work leak in?

## 9. Documentation boundary

### 9.1 Updated directly in Plan 7

`README.md` is the GitHub landing page and is updated after measured acceptance. It will:

- report `nodeflow:install`, `nodeflow:make-node-package` and `nodeflow:extract-node` as shipped;
- add `docs/09-packaging-nodes.md` to the documentation table;
- use the newly measured package Pest and Vitest counts;
- state that the interpreter has run against a real local queue worker; and
- preserve the narrower limitation that real-queue execution is not part of CI.

If G-5 is blocked, README must not imply that browser acceptance passed.

### 9.2 Deferred to the documentation session

Plan 7 creates `docs/documentation-changes.md`. For each affected file it records the current stale
claim, required replacement, supporting commit/test/browser evidence, and any prerequisite for a
status transition. At minimum it covers:

- `docs/02-integration.md`: config is optional; explicit publication uses the `nodeflow-config` tag;
- `docs/08-editor-client.md`: remove the obsolete claim that the installer remains Plan 5 work;
- `docs/superpowers/open-issues.md`: final Plan 6 commit/count reconciliation and evidence-gated
  statuses for G-5, G-7, G-8, G-9, G-11 and G-12; and
- `docs/superpowers/specs/2026-08-21-remaining-tooling-design.md`: G-11's historical E20 arithmetic.

The handoff may identify additional stale GitBook claims discovered by an exact search, but Plan 7
does not edit their target files. Documentation reconciliation is not complete until the separate
session applies and verifies the handoff.

## 10. Final verification

Measure rather than target historical counts.

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

Also require:

- PHP lint for every changed PHP file;
- Pint for changed PHP files only;
- `git diff --check` in both repositories;
- clean final statuses and the exact demo vendor link;
- no orphaned queue worker, Chrome automation or Plan 7 temporary residue;
- exact final test/assertion counts in README and the documentation handoff; and
- an explicit G-5 `passed` or `blocked` result.

Run final verification on merged local `main`, not only in the feature worktree.

## 11. Explicitly deferred

- D-1, D-2 and G-3: tenancy and durable-path security hardening.
- C-1 through C-6: failure status, durable reached-history, ownership scaling, database matrix and
  real queue-worker CI.
- G-13: accepted extraction residual for dynamic or database-held class references.
- Release tagging/publishing and semantic-version selection.
- New generators, editor features and unrelated refactors.
- Repository-wide demo formatting.
- Applying the non-README documentation changes recorded in `docs/documentation-changes.md`.

These are not stretch goals.

## 12. Completion contract

Plan 7 implementation is ready for the separate documentation session when:

1. G-7, G-8, G-9 and G-12 have passing discriminator tests and recorded killed counterfactuals;
2. G-5 has an honest browser-pass or browser-blocked record;
3. G-11 and every other stale non-README claim are captured in `docs/documentation-changes.md` with
   evidence and prerequisites;
4. README reports only measured facts;
5. all package/demo gates pass on merged local `main`; and
6. no deferred work or unrelated user changes were absorbed.

Plan 7 itself does not claim full documentation reconciliation. That final release-readiness gate is
owned by the separate documentation session that applies `docs/documentation-changes.md`.
