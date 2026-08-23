# Experimental project status

> **Experimental / pre-release:** Nodeflow is suitable for structured evaluation, not a maturity promise. Its public API, database schema, generated tooling, and operational behavior can change before a stable release.

Nodeflow currently provides a Laravel workflow layer with editable flow drafts, immutable-by-contract published snapshots, registered nodes and triggers, durable execution, waits, tenant-aware models, an editor integration surface, and run inspection. It is designed for applications that want developers to define allowed capabilities while application users compose those capabilities into journeys.

## What works today

| Area | Current scope |
| --- | --- |
| Authoring | Draft saving with revision comparison, semantic graph validation, and publishing to versioned snapshots. |
| Runtime | Registered subject and audience nodes, durable waits, cohort routing, run records, and node execution records. |
| Integration | Laravel providers, tenant and subject resolver contracts, triggers, authorization hooks, Inertia-friendly editor and run-view components. |
| Operations | Queue and worker guidance, health checks for node type resolution, retention pruning, and a test mode for explicit test runs. |
| Packages | Local Composer package scaffolding and guarded extraction of eligible host nodes. |

This is a precise scope statement, not a guarantee that every application shape is supported. In particular, the package leaves domain authorization, tenant context propagation, node idempotency, deployment checks, and incident recovery procedures to the host application.

## What experimental means

Adopters should expect changes to APIs, configuration, generated package structure, migrations, editor contracts, and operational procedures. Treat Nodeflow state as an integration you own: run your application tests, validate upgrades against representative data and durable histories, and rehearse the worker, queue, database, cache, and deployment paths you will operate.

Do not treat a successful install or a passing package check as proof that a particular automation is safe for a deadline, financial action, or irreversible side effect. Read [Known limitations](known-limitations.md) before choosing a workflow design.

## Evaluate the canonical journey in your stack

The recommended evaluation is a small, representative journey running on your own queue, durable-workflow database, cache, tenant resolver, authentication, and frontend build configuration. Use a journey with a trigger, a subject or audience node, a wait, a branch, a domain side effect, and an exit. Then change the draft, publish another version, and confirm that an already-running journey stays on its original graph.

This checks the integration boundaries that Nodeflow cannot know for you: worker supervision, tenancy in console processes, package discovery, cached configuration, queue retries, authorization, client rendering, and your own side-effect idempotency.

## Evaluation checklist

- Install and wire the package with [Installation](../getting-started/installation.md) and [Frontend setup](../integration/frontend-setup.md).
- Bind and exercise your [tenancy resolver](../integration/tenancy.md) in web, queue, and console contexts.
- Build and publish a small flow, including rejected publish and stale-draft paths.
- Run an actual worker through waits, retries, and deployment-like restarts; follow [Durable execution](../operations/durable-execution.md).
- Verify node side effects are idempotent and that your alerts, backups, retention, and repair procedures fit your business requirements.
- Exercise the editor and run view in the browser your users will use, not only through server-side checks.

## Next step

Start with [Quick start](../getting-started/quick-start.md), then use [Known limitations](known-limitations.md) to decide whether the current boundaries fit the journey you want to evaluate.
