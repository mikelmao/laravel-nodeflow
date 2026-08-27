# Uniform Audience Streaming Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an opt-in Nodeflow contract that executes a uniform-output audience in bounded memory, then prove Portia's 100,000-subject generic-message path through the real durable activity boundary.

**Architecture:** `HandlesUniformAudience` marks nodes whose successful result returns every input ID on one declared output. `NodeRunner` validates and releases each bounded result, retains scalar cross-chunk state, and transactionally records one aggregate execution plus one set-based cursor transition after all chunks succeed. Portia opts its generic message node into the contract and replaces its test-owned runner bypass with a real `RunNodeActivity` replay against PostgreSQL; only Yaya HTTP is faked.

**Tech Stack:** PHP 8.3+, Laravel 12/13, Eloquent, Orchestra Testbench, Pest 4, durable-workflow/workflow 2.0 RC, Composer VCS dependencies, PostgreSQL, SQLite, Laravel HTTP fakes, React/TypeScript/Vitest release gates, Git/GitHub CLI.

## Global Constraints

- Nodeflow implementation worktree: `/Users/mikelmao/Projects/laravel-nodeflow/.worktrees/portia-uniform-audience-streaming` on `feature/portia-uniform-audience-streaming`.
- Nodeflow base and PR target: `feature/nodeflow-integration` at `8cde0773484da60795ac03dbfc243ed96939f99b`; never target `main` during this vertical.
- Portia worktree: `/Users/mikelmao/Sites/portia-engine/.worktrees/portia-nodeflow-alert-vertical` on `feature/portia-nodeflow-alert-vertical`; its PR target remains `feature/nodeflow-integration`.
- Never edit Portia's installed `vendor/` tree. Consume the companion Nodeflow commit through Composer.
- `HandlesUniformAudience` is additive and opt-in. Do not change `HandlesAudience`, `HandlesSubject`, `AudienceContext`, `NodeResult`, durable activity arguments, or serialized workflow history.
- A successful uniform chunk returns all and only the chunk input IDs under one exact declared output, with no failures or second key. Order may differ; duplicates, omissions, additions, failures, mixed subject types, blank output, and undeclared output fail explicitly.
- Uniform execution must not populate `$seen`, merge chunk results, build an audience-sized `whereIn`, hydrate host subjects, or retain cross-chunk ID collections.
- Capture a scalar `nodeflow_run_subjects.id` high-water mark before iteration. A later insert is neither invoked nor transitioned by that execution.
- Do not hold a transaction or row lock across node/Yaya calls. After every chunk succeeds, one short transaction writes the aggregate execution projection and one set-based cursor transition.
- A late exception leaves every still-active source cursor unchanged and writes no execution projection. External replay safety remains the host node's deterministic idempotency responsibility.
- Do not claim to close the existing crash window after local transition commit but before activity-result acknowledgement. No activity-receipt migration is part of this change.
- No database migration, new configuration key, service-provider binding, data backfill, OpenSpec edit, or CodeAlmanac edit.
- TDD is mandatory: observe each named RED before production implementation, then rerun the identical test GREEN.
- The Nodeflow package does not declare Pint. Do not add it. Run `php -l`, focused/full Pest, Composer validation, Vitest, TypeScript, and `git diff --check`.
- Portia's existing Task 12 changes are user work. Preserve them and commit the completed Portia release proof once, with exact message `test: prove Portia Nodeflow readiness`.
- The expensive proof requires `PORTIA_NODEFLOW_SCALE_SUBJECTS >= 100000`, a disposable PostgreSQL URL in `PORTIA_TEST_PGSQL_URL`, and the real PostgreSQL driver. Unset count skips; invalid configured values fail; explicit SQLite skips with a PostgreSQL-only reason.
- In the 100k proof, fake only Yaya HTTP. Admission, tenant validation, materialization, registry, `RunNodeActivity`, `NodeRunner`, `GenericMessageNode`, submitter, models, and PostgreSQL are production paths.

## File Map

### Nodeflow

- Create `src/Nodes/HandlesUniformAudience.php`: additive marker and `audienceOutput(): string` contract.
- Create `src/Execution/UniformAudienceResultValidator.php`: bounded exact-result validation and privacy-safe invariant errors.
- Modify `src/Execution/NodeRunner.php`: early uniform branch, high-water chunking, scalar state, transactional aggregate projection, set-based transition.
- Create `tests/Support/FakeUniformAudienceNode.php`: configurable uniform test node with bounded call recording and late-failure hooks.
- Create `tests/Unit/UniformAudienceResultValidatorTest.php`: exact validator contract and privacy assertions.
- Modify `tests/Feature/NodeRunnerTest.php`: bounded success, terminal, late failure/replay, cancellation, high-water, mixed-type, query-shape, and legacy compatibility probes.
- Modify `docs/03-writing-nodes.md`: package-level API and retry guidance.
- Modify `docs/gitbook/building-automations/writing-nodes.md`: user-facing uniform-node guidance and limits.

### Portia

