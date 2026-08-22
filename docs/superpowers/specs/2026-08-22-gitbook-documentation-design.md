# GitBook documentation design

**Date:** 2026-08-22
**Status:** Approved design awaiting written-spec review

## Purpose

Create the canonical documentation for Laravel Nodeflow as a GitBook project under
`docs/gitbook/`. The documentation must help a Laravel developer understand why Nodeflow exists,
integrate it safely, build domain nodes and triggers, embed the editor and run view, operate durable
runs, and contribute to the package.

The documentation describes Nodeflow as experimental pre-release software. It must explain the
package's current limitations plainly and must not make production-readiness claims that the current
implementation and acceptance evidence do not support.

## Audience

The primary audience is developers integrating Nodeflow into a Laravel application. They should be
able to progress from first contact to a working flow without first learning the package internals.

The secondary audience is package contributors. Contributor material will explain architecture,
repository layout, local development, and testing in a separate section so it does not interrupt the
integration journey.

## Documentation strategy

Use a learning-path-plus-reference structure. The main path is progressive and task-oriented, in the
spirit of Laravel's documentation. Exhaustive lookup material lives in a dedicated reference section,
and package internals live in a dedicated contributor section.

The main reading path is:

`Introduction → Installation → Quick Start → Core Concepts → Integration → Build a Node → Build a
Trigger → Use the Editor → Operate Runs`

The two rejected alternatives were:

- A lifecycle-only structure, which tells a coherent story but makes reference and contributor
  material harder to locate.
- A source-subsystem structure, which mirrors the code well but makes a first integration feel
  fragmented.

## GitBook location and configuration

All GitBook-specific files will live under `docs/gitbook/`. GitBook Git Sync should use
`docs/gitbook/` as its project directory. That directory will contain its own `.gitbook.yaml`,
`README.md`, and `SUMMARY.md`.

`SUMMARY.md` is the explicit navigation contract. Every published documentation page must appear in
it so repository changes and the GitBook sidebar cannot silently diverge.

The approved structure contains 48 published Markdown pages including the GitBook home page. Pages
remain focused and cross-linked rather than combining unrelated topics to reduce the file count.

The current numbered guides at `docs/01-*.md` through `docs/09-*.md` remain unchanged as legacy
references. The GitBook becomes canonical. The root `README.md` will be refreshed to point to the
GitBook entry page and to remove setup or status claims contradicted by the current implementation.

## Information architecture

```text
docs/gitbook/
├── .gitbook.yaml
├── README.md
├── SUMMARY.md
├── getting-started/
│   ├── introduction.md
│   ├── installation.md
│   ├── quick-start.md
│   └── core-concepts.md
├── integration/
│   ├── required-contracts.md
│   ├── tenancy.md
│   ├── authorization.md
│   ├── registering-domain-components.md
│   ├── routes-and-inertia.md
│   └── frontend-setup.md
├── building-automations/
│   ├── flows-and-versions.md
│   ├── writing-nodes.md
│   ├── node-fields.md
│   ├── writing-triggers.md
│   ├── subject-attributes.md
│   ├── starting-runs.md
│   └── publishing-flows.md
├── editor-and-run-view/
│   ├── editor.md
│   ├── custom-controls.md
│   ├── custom-node-appearance.md
│   └── inspecting-runs.md
├── example-application/
│   ├── overview.md
│   ├── application-setup.md
│   ├── flood-alert-workflow.md
│   └── testing-the-workflow.md
├── operations/
│   ├── durable-execution.md
│   ├── queues-and-workers.md
│   ├── test-mode.md
│   ├── health-checks.md
│   ├── pruning-and-retention.md
│   └── troubleshooting.md
├── node-packages/
│   ├── creating-packages.md
│   └── extracting-nodes.md
├── reference/
│   ├── configuration.md
│   ├── artisan-commands.md
│   ├── core-nodes.md
│   ├── contracts.md
│   ├── routes.md
│   ├── graph-format.md
│   ├── statuses.md
│   └── database-schema.md
├── contributing/
│   ├── architecture.md
│   ├── local-development.md
│   ├── testing.md
│   └── project-structure.md
└── experimental/
    ├── project-status.md
    └── known-limitations.md
```

## Section responsibilities

### Getting Started

Explain what Nodeflow is, the problem it solves, who should use it, package requirements,
installation, a minimal working integration, and the core mental model: flows, immutable versions,
runs, subjects, and audiences.

