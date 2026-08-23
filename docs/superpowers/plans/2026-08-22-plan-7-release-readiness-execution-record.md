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

## Commit map and task outcomes

| Task | Evidence commits | Outcome |
|---|---|---|
| 1 — G-12 | RED `90d3bd6`; production `012a6c7` | Counterfactuals killed; independent review clean. |
| 2 — G-7 | RED `313f9a5`; production `89bc0d7`; remediation `a553cb3` | Empty-value and nested-duplicate review findings repaired; re-review clean. |
| 3 — G-8 | RED `b220805`; production `ef0d82b`; report cleanup `b80878b`, `0a514da` | Counterfactuals killed; independent review clean after the scratch report was untracked. |
| 4 — G-9 | RED `183c9dd`; production `a152244` | Both import-free fallback counterfactuals killed; independent review clean. |
| 5 — tooling gate | `8a1383d` | Integrated gate and adversarial review clean, with two deferred Minor observations. |
| 6 — G-5 | evidence `5918408`; clarification `56fa8e0` | Browser gate **BLOCKED**; independent review confirmed the record and scope. |
| 7 — release documentation | measured source `56fa8e0`; release-documentation evidence `556206a` | README, documentation handoff and this record only. |
| 8 — whole-branch remediation | RED `3f32dff`, `1cc9970`; production `10b982c`, `c2fa80e`; evidence/style `c60e996`, `539fc23`, `cb43942`, `8430d70` | All Critical/Important findings closed; final spec review clean and one non-blocking fidelity Minor deferred. |
| Post-Plan-8 — G-5 closure | browser/database evidence captured 2026-08-23 | Clean-fixture real-browser rerun **PASS**; the original Task 6 blocker record remains historical evidence. |

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

### Task 3: G-8 — make published config optional

- RED (test-only discriminator commit `b220805`): `vendor/bin/pest tests/Feature/Install/PublishConfigStepTest.php tests/Feature/InstallCommandTest.php --compact` produced 4 failures, 13 passes, and 53 assertions. The intended failures were the absent-config `Writable` outcome, the missing `optional` description, normal install creating `config/nodeflow.php`, and the command-level every-step-wired test finding that file present. The migration assertions remained green.
- GREEN: after making `PublishConfigStep` read-only, `vendor/bin/pest tests/Feature/Install/PublishConfigStepTest.php tests/Feature/Install/MigrationStepTest.php tests/Feature/InstallCommandTest.php --compact` passed 24 tests / 77 assertions. The same run includes the unchanged migration publication and drift tests. `php -l src/Console/Install/PublishConfigStep.php` and `php -l src/Console/InstallCommand.php` reported no syntax errors; `git diff --check` passed.
- Writer-count evidence: the command still constructs exactly nine steps in the existing order. Its normal-install contract is three default writers (provider, provider registration, Tailwind), one opt-in migration writer, four verifiers (Vite alias, Vite dedupe, tsconfig paths, host dependency), and one optional-config report. The every-step-wired test verifies all eight non-config outcomes and then confirms `--check` exits 0 with config absent. `PublishConfigStep` has no filesystem reads or writes in `check()`/`apply()`.
- Counterfactual: temporarily restored `PublishConfigStep`'s `Writable` absence path and copy-on-apply implementation. `vendor/bin/pest tests/Feature/Install/PublishConfigStepTest.php --filter='reports healthy and writes nothing' --compact` failed 1 test / 2 assertions at the expected `AlreadyPresent` versus `Writable` check. `vendor/bin/pest tests/Feature/InstallCommandTest.php --filter='keeps the optional config healthy' --compact` failed 1 test / 11 assertions because normal install created `config/nodeflow.php`. A disposable command-level probe with every other step wired, the generated config removed, and `--check` asserted `1` passed 1 test / 2 assertions, proving the mutated absent-config path exits 1 under `--check`. The production implementation and probe edit were restored immediately; the prescribed three-file green suite was rerun afterward.
- Formatting: `vendor/bin/pint` is unavailable in this worktree (`vendor/bin/pint` is not present and `laravel/pint` is not declared/installed). The two changed PHP production files were kept in existing project style and syntax-checked; scoped diff checks passed.

