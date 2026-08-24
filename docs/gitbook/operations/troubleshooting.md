# Troubleshooting

Start from an authorized, tenant-scoped flow or run view, then use logs and the commands below. Do not begin from `RunSubject` or `NodeExecution` with an unscoped lookup: child rows are isolated through their parent run. Do not place raw request IDs into repair commands or request-controlled `withoutTenancy()` queries.

> **Experimental:** Some behavior below is a deliberate current constraint rather than a configuration defect. Those constraints are tracked in [known limitations](../experimental/known-limitations.md).

## Runs do not move

**Likely cause:** no Laravel worker is consuming the queue, a durable backend is unhealthy, or a live graph references a missing node type.

**Verify:** inspect the authorized run overlay and worker logs, then run:

```bash
php artisan workflow:v2:doctor --strict
php artisan nodeflow:check-node-types
```

When the host uses Laravel failed-job storage and has its `failed_jobs` table, also run `php artisan queue:failed`.

**Correct:** start or restore the host's queue workers, repair the backend capability reported by the doctor, and register or alias a missing type before its next affected activity. A missing type or activity exception can leave the Nodeflow run `running` while the durable execution has failed. Preserve that failed/current run for durable-history diagnosis and an application-defined recovery decision after fixing the root cause; a safe new idempotent run may be appropriate when the business operation permits it. Do not mark the Nodeflow run complete manually or invent a packaged resume command. See [Queues and workers](queues-and-workers.md).

## An audience is empty

**Likely cause:** the caller supplied no IDs after normalization, or the trigger's tenant audience was empty. Duplicate IDs only shrink an audience: any non-empty set of repeated IDs still materializes one subject.

**Verify:** log the host-owned audience immediately before `StartRun::forFlow()` and inspect the run's authorized subject panel. Materialization string-casts and removes duplicate IDs before inserting rows.

**Correct:** fix the audience resolver or trigger grouping. Do not treat an empty cohort as a test preview; a normal run is still created and can complete without routing subjects.

## Subject binding is missing

**Likely cause:** the host did not bind `Nodeflow\Contracts\SubjectResolver`, registered the wrong subject type, or returned a map that does not contain the requested string IDs.

**Verify:** exercise the resolver in an application test using IDs from an authorized run and confirm it returns an ID-keyed map. The package fallback throws that the host must bind the contract.

**Correct:** bind the resolver unconditionally in a service provider's `register()` method and preserve the exact subject-type and string-ID contract. See [Required contracts](../integration/required-contracts.md).

## Tenant resolution fails in a worker or command

**Likely cause:** a multi-tenant host binds its `TenantResolver` only in request middleware, or `NODEFLOW_TENANCY` is missing or invalid.

**Verify:** read the `nodeflow:install` tenancy report and reproduce through the same console or queue context. Under `auto`, a host resolver returning null is unresolved and throws; under `resolver`, any null does. An invalid value also throws rather than reading unscoped.

**Correct:** bind the resolver in the provider's `register()` method and choose the appropriate explicit tenancy mode. Do not switch to `disabled` merely to silence an unresolved-tenant exception in a tenant-aware application. See [Tenancy](../integration/tenancy.md).

## An event does not create a run

**Likely cause:** the source is not registered, allowlists another concrete event class, rejects the event snapshot/configuration, returns no tenant audience, or the flow is not active with a current version.

**Verify:** confirm driver → node → source registration and host occurrence delivery, then inspect the active flow's immutable activation (driver, source, qualifier, trigger node, descriptor, and pinned version). The shared occurrence dispatcher reports one activation failure and continues with other matches; Laravel-event/model listeners also isolate source-level failures.

**Correct:** register driver → node → source in dependency order, correct the source's `eventClass()`, explicit value-only `snapshot()`, and `resolve()` methods, then publish an active version. Use `php artisan nodeflow:check-node-types` to identify a missing trigger node, driver, or source registration for an active activation or trigger-origin live run. Triggered runs are always live; do not dispatch production events to test node behavior. See [Writing triggers](../building-automations/writing-triggers.md).

