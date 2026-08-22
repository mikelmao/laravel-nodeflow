# Plan 6 — resume mid-execution: session handoff prompt

Paste the whole of this file as the opening message of a new session. Plan 6 is **part-way executed**;
this is not a fresh start. Everything a new session needs that is not already on disk is here.

---

We are resuming execution of `docs/superpowers/plans/2026-08-21-node-packaging.md` (Plan 6, node
packaging). Eight of fourteen tasks are complete and reviewed clean. Task 9 is mid-review. The
previous session was ended by an infrastructure spend limit, not by a problem with the work.

## 0. Where to work — read this before running anything

**Work in the existing worktree, not a new one:**

```
/Users/mikelmao/Projects/laravel-nodeflow/.claude/worktrees/plan-6-packaging
```

Branch `worktree-plan-6-packaging`, HEAD **`ab6ddd1`**, working tree **clean**.

Do **not** create a new worktree. Do **not** branch afresh. The execution state lives in that
directory and partly outside git.

**The single most important fact in this document:** the orchestration workspace is at

```
.claude/worktrees/plan-6-packaging/.superpowers/sdd/2026-08-21-node-packaging/
```

and it is **gitignored scratch**. It holds the 400-line progress ledger, nine task briefs, nine
implementer reports, and 29 review diffs. It is the only record of ~62 rulings and of every
review's evidence. **`git clean -fdx` destroys it.** Never run that. If it is ever lost, `git log`
plus the commit messages are the fallback, and they are much thinner.

Read `<workspace>/progress.md` first, in full. It is the recovery map and it is authoritative over
anything in this prompt that disagrees with it.

## 1. Verify before trusting

```bash
git branch --show-current      # worktree-plan-6-packaging
git rev-parse HEAD             # ab6ddd1...
git status --porcelain         # empty
./vendor/bin/pest              # 808 passed, 6937 assertions
npx tsc --noEmit               # silent
```

Baseline entering Plan 6 was **488 tests / 6,152 assertions**. Current is **808 / 6,937**. Vitest is
unchanged at **160** and this plan touches no client code, so if Vitest moves, something is wrong.

Dependencies are installed in that worktree. If `vendor/` or `node_modules/` are missing, run
`composer install` and `npm install` — and do **not** commit `package-lock.json`, whose `name` field
`npm install` rewrites in a differently-named worktree.

## 2. Authoritative documents, in reading order

1. `<workspace>/progress.md` — the ledger. Every ruling, every deferred minor, every task's
   measured counts. **Read it all.**
2. `docs/superpowers/specs/2026-08-21-node-packaging-design.md` — the spec. Decisions **E29–E53**.
   It is the binding authority; the plan argues from it, and conflicts resolve against it.
3. `docs/superpowers/plans/2026-08-21-node-packaging.md` — the plan, 14 tasks. **Its own preamble
   warns that its code blocks are its least reliable artifact**, and that has been measured on this
   branch: Tasks 1–5 had full code in their briefs and produced eight Criticals between them, several
   being defects in the plan's own code that implementers faithfully copied. Task 6 was the first
   brief written as numbered behavioural rules and the first to pass its first review clean.
4. `docs/superpowers/plans/2026-08-21-nodeflow-remaining-tooling-execution-record.md` — Plan 5's
   record. Its two lessons govern everything: **every significant defect was found by execution, none
   by reading**, and **a substring test standing in for real checking is this codebase's
   characteristic bug**. That bug has now appeared **eleven** times on this branch alone.
5. `<workspace>/task-9-report.md` — Task 9's full narrative across three fix rounds. Needed for the
   immediate next action.

## 3. State: what is done