### Task 4: G-9 — make both fallback snippets import-free

- RED (test-only discriminator commit `183c9dd`): `vendor/bin/pest tests/Feature/MakeTriggerCommandTest.php tests/Feature/MakeSubjectAttributeCommandTest.php --filter="prints the line" --compact` produced the two intended `BindingResolutionException` failures, plus the unrelated existing anchor-fallback pass (2 failures, 1 pass, 11 assertions). The captured trigger fallback, embedded verbatim in `App\Providers\ManualRegistrationProbe`, resolved short `TriggerRegistry::class` as missing `App\Providers\TriggerRegistry`; the captured attribute fallback in `App\Providers\ManualAttributeRegistrationProbe` likewise resolved missing `App\Providers\SubjectAttributeRegistry`. Both probes parsed before their `require`, declared no registry import, and the commands still exited 0 before the host-probe execution failed.
- GREEN: each manual fallback now emits an absolute registry class constant: `\Nodeflow\Triggers\TriggerRegistry::class` and `\Nodeflow\Schema\SubjectAttributeRegistry::class`. `vendor/bin/pest tests/Feature/MakeTriggerCommandTest.php tests/Feature/MakeSubjectAttributeCommandTest.php --compact` passed 19 tests / 71 assertions after the change and again after both mutations were restored. The generated trigger class and subject-attribute entry remained their pre-existing fully qualified values; command exit behavior was preserved.
- Counterfactual A: temporarily restored only trigger output to short `TriggerRegistry::class`, then ran `vendor/bin/pest tests/Feature/MakeTriggerCommandTest.php --filter="prints the line that registers" --compact`. It failed 1 test / 4 assertions with `Target class [App\Providers\TriggerRegistry] does not exist.` Production output was restored immediately.
- Counterfactual B: temporarily restored only attribute output to short `SubjectAttributeRegistry::class`, then ran `vendor/bin/pest tests/Feature/MakeSubjectAttributeCommandTest.php --filter="prints the line and exits zero" --compact`. It failed 1 test / 4 assertions with `Target class [App\Providers\SubjectAttributeRegistry] does not exist.` Production output was restored immediately.
- Formatting: `vendor/bin/pint` is unavailable (`vendor/bin/pint` is absent and `laravel/pint` is not declared in `composer.json`), so no formatter was run. All four changed PHP files were retained in project style and passed `php -l`; `git diff --check 183c9dd -- src/Console/MakeTriggerCommand.php src/Console/MakeSubjectAttributeCommand.php tests/Feature/MakeTriggerCommandTest.php tests/Feature/MakeSubjectAttributeCommandTest.php` passed.

## Reviews and remediation

### Task 1: G-12 — independent spec-compliance and code-quality review

- PASS: independent read-only review of base `f9dea76`, red evidence commit `90d3bd6`, and the pending production diff found no Critical, Important, or Minor findings. It confirmed the exact Vite 8.2.2 order, resolver-probe contract, `.js`/`.ts` and `.cjs`/`.cts` coverage, recursive teardown, and complete red/green/counterfactual record. The reviewer reran the focused test file (11 tests / 23 assertions), both changed PHP syntax checks, and `git diff --check`; all passed.

### Task 2: G-7 — independent spec-compliance and code-quality review

- PASS: independent read-only review of red commit `313f9a5` and the pending bounded-scanner implementation found no Critical, Important, or Minor findings. It confirmed the exact G-7 contract and execution record. The reviewer constructed a wrong `@nodeflow/editor: 'resources/js'` entry alongside a correct package path in a different alias entry; it returned `CannotWire`. Its delimiter-in-string input, `@nodeflow/editor: 'vendor/atram/laravel-nodeflow/resources/js,})'`, returned `AlreadyPresent`, confirming delimiters inside literals do not terminate the value scanner. The reviewer reran the focused Pest file (19 tests / 31 assertions), both changed PHP syntax checks, and `git diff --check`; all passed. No remediation was required.
- Fix Round 1 remediation: subsequent review identified two Important scanner gaps: empty value spans and nested duplicate keys hidden by the post-value offset jump. Both were reproduced by direct contract tests and a `ViteAliasStep` fixture, repaired, and verified by the 24-test focused file, both PHP syntax checks, and diff check before the remediation commit.

