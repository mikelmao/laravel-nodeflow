# Plan 8 — tenancy security hardening design

**Date:** 2026-08-23
**Status:** Approved design; implementation has not started

## 1. Goal

Close the three tenancy gaps deliberately deferred from the earlier plans as one coherent security
tranche:

1. **D-1:** make the effective `auto` tenancy decision programmatically inspectable and keep the
   installer report aligned with the behavior enforced by model scopes;
2. **D-2:** assert the persisted run/version tenant boundary inside `RunNodeActivity` before any
   execution-side mutation; and
3. **G-3:** reject missing or cross-tenant `FlowVersion` references written through `Flow` and
   `Run` model instances.

The work is defence in depth. Request-time scoping remains the first boundary, write-time reference
guards prevent new corrupt links, and the durable activity refuses a corrupt persisted run even when
it was introduced through a path that bypassed Eloquent events.

## 2. Verified starting state

The design session inspected the current code, tests, issue ledger and merged GitBook documentation.

### Package repository

- Path: `/Users/mikelmao/Projects/laravel-nodeflow`
- Branch: `main`
- HEAD: `8cf68a226a759cd7264526d02d553018cf3ef622`
  (`Merge branch 'docs/gitbook-documentation'`)
- The worktree is clean and tracks `origin/main`.
- The completed Plan 7 feature worktree and branch have been removed after merge.
- The locked Plan 6 worktree remains untouched at `8b51a3d850c99f3f41a24726b31f6ad94a058890`.
- No `almanac/` directory exists, so there is no CodeAlmanac repository context to consult.

### Demo repository

- Path: `/Users/mikelmao/Sites/test-workflow`
- Branch: `main`
- HEAD: `e15e5bd912fee2e248654861b826d9e1458707dc`
- The worktree is clean.
- `vendor/atram/laravel-nodeflow` resolves exactly to the package repository.

### Current implementation facts

- `nodeflow.tenancy` defaults to `auto`.
- `NodeflowServiceProvider` installs `NoTenancyResolver` with `bindIf`, so an unconditional host
  binding wins.
- `BelongsToTenant::resolveTenantIdForScope()` independently interprets the configured mode and
  bound resolver on every scoped read.
- `InstallCommand::reportTenancy()` separately interprets the same facts for human output.
- `RunNodeActivity` loads a run and its pinned version unscoped, then increments `steps_taken` and
  runs the node without comparing their tenants.
- `Flow::currentVersion()` and `Run::flowVersion()` are intentionally unscoped durable relations.
  Their comments depend on same-tenant foreign-reference invariants that no model hook currently
  enforces.
- `FlowVersion` already inherits and validates its parent Flow tenant at creation time.
- `CrossTenantWriteException` already covers create, tenant-change and parent-mismatch writes.
- The merged GitBook tenancy page accurately documents the old boundary, but will become stale when
  D-1, D-2 and G-3 ship.

## 3. Scope and non-goals

### In scope

- A public diagnostic representation of the effective tenancy decision.
- One shared decision path for scoped reads and installer diagnostics.
- A dedicated durable-execution mismatch exception.
- Eloquent create/update guards for both version-reference columns.
- Explicit missing-reference errors rather than database-dependent behavior.
- Targeted counterfactual tests, full package verification and demo regression gates.
- README, GitBook tenancy guide, documentation handoff and open-issues reconciliation.

### Out of scope

- Database composite foreign keys, triggers or schema changes.
- Scanning, repairing or rewriting already-persisted rows.
- A new run failure status or any C-series durable failure/history work.
- Making model-event guards apply to query-builder or raw-SQL writes.
- Changing the `TenantResolver` contract or the `nodeflow.tenancy` default.
- Automatic boot/process logging for the D-1 decision.
- G-13 dynamic/database-held class reference extraction.
- Plan 7's deferred high-octal decoder fidelity minor.
- Release tagging, publishing or remote operations.

## 4. Chosen architecture

Use focused enforcement at each lifecycle boundary:

- a shared tenancy-decision resolver for D-1;
- model-local write guards for G-3; and
- an explicit persisted-boundary assertion in `RunNodeActivity` for D-2.

