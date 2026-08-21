# Plan 5 execution record

What actually happened when `docs/superpowers/plans/2026-08-21-nodeflow-remaining-tooling.md` was
executed, as distinct from what it said would happen. Kept because the two differ substantially and
the differences are the useful part.

Written at the close of execution, before merge. The plan and the spec
(`docs/superpowers/specs/2026-08-21-remaining-tooling-design.md`) remain the design authority; this
records the corrections execution forced on them.

- **Branch:** `worktree-plan-5-tooling`, 28 commits, branched from `f56f9e1`
- **Package:** 358 → **488** Pest tests, 5,832 → **6,152** assertions; Vitest unchanged at 160; silent `tsc`
- **Demo** (`~/Sites/test-workflow`): 49 → **56** Pest tests, 191 → **223** assertions; silent `tsc`; passing build

---

## 1. The headline lesson

**Every significant defect in this branch was found by execution. None was found by reading.**

The sharpest example: a `tsconfig.json` check that accepted a path climbing *out* of the project
(`../vendor/…` collapsed into a match, reporting a broken host as correctly wired). It survived twelve
tests, four executed counterfactuals, and a full code review that read the code and agreed with it. It
was caught when a reviewer was asked to construct inputs and run them.

The plan's *prose* held up well under execution. Its *code blocks* did not, at roughly one defect per
two tasks. Anyone reading this branch should weight the tests and the review trail above the plan's
authorship.

A second lesson, narrower but repeated four times: **a substring test standing in for real checking**
is this codebase's characteristic bug. `ltrim($v, './')` for path comparison; `str_contains` on a
path's tail; `str_starts_with` accepting `resources/jsx` for `resources/js`; `str_replace($basePath)`
stripping every occurrence rather than the leading prefix. Each looked obviously correct.

---

## 2. Rulings taken during execution

Twenty-two decisions made without stopping to ask, each recorded with what it costs if wrong.
**Fifteen are corrections to the plan document itself.**