### Task 3: G-8 — independent spec-compliance and code-quality review

- PASS: independent review verified the `nodeflow-config` publication mapping remains in
  `NodeflowServiceProvider`, migration drift semantics remain unchanged, the nine-step order is
  preserved, and the red/green/counterfactual evidence matches the committed diff. A tracked SDD
  scratch report was then removed in `0a514da`; scoped re-review was clean.

### Task 4: G-9 — independent spec-compliance and code-quality review

- PASS: independent read-only review of RED commit `183c9dd` and the production diff found no Critical, Important, or Minor findings. It confirmed both tests use `Artisan::call()` plus `Artisan::output()`, extract and embed the exact captured block in distinct `App\Providers` probes without registry imports, parse before `require`, execute, and assert their package registries. It also confirmed the exact emitted registry FQCNs, preserved trigger/attribute-entry FQCNs and exit behavior, reran the two-file suite (19 passed / 71 assertions), the prescribed filtered fallback tests (3 passed / 13 assertions), all four changed PHP syntax checks, and the scoped diff check.

### Task 5: integrated tooling gate and cross-gap adversarial review

- Focused tooling surface PASS: `COMPOSER_DISABLE_NETWORK=1 vendor/bin/pest` over the six prescribed feature files passed **67 tests / 184 assertions** in 1.13s. All seven prescribed `php -l` checks were clean: `ViteAliasValue.php`, `ViteAliasStep.php`, `ViteConfigStep.php`, `PublishConfigStep.php`, `InstallCommand.php`, `MakeTriggerCommand.php`, and `MakeSubjectAttributeCommand.php`. Both `git diff --check` and the branch-point diff check were clean.
- Pint: the exact prescribed `vendor/bin/pint --test` invocation was run once and exited `127` with `zsh:1: no such file or directory: vendor/bin/pint`. It is unavailable, never passed, and no dependency was added: `laravel/pint` is undeclared/uninstalled in this worktree.
- Cross-gap adversarial review PASS: all six mandatory probes passed — wrong alias with correct path nested in an unrelated value; duplicate alias keys with only one correct; missing and customized config under normal install and `--check`; both fallback snippets in an import-free namespace; `.js`/`.ts` coexistence; and `.cjs`/`.cts`-only resolution. The reviewer found no Critical or Important findings. It also confirmed no GitBook, open-issues, historical-spec, demo, or TypeScript/React production file was changed.
- Deferred Minor observation: `ViteAliasValue` uses independent delimiter counters, so malformed cross-nesting such as `([ 'vendor/…' )]` may be treated as balanced. Reviewer classified Minor because malformed Vite source fails when Vite loads it.
- Deferred Minor observation: `PublishConfigStep.php` has an extra blank line immediately before the class-closing brace; cosmetic Minor.
- Deferred limitation: Pint exit 127 is a non-blocking environment limitation because `laravel/pint` is undeclared/uninstalled; record unavailable, never passed, and do not add a dependency.
- No remediation was required for Task 5; production and test files were not changed during this task.

## Browser acceptance

### Task 6: G-5 — real-browser acceptance **blocked**