This keeps the interpretation of tenancy mode centralized without coupling model events and durable
workflow execution behind one generic guard abstraction.

### Rejected alternatives

#### One universal tenant-guard service

A service handling config diagnostics, model references and workflow execution would share more code,
but those callers have different inputs, failure types and lifecycle semantics. The abstraction would
hide important ordering and mutation boundaries.

#### Database-only enforcement

Composite constraints or database triggers would enforce below Eloquent, but the earlier measured
design work found SQLite test connections with foreign-key enforcement disabled. A mechanism whose
behavior changes by supported database is not the verification boundary for this tranche.

#### Guarding attributes with `$guarded`

The package deliberately uses open model assignment. Earlier measurement found broad call-site churn
and silent attribute discards because `preventSilentlyDiscardingAttributes()` is not enabled by
default. Silently writing null references is not a security improvement.

## 5. Decisions

| ID | Decision | Reason |
|---|---|---|
| P8-E1 | D-1 exposes a diagnostic API and command output without automatic logging. | Hosts and tests can inspect runtime truth without adding noise to every web, queue or console process. |
| P8-E2 | Scoped reads and `nodeflow:install` consume one tenancy-decision resolver. | The report must describe the exact branch that the global scope enforces. |
| P8-E3 | The decision is recomputed from current config and container binding whenever requested; no tenant ID is cached. | Long-lived processes may change ambient tenant, and tests legitimately replace bindings/configuration. |
| P8-E4 | D-2 compares the persisted `Run.tenant_id` with its pinned `FlowVersion.tenant_id`, not with an ambient resolver. | Durable workers intentionally read unscoped and may have no request tenant. |
| P8-E5 | D-2 throws before `steps_taken`, graph parsing or `NodeRunner` invocation and does not change run status. | A cross-tenant graph must never execute; durable failure-state semantics belong to the separate C-series work. |
| P8-E6 | G-3 enforces both existence and tenant equality. `Flow.current_version_id` remains nullable; `Run.flow_version_id` does not. | SQLite may not enforce the ordinary FK, and draft Flows legitimately have no published version. |
| P8-E7 | G-3 uses model-local `creating` and `updating` hooks rather than one `saving` hook. | Laravel fires `saving` before the trait's `creating` listener stamps a missing tenant; the guard must validate the tenant value actually being persisted. |
| P8-E8 | Reference guards run on create and when either the reference or `tenant_id` changes; unrelated updates add no query. | The invariant is checked whenever a model write can change it without taxing status/metadata updates. |
| P8-E9 | `TenancyGuardSuspension` does not bypass structural version-reference guards. | Suspension permits a trusted system-authored tenant stamp, not a contradiction between related rows. |
| P8-E10 | The Eloquent boundary and its query-builder bypass are documented and tested explicitly. | Model events cannot truthfully claim database-wide enforcement. |
| P8-E11 | Update README and the merged GitBook tenancy page directly, then reconcile `documentation-changes.md`. | The public documentation now lives on `main` and must describe shipped behavior rather than queueing an already-completed edit. |

## 6. D-1 — inspectable tenancy decisions

### 6.1 Public result

Add an immutable `TenancyDecision` value under `Nodeflow\Tenancy`. It represents:

- the configured value;
- whether that value is valid;
- the effective null-tenant outcome: unscoped, `TenancyUnresolvedException`, or invalid-config
  failure;
- the bound resolver class;
- whether `auto` inferred the outcome; and
- the inference reason, including the specific case where a host resolver caused `auto` to choose
  the fail-closed resolver behavior.

The public surface returns structured facts. Human prose belongs in the installer formatter, so a
host health check does not need to parse a console sentence.

### 6.2 Shared resolver

Add a container-managed `TenancyDecisionResolver` with two responsibilities:

1. produce a fresh `TenancyDecision` from the current `nodeflow.tenancy` value and current
   `TenantResolver` binding; and
2. resolve the tenant for an Eloquent scope using that same decision, throwing the existing
   `TenancyUnresolvedException` or `InvalidArgumentException` where appropriate.