## Draft save reports a stale conflict

**Likely cause:** another browser save or publish advanced the flow's monotonic `draft_revision`.

**Verify:** the draft endpoint returns HTTP 409 with the winning graph and current revision.

**Correct:** reload or merge the returned graph deliberately, then save using its revision. Do not retry the stale payload unchanged; it would overwrite newer author work.

## Publish reports validation errors

**Likely cause:** the graph has an invalid start, edge, node type, field configuration, or unsupported graph shape.

**Verify:** the publish endpoint returns HTTP 422 with general `errors` and node-specific `node_errors`.

**Correct:** display and fix the returned entries beside their affected nodes, then publish again. A draft may be structurally incomplete; publication is the point where graph semantics are enforced. See [Publishing flows](../building-automations/publishing-flows.md).

## The editor has missing styles

**Likely cause:** Tailwind is not scanning the package React source.

**Verify:** run `php artisan nodeflow:install --check`; look for the Tailwind source requirement.

**Correct:** add the documented `@source` entry relative to the host CSS entry, rebuild assets, and re-run the check. See [Frontend setup](../integration/frontend-setup.md).

## React reports an invalid hook call

**Likely cause:** Vite resolved more than one copy of React, React DOM, or XYFlow, especially with a local Composer symlink.

**Verify:** run `php artisan nodeflow:install --check` and inspect the Vite dedupe requirement.

**Correct:** install `@xyflow/react` in the host and add `react`, `react-dom`, and `@xyflow/react` to Vite `resolve.dedupe`. See [Frontend setup](../integration/frontend-setup.md).

## Dynamic field options do not load

**Likely cause:** the route cannot resolve the registered node type or field, the field has no declared `OptionSource`, the source is not bound correctly, or authorization rejects the flow.

**Verify:** make the editor's authorized options request and inspect its HTTP status. The endpoint accepts a node type and field key, not a source class; a 404 distinguishes unknown type, unknown field, static field, or missing dynamic source.

**Correct:** keep the source on the node's declared field, implement `OptionSource`, and scope its data to the current tenant. Never broaden the endpoint to accept an arbitrary class name. See [Custom controls](../editor-and-run-view/custom-controls.md).

## A live node type is unresolved

**Likely cause:** a deploy removed or renamed a class while a pinned flow version still references its old type.

**Verify:**

```bash
php artisan nodeflow:check-node-types
```

**Correct:** restore registration or add a direct alias to the current type, then rerun the check. A boot-time log is only one check per process; use the command as the deploy gate. See [Health checks](health-checks.md).

## Overlay polling halts while the run looks unfinished

**Likely cause:** the overlay treats only `completed` as terminal. A failed or otherwise halted engine execution may not receive a Nodeflow terminal status, so the client can keep polling; this package currently has no automatic status-reconciliation fix.

**Verify:** inspect the authorized run overlay, worker logs, and durable doctor output.

**Correct:** diagnose the durable failure and preserve the run for recovery. Do not configure a client interval to mask the state mismatch; follow [known limitations](../experimental/known-limitations.md).

## Subjects remain active at a node

**Likely cause:** an activity was interrupted or failed before `NodeRunner` advanced and reconciled its subjects, application code mutated a cursor or status outside the runner, or durable-engine and Nodeflow state diverged.

**Verify:** use the authorized run's node-subject endpoint and overlay. NodeRunner reconciles only subjects it actually processed at that node: an ID absent from both outputs and failures completes the flow, while each returned ID must be from the current chunk and appear at most once.

**Correct:** restore workers first, then diagnose the durable history and Nodeflow parent run together. A subject omitted from a `NodeResult` is completed, not left active; correct the node to return an output or failure for every subject that should continue, and omit an ID only to complete it. Do not perform an unscoped child-table update to clear a cursor; that bypasses the run's isolation and can make audience-empty signaling incorrect. See [Writing nodes](../building-automations/writing-nodes.md).

## Next step

Use [Health checks](health-checks.md) for the repeatable checks to put in every deployment.