- Real authenticated browser evidence: the approved Chrome extension controlled the already authenticated session. Because unrelated Chrome occupied 9222, the controlled Chrome ran on 9223; `GET http://127.0.0.1:9223/json/version` returned 200 with Chrome 151, protocol 1.3, and a WebSocket debugger URL. It was stopped afterward and `/tmp/nodeflow-chrome` was retained. The controlled worker root session `24258` was stopped with Ctrl-C; no Plan 7 worker remains.
- Editor success with graph-contract failure: `/nodeflow/flows/2/edit` titled `Edit Fast demo (seconds) - Laravel`; compiled `app-xBSsNUl4.js`, `editor-B6fUKYPu.js`, `app-CPd24EWd.css`, and `js-DLioOiRN.css` loaded. `FlowEditor` and the Condition configuration surface rendered and were interactive, without blank/unstyled UI or invalid-hook-call errors. But the editor contained **12 draft nodes / 13 edges**, while the dashboard reported published v2 with **11 nodes**, contradicting the required ten-node canvas.
- Browser observability: zero console errors and zero unhandled rejections; six repeated Inertia-devtools warnings about request lineage not being recorded. The approved Chrome surface did not provide full network capture, so the exact action-POST redirect status and the total failed-request count could not be proven.
- Runs/actions: initial run #5 (subjects 17–20; executions 31–38; messages 65–76) completed normally but missed its action window and is preserved only as retry evidence. Retry run #6 used subjects 21–24: subject 21/user 30 received clicked (`/nodeflow/runs/6/subjects/21/click`) and subject 22/user 31 received convert/exit (`/nodeflow/runs/6/subjects/22/convert`). No old `/nodeflow/subjects/...` action URL was used by the UI, but exact POST codes are unavailable.
- Recovery blocker: run #6’s initial workflow job hit SQLite `database is locked` at 20:31:46 while inserting `activity_executions`. Plain queue retry cleared the failed queue job but left durable task `01m0njr7d97hcg8amkjm5p6g7a` failed/pending for workflow run `01m0njr7d47dhad0m1jhhbkgmc`. The official targeted `workflow:v2:repair-pass --run-id=01m0njr7d47dhad0m1jhhbkgmc --json` repaired/dispatched one missing task. The run then completed, but convert occurred before recovered workflow start, so it cannot prove cancellation after an earlier message.
- Partial behavior observed: run #6 completed (11 steps, no error, 20:34:03–20:34:36); subject 21 completed, subject 22 exited at 20:31:53, subjects 23–24 completed; user 30 clicked at 20:31:50 and reached plan `plus`; user 31 confirmed interest at 20:31:53 and stayed `basic`; executions 39–48 include clicked `yes=1`, `no=2`, `upgrade=1`, `followup=2`; messages 77–84 were created with none for user 31. Final queue state: 0 jobs.
- Run view/logout: `/nodeflow/runs/6` titled `Run #6 — Fast demo (seconds) - Laravel`; `FlowRun` rendered a pinned **11-node** graph and status/node badges, again conflicting with the ten-node expectation. Logout returned from `/dashboard` to `/`; direct `/nodeflow` redirected to `Log in - Laravel` with no protected content. Ignored screenshots: `browser/editor.png`, `post-actions.png`, `run-view.png`, and `login.png`.
- Database high-water evidence: baseline runs 4/4, subjects 16/16, executions 27/27, messages 44/60, jobs 1/122; the pre-existing queue drain raised executions to 30 and messages to 64 before acceptance. Final values were runs 6/6, subjects 24/24, executions 48/48, messages 68/84, jobs 0/null.
- **Gate outcome: G-5 blocked, not passed.** Exact blockers are the non-ten-node graphs, unavailable exact action redirect/failed-request evidence, and lock/recovery timing that does not prove mid-journey cancellation. The successful partial observations above must not be treated as a substitute for those gates.
- Reopened 9222 review (read-only): current Chrome PID 43376 (`Google Chrome --restart`) listens on IPv4 9222, but `/json/version`, `/json/list`, and `/json` each return 404 and IPv6 loopback refuses the connection; it exposes no CDP target metadata or WebSocket endpoint. Chrome extension discovery found only the prior NodeFlow `/login` tab (not authenticated), already owned by browser session `01a02a9b-5397-7ae2-9100-36665f6a0081`; no tab was taken over, navigated, or otherwise changed. So this existing listener cannot close the exact action-POST redirect or failed-request evidence gap.
- Ten-node reconciliation: the current canonical Fast-demo seeder has exactly 10 nodes / 13 edges, so the brief’s expectation is valid for a fresh fixture. Persistent flow #2 has published version 2 with 11 nodes / 13 edges (extra `v2only`) and a draft with 12 nodes / 13 edges (additional `draftonly`, the intentional pinned-version-divergence test node). No reseed or repair was permitted. The node-count gap therefore remains a real-demo fixture/data-drift blocker for this run, not a demonstrated package rendering defect. The earlier SQLite recovery/timing blocker is unchanged.

### Task 6 review

- PASS on record integrity and scope: independent review confirmed that the evidence supports a
  **BLOCKED**, not passed, G-5 result and that only this execution record changed in the Task 6
  commit range. No remediation was required after the blocker clarification in `56fa8e0`.