The quick start must be a complete vertical path. It will install the package, run
`nodeflow:install`, bind the required host contracts, register the minimum domain surface, configure
authorization and routes, publish a small graph, start it, and identify the worker requirement. It
must link to deeper pages instead of hiding important caveats inside one oversized chapter.

### Integration

Document the boundary between Nodeflow and a host application:

- `TenantResolver` and `SubjectResolver`
- tenancy modes and the meaning of a null tenant
- fail-closed behavior and ownership checks
- authorization gates and tenant-aware gate examples
- registration of nodes, triggers, and subject attributes
- opt-in package routes and Inertia page expectations
- Vite, TypeScript, Tailwind, React Flow, and React deduplication wiring

Security-sensitive instructions must use safe examples. Tenant resolver bindings belong in a
provider's `register()` method, gates receiving a `Flow` or `Run` check tenant ownership as defence in
depth, and `RunSubject` or `NodeExecution` records are reached through an already scoped `Run` rather
than bound directly.

### Building Automations

Explain the public authoring surface:

- flow drafts and immutable published versions
- graph structure and publish-time validation
- node definitions, stable type identifiers, output names, and default configuration
- `HandlesSubject` and `HandlesAudience` cardinality
- `SubjectContext`, `AudienceContext`, and `NodeResult`
- fields, validation, inline options, dynamic options, and custom field types
- triggers, event matching, per-tenant audiences, and idempotency
- subject attributes and condition evaluation
- manual, event, and subflow run starts

The node and trigger chapters must contain complete classes, not disconnected method fragments.
Each must include registration, test-mode behavior, failure behavior, and a verification example.

### Editor and Run View

Document how a host embeds the shipped React clients without treating them as a standalone
application. Cover thin Inertia pages, editor props, autosave and stale-draft behavior, structured
publish errors, custom controls, custom node appearance, the read-only run overlay, subject
drill-down, cursor pagination, and polling.

### Example Application

Provide a self-contained, illustrative flood-alert application using neutral `Organization` and
`User` models. It will show tenant resolution, subject resolution, an event trigger, messaging and
branching nodes, subject attributes, graph publication, waits, cancellation, test mode, and tests.

A private internal reference application must never be named, linked, or described as publicly
available. It may be used only as private evidence when checking that a generic integration pattern
is realistic. The published example must stand on its own and must not require access to that
application.

### Operations

Explain durable execution in operational terms: workflow and activity queues, waits and resumption,
cancellation, chunking, test-mode expectations, node-type checks, boot-time verification, pruning,
retention, status lifecycles, logs, and common failure modes.

Known architectural limitations belong in the experimental section and may be linked from operations.
Ordinary misconfiguration and recovery steps belong in troubleshooting. The two must not be mixed,
because a reader can fix the latter but not the former.

### Node Packages

Cover `nodeflow:make-node-package` and `nodeflow:extract-node`, including package layout, service
provider registration, editor controls, statically fixed node types, detectable reference forms,
Composer verification, rollback behavior, and accepted extraction limits.

### Reference

Provide concise, exhaustive lookup pages for configuration values, Artisan commands and options,
core node behavior, public contracts and signatures, route methods and names, graph payload format,
status values, and package tables. Reference pages should link back to the relevant guide for context
instead of repeating tutorials.

### Contributing

Explain the package's major subsystems and boundaries, important request-to-execution flows, local
installation, PHP and TypeScript tooling, focused and full test commands, repository organization,
and how the host-compiled editor source differs from an independently published JavaScript package.

### Experimental

State prominently that Nodeflow is experimental pre-release software. Consolidate current known
limitations and deferred hardening work, including any lack of real queue-worker acceptance evidence,
durable failure-state gaps, scaling concerns, graph execution constraints, and database or tenancy
hardening that current code and project records still identify as open.

Do not present a historical issue as current merely because it appears in an older design document.
Every limitation must be checked against the implementation, tests, and current open-issues record.

## Writing style

Use practical, direct language modeled on Laravel's documentation:

- Lead with what a feature does and when to use it.
- Introduce terminology only when the reader needs it.
- Prefer complete, copyable examples over isolated API fragments.
- Explain important behavior directly after the relevant code.
- Use short notes and warnings for security, tenancy, queues, and experimental limitations.
- End tutorial pages with a clear next step.
- Keep reference pages compact and scannable.

A practical guide should generally contain:

1. The outcome the reader will achieve
2. Prerequisites
3. Implementation steps
4. Complete code examples
5. Explanation of important decisions
6. Common mistakes
7. A way to verify the result
8. The next relevant page

Most pages should remain focused rather than becoming long catch-all chapters. Complex subjects such
as tenancy, node authoring, and durable execution can be longer when splitting them would separate
code from the explanation needed to use it safely.