| # | Decision | Cost if wrong |
|---|---|---|
| R1 | Use the existing `expectParseablePhp()` helper rather than the plan's open-coded `exec('php -l')`. `exec()` appends to `$output`, so a second call in one test reports the first file's output too | None — established convention, strictly safer |
| R2 | Branch is `worktree-plan-5-tooling`; the plan's merge command named a branch that does not exist | Loud merge failure only |
| R3 (revised) | Do **not** re-point the demo's vendor symlink. Measured: Plan 5's only changes to code the demo exercises are +14 comment lines in `BelongsToTenant` and +3 command registrations | A demo test depending on changed behaviour would test the wrong copy; the diff measurement was exhaustive over `src/` and `stubs/` |
| R4 | Recorded the verified `Flow::create()` attribute set rather than letting an implementer guess | Immediate test failure |
| R5 | `?string $body = null`. The plan's implicitly-nullable parameter is deprecated in PHP 8.4 and flipped the whole suite's summary from `365 passed` to `365 deprecated`, which would have masked real deprecations for thirteen tasks | None; correct 8.4 signature |
| R6 | The plan's Task 4 counterfactual (a) — "collapse `$nodes` to one line, expect failure" — is **not a discriminator**; spec §4.1 permits that form deliberately. Ordered a duplicate-anchor counterfactual instead, which discriminates the uniqueness half of the guard | None — added evidence |
| R7 | The additive provider edit must insert **fully-qualified** names; the plan's test asserted the short form against its own FQ-emitting code, so the test was unrunnable as written | None; FQ names always resolve |
| R8 | Removed `ProviderStep`'s injected `NodeRegistrationWriter` — zero uses. The plan claimed the step consumes `appendTo()`, but it cannot: `appendTo()` appends into an array, this step inserts whole declarations | None behavioural |
| R9 | `--force-migrations implies --publish-migrations` enforced in `MigrationStep`'s **constructor**, not left to the caller. The plan asserted the invariant and enforced it nowhere, so `apply()` could return `Writable` — a value `InstallOutcome`'s docblock says it never returns | None; removes a state no caller wants |
| R10 | Spec beats plan: the drift message names **both paths**, as §3.2.1 and §10 both require. The plan emitted basenames while its test was named "and names both paths" | None; more diagnostic output |
| R11 | The plan's test-count table went stale from Task 8 onward. Dispatches carry the arithmetic (previous measured total + this task's new tests), never the table | A task judged against a wrong expected count |
| R12 | **Critical.** `ltrim($value, './')` strips any leading *run* of `.` and `/`, so `../vendor/…` collapsed to a match and `check()` returned `AlreadyPresent` for a path above the project root. Replaced with segment comparison | A legitimate `..` mapping refused — loud, not silent; no real host has one |
| R13 | The `..` rejection must run on the **merged** baseUrl+target segment list, not the target alone — round 1's fix had moved the defect into `baseUrl` rather than closing it. Absolute paths rejected before segmentation | An exotic-but-valid `..` refused loudly |
| R14 | The CSS-entry search became recursive under `resources/`; the plan's own test placed the entry somewhere its own flat glob could never find | Ambiguity where before it was not-found — same outcome, different message |
| R15 | **Real bug, found by an ordered adversarial probe rather than by any test.** `str_replace($this->basePath, '', …)` strips *every* occurrence, so an entry at `<root>/resources/<projectname>/css/app.css` undercounted the `../` depth and emitted a path escaping the project | Fix direction strictly safer — a wrong count makes Tailwind match nothing |
| R16 | **Scope expansion, taken deliberately.** Fixed `MakeNodeCommand`'s cached `--type` — shipped in Plan 1. Proven by probe: two `Artisan::call` invocations in one process, the second passing `--type=yaya.leak_two`, produced a class whose `type()` returns `yaya.leak_one`, at exit 0. The spec says published flow versions resolve through that string forever | Visible scope creep, revertible in one commit. The alternative was knowingly shipping a proven silent-wrong-output bug in the file this plan was written to clean up |
| R17 | Persisted tests for two guards that shipped proven only by deleted throwaways. That is **F-2's own defect class**, which is in this plan's scope precisely because of it | Three extra tests, and a count higher than the table predicted |
| R18 | Refused to pad: two of the plan's three Task 14 tests already existed at `TenancyTest.php:157` and `:206`. Task 14 adds one test, not three | One fewer test than predicted, against a duplicate nobody wanted |
| R19 | The plan's demo security tests gave a **false pass** pre-fix, 404-ing on a URL the fix itself introduces. Genuine proof was obtained by a throwaway probe against the original route shape | None — the genuine proof exists and is recorded below |
| R20 | **Real bug.** `App\Models\User` carries `#[Fillable(['name','email','password'])]`, so the plan's `->update(['confirmed_interest_at' => now()])` silently no-ops. The fix would have scoped correctly and written nothing, with a green suite | `forceFill()` bypasses mass-assignment protection, so the call site carries an explicit caution against copying the pattern onto request-derived columns |
| R21 | The plan's demo counterfactuals do not isolate the route-binding fix, because two scoping layers are redundant. Reported rather than fabricated | The route-binding change must not be described as "proven load-bearing" |
| R22 | The final fix wave's own regression, fixed rather than parked at the user's direction: comparing the exact quoted `@source` string rejected correct double-quoted and trailing-slash forms — and Tailwind's own docs use double quotes | Now tolerant of lexical form while still strict about the path; the three wrong-prefix rejections were re-verified as still holding |

---

## 3. What the final whole-branch review caught

Sixteen per-task reviews each judged one brief in isolation and passed. The whole-branch review then
found **four Critical defects and one Important**, all false accepts or false rejects in
`nodeflow:install`'s exit code — the command's entire product, because CI scripts it and three of the
requirements it checks fail silently.