## Task 7 release-readiness gates

- Package source `56fa8e064b344083c36d8ad7b3d98b19607dd636`: the controlled full Pest
  run passed **922 tests / 7,514 assertions** in 93.41s. Vitest passed **160 tests across 17 files**;
  `npx tsc --noEmit` was silent; Composer metadata was valid; and `git diff --check` passed.
- Demo source `e15e5bd912fee2e248654861b826d9e1458707dc`: the package symlink resolved
  exactly to `/Users/mikelmao/Projects/laravel-nodeflow`; Pest passed **56 tests / 223 assertions**;
  TypeScript was silent; the production build transformed 2,497 modules and passed; Composer
  metadata/lock validation passed with only the known unbound `@dev` and `*` local-package
  warnings; and the demo diff/status checks were clean after all gates.
- Browser disposition: G-5 remained **BLOCKED** for the three reasons recorded above. README did
  not gain a new browser-pass claim. It retains only the truthful earlier local-worker evidence and
  states that real-queue execution is not part of CI.
- Demo database evidence remained the Task 6 high-water mark: runs 5–6; subjects 17–24;
  executions 31–48; messages 65–84. Task 7 ran tests, type checks and a build only; it did not reset,
  reseed, migrate, repair, clean or otherwise write acceptance rows.
- Documentation boundary: Plan 7 updated `README.md`, created `docs/documentation-changes.md`, and
  updated this execution record. It did not edit `docs/02-integration.md`,
  `docs/08-editor-client.md`, `docs/superpowers/open-issues.md`, or
  `docs/superpowers/specs/2026-08-21-remaining-tooling-design.md`.

## Task 8 whole-branch review remediation

- Whole-branch review found two Important G-7 defects. First, `ViteAliasValue::valueAt()` tracked
  delimiter types independently, so a malformed cross-nested value could close out of LIFO order
  and still be accepted. Second, alias keys were compared as raw source bytes, so escaped slash and
  Unicode spellings of `@nodeflow/editor` could evade duplicate detection or make a lone valid
  escaped key look absent. The same raw treatment made a Vite-valid escaped package-path literal a
  false negative. The two previously noted Minors were also closed: the delimiter issue was raised
  to Important by the whole-branch review, and the extra `PublishConfigStep` blank line was removed.
- RED (test-only commit `3f32dff`): the complete focused file produced **12 intended failures, 24
  passes and 48 assertions**. Direct extractor and `ViteAliasStep` cases reproduced malformed
  cross-nesting; escaped-slash, `\\u002f` and `\\u{2f}` datasets reproduced semantic duplicate and
  lone-key behavior; and an escaped package-path literal reproduced the Vite-semantic false
  negative. PHP syntax and the diff check were clean before production changed.
- GREEN (production commit `10b982c`): `valueAt()` now uses a LIFO opener stack. Quoted keys are
  decoded for semantic comparison while their raw source offsets still govern scanning, and quoted
  strings inside the extracted value are decoded before the package-path check. The focused file
  passed **36 tests / 51 assertions**. `ViteAliasValue.php`, `ViteAliasStep.php`,
  `PublishConfigStep.php` and `ViteStepsTest.php` all passed `php -l`; `git diff --check` passed.
- Delimiter counterfactual: temporarily replacing the top-of-stack match with non-LIFO opener
  removal made both stack-order regressions fail (**2 failures / 2 assertions**), accepting the
  malformed value in both the direct extractor and step. LIFO matching was restored immediately.
- Semantic-key counterfactual: temporarily comparing the quoted key's raw bytes instead of its
  decoded value made all escaped-key regressions fail (**9 failures / 9 assertions**) across the
  direct duplicate, step duplicate and lone-key datasets. Semantic comparison was restored
  immediately, and the complete focused file passed again at **36 / 51**.
- Fix Round 2 Important: adversarial `.cjs` review found that Vite 8.2.2 accepts Annex B legacy
  octal escapes while the PHP decoder treated escaped digits as identity characters. A correct raw
  key followed by `@nodeflow\\057editor: 'resources/js'` therefore resolved in Vite to the wrong
  replacement but PHP falsely returned `AlreadyPresent`; lone octal-key and octal-path correct
  configurations were false negatives.