- Modify `composer.json` and `composer.lock`: pin the reviewed companion Nodeflow commit through its development branch until an immutable release exists.
- Modify `app/Nodeflow/Nodes/GenericMessageNode.php`: implement `HandlesUniformAudience` and declare `sent`.
- Modify `tests/Unit/Nodeflow/GenericMessageNodeTest.php`: assert the marker/output contract.
- Modify `tests/Feature/Nodeflow/PortiaNodeflowScaleTest.php`: truthful probes and real `RunNodeActivity` late-failure/replay proof.
- Preserve and finalize `.env.example`, `README.md`, `config/database.php`, and `docs/runbooks/portia-nodeflow-alerts.md`: current Task 12 environment, UTC PostgreSQL session, operational link, exact text/image limits, dependency blocker, and release commands.
- Update ignored `.superpowers/sdd/2026-08-27-portia-nodeflow-alert-vertical/task-12-report.md`: exact evidence, review verdicts, known baseline OpenRouter failures, build warnings, and dependency status.

---

### Task 1: Define and validate the uniform audience contract

**Files:**
- Create: `src/Nodes/HandlesUniformAudience.php`
- Create: `src/Execution/UniformAudienceResultValidator.php`
- Create: `tests/Unit/UniformAudienceResultValidatorTest.php`

**Interfaces:**
- Consumes: `Nodeflow\Nodes\HandlesAudience`, `Nodeflow\Execution\NodeResult`.
- Produces: `HandlesUniformAudience::audienceOutput(): string` and `UniformAudienceResultValidator::assertValid(string $nodeType, string $nodeId, string $expectedOutput, array $declaredOutputs, array $expectedSubjectIds, NodeResult $result): void`.

- [ ] **Step 1: Write the marker and validator tests before either class exists**

Create focused tests that compile a small anonymous uniform node and exercise every result shape. The key cases must contain these assertions:

```php
expect(is_subclass_of(HandlesUniformAudience::class, HandlesAudience::class))->toBeTrue();

$validator->assertValid(
    'test.uniform',
    'message',
    'sent',
    ['sent'],
    ['3', '1', '2'],
    NodeResult::partition(['sent' => ['2', '3', '1']]),
);

expect(fn () => $validator->assertValid(
    'test.uniform', 'message', 'sent', ['sent'], ['1', '2'],
    NodeResult::partition(['sent' => ['1', '1']]),
))->toThrow(RuntimeException::class, 'duplicate_ids');
```

Use a dataset for `blank_output`, `undeclared_output`, `failures`, `unexpected_output_keys`, `duplicate_ids`, and `missing_or_extra_ids`. For every thrown exception, assert the message contains node type, node ID, category, and expected output but does not contain sentinel subject ID `private-subject-9981` or configuration content.

- [ ] **Step 2: Run the validator test and capture RED**

Run:

```bash
vendor/bin/pest tests/Unit/UniformAudienceResultValidatorTest.php --compact
```

Expected: FAIL because `Nodeflow\Nodes\HandlesUniformAudience` and `Nodeflow\Execution\UniformAudienceResultValidator` do not exist. Record the command, exit code, and missing-class failure.

- [ ] **Step 3: Add the marker interface**

Create exactly this public contract:

```php
<?php

namespace Nodeflow\Nodes;

interface HandlesUniformAudience extends HandlesAudience
{
    public function audienceOutput(): string;
}
```

- [ ] **Step 4: Implement bounded exact-result validation**

Implement the declared `assertValid(...)` signature. Validate output first, then failures and exact keys, then duplicate IDs, then sorted string equality:

```php
if (trim($expectedOutput) === '' || ! in_array($expectedOutput, $declaredOutputs, true)) {
    $this->fail($nodeType, $nodeId, $expectedOutput, 'invalid_output');
}

if ($result->failures() !== []) {
    $this->fail($nodeType, $nodeId, $expectedOutput, 'failures');
}

$outputs = $result->outputs();
if (array_keys($outputs) !== [$expectedOutput]) {
    $this->fail($nodeType, $nodeId, $expectedOutput, 'unexpected_output_keys');
}

$actual = array_map('strval', $outputs[$expectedOutput]);
if (count(array_unique($actual, SORT_STRING)) !== count($actual)) {
    $this->fail($nodeType, $nodeId, $expectedOutput, 'duplicate_ids');
}

$expected = array_map('strval', $expectedSubjectIds);
sort($actual, SORT_STRING);
sort($expected, SORT_STRING);
if ($actual !== $expected) {
    $this->fail($nodeType, $nodeId, $expectedOutput, 'missing_or_extra_ids');
}
```

`fail(string $nodeType, string $nodeId, string $expectedOutput, string $category): never` throws `RuntimeException` using only those four values. It must never interpolate IDs, result failures, or node configuration.

- [ ] **Step 5: Run focused GREEN and syntax checks**

Run:

```bash
vendor/bin/pest tests/Unit/UniformAudienceResultValidatorTest.php --compact
php -l src/Nodes/HandlesUniformAudience.php
php -l src/Execution/UniformAudienceResultValidator.php
php -l tests/Unit/UniformAudienceResultValidatorTest.php
git diff --check
```

Expected: validator tests PASS; every `php -l` reports no syntax errors; diff check exits 0.

- [ ] **Step 6: Commit the contract independently**

```bash
git add src/Nodes/HandlesUniformAudience.php src/Execution/UniformAudienceResultValidator.php tests/Unit/UniformAudienceResultValidatorTest.php
git commit -m "feat: define uniform audience contract"
```

### Task 2: Add bounded uniform execution and one set-based transition

**Files:**
- Create: `tests/Support/FakeUniformAudienceNode.php`
- Modify: `tests/Feature/NodeRunnerTest.php`
- Modify: `src/Execution/NodeRunner.php`

