# Uniform Audience Streaming Design

Date: 2026-08-28

Status: approved for implementation planning

Branch: `feature/portia-uniform-audience-streaming`

PR target: `feature/nodeflow-integration`

Baseline: `8cde0773484da60795ac03dbfc243ed96939f99b`

## Purpose

Nodeflow currently bounds the input given to an audience node, but `NodeRunner` then retains every
visited subject ID in `$seen` and merges every chunk result into one audience-sized `NodeResult`.
Portia's generic message node therefore has O(N) runner memory even though Yaya requests and commands
are individually bounded. This prevents an honest 100,000-subject production-path readiness proof.

This change adds an opt-in execution contract for audience nodes that emit every input subject to one
declared output. The runner will validate and discard each bounded chunk result, retain scalar state
only, and perform one set-based local transition after every chunk has succeeded. Existing audience
and per-subject node contracts remain unchanged.

The design is intentionally narrow. It solves the generic-message vertical without pretending that
arbitrary partitioning audience nodes can be streamed without durable per-subject output storage.

## Public contract

Add `Nodeflow\Nodes\HandlesUniformAudience`:

```php
interface HandlesUniformAudience extends HandlesAudience
{
    public function audienceOutput(): string;
}
```

`audienceOutput()` returns the exact graph output name used for every successfully processed subject.
The value must be a non-empty string and must be one of the node definition's declared outputs. It is
configuration-independent: one node class cannot choose different uniform outputs for different
chunks or runtime data. A node needing that behavior must continue using ordinary `HandlesAudience`.

The inherited `forAudience(AudienceContext $context): NodeResult` method remains the execution method.
For every successful call it must return all and only `AudienceContext::subjectIds()` under the exact
`audienceOutput()` key, with no failures and no second output key. Order is not significant; duplicates,
missing IDs, extra IDs, even empty extra output buckets, and failures violate the contract. Throwing from
`forAudience()` keeps its current meaning: the durable activity attempt fails and may be retried under
the node's published activity policy.

`NodeResult`, `AudienceContext::all()`, `HandlesAudience`, and `HandlesSubject` do not change. The new
interface is opt-in, so existing host nodes continue through the legacy aggregation path with identical
behavior.

## Runner algorithm

`NodeRunner::run()` resolves the graph definition and node as it does today. When the node implements
`HandlesUniformAudience`, it selects a dedicated uniform path; all other nodes use the existing path.

The uniform path performs these steps:

1. Read and validate `audienceOutput()` once, before invoking the node. Resolve its first graph target
   with the same graph semantics used by the existing `advance()` method.
2. Build the existing active-subject query scoped by run ID, current node ID, and `status = active`.
   Capture the query's current maximum `nodeflow_run_subjects.id` as a scalar high-water mark. No row
   inserted after execution starts may be called or advanced by this invocation.
3. Iterate through that fixed range with `chunkById(nodeflow.limits.audience_chunk)`. Each chunk holds
   only its Eloquent rows, string subject IDs, `AudienceContext`, and returned `NodeResult`.
4. Establish the subject type from the first row. Reject a chunk containing another type, or a later
   chunk with a different type. Nodeflow runs are expected to contain one audience type; failing loudly
   is safer than passing an ID to the wrong host resolver.
5. Invoke `forAudience()`, validate its result against the current chunk, increment a scalar processed
   count, and release the rows, IDs, context, and result before loading the next chunk. Validation sorts
   bounded copies with string comparison so order may differ while duplicate, missing, and extra IDs
   remain detectable.
6. If there were no eligible rows, return an empty next-node list and write no execution record, which
   preserves current empty-node behavior.
7. After all chunks succeed, open one short database transaction. Write the single aggregate
   `nodeflow_node_executions` row for the declared output and processed count, then issue one set-based
   update scoped by run ID, subject type, source node ID, active status, and the captured high-water mark.
   If the graph output has no target, mark the rows completed and clear their cursor; otherwise move them
   to that target. Commit both local writes together.
8. Return the target node once, or an empty list for a terminal output.

The uniform path never populates legacy `$seen`, never merges chunk results, never constructs an
audience-sized `whereIn`, and never hydrates host subject models. Its resident ID collections are bounded
by `nodeflow.limits.audience_chunk`; its cross-chunk state is the subject type, output, high-water mark,
processed count, duration, and target.

The final update count can be lower than the processed count when a subject is concurrently exited after
its external command is accepted. The execution row retains the current semantic—subjects the node
reported on its output—not the number of rows still eligible for the conditional transition. Rows no
longer active at the source node are never resurrected or moved.

## Transactions, retries, and crash boundaries

