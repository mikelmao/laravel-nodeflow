# Plan 6 — packaging: session handoff prompt

Paste the whole of this file as the opening message of a new session. It is written to be
self-contained: everything a fresh session needs that is not already in the repository.

---

We're continuing work on laravel-nodeflow. Plan 5 is merged. The next and final piece of the
six-plan effort is Plan 6.

## Current state

**Package** — `~/Projects/laravel-nodeflow`, branch `main`, expected HEAD
`768023cc3a77fc9a7753adcf389a6c84920a4a98`. Clean baseline:
- 488 Pest tests, 6,152 assertions
- 160 Vitest tests
- `npx tsc --noEmit` silent

**Demo application** — `~/Sites/test-workflow`, branch `main`, expected HEAD
`58cd733cae33c8444b50bed1a0f17745a8b3dd42`. Clean baseline:
- 56 Pest tests, 223 assertions
- `npx tsc --noEmit` silent
- `npm run build` passes
- `vendor/atram/laravel-nodeflow` symlinks to `~/Projects/laravel-nodeflow`
- Local domain: http://test-workflow.test/
- **It has been through `nodeflow:install` for real.** Its provider now carries all three
  registration homes, and `config/nodeflow.php` is published.

Plans 1 through 5 are delivered: the node generator, the security floor, the React `FlowEditor`, the
read-only `FlowRun` run view, and the remaining tooling — `nodeflow:install`, `make-trigger`,
`make-subject-attribute`.

**Before designing or changing anything:**

1. Verify both repositories' branches, HEADs, cleanliness, the symlink, and the baseline counts. If
   reality differs, report it rather than silently adapting.