- Fix Round 2 RED (test-only commit `1cc9970`): three `.cjs` fixtures resolve the effective alias
  through installed Vite before checking PHP. The duplicate resolved to `resources/js`, while the
  lone octal key and octal path both resolved to
  `vendor/atram/laravel-nodeflow/resources/js`; PHP then failed the intended parity assertion in
  every case. The filtered run produced **3 failures / 9 assertions**, and the complete focused
  file produced **3 failures, 36 passes and 60 assertions**.
- Fix Round 2 GREEN (production commit `c2fa80e`): the decoder now consumes one to three octal
  digits when the first digit is `0`–`3`, or at most two when it is `4`–`7`, which also gives
  `\\0` its JavaScript null-escape behavior without changing simple, hex or Unicode handling. The
  complete focused file passed **39 tests / 60 assertions**; production/test PHP lint, the Node
  resolver syntax check and `git diff --check` passed.
- Fix Round 2 counterfactual: temporarily restoring identity-digit decoding made all three new
  installed-Vite/PHP parity cases fail again (**3 failures / 9 assertions**). Octal decoding was
  restored immediately, and the complete focused file returned to **39 / 60**.
- Pint remediation: whole-branch spec review correctly raised the Task 5 exit `127` as an unresolved
  required gate. Laravel Pint v1.30.5 was installed only in an isolated `/tmp` Composer project; no
  package dependency or manifest changed. Its first scoped `--test` run over the exact Plan 7 PHP
  file list failed on four files. Pint then formatted only those changed files in `539fc23`; the
  same scoped `--test` command passed, and the six-file focused Pest surface passed **79 tests / 199
  assertions** afterward. This closes the earlier unavailable/never-passed limitation without
  adding Pint to the repository.
- Final review disposition: both independent reviewers reported zero Critical and zero Important
  findings after Fix Round 2. Spec review reported zero Minors. Adversarial review retained one
  non-blocking fidelity Minor: legacy octal code units above ASCII are decoded as raw bytes rather
  than UTF-8. This cannot affect the ASCII `@nodeflow/editor` key or package-path substring and is
  deferred rather than included in G-7 scope.
- Post-remediation branch gates at source `8430d7055d1505526f6e046024ae8e08e768989e`
  passed: Pest **937 tests / 7,538 assertions** in 97.13s; Vitest **160 tests across 17 files**;
  TypeScript silent; Composer metadata valid; scoped Pint passed; and `git diff --check` clean.

## Final merged-main verification

- Local integration: `plan-7-release-readiness` was merged into local `main` with merge commit
  `f487cedc62a65727a69c989221bec8bb4bc8ae89`. The pre-merge `main` commit remained the approved
  branch point `f9dea76`; no concurrent target-file change required reconciliation. An `origin`
  remote was present at integration even though the starting plan expected none; it was not fetched,
  pulled, pushed or otherwise changed.
- Merged package gates: `COMPOSER_DISABLE_NETWORK=1 vendor/bin/pest --compact` passed **937 tests /
  7,538 assertions** in 100.00s; Vitest passed **160 tests across 17 files**; TypeScript was silent;
  Composer metadata was valid; and `git diff --check` passed. These totals match `README.md` and
  `docs/documentation-changes.md`.
- Final demo gates at `e15e5bd912fee2e248654861b826d9e1458707dc`: the package link resolved
  exactly to merged package `main`; Pest passed **56 tests / 223 assertions** in 67.155s;
  TypeScript was silent; the production build transformed 2,497 modules and passed in 2.20s;
  Composer validation passed with the two known unbound local-package warnings; and demo diff/status
  checks were clean.
- G-5 remains **BLOCKED**, not passed, with the exact browser, network, graph-shape and SQLite
  recovery evidence above. Its retained demo rows remain runs 5–6, subjects 17–24, executions
  31–48 and messages 65–84. Final read-only high-water values remained runs 6/6, subjects 24/24,
  executions 48/48, messages 68/84 and jobs 0/0.
- Cleanup and hygiene before this record commit: package `main`, feature worktree and demo were
  clean; no Plan 7 queue worker or TCP 9223 listener remained; `/tmp/nodeflow-chrome` was retained;
  and the locked Plan 6 worktree was untouched.