**Interfaces:**
- Consumes: `HandlesUniformAudience::audienceOutput()` and `UniformAudienceResultValidator::assertValid(...)` from Task 1.
- Produces: unchanged `NodeRunner::run(Run $run, Graph $graph, string $nodeId): array`; uniform mode retains only scalar cross-chunk state and returns `list<string>`.

- [ ] **Step 1: Add a configurable uniform fake**

Create `FakeUniformAudienceNode extends Node implements HandlesUniformAudience` with exact public test state:

```php
public static string $output = 'sent';
public static array $chunks = [];
public static ?Closure $handler = null;

public static function type(): string { return 'test.uniform-audience'; }
public function audienceOutput(): string { return self::$output; }
public function definition(): NodeDefinition
{
    return NodeDefinition::make('Uniform audience')->outputs(['sent']);
}
public function forAudience(AudienceContext $context): NodeResult
{
    self::$chunks[] = $context->subjectIds();

    return self::$handler instanceof Closure
        ? (self::$handler)($context, count(self::$chunks))
        : $context->all(self::$output);
}
```

Add `reset(): void` and call it before each relevant test so static state never leaks.

- [ ] **Step 2: Write success, terminal, query-shape, and high-water tests**

Register the fake, set `nodeflow.limits.audience_chunk` to `2`, and add tests that assert:

```php
expect(FakeUniformAudienceNode::$chunks)->toBe([['1', '2'], ['3', '4'], ['5']])
    ->and($next)->toBe(['n2'])
    ->and($run->nodeExecutions()->count())->toBe(1)
    ->and($run->nodeExecutions()->first()->subject_count)->toBe(5);
```

Listen to `QueryExecuted` and assert the uniform success path issues exactly one `update` against `nodeflow_run_subjects`, that it contains no `subject_id in`, and that its binding count does not grow with 2,001 subjects. Add a terminal graph assertion that all processed rows become `completed` with a null cursor and the return is `[]`.

In a separate test, insert subject `99` from the first chunk handler. Assert it is absent from recorded chunks and remains active at the source node after the original high-water population advances.

- [ ] **Step 3: Run the success tests and capture RED against the legacy runner**

Run:

```bash
vendor/bin/pest tests/Feature/NodeRunnerTest.php --filter='uniform audience' --compact
```

Expected: FAIL. The old runner follows legacy aggregation, performs chunked `whereIn` updates for 2,001 IDs, and processes the above-high-water insert. Record at least one assertion failure that specifically distinguishes the old O(N) path.

- [ ] **Step 4: Route uniform nodes before legacy arrays are allocated**

Inject the validator as a third constructor dependency:

```php
public function __construct(
    private NodeRegistry $registry,
    private SubjectResolver $subjects,
    private UniformAudienceResultValidator $uniformResults,
) {}
```

Immediately after resolving the node/config/start time, before creating `$merged` or `$seen`, route:

```php
if ($node instanceof HandlesUniformAudience) {
    return $this->runUniformAudience(
        $run,
        $graph,
        $nodeId,
        $definition['type'],
        $config,
        $node,
        $node->definition()->outputNames(),
        $startedAt,
    );
}
```

Keep `RecordingNodeRunner` source-compatible: it overrides its constructor and unchanged three-argument `run()` signature, so no activity argument or test fake changes are permitted.

- [ ] **Step 5: Implement high-water chunking with scalar cross-chunk state**

Add a private `runUniformAudience(...)` method whose cross-chunk variables are exactly `$subjectType`, `$processedCount`, `$highWatermark`, `$output`, `$target`, and `$startedAt`. Use:

```php
private function runUniformAudience(
    Run $run,
    Graph $graph,
    string $nodeId,
    string $nodeType,
    array $config,
    HandlesUniformAudience $node,
    array $declaredOutputs,
    float $startedAt,
): array
```

Its query and callback begin:

```php
$base = RunSubject::query()
    ->where('run_id', $run->id)
    ->where('current_node_id', $nodeId)
    ->where('status', 'active');
$highWatermark = (clone $base)->max('id');

if ($highWatermark === null) {
    return [];
}

$base->where('id', '<=', $highWatermark)
    ->chunkById((int) config('nodeflow.limits.audience_chunk', 5000), function ($rows) use (
        &$subjectType,
        &$processedCount,
        $node,
        $nodeType,
        $nodeId,
        $run,
        $config,
        $output,
        $declaredOutputs,
    ): void {
        $types = $rows->pluck('subject_type')->map('strval')->unique()->values()->all();
        if (count($types) !== 1 || ($subjectType !== null && $subjectType !== $types[0])) {
            throw new RuntimeException("Uniform audience node [{$nodeType}] at [{$nodeId}] violated [mixed_subject_types].");
        }

        $subjectType ??= $types[0];
        $ids = $rows->pluck('subject_id')->map('strval')->all();
        $result = $node->forAudience(new AudienceContext($run, $nodeId, $config, $subjectType, $ids));
        $this->uniformResults->assertValid($nodeType, $nodeId, $output, $declaredOutputs, $ids, $result);
        $processedCount += count($ids);
    });
```

Do not capture an ID array by reference or assign to `$merged`/`$seen` in this method.

- [ ] **Step 6: Implement the short aggregate transaction**

After all chunks succeed, calculate duration once and use one transaction:

```php
DB::transaction(function () use ($run, $nodeId, $output, $processedCount, $durationMs, $subjectType, $highWatermark, $target): void {
    $run->nodeExecutions()->create([
        'node_id' => $nodeId,
        'output' => $output,
        'subject_count' => $processedCount,
        'duration_ms' => $durationMs,
    ]);

    RunSubject::query()
        ->where('run_id', $run->id)
        ->where('subject_type', $subjectType)
        ->where('current_node_id', $nodeId)
        ->where('status', 'active')
        ->where('id', '<=', $highWatermark)
        ->update($target === null
            ? ['status' => 'completed', 'current_node_id' => null]
            : ['current_node_id' => $target]);
});
```

Return `$target === null ? [] : [$target]`. Preserve the legacy `advance()` and `reconcileDepartures()` code byte-for-byte except for imports or extraction needed by the early branch.

- [ ] **Step 7: Run identical focused GREEN plus surrounding regressions**

```bash
vendor/bin/pest tests/Unit/UniformAudienceResultValidatorTest.php tests/Feature/NodeRunnerTest.php tests/Feature/RunNodeActivityTest.php --compact
php -l src/Execution/NodeRunner.php
php -l tests/Support/FakeUniformAudienceNode.php
git diff --check
```

Expected: all focused tests PASS; query guard reports one bounded set update; no syntax or whitespace failures.

- [ ] **Step 8: Commit the runner success path**

```bash
git add src/Execution/NodeRunner.php tests/Support/FakeUniformAudienceNode.php tests/Feature/NodeRunnerTest.php
git commit -m "feat: stream uniform audience execution"
```

### Task 3: Prove failure atomicity, cancellation safety, and compatibility

**Files:**
- Modify: `tests/Feature/NodeRunnerTest.php`
- Modify: `docs/03-writing-nodes.md`
- Modify: `docs/gitbook/building-automations/writing-nodes.md`

**Interfaces:**
- Consumes: the uniform runner from Task 2.
- Produces: regression coverage for retry/cancellation/compatibility and public host guidance; no new production API.

- [ ] **Step 1: Add a late-chunk failure and replay test**

Configure five IDs at chunk size two. Throw `RuntimeException('late uniform failure')` on call three after hashing each chunk. Assert the failed invocation leaves all five rows active at `n1`, writes zero node executions, and has seen `[['1','2'],['3','4'],['5']]`. Reset only the handler/call log, rerun, and assert one execution with count five and all rows at `n2`.

- [ ] **Step 2: Prove validation failures cannot mutate cursors**

Use the fake handler dataset to return wrong key, failure, duplicate, missing, and extra ID results. For each case assert `RuntimeException`, zero execution rows, and every original subject still active at `n1`. Add a mixed `subject_type` row and assert `mixed_subject_types` before any node call for that mixed chunk.

- [ ] **Step 3: Prove cancellation and high-water behavior**

From the first callback, mark subject `1` exited and insert subject `99`. Assert later original IDs are all visited exactly once, subject `1` remains exited, original active rows advance, and `99` remains active at `n1`. This is the counterexample for offset pagination and an unbounded live query.

- [ ] **Step 4: Run new tests RED against deliberate counterfactuals**

Temporarily make the uniform branch use `chunk()` instead of `chunkById()` and omit the high-water predicate. Run:

```bash
vendor/bin/pest tests/Feature/NodeRunnerTest.php --filter='uniform audience' --compact
```

Expected: cancellation/high-water tests FAIL. Restore production code immediately. Then temporarily move the local transaction before the chunk loop or apply the transition per chunk; the late-failure test must FAIL because cursors/execution state changed. Restore production code. These reversions are verification only and must not be committed.

- [ ] **Step 5: Run GREEN and legacy compatibility gates**

```bash
vendor/bin/pest tests/Feature/NodeRunnerTest.php tests/Feature/SubjectExiterTest.php tests/Feature/CanonicalJourneyTest.php tests/Feature/RunNodeActivityTest.php --compact
```

Expected: all pass. Existing ordinary audience partitioning, subject failures, `NodeResult::empty()` departure reconciliation, activity invocation, and canonical journey assertions remain unchanged.

- [ ] **Step 6: Document the opt-in contract and honest limitations**

In both writing-node guides, include this exact host shape:

```php
final class BroadcastNode extends Node implements HandlesUniformAudience
{
    public function audienceOutput(): string { return 'sent'; }

    public function forAudience(AudienceContext $context): NodeResult
    {
        $this->transport->sendIdempotently($context->runId(), $context->nodeId(), $context->subjectIds());

        return $context->all('sent');
    }
}
```

State that ordinary `HandlesAudience` remains O(N) in runner aggregation, uniform nodes must return the exact chunk or throw, external effects must be replay-idempotent, no transaction spans transport calls, and the change does not add an exactly-once activity receipt.

- [ ] **Step 7: Verify and commit compatibility/docs**

```bash
vendor/bin/pest tests/Feature/NodeRunnerTest.php tests/Feature/SubjectExiterTest.php tests/Feature/CanonicalJourneyTest.php tests/Feature/RunNodeActivityTest.php --compact
php -l tests/Feature/NodeRunnerTest.php
git diff --check
git add tests/Feature/NodeRunnerTest.php docs/03-writing-nodes.md docs/gitbook/building-automations/writing-nodes.md
git commit -m "test: prove uniform audience retries"
```

Expected: tests and checks PASS; commit contains only failure/compatibility coverage and documentation.

### Task 4: Gate and independently review the Nodeflow companion

**Files:**
- Modify only files named in Tasks 1-3 when fixing accepted review findings.

**Interfaces:**
- Consumes: completed Nodeflow contract/runner/tests/docs.
- Produces: reviewed companion commit merged through a PR into `feature/nodeflow-integration` and reachable by Portia Composer.