| # | Defect | Why the per-task reviews could not see it |
|---|---|---|
| C1 | `ProviderRegistrationStep`'s presence needle was the **fully-qualified** provider name, but the reference host's `bootstrap/providers.php` registers through an *import* and lists the short name. So `install --check` — the form the docs put in CI — exited **non-zero on a correctly wired host**, and `apply()` then wrote a permanent duplicate | Needed the real host's file. Every task fixture used the FQ form. Spec §3.4 explicitly argued this could not happen |
| C2 | `NodeRegistrationWriter`'s `return [` search was not anchored to statement start, and `appendTo()` never re-verified its write. A provider carrying `// e.g. return [ … ];` got the entry written **into the comment**: outcome `Appended`, exit 0, `php -l` status 255 | Recorded as a deferred *minor* during Task 3; materially worse than logged |
| C3 | `TailwindSourceStep` tested only the **tail** of the `@source` path, never the `../` prefix that decides whether Tailwind resolves anything. Three hosts read `AlreadyPresent` while Tailwind matched nothing — including the docs' own literal `'../../'` used from a nested entry | No shipped test ever fed `check()` a line it had not itself written |
| C4 | Comment-stripping (spec decision **E22**) was applied to the three steps reading JS/TS/CSS but **not** to the two reading PHP. Commented-out `boot()` calls and a commented-out `providers.php` entry both read `AlreadyPresent` — exit 0 on a host where nothing registers | Each task's brief specified its own step; nothing compared the five against each other |
| I1 | `TsconfigPathsStep` accepted `"@nodeflow/editor/*": [".../resources/js"]` — a wildcard mapping missing its `*`, which is exactly the quiet failure its own snippet warns about | The structural check verified the path, not the wildcard |

All five were reproduced by execution, fixed in one wave, and confirmed fixed by a scoped re-review
that also verified the reference host still passes all six checks read-only. Two cheap hardenings rode
along: `exitCode()` now fails on a residual `Writable` in both modes, and `MakeNodeCommand`'s
`ProviderMissing` message gained the `nodeflow:install` pointer its siblings already carried.

---

## 4. Demo security fix — the proof it was real

The demo's cross-tenant write is closed, and because the fix reshapes the route, the evidence would
have been lost. Captured before fixing:

> An **unauthenticated** POST to the original route shape `/nodeflow/subjects/{subject}/convert`
> returned **302** — not 404, not a login redirect — flipped another organisation's
> `confirmed_interest_at` from null to non-null, and moved their subject to `exited`.

After the fix, the same attack from an Acme session against a Globex run returns **404** with the
victim's row untouched and their subject still `active`; the legitimate own-organisation path still
returns 302 and writes.

**What the fix does not do**, stated so it is not mistaken for closed: an authenticated demo user can
still switch to another organisation deliberately. That is the switcher's purpose in a demo and is
recorded in `open-issues.md` as an accepted limitation.

---

## 5. Deferred minors, triaged at the final review

Cleared to ship: the Task 2 `--filter` overlap; Task 6's missing direct-`apply()` refusal tests (added
during the C1 fix anyway); Task 7's independent drift logic in `check()`, which is contractually
read-only and verified non-divergent; Task 8's `ViteConfigStep::apply()`, now structurally safe after
the `exitCode()` hardening; Task 11's missing `use` import; Task 16's non-isolated route-binding test.

Escalated to fix-before-merge: Task 3's minor became **C2**, and Task 10's became **C3**.

---

## 6. Known residuals

- `PublishConfigStep` and `MigrationStep` take opposite positions on the same question: an unpublished
  config reads `Writable` while an unpublished migration reads `AlreadyPresent`. Both are optional
  (`mergeConfigFrom` covers the config), so `--check` is red on a working host that never published it.
- Path logic is still duplicated across the steps that produced two of this branch's fix-round bugs:
  three `PACKAGE_SOURCE` constants with two different values, and two independent `segments()` helpers
  with different filtering rules.
- `ViteAliasStep` requires two facts that need never be adjacent — an alias pointing at the wrong
  directory plus any other mention of the vendor path reads as present. Its docblock discloses a
  narrower limit than the real one.
- The three generators' paste snippets use short class names while the additive edit inserts
  fully-qualified ones (R7's reasoning, opposite conclusion).
- `ViteConfigStep::CONFIG_CANDIDATES` prefers `vite.config.ts`; Vite itself resolves `.js` first.
- The writer's documented short-name gap — the comment behind C1 — was deleted during Task 3's
  refactor and exists nowhere in the tree, while spec §3.4 still cites it.
- Spec decision **E20**'s own arithmetic is wrong: it says "writes four things and verifies three" and
  then lists four verifies. §3.2's nine steps are five writers and four verifiers. Code and docs follow
  §3.2, which is the correct resolution.