| Task | State | Commits |
|---|---|---|
| 1 HostPath, shared install-step path arithmetic | complete, clean, 1 fix round | `f0e733d..74f0e4b` |
| 2 NodeTypeLiteral (E36 `type()` guard) | complete, clean, 2 fix rounds | `74f0e4b..cb8d0c3` |
| 3 PhpNameResolver | complete, clean, 2 fix rounds | `cb8d0c3..3a0489b` |
| 4 `removeFrom()`, `NodeRemovalOutcome`, `appendTo()` fix | complete, clean, 3 fix rounds | `3a0489b..2ee713b` |
| 5 NodeReferenceScanner with span recording | complete, clean, 3 fix rounds | `2ee713b..38fa4c2` |
| 6 PackageScaffolder + stubs | complete, clean, **0 fix rounds** | `38fa4c2..2ada497` |
| 7 MakeNodePackageCommand | complete, clean, 2 fix rounds | `2ada497..0f90fa1` |
| 8 extract-node's eight read-only gates | complete, clean, 4 fix rounds | `0f90fa1..2cb0260` |
| 9 the moves, journal, restore | **fix round 3 applied; its re-review never ran** | `2cb0260..ab6ddd1` |
| 10 M8 install + M9 host-boot verification | not started | — |
| 11 register `ExtractNodeCommand` | not started (narrowed — see §6) | — |
| 12 demo: migrate three nodes into `$nodes` | not started | — |
| 13 demo: failed extraction, then the real one | not started | — |
| 14 docs, spec, open-issues | not started | — |

Note `5265021` in the history is a deliberately-labelled WIP commit (`TESTS NOT YET WRITTEN`) from
when a spend limit killed a Task 8 fix round mid-work. It was not amended, on purpose: the boundary
between "fixes applied" and "fixes proven" is the honest record of where that stopped.

## 4. THE IMMEDIATE NEXT ACTION

Task 9's round-3 fixes are committed at `ab6ddd1` and the suite is green, but **the scoped re-review
of those fixes never ran** — the agent was killed seconds after starting. Nothing is verified.

The diff package already exists: `<workspace>/review-283739d..ab6ddd1.diff`.

Dispatch that re-review on the **most capable available model**. Task 9 is the destructive-moves
task; every Critical on it was found by an Opus review constructing and running inputs, and Sonnet
reviews of the same paths missed things. Use this prompt (it is the one that was killed, verbatim):