- [ ] **Step 1: Run complete Nodeflow gates**

```bash
vendor/bin/pest --compact
npm test
npm run types:check
composer validate --strict
for file in src/Nodes/HandlesUniformAudience.php src/Execution/UniformAudienceResultValidator.php src/Execution/NodeRunner.php tests/Support/FakeUniformAudienceNode.php tests/Unit/UniformAudienceResultValidatorTest.php tests/Feature/NodeRunnerTest.php; do php -l "$file" || exit 1; done
git diff --check feature/nodeflow-integration...HEAD
git status --short --branch
```

Expected: full Pest, 393-test Vitest baseline plus any additions, TypeScript, Composer, syntax, and diff checks PASS; the worktree is clean after committed implementation.

- [ ] **Step 2: Request an independent spec-mapping review**

Give the reviewer the approved design, this plan, `feature/nodeflow-integration...HEAD`, and these mandatory questions: does any uniform path retain O(N) IDs; can invalid/mixed results advance; are transaction and retry claims honest; are legacy contracts unchanged; are error messages privacy-safe? Require an explicit `READY` verdict. Fix accepted findings by adding a failing regression first, then the minimum code, rerun Task 4 Step 1, and commit each accepted correction as `fix: preserve uniform audience invariants`.

- [ ] **Step 3: Publish only after the review is READY**

Verify/create the remote integration base, push the reviewed feature, open its PR, require green checks, and merge only through that PR:

```bash
git ls-remote --exit-code --heads origin feature/nodeflow-integration || git push origin feature/nodeflow-integration:feature/nodeflow-integration
git push -u origin feature/portia-uniform-audience-streaming
NODEFLOW_PR_URL="$(gh pr create --base feature/nodeflow-integration --head feature/portia-uniform-audience-streaming --title "feat: stream uniform audience execution" --body "Adds the opt-in uniform audience contract and bounded NodeRunner path required by the Portia 100k production proof.")"
gh pr checks --watch "$NODEFLOW_PR_URL"
gh pr merge --merge "$NODEFLOW_PR_URL"
git fetch origin feature/nodeflow-integration
```

Expected: PR base is exactly `feature/nodeflow-integration`, all required checks pass, and `origin/feature/nodeflow-integration` contains the reviewed companion commit; no PR targets `main`. Record the PR URL, reviewed feature SHA, and merged integration SHA for Portia.

### Task 5: Opt Portia generic messages into the reviewed contract

**Files:**
- Modify: `/Users/mikelmao/Sites/portia-engine/.worktrees/portia-nodeflow-alert-vertical/composer.json`
- Modify: `/Users/mikelmao/Sites/portia-engine/.worktrees/portia-nodeflow-alert-vertical/composer.lock`
- Modify: `/Users/mikelmao/Sites/portia-engine/.worktrees/portia-nodeflow-alert-vertical/app/Nodeflow/Nodes/GenericMessageNode.php`
- Modify: `/Users/mikelmao/Sites/portia-engine/.worktrees/portia-nodeflow-alert-vertical/tests/Unit/Nodeflow/GenericMessageNodeTest.php`

**Interfaces:**
- Consumes: `Nodeflow\Nodes\HandlesUniformAudience` from the reviewed commit merged into `feature/nodeflow-integration`.
- Produces: `GenericMessageNode::audienceOutput(): string` returning `sent`; existing `forAudience(AudienceContext): NodeResult` unchanged.

- [ ] **Step 1: Write the Portia marker assertion and capture dependency RED**

Change the registration test to assert:

```php
$node = app(GenericMessageNode::class);
expect($node)->toBeInstanceOf(HandlesUniformAudience::class)
    ->and($node->audienceOutput())->toBe('sent')
    ->and($node->definition()->outputNames())->toBe(['sent']);
```

Run before updating Composer:

```bash
php artisan test --compact tests/Unit/Nodeflow/GenericMessageNodeTest.php --filter='registers one exact generic message audience node'
```

Expected: FAIL because the installed Nodeflow revision does not define `HandlesUniformAudience`.

- [ ] **Step 2: Pin the reviewed integration commit through Composer**

After Task 4 merges the companion PR, run from the Portia worktree:

```bash
git -C /Users/mikelmao/Projects/laravel-nodeflow fetch origin feature/nodeflow-integration
export NODEFLOW_SHA="$(git -C /Users/mikelmao/Projects/laravel-nodeflow rev-parse origin/feature/nodeflow-integration)"
composer require "atram/laravel-nodeflow:dev-feature/nodeflow-integration#${NODEFLOW_SHA}" --no-update
composer update atram/laravel-nodeflow --with-all-dependencies --no-interaction
composer show atram/laravel-nodeflow --all
php -r '$lock=json_decode(file_get_contents("composer.lock"), true, flags: JSON_THROW_ON_ERROR); foreach ($lock["packages"] as $package) { if ($package["name"] === "atram/laravel-nodeflow") { exit(($package["source"]["reference"] ?? null) === getenv("NODEFLOW_SHA") ? 0 : 1); } } exit(1);'
```

Expected: installed and locked source reference equals the reviewed commit on `origin/feature/nodeflow-integration`. Do not edit files under `vendor/`.

- [ ] **Step 3: Implement the minimal host opt-in**

Replace `HandlesAudience` with `HandlesUniformAudience` in the class declaration and add:

```php
public function audienceOutput(): string
{
    return 'sent';
}
```

