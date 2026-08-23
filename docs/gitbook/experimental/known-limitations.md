# Known limitations

Nodeflow is experimental. Use these current boundaries to decide what must be handled by your application before adopting a workflow. Each item includes the practical impact and the available mitigation.

## Execution and scale

### Branches do not run in parallel

**Impact:** A graph cannot fan out one output into parallel paths. Where distinct branches eventually contain waits, cursor items run sequentially, so wait durations add rather than overlap.

**Mitigation:** Design one route per output and keep dependent work in sequence. Publishing warns about directly branched waits, but the warning is not a substitute for a timing review. See [Publishing flows](../building-automations/publishing-flows.md) and [Durable execution](../operations/durable-execution.md).

### A run can reach its step limit without becoming an error

**Impact:** The interpreter stops after `nodeflow.limits.max_steps_per_run` node activities and then completes the run. The default is 1000. An oversized or deep acyclic graph can therefore finish before all intended work is observed as an explicit runtime failure. Normal publishing rejects cycles; a cycle can reach this limit only when host code bypasses normal publishing validation.

**Mitigation:** Set a limit appropriate to your graph, alert on unexpected completion patterns, and keep normal publishing validation in every host publishing path. Review the limit and the executed node records in [Durable execution](../operations/durable-execution.md).

### Audience work is chunked, not one whole-cohort call

**Impact:** Audience nodes receive one chunk at a time. A side effect or child-flow start may therefore happen multiple times for one logical audience.

**Mitigation:** Make audience-node effects idempotent per subject and per chunk. Tune the configured chunk sizes only after measuring your own database and worker capacity. See [Durable execution](../operations/durable-execution.md).

### Audience materialization checks every distinct subject

**Impact:** Starting a run calls `TenantResolver::ownsSubject()` once for each distinct, normalized subject ID before inserting the audience. Large audiences can therefore create many membership checks and remote database round trips.

**Mitigation:** Keep membership checks cheap, and use operation-scoped batching, local preloading, or caching inside the resolver where that remains safe. Measure representative audiences against your supported database. Do not weaken the per-subject ownership check. See [Starting runs](../building-automations/starting-runs.md).

### Child flows are keyless per chunk

**Impact:** `core.start_flow` starts a child flow once for each audience chunk and does not supply an idempotency key. Retrying or re-executing that work can create duplicate child runs; one child run may represent only part of the parent audience.

**Mitigation:** Make child-flow side effects idempotent and do not assume one child run represents the complete parent audience. See [Starting runs](../building-automations/starting-runs.md).

### Explicit run strategies are not validated

**Impact:** A caller can persist an arbitrary `strategy` string. The meaningful current values are `subject` and `cohort`, but the service does not enforce an enum.

**Mitigation:** Validate strategy values at every application boundary and use only `subject` or `cohort`. See [Starting runs](../building-automations/starting-runs.md).

## Tenancy and security

### Model-event tenant guards can be bypassed

**Impact:** Flow and Run Eloquent writes validate referenced version existence and tenant equality for `current_version_id` and `flow_version_id`. Query-builder and raw SQL writes bypass those model-event hooks. Other application-owned foreign-key writes still require trusted input and validation.

**Mitigation:** Keep version and tenant foreign-key writes on Eloquent model instances, or use equivalent explicit existence and tenant checks in trusted services. Never accept those identifiers from request input. See [Tenancy](../integration/tenancy.md) and [Flows and versions](../building-automations/flows-and-versions.md).

### Tenant context must exist outside HTTP requests

**Impact:** A resolver bound only in request middleware can leave queue and console execution without the intended tenant context. Explicitly choosing disabled tenancy can also permit unscoped reads when a resolver returns null.

**Mitigation:** Bind the resolver unconditionally in an application provider and exercise queue and console behavior during deployment validation. See [Tenancy](../integration/tenancy.md).

## Authoring, versions, and inspection

### Published versions are immutable by contract

**Impact:** Nodeflow services do not edit a published graph, but the model and database do not prevent host code from updating or deleting a `FlowVersion`. Changing or removing a version required by a live run can invalidate its pinned history.

**Mitigation:** Treat published versions as append-only records in application code and protect them from direct update and delete paths. See [Flows and versions](../building-automations/flows-and-versions.md).