> SCOPED re-review of one fix diff for `atram/laravel-nodeflow`. Working directory:
> `/Users/mikelmao/Projects/laravel-nodeflow/.claude/worktrees/plan-6-packaging`.
>
> Verdict the open findings, adjudicate the implementer's own flagged concern, and flag new
> Critical/Important breakage **from this diff only**. This is intended to be the last review of the
> most dangerous task in the plan.
>
> Inputs: fix diff `<workspace>/review-283739d..ab6ddd1.diff`; report `<workspace>/task-9-report.md`.
> Suite verified: 808 passed, 6937 assertions, `tsc` silent. Do not re-run the whole suite.
>
> What this code is: `nodeflow:extract-node`'s destructive moves — writes a package, rewrites
> namespaces, edits a host provider and `composer.json`, **deletes the originals**. Failure anywhere
> must leave the host tree byte-identical, and the command must never delete a file while leaving the
> host referencing a class that no longer exists.
>
> Open findings:
> 1. **Important (N1)** — escaping was gated on being inside a heredoc, but every
>    `T_ENCAPSED_AND_WHITESPACE` except a nowdoc's processes escapes, so an interpolated
>    double-quoted string carrying the FQCN corrupted identically: `--namespace=acme\things` produced
>    a literal TAB in the runtime value at exit 0. Ordered fix: gate on nowdoc-ness.
> 2. **Important (N2)** — four survivors in the escaping logic: `needsHeredocEscaping() → true`;
>    `$isNowdoc → false`; deleting the `T_END_HEREDOC` reset; dropping the
>    `T_ENCAPSED_AND_WHITESPACE` check. No test pinned the false direction for a *plain-spelled*
>    nowdoc, nor Blade `T_INLINE_HTML`.
> 3. **Minor (N3)** — a docblock asserting a non-fatal downgrade the code does not implement.
> 4. **Structural gap A** — a loose `.php` file at the host root (e.g. `rector.php`) was never
>    scanned, so extraction deleted the original and left a dangling reference. Ordered: root-level
>    `*.php` files join the shared scan set.
> 5. **Structural gap B** — a symlinked directory *inside* a scanned root was invisible to both the
>    gate and the post-move rescan, while PSR-4 made its contents genuinely autoloadable. Ordered:
>    scan through such symlinks with cycle detection, refusing on a cycle or unreadable target.
>
> **THE HEADLINE — the implementer flagged this themselves and they are right to.** Gap B's fix
> **removes `NodeReferenceScanner`'s internal `HostPath::contains()` filter entirely**, replacing it
> with symlink-following plus cycle/broken-target detection, and an existing unit test that asserted
> the old behaviour was **rewritten** to assert the new one. Judge carefully:
> - Is the rewritten test a legitimate change of requirement, or a test adjusted to pass? Read what
>   it asserted before and after and say which.
> - With the containment filter gone, **what now bounds what the scanner reads?** Construct: a
>   symlink inside a scan root pointing at `/`; at the host's own parent; at a very deep tree; a
>   symlink chain; a cycle; a broken symlink; a symlink to a file rather than a directory. For each,
>   report whether the scan terminates, refuses, or wanders.
> - Earlier in this plan a defect let the scanner read **outside** the host via a `../` PSR-4 value,
>   closed by constraining the roots. Confirm removing the filter has not reopened it by another
>   door — that the roots are still the only entry point.
>
> Verify findings 1, 2, 4 and 5 by construction, reporting actual output. For 1, check the
> *evaluated runtime value* of an interpolated double-quoted string and a backtick string, not just
> the file text. For 4, use a root-level `rector.php`. For 5, use the `app/Linked` symlink case.
>
> Two further adjudications: (a) they report N3's re-verify block is now reachable by a real test but
> still not discriminable from "do nothing" by any mutation, documented honestly rather than left
> with the disproven earlier claim — confirm; (b) they report G5 now pays file-root scanning and
> symlink-following with cycle tracking on top of a full-tree walk — judge whether that is
> acceptable for a one-shot developer command, and whether any input makes it pathological.
>
> Then mutation-sweep this diff — at least six branches across the symlink traversal, cycle
> detection, root-file scanning and the corrected escaping gate. Any line whose deletion leaves the
> suite green is Important.
>
> **Finally, ask the whole-task question again**: is there any remaining path by which this command
> deletes a file and leaves the host unable to load a class it still references? Construct what you
> can. If you find none, say so plainly and describe what you tried — that is the answer I most want.
>
> **Leave the working tree exactly as you found it** — restore every mutation, confirm with
> `git status` and file hashes.
>
> Return: a verdict per finding, the headline judgement with your symlink table, the two
> adjudications, mutation results, your answer to the whole-task question, and any new breakage.
> Under 800 words.

Task 9 is on **fix round 3 of a cap of 5**. If round 5's re-review still leaves findings open, stop
dispatching and adjudicate each one yourself, recording every decision in the ledger.

## 5. The process that is working — keep it

This is not ceremony. It is what has found 25+ real defects including eleven Criticals.

- **Mutation testing is the highest-yield technique on this branch.** Delete or invert a line, run
  the covering tests. It has found a real defect nearly every time it has been used. Instruct every
  implementer to do it before reporting, and every reviewer to sweep branches the implementer did
  not. It pointed the wrong way exactly once: on Task 8, an implementer correctly removed a
  "redundant" equality arm, which left a broken `fnmatch()` as the sole test and created a Critical.
  The lesson recorded there is that a guard load-bearing only because a neighbour is broken stops
  being load-bearing when the neighbour is fixed.
- **Order adversarial probes explicitly** and name the angles. Free-form review has found almost
  nothing on this branch; constructed-and-executed inputs have found almost everything.
- **A test that cannot detect the removal of the thing it names is worse than no test.** At least six
  such tests were shipped and caught on this branch, including two written specifically to catch a
  mutation. Ask reviewers to verify each new test fails for the *intended* reason, and to weaken its
  assertions to confirm the covering power is where it is claimed.