The service itself may be a singleton, but it must not snapshot configuration, a resolver class or a
tenant ID in its constructor. Each operation resolves current inputs once and uses the same resolver
instance for its decision and current-tenant lookup.

`BelongsToTenant` delegates its existing mode match to this service. The semantics remain:

- `auto` + `NoTenancyResolver`: a null tenant means the host is intentionally single-tenant and the
  read is unscoped;
- `auto` + host resolver: a null tenant throws `TenancyUnresolvedException`;
- `resolver`: a null tenant throws;
- `disabled`: a null tenant reads unscoped; and
- any other value throws `InvalidArgumentException` rather than failing open.

### 6.3 Installer output

`InstallCommand::reportTenancy()` consumes the structured decision. It continues to be an
informational report that never changes the install command's exit code. In the auto/host-resolver
case it explicitly states that the host binding caused `auto` to select fail-closed resolver mode and
continues to advise binding unconditionally in a provider's `register()` method.

No log line, event or once-per-process warning is added.

## 7. G-3 — write-time version-reference guards

### 7.1 Hook ordering

`Flow` and `Run` register model-local listeners from `booted()`:

- a `creating` listener, registered after `BelongsToTenant`'s trait listener, so a missing tenant has
  already been stamped from the ambient resolver; and
- an `updating` listener that runs when the version reference or `tenant_id` is dirty.

The earlier rough proposal named a `saving` hook. This design deliberately refines it: `saving`
precedes `creating`, so it would inspect a pre-stamp tenant and either duplicate the trait's inference
or validate the wrong value.

### 7.2 Flow guard

For `Flow.current_version_id`:

- null is valid;
- a non-null ID is loaded with `FlowVersion::withoutTenancy()`;
- no matching row throws `InvalidFlowVersionReferenceException`; and
- a version tenant different from the Flow tenant throws
  `CrossTenantWriteException::forReferenceMismatch()`.

The security invariant is same-tenant reference. This tranche does not add a separate same-Flow
identity rule beyond the established publish path.

### 7.3 Run guard

For `Run.flow_version_id`:

- null or a missing ID throws `InvalidFlowVersionReferenceException` before the database insert or
  update;
- the version is loaded unscoped; and
- a tenant mismatch throws `CrossTenantWriteException::forReferenceMismatch()`.

`StartRun` remains valid: it already derives both values from trusted related rows and supplies the
tenant explicitly inside `TenancyGuardSuspension`.

### 7.4 Query and bypass boundary

A create with a reference and an update that dirties the reference or tenant performs one unscoped
version lookup. An unrelated update performs none.

`withoutTenancy()` removes only the global read scope; it does not disable model events. Conversely,
`Model::query()->update(...)`, the query builder and raw SQL do not fire Eloquent model events and
therefore bypass these guards. Existing comments, public documentation and a counterfactual test will
state that limit. No migration or misleading database-level guarantee is added.

## 8. D-2 — durable execution assertion

`RunNodeActivity` continues to load the run and its pinned version without tenancy scopes. Immediately
after the load it validates the persisted relationship:

1. if the referenced `FlowVersion` is absent, fail explicitly as a missing Eloquent model reference;
2. compare the run and version tenant IDs as strings; and
3. throw a new `CrossTenantExecutionException` containing the run ID, version ID and both tenant IDs
   when they differ.

Only after these checks may the activity:

- increment `steps_taken`;
- build the graph; or
- invoke `NodeRunner`.

The assertion deliberately does not read `TenantResolver`. A queue or console worker may have no
ambient tenant, while the two persisted rows remain the authoritative durable boundary.

The exception propagates through the existing workflow/activity mechanism. It does not update
`runs.status`; introducing a durable failed state is C-series work and must not be smuggled into this
security tranche.

## 9. Error model

### `CrossTenantExecutionException`

A dedicated runtime exception for a persisted run/version tenant mismatch. It is distinct from a
write exception because callers and incident response act differently: the unsafe row already exists
and execution was refused.

### `InvalidFlowVersionReferenceException`

A dedicated write-time exception for a null required reference or a referenced ID that does not
exist. Its message names the model, attribute and attempted ID. It does not call a missing row
"cross-tenant."

### `CrossTenantWriteException`