## Code-example rules

- Show namespaces and imports when a reader needs them to use the example.
- State the intended file path before a substantial snippet.
- Use current Laravel conventions and realistic classes.
- Mark deliberately abbreviated code explicitly.
- Keep types, names, routes, graph ids, and domain concepts consistent across pages.
- Explain why security-sensitive lines exist.
- Avoid invented helpers and APIs.
- Derive public method names and signatures from current source.
- Derive expected behavior from current tests where tests enforce it.
- Use one example domain across the tutorial so the reader does not repeatedly relearn context.

## Diagrams

Use a small number of compact diagrams only where relationships or state transitions are harder to
understand in prose. The intended diagrams are:

- flow → immutable version → run → subjects
- event trigger → tenant audiences → isolated runs
- editor draft → validation → publish → durable execution
- interpreter → queued activity → wait → resume
- scoped run → node → subject drill-down

Diagrams must have adjacent prose that communicates the same essential behavior so the pages remain
understandable when a renderer does not support the diagram syntax.

## Sources of truth

Documentation claims will be checked in this order:

1. Current implementation
2. Current tests
3. `composer.json`, `composer.lock`, `package.json`, and `package-lock.json`
4. Approved architecture specifications and execution records
5. Existing numbered guides
6. The private internal application, for validation only

This ordering resolves the current drift in which the root README says installation and packaging
commands do not exist even though the current service provider registers them.

No hard-coded test counts belong in the primary learning path. Counts become stale without helping a
developer integrate the package. Contributor testing pages will document the commands and the kinds
of behavior each suite verifies.

## Error and limitation handling

Documentation examples must show the package's real failure behavior rather than only the happy path:

- missing host resolvers fail closed
- an unresolved tenant throws in the modes where unscoped access would be unsafe
- missing authorization gates deny access
- stale editor drafts return a conflict and require reconciliation
- invalid graphs return structured publish errors
- unresolved node types are detectable before deployment
- packaging extraction can refuse unsafe or ambiguous source layouts and roll back failed work

Warnings should say what failed, why the safeguard exists, and how the host developer corrects the
configuration. They must not imply that an architectural limitation has an application-level fix
when it does not.

## Verification strategy

Before completion:

- Confirm every planned page exists and every published page appears in `SUMMARY.md`.
- Check every relative Markdown link and local anchor in the GitBook tree.
- Compare configuration documentation with `config/nodeflow.php`.
- Compare command names, arguments, options, and output claims with current console command classes
  and command tests.
- Compare routes with `src/Http/routes.php` and authorization behavior with controllers and policies.
- Compare public contracts, node interfaces, contexts, result helpers, and schema builders with their
  current PHP signatures.
- Compare editor exports and props with the current TypeScript source and tests.
- Compare graph, status, and database reference pages with models, migrations, and tests.
- Run the repository's existing PHP and TypeScript verification commands when practical to establish
  that a documentation-only change did not disturb the project.
- Inspect the final diff and confirm it contains documentation and GitBook configuration changes only.

## Scope boundaries

This work includes:

- the complete `docs/gitbook/` documentation tree
- GitBook configuration and explicit navigation
- a refreshed root README that directs readers to the canonical GitBook
- a self-contained flood-alert example application walkthrough
- public integration, operations, reference, and contributor documentation

This work does not include:

- production PHP or TypeScript behavior changes
- edits to the existing numbered legacy guides
- publishing or configuring the GitBook space in the GitBook user interface
- releasing, tagging, or publishing the package
- exposing or linking the private internal application
- resolving limitations that the documentation identifies

## Acceptance criteria

The documentation is complete when:

1. A new Laravel developer can explain what Nodeflow does and identify whether its experimental
   status is acceptable for their application.
2. The quick start gives them one coherent path from installation to a published, runnable flow.
3. Integration pages cover every host responsibility and use safe tenancy and authorization defaults.
4. Node, trigger, subject-attribute, field, and run-start guides use real public APIs and complete
   examples.
5. Editor, run-view, operations, and node-package guides cover the current shipped behavior.
6. The example application is self-contained and contains no reference to the private internal app.
7. Reference pages cover current configuration, commands, core nodes, contracts, routes, graph shape,
   statuses, and schema.
8. Contributor pages match the repository's current PHP and TypeScript toolchains and architecture.
9. Experimental status and known limitations are easy to find and make no unsupported readiness
   claims.
10. GitBook navigation is complete, all internal links resolve, and the final diff stays within the
    approved documentation-only boundary.