- **Reward implementers for reporting a probe that does not discriminate.** It has happened seven
  times and been correct every time — twice the thing that failed to discriminate was a *reviewer's*
  own construction. Tell them explicitly: say so with reasoning rather than fabricating a match.
- **Model choice matters on destructive paths.** Use the most capable model for reviews of Tasks 9
  and 10, and for the final whole-branch review. Cheaper tiers are fine for narrow test-only diffs.
- **Ledger every ruling** as `Ruling: <what> — <why> — <what it costs if wrong>`, and never discard a
  finding silently.
- **Verify the implementer's headline claim yourself** with one cheap command before packaging a
  review. One implementer reported DONE on a red suite.

## 6. Remaining tasks — what the ledger says you must carry

**Task 10 — M8 dependency install + M9 host-boot verification.** The riskiest remaining work after
Task 9. Two spec decisions drive it, both measured rather than assumed:
- **E48:** `composer dump-autoload` **does not install a newly required path package** — reproduced:
  `Generated autoload files`, then `class_exists(...) === false`. M8 must be a scoped
  `composer update vendor/name --no-scripts` when `composer.lock` exists, and a full install when it
  does not, with `composer.lock`, `vendor/composer/installed.json` and the generated autoload files
  **journaled**. `--no-scripts` is not optional: `post-autoload-dump` runs arbitrary host scripts
  outside the journal, and the demo's own `composer.json` runs `package:discover` there.
- **E49:** M9 must **boot the host** in a fresh process and assert the registry **already** maps the
  recorded type to the new FQCN, *before* any manual `register()` call. Calling `register()` yourself
  proves the class is valid, not that the provider was discovered. A `dont-discover` entry is refused
  at G6.
- The pre-flight ruling **F6** stands: `bootAndResolve()` may be exercised against a minimal Composer
  fixture rather than a full Laravel app, provided Task 13's demo run supplies the real-host evidence
  and the spec's §7.2 boundary statement is updated to say so.
- The real-Composer fixture proving `dump-autoload` insufficient **must not be mocked** — it is the
  whole reason E48 exists.

**Task 11 — narrowed by a ruling.** Task 7's implementer already registered
`nodeflow:make-node-package` in `NodeflowServiceProvider` (its feature tests need it to reach the
command through `artisan()`). **Task 11 registers `ExtractNodeCommand` only.**

**Tasks 12 and 13 — demo repo, and a worktree-isolated session cannot run git there.** The harness
refuses. Dispatch a subagent with `working_dir` set to `/Users/mikelmao/Sites/test-workflow`, or
`ExitWorktree` first. Ruling **F3** in the ledger.
- **Task 12:** move the demo's three nodes from the legacy `Nodeflow::register([...])` literal into
  `$nodes` **as short names**, keeping the existing `use` imports — deliberately, so the real-host run
  exercises the short-name removal path rather than the easy fully-qualified one. Gate: demo stays at
  **56 tests / 223 assertions**, silent `tsc`, passing `npm run build`.
- **Before trusting any demo gate**, assert `readlink -f vendor/atram/laravel-nodeflow` points at
  **this worktree**, not `~/Projects/laravel-nodeflow` on main. Any `composer install` in the demo
  re-points it at main, and the run would then exercise the wrong copy.
- **Task 13:** first a *deliberately failed* extraction restored by the command's own journal, with
  `git status` as an independent oracle no fixture test has; then the real extraction of
  `App\Nodeflow\Nodes\SendMessage`, **kept permanently** (E42). Verify `demo.send` still resolves to
  the new FQCN against the seeded published `flow_versions.graph` rows — that is E10's guarantee
  against real persisted data and the most valuable single piece of evidence in the plan.
- **Never `migrate:fresh` against the demo** — it destroys the developer's own login and passkeys.
- State plainly that the demo run does **not** exercise M3: the demo has no `tests/Feature/Nodeflow/`,
  so the test-move path is covered by synthetic fixtures only.