Do not change `forAudience()`, command construction, error classification, artifact validation, or node definition.

- [ ] **Step 4: Run focused GREEN and integration regressions**

```bash
php artisan test --compact tests/Unit/Nodeflow/GenericMessageNodeTest.php tests/Feature/Nodeflow/GenericMessageExecutionTest.php tests/Feature/Nodeflow/PortiaNodeflowVerticalTest.php
vendor/bin/pint --test app/Nodeflow/Nodes/GenericMessageNode.php tests/Unit/Nodeflow/GenericMessageNodeTest.php
git diff --check
```

Expected: all focused tests and scoped Pint PASS; GenericMessage still emits identical Yaya commands and now advertises the uniform output.

Do not commit Portia yet; preserve the single Task 12 commit boundary required by the release plan.

### Task 6: Replace the scale bypass with a true durable activity replay

**Files:**
- Modify: `/Users/mikelmao/Sites/portia-engine/.worktrees/portia-nodeflow-alert-vertical/tests/Feature/Nodeflow/PortiaNodeflowScaleTest.php`

**Interfaces:**
- Consumes: real `DeliverRadaActivation`, `RunNodeActivity`, `GenericMessageNode`, `YayaMessageCommandSubmitter`, `BatchTenantResolver`, and uniform NodeRunner path.
- Produces: separate truthful admission/materializer/runner/hydration/resource metrics and two deterministic command replay hashes.

- [ ] **Step 1: Replace ambiguous probe fields with stage-specific counters**

Remove `maxOutstandingIds`, SQL-substring hydration, and manual `taskTwelveSubmitCommands()`. Add exact fields:

```php
public int $admissionPages = 0;
public int $admissionYieldedIds = 0;
public int $largestAdmissionPage = 0;
public int $materializerBatchCalls = 0;
public int $materializerValidatedIds = 0;
public int $largestMaterializerBatch = 0;
public int $scalarOwnershipCalls = 0;
public int $subjectInsertQueries = 0;
public int $subjectInsertedRows = 0;
public int $largestSubjectInsert = 0;
public int $runnerCalls = 0;
public int $runnerInputIds = 0;
public int $largestRunnerChunk = 0;
public int $largestRunnerResult = 0;
public int $commands = 0;
public int $largestCommand = 0;
public int $userHydrations = 0;
public array $snapshotHashes = [];
public array $replayHashes = [];
```

The tenant decorator increments scalar calls in `ownsSubject()` and all three materializer batch metrics in `ownedSubjectIds()`, while delegating to the real `PortiaTenantResolver`. Count inserted rows from bindings and assert the expected five bindings per run-subject row. Listen for `eloquent.retrieved: App\Models\User` to count real hydration events.

- [ ] **Step 2: Add a delegating submitter probe**

Because the production submitter is final, bind a test-only object under `YayaMessageCommandSubmitter::class` whose `submit(AudienceContext $context): NodeResult` records input/result sizes and delegates to the already-resolved real object:

```php
public function submit(AudienceContext $context): NodeResult
{
    $count = count($context->subjectIds());
    $this->probe->runnerCalls++;
    $this->probe->runnerInputIds += $count;
    $this->probe->largestRunnerChunk = max($this->probe->largestRunnerChunk, $count);
    $result = $this->inner->submit($context);
    $this->probe->largestRunnerResult = max($this->probe->largestRunnerResult, $result->subjectCount());

    return $result;
}
```

This is instrumentation, not a transport fake: every call must delegate to the real submitter. Resolve the real submitter before registering the decorator to avoid recursive container resolution.

- [ ] **Step 3: Make Yaya fail only the final command of attempt one**

Add `beginReplay(bool $failOnFinalCommand)` and per-attempt command position. Hash the complete command payload before selecting the response. On the first attempt's exact final command, return a retryable `503`; on all other commands return production-shaped `202`, with `duplicate=true` during replay two. Finish/finalize the hash even when the request throws.

Hash the single admission traversal separately from command attempts. Opaque cursors remain HMAC-signed and validated; page IDs remain canonical ascending.

- [ ] **Step 4: Write the real activity assertions and capture RED against the bypass**

Configure `nodeflow.limits.audience_chunk=500`, deliver/materialize through `DeliverRadaActivation`, then call:

```php
$probe->beginReplay(failOnFinalCommand: true);
expect(fn () => app(RunNodeActivity::class)->handle($run->id, 'message'))
    ->toThrow(RetryableMessageCommandException::class);
$probe->finishReplay();

expect($run->subjects()->where('current_node_id', 'message')->where('status', 'active')->count())->toBe($total)
    ->and($run->nodeExecutions()->where('node_id', 'message')->count())->toBe(0);

$probe->beginReplay(failOnFinalCommand: false);
app(RunNodeActivity::class)->handle($run->id, 'message');
$probe->finishReplay();
```

Assert after success: zero active rows at `message`, `$total` active rows at `exit`, one message execution row with output `sent` and count `$total`, two equal replay hashes, one snapshot hash/traversal, and two activity attempts. Run:

```bash
php artisan test --compact tests/Feature/Nodeflow/PortiaNodeflowScaleTest.php
```

Expected RED before completing the replacement: the old helper bypasses `RunNodeActivity`/`NodeRunner`, cannot produce the late-failure cursor assertions, and cannot populate runner metrics truthfully.

- [ ] **Step 5: Complete exact always-on and opt-in assertions**