2. Check whether either repository contains an `almanac/` directory and consult it if present. (At
   Plan 5's close, neither did.)
3. Read the authoritative documents, in this order:
   - `docs/superpowers/plans/2026-08-21-nodeflow-remaining-tooling-execution-record.md` — **read this
     before Plan 5's plan document.** It records what execution corrected, and the corrections are
     the useful part.
   - `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md` — decisions **E9** and
     **E10**, and **§8**, which is Plan 6's specification. Read the "as built" block on §7.2 too.
   - `docs/superpowers/specs/2026-08-21-remaining-tooling-design.md` — Plan 5's design. **E23** and
     **E25** matter to you: they define the provider shape and the writer you will extend.
   - `docs/superpowers/open-issues.md` — the whole file. **G-5 through G-12 are Plan 5's residuals**
     and several sit in code you will touch.
   - `docs/02-integration.md` and `docs/08-editor-client.md` — what a host is told today.
4. `docs/prompts/plan-5-tooling.md`, `plan-3b-and-beyond.md` and `editor-and-node-tooling.md` are
   useful history and are now stale. Do not treat their counts or state as authoritative.

Use `superpowers:brainstorming` first. §8 is an outline, not an implementation design. Inspect the
shipped console commands, `NodeRegistrationWriter`, the stubs, and Plan 5's tests before proposing
anything.

Raise only decisions that materially affect the public contract, security, or scope. Use your
judgement on ordinary implementation details. Do not work around contradictions or blockers — say so.

Once the design is approved: `superpowers:writing-plans`, then review the plan rigorously, then
`superpowers:using-git-worktrees`, then execute with `superpowers:subagent-driven-development` under
strict TDD, requesting code-quality and spec-compliance review after each meaningful task, then
`superpowers:verification-before-completion` and `superpowers:finishing-a-development-branch`.

## Plan 6 scope and contract

### `nodeflow:make-node-package {vendor/name}` (§8.1)

Scaffolds an ordinary Composer package (**E9**): a `composer.json` requiring
`atram/laravel-nodeflow`, a service provider calling `Nodeflow::register()`,
`extra.laravel.providers`, an optional `resources/js/index.ts` exporting a `controls` object, a README
documenting both the provider and the controls spread, and a test directory.

**There is no manifest, and E9 explains why**: everything a manifest would declare is already
declared where it works — compatibility by `require`, provider loading by `extra.laravel.providers`,
node identity by `type()` plus explicit registration. The one failure a manifest appears to guard
against, a host registering the PHP nodes and forgetting to spread the controls, is already caught
loudly by §5.7's `Unregistered` control naming the exact missing field type. PHP cannot see whether a
JSX spread happened, so a manifest cannot detect it better.

### `nodeflow:extract-node {FQCN} --package=vendor/name` (§8.2)

Scaffolds, then moves: the class and its test, the namespace rewrite, the provider's register array,
and a path repository plus `require` in the host's `composer.json` so the host keeps working from the
new location.

**Its most important check has nothing to do with files (E10).** `type()` is the stable identifier
that immutable graph versions and live mid-wait runs resolve through, and the foundation spec §5 is
explicit that it must never derive from the class name. So extraction must guarantee `type()` is
byte-identical afterwards, and must **refuse outright** if `type()` does not return a plain string
literal — a `type()` computed from `static::class` silently changes identity the moment the namespace
moves, orphaning every published version that references it. That is the one failure this command
could cause that re-running it cannot repair.

Verification after the move: `composer dump-autoload`, assert the new FQCN resolves, assert
`NodeRegistry::register()` accepts it, assert `type()` unchanged. Any failure aborts and restores.
The command never leaves a half-moved state.

### The thing §8 does not tell you, and it is the hard part

**`extract-node` must REMOVE an entry from the provider's `$nodes` array. Nothing in the package can
do that yet.** `NodeRegistrationWriter` only appends. Removal is materially harder than appending and
every lesson below applies to it:

- It must not remove a *different* entry whose name merely contains the target's
  (`SendSms::class` versus `SendSmsExtra::class`).
- It must recognise the entry in every form a host may have written it — fully qualified with or
  without a leading backslash, or the bare imported short name. **The short-name case is a known,
  proven trap**: Plan 5's whole-branch review found `install --check` failing on the reference host
  for exactly this reason, because the reference host's `bootstrap/providers.php` registers through
  an import. Read **G-10**.
- It must not match inside a comment. Plan 5 shipped `SourceText::withoutPhpComments()` for this.
- It must leave the file byte-identical when it refuses, and re-verify that the result still parses
  when it writes — the writer now does the latter and returns a `WriteFailed` outcome.
- And the host may have the class listed in a legacy `Nodeflow::register([...])` literal instead of
  `$nodes`, because Plan 5's `install` deliberately leaves such calls alone (**E25**). The demo has
  both shapes in one provider right now. Decide what extraction does about that, explicitly.

### Interfaces Plan 5 shipped that you will build on

`Nodeflow\Console\NodeRegistrationWriter`:
- `public const ANCHOR = 'protected array $nodes = ['`
- `public const TRIGGER_ANCHOR = 'protected array $triggers = ['`
- `public const ATTRIBUTE_ANCHOR = 'protected function subjectAttributes(): array'`
- `register(string $providerPath, string $nodeClass): NodeRegistrationOutcome`
- `appendTo(string $providerPath, string $anchor, string $presenceNeedle, string $entry, string $indent = '        '): NodeRegistrationOutcome`

`Nodeflow\Console\NodeRegistrationOutcome` has **six** cases, not five —
`Appended`, `AlreadyPresent`, `ProviderMissing`, `AnchorMissing`, `AnchorAmbiguous`, and
**`WriteFailed`** (added when the writer gained post-write verification). Every `match` over this enum
must handle all six or PHP throws `UnhandledMatchError`.

`Nodeflow\Console\SourceText` — `withoutJsComments()`, `withoutCssComments()`, `withoutPhpComments()`.
Use these; do not write a fourth stripper.

`Nodeflow\Console\Install\*` — nine steps behind `nodeflow:install`, each with `describe()`,
`check()`, `apply()`, `snippet()` and an `InstallOutcome` of `AlreadyPresent | Writable | Wired |
CannotWire`. Worth reading as prior art for "verify, then report honestly".

### Three constraints inherited from Plans 1 and 5

- **`handle(): int` on every command.** Returning `false` from a Laravel `handle()` is cast with
  `(int)` and exits **0**. A refusal that exits 0 is the failure mode `extract-node` can least afford,
  since **E10**'s whole point is refusing a dangerous move.
- **Reset any instance-cached state at the top of `handle()`.** Symfony resolves one command object
  per name and keeps it for the process's lifetime. Both `MakeNodeCommand` and `MakeTriggerCommand`
  shipped a leak where a second `Artisan::call` in one process inherited the first's validated values
  and skipped validation entirely — generating a node with a permanently wrong `type()` at exit 0. It
  is fixed in both; do not reintroduce it.
- **`--type` and output names are pattern-validated; `--group` is escaped, not rejected.** Read
  `MakeNodeCommand` before inventing new validation.

### Also worth folding in, if the design supports it

**G-6** — three `PACKAGE_SOURCE` constants holding two different values, and two independent
`segments()` helpers with different filtering rules, across the install steps. Two of Plan 5's
fix-round defects were path-arithmetic bugs in those classes and the logic was never shared. If Plan 6
touches path handling — and `extract-node` moves files, so it will — sharing one normalised helper is
the structural fix.

**G-10** — the writer's short-name gap is closed for `bootstrap/providers.php` but its generic
behaviour and its documentation are both unrestated, and spec §3.4 still cites a comment that was
deleted. You will be reasoning about exactly this gap; fix the record while you are in there.

### Explicitly out of scope

- **D-1**, **D-2** and **G-3** — all three are tenant assertions on write or execution paths, all
  approved, all unimplemented, and all reserved for a dedicated security-hardening plan. **Do not
  absorb them.**
- **G-5** — browser acceptance for Plan 5 never ran, and it needs a manual Chrome toggle. It is
  tracked; it is not yours unless you choose to clear it as a favour, in which case read G-5's own
  instructions rather than Plan 4's stale workaround.
- Everything §11 of the editor spec lists as out of scope, and the C-series limitations C-1 through
  C-6. **C-5** and **C-6** are Plan 4's two honest `reached` limitations — preserve them, do not
  redesign `reached`, and note that closing either means writing to the durable execution path.
- `make-flow` and `make-field-control`, with §7.3's reasons.

## Environment traps, all paid for already

- **A fresh git worktree has no dependencies.** `vendor/`, `node_modules/`, `.env` and
  `public/build/` are all gitignored. The demo needs all four before any gate runs, and 15 of its
  tests render Blade through `@vite`, so a missing build fails them with a Vite-manifest error rather
  than anything informative.
- **`EnterWorktree` prefixes the branch name.** Asking for `plan-6-packaging` produces
  `worktree-plan-6-packaging`, and the repo has **no remote**, so the tool's default `fresh` base ref
  (which branches from `origin/<default>`) cannot apply. It branched from local HEAD in practice.
- **A worktree-isolated session cannot run git in another directory.** The harness refuses. Merging
  to `main` therefore needs `ExitWorktree` first, and demo-repo git work must be done by a subagent
  or after exiting.
- **The demo's `composer.json` hardcodes a path repository at `~/Projects/laravel-nodeflow`.** Any
  `composer install` re-points `vendor/atram/laravel-nodeflow` at **main**. Assert `readlink -f`
  before trusting any demo gate. Plan 5 measured that a re-point is *unnecessary* when the plan
  changes no runtime path the demo exercises — measure before assuming either way.
- **`npm install` in a differently-named worktree rewrites `package-lock.json`'s `name` field.** Do
  not commit it.
- **The demo's test suite cannot start a run under `QUEUE_CONNECTION=sync`.** The durable engine
  throws `UnsupportedBackendCapabilitiesException`. Set `config(['queue.default' => 'database'])` and
  drain with `Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1])` — see
  `driveRun()` in the demo's `tests/Feature/NodeflowRunViewTest.php`.
- **Never `migrate:fresh` against the demo.** It destroys the developer's own login account and
  passkeys.
- **`App\Models\User` in the demo carries `#[Fillable(['name','email','password'])]`**, so a plain
  `->update()` on any other column silently no-ops. Plan 5 shipped a fix that would have written
  nothing because of this. `forceFill()->save()` is the workaround, and the call site carries a
  caution against copying it onto request-derived columns.
- **Browser acceptance needs a manual Chrome toggle.** Chrome 151 requires "Allow remote debugging"
  even for a separate instance launched with `--remote-debugging-port` and a throwaway profile, which
  is the workaround Plan 4 recorded as sufficient. It no longer is. Chrome also binds the CDP endpoint
  to **IPv6** loopback — use `http://[::1]:9222/json/version`, not `127.0.0.1`.
- The package sets `noUncheckedIndexedAccess: true`. Indexing a `Record<string, T>` yields
  `T | undefined`.
- Worktrees must ultimately be merged and retested on `main`. No remote, push or PR is configured.

## Testing discipline — read the execution record, then this

Spec §9's rule stands: for every test, name the production change that would make it fail, and
**execute it**. Plan 5 proved something sharper, and it is the single most useful thing to carry
forward:

**Every significant defect in Plan 5 was found by execution. None was found by reading.**

A `tsconfig.json` check that accepted a path climbing *out* of the project survived twelve tests, four
executed counterfactuals, and a full code review that read the code and agreed with it. It was caught
when a reviewer was asked to *construct inputs and run them*. So:

- **Order adversarial probes explicitly.** Three of Plan 5's real defects were found only because an
  implementer was told "before you commit, try to break your own rule" and given specific angles.
  Free-form review did not find them.
- **The characteristic bug of this codebase is a substring test standing in for real checking.** It
  appeared four times in Plan 5: `ltrim($v, './')` for path comparison; `str_contains` on a path's
  tail; `str_starts_with` accepting `resources/jsx` for `resources/js`; `str_replace($basePath)`
  stripping every occurrence rather than the leading prefix. Every one looked obviously correct.
  `extract-node` does path and namespace surgery. Assume this bug is present and go find it.
- **A test can pass against the very bug it names.** Plan 5's demo security tests gave a false pass
  pre-fix by 404-ing on a URL the fix itself introduces. If a fix changes the route, the shape, or the
  signature a test exercises, the pre-fix run proves nothing — get proof against the *old* shape.
- **A counterfactual that does not fail is information, not an embarrassment.** Three of Plan 5's
  named counterfactuals turned out not to discriminate; each was reported honestly and replaced. Say
  so plainly rather than fabricating a match.
- **Every production guard ships with a covering test.** Proving a guard load-bearing in a throwaway
  and deleting the evidence leaves code anyone can delete on a green suite — that is F-2's defect
  class, and Plan 5 reproduced it twice before catching it.
- **A whole-branch review at the end is not optional.** Sixteen per-task reviews each passed their
  own brief; the final whole-branch review then found **four Critical** false accepts or false rejects
  that only became visible when the steps were compared against each other and against the real host.
- **Keep exact test arithmetic, and distrust the plan's own count table.** Plan 5's table went stale
  by task eight because fix rounds legitimately added tests. Carry the arithmetic in each dispatch —
  previous measured total plus this task's new tests — and record measured assertion counts rather
  than predicting them. A count that comes in *higher* is worth reading, not rounding away.
- **Run the command against the real host.** Plan 5's most valuable single piece of evidence was
  running `install` against the demo: it proved the additive-edit claim on a genuine hand-written
  provider, and it is where the worst defect would have surfaced had the fix wave not caught it first.
  `extract-node` has an equivalent, and it is riskier: **it moves real files.** Decide early how you
  will exercise it against something real without destroying the demo, and how you will restore.