**Task 14 — docs, spec, open issues.** Beyond the plan's own list, the ledger requires:
- `docs/02-integration.md` Step 3 must say that `extract-node` now **refuses** when a node is
  registered outside `$nodes` — *at the place the permission was granted*, not only on the new page.
- **G-6 closed** per the corrected **E41**, recording that the first design draft's rationale for the
  shorter `PACKAGE_SOURCE` was false and reproduced as such.
- **G-7 explicitly still open** — E41 unified the constant but did **not** bound the match to the
  alias entry, independently re-confirmed during Task 1.
- **New open issue:** `appendTo()`'s whole-file presence defect, found by this plan's external review
  and fixed by **E50**, together with the one rewritten writer test.
- **New residual:** dynamic and database-stored class names are out of reach of the scan (**E46**),
  plus the newer limits recorded in `sharedScanRoots()`'s docblock.
- **New state:** the demo permanently has one node in a package and two in the host (**E42**).
- Untouched and must be stated as such: **G-5**, **G-8**, **G-9**, **G-11**, **G-12**, **D-1**,
  **D-2**, **G-3**.

## 7. After Task 14

Run the **whole-branch review** on the most capable model, over `git merge-base main HEAD..HEAD`,
pointing it at the ledger's deferred-minor and parked lines so it can triage what must be fixed
before merge. Plan 5's precedent: sixteen per-task reviews each passed their own brief, and the
whole-branch review then found four Criticals visible only when the steps were compared against each
other and against the real host. **Two cross-task findings have already occurred on this branch** —
Task 5 found a Critical in Task 3's reviewed-clean code, and Task 9 forced changes to Task 8's — so
expect more.

Then: final gates (package Pest, Vitest **160 unchanged**, silent `tsc`; demo 56/223, silent `tsc`,
passing build), write an execution record at
`docs/superpowers/plans/2026-08-21-node-packaging-execution-record.md`, and use
`superpowers:finishing-a-development-branch`. Merging to `main` needs `ExitWorktree` first. There is
no remote, no push, no PR.

Before deleting the workspace, collect **every** ledger line containing `Ruling:` into the final
message under "Rulings I made", in order, each with what it costs if wrong. That list is the only
place those ~62 decisions reach the user.

## 8. Environment traps, all paid for already

- **The org monthly spend limit has killed three subagents mid-work** in this effort. When it happens:
  check `git status` immediately, commit any partial work as clearly-labelled WIP rather than losing
  it or passing it off as complete, ledger the exact resume point, and tell the user — raising the
  limit is their call.
- **A worktree-isolated session refuses compound shell commands** it cannot prove stay inside the
  worktree. Symptom: "too complex to verify". Break into plain separate commands; a `VAR=...` prefix
  plus a heredoc plus a redirect is usually what trips it. Writing a fragment with the Write tool and
  `cat`-ing it in works when a heredoc will not.
- **`EnterWorktree` prefixes branch names** and its default `fresh` base ref cannot apply here (no
  remote); it branched from local HEAD in practice.
- The package sets `noUncheckedIndexedAccess: true`. Indexing a `Record<string, T>` yields
  `T | undefined`.
- **Do not commit `.superpowers/`** — gitignored scratch, and the reports are large.

## 9. What not to do

- Do not re-dispatch a completed task. The ledger's `Task <N>: complete` lines are authoritative, and
  a controller that lost its place has re-run whole completed sequences before — the most expensive
  failure observed in this workflow.
- Do not absorb **D-1**, **D-2** or **G-3** — tenant assertions on write and execution paths, all
  reserved for a dedicated security-hardening plan (**E26**).
- Do not attempt **G-5** browser acceptance unless the user asks; it needs a manual Chrome toggle they
  must click, and Chrome binds CDP to IPv6 loopback (`http://[::1]:9222/json/version`).
- Do not "simplify" a guard the ledger records as documented redundancy without reading why. Five
  such lines exist in `ExtractNodeCommand` alone, each deliberately kept and documented.
- Do not trust the plan's test-count predictions. They went stale by Task 2. Compute from the measured
  previous total, every time.