No database transaction or row lock remains open while the node calls an external system. A failure on
any chunk occurs before local advancement, so every still-active source row remains at the node and no
aggregate execution row is written. The durable engine may replay the whole activity. Side-effecting
nodes must therefore use stable per-chunk or per-subject idempotency keys, as Nodeflow already requires.

Portia derives Yaya command identities from immutable run, node, artifact, and ordered chunk inputs. If
Yaya accepts a command and the response is lost, or a later chunk fails, the retry sends the same payload
and command identity. Yaya returns the existing accepted command rather than creating duplicate work.
Only after all command responses are accepted does Nodeflow advance the local audience.

The aggregate execution insert and set-based subject update are one short local transaction. A database
error rolls both back; a retry then safely repeats deterministic external commands. A worker crash before
that transaction leaves the entire source audience eligible. A crash after its commit but before the
durable activity result is acknowledged has the same boundary as the existing `NodeRunner::advance()`:
the local subject transition is committed and the external commands remain idempotent, but Nodeflow does
not add a new activity receipt or exactly-once result protocol in this change. This feature must not claim
to close that pre-existing crash window. Closing it for loops and multi-output nodes requires a stable
logical activity-execution identity and is separate engine work.

`chunkById` plus the high-water mark also preserves current cancellation safety: deleting or exiting a
row cannot shift later offset pages, and a row added after the invocation begins is left for a later
execution rather than advanced without a node call.

## Validation and errors

Uniform-result validation happens immediately after each node call and before any local transition.
Violations throw `RuntimeException` with the node type, node ID, expected output, and violation category.
Messages must not contain subject IDs or node configuration because host applications may persist or log
the exception. Categories distinguish invalid output name, mixed subject types, failures, unexpected
output keys, duplicate IDs, and missing or extra IDs.

There is no fallback to legacy accumulation when a class opts into `HandlesUniformAudience`. Falling
back would turn a contract bug into an unbounded production path. The durable activity applies the node's
already-published attempts, backoff, timeout, and non-retryable error list exactly as it does today.

The output is validated both against the node definition and each returned `NodeResult`. Graph target
resolution remains unchanged: the first target for that output is used, and no target means completion.

## Compatibility and migration

This is an additive PHP API. Existing implementations of `HandlesAudience` do not need to change.
Existing `NodeResult` construction, merge behavior, audience context methods, published graphs, activity
arguments, and serialized durable history are untouched. A host opts in only by implementing the new
interface and method after installing a package version that contains them.

No database migration, configuration key, service-provider binding, workflow history rewrite, or data
backfill is required. `nodeflow.limits.audience_chunk` remains the bound. Deploy the compatible package
before deploying a host node that implements the marker; old workers must be drained or restarted so
they never autoload the host class without the new interface.

The ordinary audience path remains O(N) by design because it supports arbitrary partitions, failures,
and departures. Documentation must describe the new marker as a performance contract, not a universal
replacement for `HandlesAudience`.

## Portia integration

Portia's `App\Nodeflow\Nodes\GenericMessageNode` will implement `HandlesUniformAudience` and return
`'sent'` from `audienceOutput()`. Its existing `forAudience()` continues to call the production
`YayaMessageCommandSubmitter`, which returns the current chunk as `sent` only after every bounded Yaya
command is accepted. Its validation, artifact contract, external error types, and command payload are
unchanged.

Portia must update its Composer lock to the immutable Nodeflow commit containing this API. Until the
Nodeflow branch is merged and an immutable compatible release is available, Portia must retain and
document the package commit as a release blocker rather than selecting the incompatible `v0.0.1` tag.
Installed `vendor/` code is never patched.

## Production-path 100,000-subject proof

The Portia release proof must enter through real application boundaries:

1. `DeliverRadaActivation` uses the production Yaya snapshot stream, admission, batch tenant resolver,
   Nodeflow run starter, and audience materializer against disposable PostgreSQL.
2. The only fake is Yaya's HTTP transport. It emits 100,000 canonical IDs through signed opaque cursors
   in 1,000-ID pages and accepts deterministic message commands of at most 500 IDs.
3. The generic message is executed through the real `RunNodeActivity`, `NodeRunner`, registry,
   `GenericMessageNode`, and `YayaMessageCommandSubmitter`. A test decorator around the real submitter may
   observe calls but must delegate every call to it.
4. The first activity attempt fails on the final Yaya command after recording its payload hash. It must
   leave all subjects active at the message node and write no message execution row. The second attempt
   succeeds, produces the identical ordered command-payload hash, writes one aggregate execution row,
   and moves all subjects to the `exit` node. Snapshot traversal occurs once because materialization is
   durable; command traversal occurs twice because the node activity is replayed.