## Post-Plan-8 G-5 closure rerun (PASS)

This 2026-08-23 rerun closes G-5 without rewriting the blocked Task 6 attempt above. Browser work
started at package `b0dfaca10be1d974801c83ae1a94c0d41cfbb807`; concurrent commits through merged-main
`bc6d7d0` changed documentation only, so the symlinked demo's runtime code and assets did not change
during acceptance. The demo stayed on its existing database: no reset, reseed, migration, repair,
or deletion ran. A disposable unscoped login account was added as user 37; acceptance data remains
available for inspection.

- **Browser/editor:** the approved Chrome-extension browser authenticated into Globex and opened
  `/nodeflow/flows/4/edit`. Canonical flow 4's published version 1 rendered exactly **10 React Flow
  nodes / 13 edges**, its configuration surface was visible, and compiled styling was present.
  `/nodeflow/runs/8` likewise rendered the pinned ten-node graph and showed
  `Version 1 · completed`.
- **Real actions and durable behavior:** a controlled demo queue worker processed the jobs. Run 7
  (subjects 25–27, executions 49–58, messages 85–92) completed and is retained as a timing attempt:
  click succeeded for subject 25/user 34, but conversion was not submitted before the short action
  window. Corrective run 8 is the accepted run. It completed with nine steps from 16:55:45 through
  16:56:17 using subjects 28–30 and executions 59–66. The browser submitted click for subject
  29/user 35 at 16:55:47 and convert for subject 30/user 36 at 16:55:51 through the current
  `/nodeflow/runs/8/subjects/{subject}/...` routes. User 35 finished on `plus`; user 36 remained
  `basic`, exited at conversion, and received no message after exit. Run 8 messages are 93–96:
  welcome for users 34–36 and the later offer only for user 35.
- **Exact HTTP evidence:** a temporary PHP front controller on `127.0.0.1:8123`, reached in Chrome
  as `http://test-workflow.test:8123`, recorded final application response codes while preserving
  the same hostname, session, code, symlink, and database. Its 238-entry ledger contains **230 ×
  200** and **8 × 302**, with **zero 4xx/5xx**. Both run-creation POSTs, run 7's click, run 8's click
  and convert, and logout returned 302 with successful follows; the run-8 view returned 200. After
  logout, direct `/nodeflow` returned 302 and `/login` returned 200. No obsolete
  `/nodeflow/subjects/...` action URL appears.
- **Console and protection:** all acceptance interactions produced zero console errors, zero
  unhandled rejections, and no invalid-hook-call. The only browser diagnostic was the known
  non-failing Inertia-devtools request-lineage warning. After logout the login page contained no
  Globex, flow, or run content, and direct protected navigation redirected to login.
- **Database high water:** final read-only values were runs **8/8**, subjects **30/30**, node
  executions **66/66**, demo messages **80/96**, and jobs **0/null**. The non-contiguous message IDs
  are expected from earlier deletions; this rerun added the preserved IDs 85–96. Users 34–36 and
  all run rows remain as acceptance evidence.
- **Ignored artifacts:** `.superpowers/sdd/2026-08-22-plan-7-release-readiness/browser/` holds
  `database-final.json` (`78fd4e7f…e962`), `http-status-ledger.log` (`37883399…24c`),
  `editor-instrumented.png` (`704ba670…640`), `post-actions.png` (`52a48159…97c`),
  `post-actions-completed.png` (`dcf9eac9…ba0e`), `run-view.png` (`fca207b7…990`), and
  `login-after-logout.png` (`435b7a57…270`). These files are intentionally ignored, not release
  artifacts.
- **Cleanup:** the controlled queue worker and temporary HTTP server were stopped, their temporary
  router/log/database-export copies were deleted after evidence preservation, no matching
  controlled process remains, the demo worktree is clean, and its package symlink still resolves
  exactly to package `main`. The unrelated developer browser and its processes were not changed.

**Gate outcome: G-5 passed.** The rerun directly proves the ten-node editor/run rendering, current
client action URLs and redirect results, click/convert/exit behavior with no post-exit delivery,
console/request cleanliness, and authenticated-route logout behavior that the original Task 6 run
could not prove.