Add a named `forReferenceMismatch()` constructor describing the model, reference attribute and ID,
model tenant, and referenced version tenant. Existing constructors and messages remain compatible.

## 10. Test design

Implementation follows test-driven development. Every security check requires a counterfactual that
would pass or mutate under the current code.

### 10.1 D-1 tests

- `auto` with the package fallback reports an inferred unscoped null outcome.
- `auto` with a host resolver reports that the host binding caused fail-closed resolver behavior.
- explicit `resolver` and `disabled` decisions do not claim inference.
- an invalid mode is represented diagnostically and scoped reads still throw
  `InvalidArgumentException`.
- the diagnostic API and an actual scoped query agree for every mode.
- replacing config or the container binding after an earlier inspection produces a fresh decision.
- installer output is derived from the same result and remains report-only.

### 10.2 G-3 tests

- Flow create/update accepts null and valid same-tenant references.
- Flow create/update rejects nonexistent and cross-tenant references.
- Run create/update accepts valid same-tenant references.
- Run create/update rejects null, nonexistent and cross-tenant references.
- changing `tenant_id` alongside an unchanged or changed reference cannot create a contradiction.
- `TenancyGuardSuspension` does not bypass either structural guard.
- unrelated saves issue no version lookup; relevant writes issue exactly one.
- an explicit query-builder write demonstrates the documented model-event bypass.

### 10.3 D-2 tests

- a persisted cross-tenant run/version pair throws `CrossTenantExecutionException`;
- `steps_taken` is unchanged after refusal;
- `NodeRunner` is never invoked after refusal;
- a missing pinned version fails before mutation;
- matching persisted tenants execute with no ambient tenant; and
- the successful path invokes the runner and increments `steps_taken` exactly once.

Corrupt fixtures are inserted through the query builder so the new G-3 hooks cannot make D-2's tests
vacuously impossible.

## 11. Documentation

Update:

- `README.md` with the effective `auto` diagnostic and the two new enforcement boundaries;
- `docs/gitbook/integration/tenancy.md` with exact model-event and durable-execution guarantees,
  missing-reference behavior and the query-builder bypass;
- `docs/documentation-changes.md` to remove or mark complete any tenancy instructions now applied to
  the merged GitBook, while preserving unrelated future handoff items; and
- `docs/superpowers/open-issues.md` to close D-1, D-2 and G-3 with implementation and test evidence.

Historical specifications remain historical. This design supersedes only their explicitly deferred
D-1, D-2 and G-3 proposals.

## 12. Verification and acceptance

The implementation plan must name exact changed-file commands, but acceptance includes at least:

1. focused Pest files for tenancy decisions, model reference guards and `RunNodeActivity`;
2. the complete package Pest suite;
3. the complete Vitest suite and TypeScript check, even though no client behavior is intended to
   change;
4. Pint `--test` over every changed PHP source and test file;
5. `composer validate --strict` and `git diff --check`;
6. demo Pest, TypeScript and production-build regression gates through the exact package symlink; and
7. clean package and demo worktrees after committed execution evidence.

The feature will be implemented in a fresh isolated Plan 8 worktree created from the then-current
local `main`. The locked Plan 6 worktree remains outside the operation. No push, PR, tag or other
remote mutation is authorized by this design.

## 13. Acceptance criteria

Plan 8 is complete only when all of the following are true:

1. A host can inspect why `auto` chose its effective null-tenant behavior through structured PHP API.
2. The installer describes that same decision without affecting its wiring exit code.
3. Scoped reads retain all current fail-open/fail-closed semantics, including invalid-mode refusal.
4. Flow and Run Eloquent writes refuse missing and cross-tenant version references with explicit
   exceptions.
5. The structural guards remain active during `TenancyGuardSuspension` and their query-builder bypass
   is documented honestly.
6. `RunNodeActivity` refuses a persisted tenant mismatch before any counter or node-side effect.
7. No run-status semantics, schema changes or ambient-worker tenant requirement are introduced.
8. README, GitBook tenancy documentation, the documentation handoff and issue ledger agree with the
   shipped code.
9. All focused, full-package and demo verification gates pass with recorded evidence.