Metrics have separate, truthful meanings rather than one synthetic `maxOutstandingIds` value:

- admission: pages, yielded IDs, largest Yaya page, opaque-cursor validation, and snapshot-stream hash;
- materializer: batch resolver calls and IDs, scalar ownership calls, insert statements and rows per
  statement, and created run-subject count;
- runner: audience-node calls, IDs admitted to each `AudienceContext`, largest returned result, Yaya
  commands and largest command, query types, execution-row count, source/target subject counts, and both
  replay hashes;
- hydration: an Eloquent `retrieved` listener on the host `User` model, not a SQL substring heuristic;
- resources: start/peak PHP memory delta and wall duration for the entire real path.

The always-on 2,001-subject guard asserts the same structural bounds with SQLite and includes a modest
memory ceiling. The opt-in proof requires `PORTIA_NODEFLOW_SCALE_SUBJECTS >= 100000`, a non-empty
`PORTIA_TEST_PGSQL_URL`, and an active PostgreSQL driver. Unset scale count skips the expensive case;
malformed, zero, negative, fractional, leading-zero, overflow, or sub-minimum values fail explicitly;
an explicitly configured SQLite run skips with a PostgreSQL-only reason.

Memory is supporting evidence, not the sole no-buffer proof. The decisive evidence is the real activity
path plus runner/node/materializer chunk counters and package regressions that would fail if uniform mode
reintroduced `$seen` or merged output IDs. The report records exact pages, chunks, query classes,
hydrations, hashes, memory, duration, database version, package SHA, and race/migration gates.

## Tests

Nodeflow package tests are written RED first and cover:

- a uniform fake receiving several chunks while the runner records one aggregate execution and advances
  every row with the declared graph output;
- terminal uniform output completion;
- exact chunk bounds and scalar cross-chunk state under a large synthetic audience;
- output order independence;
- rejection of blank or undeclared output, failures, extra output keys, duplicate IDs, missing IDs,
  extra IDs, and mixed subject types, with no local transitions or execution row;
- a late-chunk exception leaving the entire audience at the source node, followed by an idempotent replay
  that succeeds;
- a row exited during iteration remaining exited while later rows are neither skipped nor resurrected;
- a row inserted above the captured high-water mark remaining at the source node and never being passed
  to the node;
- unchanged behavior for ordinary `HandlesAudience`, `HandlesSubject`, empty results, failures, and
  departure reconciliation;
- `RunNodeActivity` continuing to apply the pinned retry policy and call the real runner boundary.

Portia tests are written RED against the old package first, then updated only after the Nodeflow commit is
installed through Composer. They cover the real PostgreSQL replay described above, the always-on SQLite
guard, exact environment parsing, real batch-resolver instrumentation with zero scalar calls, zero host
model hydrations, and existing PostgreSQL activation/delivery/image races. Full package and Portia release
gates remain required.

## Rejected alternatives

### Durable per-subject staging table

A staging table keyed by run, node, output, and subject could stream arbitrary audience partitions. It
would also require schema, cleanup, uniqueness, retry fencing, concurrency rules, and a substantially
larger recovery design. That work is justified only when a real partitioning audience node needs bounded
execution; Portia's message node does not.

### One transaction across external calls

Applying each chunk inside a single database transaction would bound PHP memory and roll back on a late
failure, but it would hold a transaction and locks while performing up to hundreds of network requests.
That increases contention, timeout, vacuum, and deadlock risk and couples database health to Yaya latency.

### Symbolic `NodeResult::all()`

Changing `AudienceContext::all()` or `NodeResult` to carry a symbolic all-subjects value would alter a
widely used result contract and could silently change host nodes. A new symbolic method would still make
result representation, rather than node capability, responsible for selecting execution semantics. The
explicit marker makes the optimization auditable and keeps every existing result API intact.

## Delivery sequence

1. Implement and verify the Nodeflow change on `feature/portia-uniform-audience-streaming`, branched from
   local `feature/nodeflow-integration` at `8cde0773484da60795ac03dbfc243ed96939f99b`.
2. Open the Nodeflow PR into `feature/nodeflow-integration`; do not target `main` while the vertical is in
   development.
3. Update Portia's package commit, implement the marker on `GenericMessageNode`, and replace the bypassing
   scale harness with the real activity proof.
4. Merge Portia through its own `feature/nodeflow-integration` branch only after both repositories' gates
   and independent reviews are ready.

This design document is the brainstorming gate. It authorizes an implementation plan, not source-code
changes by itself.