For 2,001 subjects assert three 1,000-bounded admission pages, three materializer batches/inserts, five 500-bounded commands per activity attempt, runner/result max 500, zero scalar ownership calls, zero `User` hydrations, identical replays, and peak delta at most `16 * 1024 * 1024` bytes.

For configured 100,000+ subjects assert:

```php
->and($probe->admissionPages)->toBe((int) ceil($total / 1000))
->and($probe->admissionYieldedIds)->toBe($total)
->and($probe->materializerValidatedIds)->toBe($total)
->and($probe->subjectInsertedRows)->toBe($total)
->and($probe->largestAdmissionPage)->toBeLessThanOrEqual(1000)
->and($probe->largestMaterializerBatch)->toBeLessThanOrEqual(1000)
->and($probe->runnerCalls)->toBe(2 * (int) ceil($total / 500))
->and($probe->runnerInputIds)->toBe(2 * $total)
->and($probe->largestRunnerChunk)->toBeLessThanOrEqual(500)
->and($probe->largestRunnerResult)->toBeLessThanOrEqual(500)
->and($probe->commands)->toBe(2 * (int) ceil($total / 500))
->and($probe->largestCommand)->toBeLessThanOrEqual(500)
->and($probe->scalarOwnershipCalls)->toBe(0)
->and($probe->userHydrations)->toBe(0)
->and($probe->memoryDeltaBytes())->toBeLessThanOrEqual(32 * 1024 * 1024);
```

Keep invalid count cases `''`, `0`, `-1`, `1.5`, `99999`, `0100000`, and integer overflow as explicit failures. Check configured SQLite after database selection and skip with `The scale proof requires the PostgreSQL driver.`

- [ ] **Step 6: Run default GREEN and invalid/SQLite counterchecks**

```bash
php artisan test --compact tests/Feature/Nodeflow/PortiaNodeflowScaleTest.php
PORTIA_NODEFLOW_SCALE_SUBJECTS=0 php artisan test --compact tests/Feature/Nodeflow/PortiaNodeflowScaleTest.php
PORTIA_NODEFLOW_SCALE_SUBJECTS=100000 php artisan test --compact tests/Feature/Nodeflow/PortiaNodeflowScaleTest.php
```

Expected: default suite passes with only the expensive case skipped; `0` exits non-zero with the exact minimum-contract error; configured 100,000 without a PostgreSQL URL skips explicitly rather than passing a fake scale path.

Do not commit Portia yet.

### Task 7: Run real PostgreSQL proof, release gates, reviews, and final commits

**Files:**
- Finalize all Portia Task 12 files listed in the File Map.
- Update ignored `/Users/mikelmao/Sites/portia-engine/.worktrees/portia-nodeflow-alert-vertical/.superpowers/sdd/2026-08-27-portia-nodeflow-alert-vertical/task-12-report.md`.
- Modify only accepted-review files in either repository, with RED/GREEN evidence.

**Interfaces:**
- Consumes: complete Nodeflow companion and Portia activity proof.
- Produces: two READY reviews, exact release evidence, clean committed branches, and PRs whose base is `feature/nodeflow-integration`.

- [ ] **Step 1: Start/verify a disposable PostgreSQL database**

Use a local disposable database whose name contains `portia_task12`; never point migration/scale commands at a shared database. Export its URL and prove identity:

```bash
export PORTIA_TEST_PGSQL_URL='postgresql://postgres:postgres@127.0.0.1:55432/portia_task12'
case "$PORTIA_TEST_PGSQL_URL" in *portia_task12*) ;; *) exit 1;; esac
psql "$PORTIA_TEST_PGSQL_URL" -v ON_ERROR_STOP=1 -c 'select current_database(), version();'
```

Expected: database is `portia_task12`; record PostgreSQL version.

- [ ] **Step 2: Run the real 100k activity proof**

```bash
PORTIA_NODEFLOW_SCALE_SUBJECTS=100000 PORTIA_TEST_PGSQL_URL="$PORTIA_TEST_PGSQL_URL" php -d memory_limit=512M vendor/bin/pest tests/Feature/Nodeflow/PortiaNodeflowScaleTest.php --compact
```

Expected: PASS. Capture subjects, admission pages/hash, materializer batches/inserts, runner calls/chunks/results, commands/replay hashes, query classes, zero scalar calls, zero user hydrations, memory delta, package SHA, PostgreSQL version, and duration. The first real activity attempt must fail late without cursor changes; the second must transition all 100,000.

- [ ] **Step 3: Run all PostgreSQL race tests together**

```bash
PORTIA_TEST_PGSQL_URL="$PORTIA_TEST_PGSQL_URL" php -d memory_limit=512M vendor/bin/pest tests/Feature/Nodeflow/PostgresRadaActivationDeliveryTest.php tests/Feature/Nodeflow/PostgresRadaReconciliationTest.php tests/Feature/Nodeflow/WorkflowImagePostgresConcurrencyTest.php --compact
```

Expected: all three PostgreSQL race suites PASS under the `+00:00` application session timezone.

- [ ] **Step 4: Prove SQLite and PostgreSQL migration up/down/up**

Create a disposable SQLite file and run the current eleven Task migrations down/up, then repeat on the disposable PostgreSQL database:

```bash
TASK12_SQLITE="$(mktemp /tmp/portia-task12-sqlite.XXXXXX)"
trap 'rm -f "$TASK12_SQLITE"' EXIT
DB_CONNECTION=sqlite DB_DATABASE="$TASK12_SQLITE" php artisan migrate:fresh --force
DB_CONNECTION=sqlite DB_DATABASE="$TASK12_SQLITE" php artisan migrate:rollback --step=11 --force
DB_CONNECTION=sqlite DB_DATABASE="$TASK12_SQLITE" php artisan migrate --force
DB_URL="$PORTIA_TEST_PGSQL_URL" DB_CONNECTION=pgsql php artisan migrate:fresh --force
DB_URL="$PORTIA_TEST_PGSQL_URL" DB_CONNECTION=pgsql php artisan migrate:rollback --step=11 --force
DB_URL="$PORTIA_TEST_PGSQL_URL" DB_CONNECTION=pgsql php artisan migrate --force
EXPECTED_INDEXES='rada_audience_snapshot_key_unique|rada_delivery_due_index|rada_delivery_nodeflow_run_index|rada_delivery_stale_index|workflow_image_assets_reconcile_index|workflow_image_storage_operations_due_index'
test "$(sqlite3 "$TASK12_SQLITE" "select name from sqlite_master where type='index';" | grep -E "$EXPECTED_INDEXES" | sort -u | wc -l | tr -d ' ')" = 6
test "$(sqlite3 "$TASK12_SQLITE" "select count(*) from pragma_foreign_key_list('workflow_image_references');")" = 3
test "$(sqlite3 "$TASK12_SQLITE" "select count(*) from pragma_foreign_key_list('rada_activation_deliveries');")" = 1
test "$(psql "$PORTIA_TEST_PGSQL_URL" -Atc "select count(distinct indexname) from pg_indexes where indexname ~ '^(${EXPECTED_INDEXES})$';")" = 6
test "$(psql "$PORTIA_TEST_PGSQL_URL" -Atc "select count(*) from information_schema.table_constraints where constraint_type='FOREIGN KEY' and table_name in ('workflow_image_references','rada_activation_deliveries');")" = 4
DB_URL="$PORTIA_TEST_PGSQL_URL" DB_CONNECTION=pgsql php artisan tinker --execute="dump(DB::scalar('SHOW timezone'));"
```

Expected: all commands exit 0; both catalogs contain the six exact named indexes; SQLite and PostgreSQL each expose the three workflow-image-reference foreign keys plus the Rada-delivery-event foreign key; the application connection prints `+00:00`.

- [ ] **Step 5: Run the complete Portia release gate**

```bash
php artisan test --compact
php -d memory_limit=512M vendor/bin/pest --compact
vendor/bin/pint --test
vendor/bin/pint --test app/Nodeflow/Nodes/GenericMessageNode.php tests/Unit/Nodeflow/GenericMessageNodeTest.php tests/Feature/Nodeflow/PortiaNodeflowScaleTest.php config/database.php
npm run build
npm run types
npm test
composer validate --strict
composer install --dry-run --no-interaction
php artisan nodeflow:install --check
git diff --check
```

Expected: direct 512M Pest passes every in-scope Nodeflow/Rada assertion. Record the known inherited live OpenRouter 401 failures if they remain; do not describe a failing full command as green. Record exact default-memory `artisan test` and repository-wide Pint outcomes, while requiring the scoped Task 12 Pint gate, build, types, Vitest, Composer, installer, and diff checks to pass. Record existing Vite brand-asset/browser-externalization/large-chunk warnings without treating warnings as failures.

- [ ] **Step 6: Request two independent READY reviews in order**

Review 1 receives the approved spec, this plan, both diffs, dependency SHA, and all metrics. It maps every requirement and specifically verifies real `RunNodeActivity` traversal, no uniform `$seen`/merge, truthful counters, late-failure state, PostgreSQL proof, and dependency honesty.

After Review 1 is `READY`, Review 2 independently checks security, privacy-safe errors/telemetry, tenant isolation, transaction/cancellation races, retry/idempotency, crash-boundary wording, deployment order, rollback, runbook limits, and both PR targets. Require `READY` from both. Every accepted finding begins with a failing regression, then a minimal fix, focused GREEN, and relevant full gate rerun.

- [ ] **Step 7: Finalize the ignored report and commit Portia once**

Write exact commands, exit codes, counts, hashes, memory, duration, database/package versions, migration schema evidence, both reviewer verdicts, dependency blocker, OpenRouter baseline, and build warnings to the ignored Task 12 report. Then:

```bash
git add .env.example README.md config/database.php composer.json composer.lock docs/runbooks/portia-nodeflow-alerts.md app/Nodeflow/Nodes/GenericMessageNode.php tests/Unit/Nodeflow/GenericMessageNodeTest.php tests/Feature/Nodeflow/PortiaNodeflowScaleTest.php
git commit -m "test: prove Portia Nodeflow readiness"
git status --short --branch
```

Expected: Portia worktree clean; ignored report remains present but untracked by Git; commit contains only Task 12 production-readiness changes.

- [ ] **Step 8: Push Portia and open its integration PR**

After the final clean-tree check, push Portia and open its PR into the standing integration branch:

```bash
git push -u origin feature/portia-nodeflow-alert-vertical
gh pr create --draft --base feature/nodeflow-integration --head feature/portia-nodeflow-alert-vertical --title "test: prove Portia Nodeflow readiness" --body "Completes the Portia Nodeflow alert vertical with real durable-activity scale proof and operational release evidence."
```

Expected: Portia has one Task 12 commit, its lock source reference equals the already-reviewed `origin/feature/nodeflow-integration` commit, and the draft PR targets `feature/nodeflow-integration`, never `main`.