### Publishing does not compare the draft revision

**Impact:** Draft saving uses compare-and-swap, but publishing does not accept the revision. A save that arrives after the final draft save and before the publish can be cleared by that publish.

**Mitigation:** Serialize draft-save and publish requests per flow, or restrict each flow to one active editor/author. Restricting publishers alone is insufficient. See [Publishing flows](../building-automations/publishing-flows.md).

### Durable-engine failures do not automatically reconcile Nodeflow run status

**Impact:** A missing type or activity exception can fail the durable execution while the Nodeflow run remains `running`. The package has no automatic status reconciliation or packaged generic resume/recovery service.

**Mitigation:** Monitor the durable engine and Nodeflow records together, enable node-type checks where appropriate, and define an application repair procedure before operating critical flows. See [Durable execution](../operations/durable-execution.md) and [Health checks](../operations/health-checks.md).

### The run view is current evidence, not historical proof

**Impact:** The subjects panel lists active subjects at the selected node. A node that leaves no execution row or output, including `core.exit`, can later display as never reached. Cancellation-only current evidence can similarly make a node appear reached while a subject is active there, then not reached once no active row remains. Dimming and `reached` are current observable evidence, not audit facts.

**Mitigation:** Use durable workflow history and application audit data for historical truth, and use the run view for current operational inspection. See [Inspecting runs](../editor-and-run-view/inspecting-runs.md).

### Editor autosave recovery depends on the failure

**Impact:** A stale save (`HTTP 409`) offers **Keep mine** and **Use theirs**; resolving that conflict restarts autosave. Other refused draft saves, including session and network failures, stop autosave for the mounted editor session without an in-place retry control. A user can retain a local graph in the browser without a retry control.

**Mitigation:** Present the conflict flow clearly. For a non-conflict failure, tell authors to preserve visible changes before reloading or remounting when recovery is needed. See [Editor](../editor-and-run-view/editor.md).

## Tooling and packages

### Node extraction is intentionally static and conservative

**Impact:** Extraction cannot discover dynamic class strings, reflection-based lookup, database-stored references, configuration-driven references, or references in unsupported file types. It also refuses source layouts it cannot safely prove, such as multi-symbol files or non-PSR-4 host source.

**Mitigation:** Search and migrate non-static references manually, keep extractable nodes in their own PSR-4 files, and run a fresh host boot after any manual move. See [Extracting nodes](../node-packages/extracting-nodes.md).

### Install checks are not a TypeScript parser or a runtime proof

**Impact:** The Vite alias check looks for the editor alias and package source in uncommented configuration text. It cannot prove that they form the active exported alias entry. When multiple Vite config filenames exist, the check can inspect a file Vite does not use. An unpublished optional config file can also appear as outstanding install work even though package defaults are merged at runtime.

**Mitigation:** Keep one active Vite config, review the alias in its exported configuration, decide deliberately whether to publish configuration, and build and open the host frontend after wiring. See [Frontend setup](../integration/frontend-setup.md) and [Installation](../getting-started/installation.md).

### Published package migrations can shadow package migrations

**Impact:** A copied migration with the same filename can take precedence over the package migration and later drift from it.

**Mitigation:** Prefer the package migration path. If you publish migrations, compare them on upgrades and resolve drift deliberately before running migrations. See [Installation](../getting-started/installation.md).

## Database, workers, and CI

### Application-specific operational validation remains required

**Impact:** The current package test suite runs on SQLite only and CI does not execute the interpreter through a real queue worker. Package-level checks therefore cannot prove your supported database behavior, queue retry policy, durable-workflow storage, cache configuration, browser build, package discovery cache, or domain side effects work together.

**Mitigation:** Run a representative canonical journey against each supported database and with a supervised real queue worker, using the same queue, cache, tenancy, and frontend setup you intend to operate. Include worker restarts, waits, retries, browser interaction, and deployment-like node-type checks in your release process. See [Project status](project-status.md), [Queues and workers](../operations/queues-and-workers.md), and [Health checks](../operations/health-checks.md).

## Next step

Use [Project status](project-status.md) to plan a bounded evaluation, then follow [Durable execution](../operations/durable-execution.md) for the runtime safeguards your application must operate.
