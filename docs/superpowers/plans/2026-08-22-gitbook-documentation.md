# GitBook Documentation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish a complete, developer-first GitBook under `docs/gitbook/` that accurately documents Nodeflow's current experimental release for integrators and contributors.

**Architecture:** Organize the documentation as a progressive learning path backed by compact reference pages. Treat current source and tests as authoritative, keep one flood-alert domain consistent across all examples, and isolate contributor and experimental material from the integration path. GitBook uses `docs/gitbook/` as its Project directory, with configuration, home page, and navigation contained there.

**Tech Stack:** GitBook Git Sync, Markdown, YAML, Laravel 12/13, PHP 8.3+, Inertia, React, TypeScript, React Flow, Pest, and Vitest.

---

## File map

The implementation creates these GitBook files and changes no production source:

| Path | Responsibility |
|---|---|
| `docs/gitbook/.gitbook.yaml` | Tell GitBook which files are the home page and explicit navigation. |
| `docs/gitbook/README.md` | Landing page: value proposition, experimental notice, capabilities, and next steps. |
| `docs/gitbook/SUMMARY.md` | Complete ordered sidebar containing every published page. |
| `docs/gitbook/getting-started/*.md` | First-contact material, installation, the vertical quick start, and core concepts. |
| `docs/gitbook/integration/*.md` | Host contracts, tenancy, authorization, registration, routes, Inertia, and asset-tooling setup. |
| `docs/gitbook/building-automations/*.md` | Flows, versions, nodes, fields, triggers, attributes, run starts, and publishing. |
| `docs/gitbook/editor-and-run-view/*.md` | Editor embedding, extension seams, and run inspection. |
| `docs/gitbook/example-application/*.md` | A self-contained flood-alert example with copyable code and tests. |
| `docs/gitbook/operations/*.md` | Durable execution, workers, test mode, health, retention, and troubleshooting. |
| `docs/gitbook/node-packages/*.md` | Package scaffolding and safe node extraction. |
| `docs/gitbook/reference/*.md` | Exact configuration, commands, nodes, contracts, routes, graph, status, and schema lookup. |
| `docs/gitbook/contributing/*.md` | Architecture, setup, test workflow, and repository map for maintainers. |
| `docs/gitbook/experimental/*.md` | Maturity statement and only those limitations still true in current code. |
| `README.md` | Short project entry point linking to the canonical GitBook and removing obsolete claims. |

The existing `docs/01-*.md` through `docs/09-*.md` files remain unchanged legacy references.

## Shared writing contract

Apply these rules to every content task:

- Start each page with one paragraph saying what the reader will learn or accomplish.
- Use sentence-case headings and direct, non-marketing language.
- Put the intended Laravel file path immediately before substantial code blocks.
- Include namespaces and imports in code intended to be copied as a complete class.
- Label partial snippets explicitly with prose such as “Add this inside `boot()`.”
- Use `> **Note:**`, `> **Warning:**`, and `> **Experimental:**` blockquotes for portable callouts.
- Explain the reason immediately after security-sensitive or non-obvious code.
- Link to detail instead of repeating the same explanation on several pages.
- End guide pages with a `## Next step` section linking to the next page in the learning path.
- Keep public docs free of private repository names, absolute workstation paths, historical plan numbers, stale test counts, and internal implementation anecdotes.
- Re-read the current source named in each task immediately before drafting; if it contradicts a legacy guide, document the source behavior.

## Task 1: GitBook home and getting-started path

**Files:**

- Create: `docs/gitbook/.gitbook.yaml`
- Create: `docs/gitbook/README.md`
- Create: `docs/gitbook/getting-started/introduction.md`
- Create: `docs/gitbook/getting-started/installation.md`
- Create: `docs/gitbook/getting-started/quick-start.md`
- Create: `docs/gitbook/getting-started/core-concepts.md`
- Read: `composer.json`
- Read: `config/nodeflow.php`
- Read: `src/NodeflowServiceProvider.php`
- Read: `src/Console/InstallCommand.php`
- Read: `tests/Feature/CanonicalJourneyTest.php`
- Read: `tests/Feature/InstallCommandTest.php`
- Legacy evidence: `docs/01-overview.md`, `docs/02-integration.md`

- [ ] **Step 1: Create the GitBook configuration**

Write `docs/gitbook/.gitbook.yaml` exactly as:

```yaml
root: ./

structure:
  readme: README.md
  summary: SUMMARY.md
```

GitBook Git Sync must be configured with `docs/gitbook/` as its Project directory; state that in the handoff, not in the public content.

- [ ] **Step 2: Write the landing page**

Write `docs/gitbook/README.md` with these sections:

```markdown
# Laravel Nodeflow

> **Experimental:** Nodeflow is pre-release software...

## Why Nodeflow exists
## What it provides
## What your application provides
## A small example
## Start here
```

Define Nodeflow as a visual workflow builder and durable execution engine for Laravel applications whose users author long-running automations. Contrast package-owned mechanics with host-owned domain behavior. Summarize durable waits, cancellation, cohort execution, immutable versions, custom nodes, triggers, editor embedding, and run inspection. The small example should be a plain-language flood-alert sequence, not installation code.

- [ ] **Step 3: Write the introduction**

Write `docs/gitbook/getting-started/introduction.md` with:

- the deployment-per-automation problem;
- the separation between capabilities written by developers and journeys assembled by application users;
- appropriate use cases and poor fits;
- a table of package responsibilities versus host responsibilities;
- the four shipped domain-free nodes;
- an explicit link to experimental status.

Do not repeat installation commands here.

- [ ] **Step 4: Write installation**

Write `docs/gitbook/getting-started/installation.md` from current Composer metadata and `InstallCommand` behavior. Include:

```bash
composer require atram/laravel-nodeflow
php artisan nodeflow:install
php artisan migrate
```

State PHP and Laravel constraints exactly as `composer.json` declares them. Explain package-loaded migrations, the opt-in migration-publication flags, idempotent installation, `--check` for CI, the non-`sync` queue requirement, atomic cache locks, and the client wiring that the command verifies but may ask the host to add manually. Do not reproduce stale claims from the root README.

- [ ] **Step 5: Write the vertical quick start**

Write `docs/gitbook/getting-started/quick-start.md` as a minimal end-to-end integration using `Organization` as tenant and `User` as subject. It must include:

1. the install commands;
2. compact but complete `TenantResolver` and `SubjectResolver` classes;
3. unconditional container bindings inside a provider's `register()`;
4. four fail-closed gates inside `boot()`;
5. `Nodeflow::routes()` inside an authenticated route group;
6. one generated `SendWelcomeMessage` subject node that honors `isTest()`;
7. node registration;
8. a valid two-node graph with `app.send_welcome` leading to `core.exit`;
9. `PublishFlow::publish()` and `StartRun::forFlow()` calls;
10. `php artisan queue:work`;
11. a verification checklist that confirms a run and its subjects exist.

Link each abbreviated concern to its full guide. Use the actual `StartRun` options and graph keys from source.

- [ ] **Step 6: Write core concepts**

Write `docs/gitbook/getting-started/core-concepts.md` defining flow, flow version, run, subject, audience, node, trigger, and graph. Include a compact Mermaid relationship diagram plus equivalent prose. Explain one run per audience, single-subject node code, cohort-relative waits, immutable version pinning, and why Nodeflow stores string subject types and ids.

- [ ] **Step 7: Check and commit Task 1**

Run:

```bash
git diff --check
rg -n "nodeflow:install|PHP 8\.3|Laravel 12|experimental" docs/gitbook/README.md docs/gitbook/getting-started
```

Expected: `git diff --check` exits 0; the search shows installation and maturity language in the intended pages.

Commit:

```bash
git add docs/gitbook/.gitbook.yaml docs/gitbook/README.md docs/gitbook/getting-started
git commit -m "docs: add GitBook getting-started path"
```

## Task 2: Backend integration guides

**Files:**

- Create: `docs/gitbook/integration/required-contracts.md`
- Create: `docs/gitbook/integration/tenancy.md`
- Create: `docs/gitbook/integration/authorization.md`
- Create: `docs/gitbook/integration/registering-domain-components.md`
- Read: `src/Contracts/SubjectResolver.php`
- Read: `src/Contracts/TenantResolver.php`
- Read: `src/Tenancy/NoTenancyResolver.php`
- Read: `src/Models/Concerns/BelongsToTenant.php`
- Read: `src/Models/Concerns/TenancyGuardSuspension.php`
- Read: `src/Policies/FlowPolicy.php`, `src/Policies/RunPolicy.php`, `src/Policies/DelegatesToGate.php`
- Read: `src/Nodes/NodeRegistry.php`, `src/Triggers/TriggerRegistry.php`, `src/Schema/SubjectAttributeRegistry.php`
- Read: `stubs/provider.stub`
- Tests: `tests/Feature/TenancyTest.php`, `tests/Feature/TenancyModeTest.php`, `tests/Feature/TenancyAutoModeTest.php`, `tests/Feature/PolicyTest.php`, `tests/Feature/TenantIdImmutabilityTest.php`

- [ ] **Step 1: Document required contracts**

Write `required-contracts.md` with complete safe implementations of `TenantResolver` and `SubjectResolver`. Explain that `ownsSubject()` runs once per subject before audience insertion, that materialisation is all-or-nothing, and that `SubjectResolver::resolve()` returns a string-keyed map. Keep the page focused on these two bindings; `AudienceResolver` exists in source but is not currently consumed by the runtime and belongs only in the exact contract reference.

- [ ] **Step 2: Document tenancy**

Write `tenancy.md` with:

- a table for `auto`, `disabled`, and `resolver`;
- the exact meaning of a null tenant in each mode;
- why a custom resolver must be bound unconditionally in `register()`;
- `Model::withoutTenancy()` as a system-operation escape hatch;
- immutable `tenant_id` behavior and `CrossTenantWriteException`;
- the query-builder update bypass warning;
- the parent-row invariants for models reached through unscoped relations;
- the fact that child records without their own tenant scope must be reached through scoped parents.

Check current model traits before naming which models are tenant-scoped; do not copy the legacy list blindly.

- [ ] **Step 3: Document authorization**

Write `authorization.md` covering all four abilities:

| Ability | Purpose |
|---|---|
| `nodeflow.viewAny` | List and view flows or runs. |
| `nodeflow.update` | Edit drafts and resolve field options. |
| `nodeflow.publish` | Publish an immutable version. |
| `nodeflow.runManually` | Start live or test runs manually. |

Include tenant-aware gate definitions, absent-gate deny behavior, guest closure signatures, `Gate::before`, policy overrides, and the distinction between tenant-scoped reachability and authorization.

- [ ] **Step 4: Document domain registration**

Write `registering-domain-components.md` around the exact generated `NodeflowServiceProvider` anchors: `$nodes`, `$triggers`, and `subjectAttributes()`. Show complete provider registration. Explain singleton registries, listener attachment during trigger registration, generator append behavior, manual fallback snippets, stable node/trigger type ownership, and node aliases for renamed implementations.

- [ ] **Step 5: Check and commit Task 2**

Run:

```bash
git diff --check
rg -n "return true|middleware|RunSubject::find|NodeExecution::find" docs/gitbook/integration
```

Expected: formatting passes; any match is either an explicit warning showing what not to do or absent. Review every match manually before committing.

Commit:

```bash
git add docs/gitbook/integration/required-contracts.md docs/gitbook/integration/tenancy.md docs/gitbook/integration/authorization.md docs/gitbook/integration/registering-domain-components.md
git commit -m "docs: explain Nodeflow backend integration"
```

## Task 3: Routes, frontend wiring, editor, and run view

**Files:**

- Create: `docs/gitbook/integration/routes-and-inertia.md`
- Create: `docs/gitbook/integration/frontend-setup.md`
- Create: `docs/gitbook/editor-and-run-view/editor.md`
- Create: `docs/gitbook/editor-and-run-view/custom-controls.md`
- Create: `docs/gitbook/editor-and-run-view/custom-node-appearance.md`
- Create: `docs/gitbook/editor-and-run-view/inspecting-runs.md`
- Read: `src/Nodeflow.php`, `src/Http/routes.php`, `src/Http/Controllers/*.php`
- Read: `src/Console/Install/TailwindSourceStep.php`, `TsconfigPathsStep.php`, `ViteAliasStep.php`, `ViteDedupeStep.php`, `XyflowDependencyStep.php`
- Read: `resources/js/index.ts`, `resources/js/editor/FlowEditor.tsx`, `resources/js/editor/useAutosave.ts`
- Read: `resources/js/controls/types.ts`, `resources/js/canvas/context.ts`
- Read: `resources/js/run/FlowRun.tsx`, `resources/js/run/SubjectPanel.tsx`, `resources/js/run/useOverlayPolling.ts`
- Read: `resources/js/graph/types.ts`
- Tests: `tests/Feature/EditorRoutesTest.php`, `RunViewTest.php`, `RunSubjectsTest.php`, `FieldOptionsRouteTest.php`, `StructuredPublishErrorsTest.php`
- Tests: `resources/js/editor/*.test.ts*`, `resources/js/run/*.test.ts*`, `resources/js/controls/*.test.ts*`

- [ ] **Step 1: Document routes and Inertia ownership**

Write `routes-and-inertia.md` with the authenticated route-group example, the exact seven package routes, canonical names, controller purpose, required gate, tenant-scoped binding behavior, and the warning against adding a host route-name prefix. Show thin `nodeflow/editor.tsx` and `nodeflow/run.tsx` pages that render `FlowEditor` and `FlowRun` from server-authored props and URLs.

- [ ] **Step 2: Document frontend setup**

Write `frontend-setup.md` with the five current host requirements: Vite alias, TypeScript path, Tailwind source, `@xyflow/react`, and React deduplication. Use current install-step snippets, distinguish package installation from local symlink development, show `nodeflow:install --check`, and explain the failure symptom for each missing setting.

- [ ] **Step 3: Document editor embedding**

Write `editor.md` with the `FlowEditorProps` contract, a complete thin page, server-owned URL rationale, draft selection rules, autosave debounce, monotonic `draft_revision`, stale-draft conflict behavior, publishing states, structured node/field errors, and the absence of an Inertia dependency inside the editor component itself.

- [ ] **Step 4: Document custom controls**

Write `custom-controls.md` showing `Field::custom()`, a complete React control implementing the six-key `FieldControlProps` contract, a `ControlMap`, and passing it to `FlowEditor`. Explain base validation rules, resolved dynamic options, loading and error delivery, the unregistered-control fallback, and why the client never receives an option-source class name.

- [ ] **Step 5: Document custom node appearance**

Write `custom-node-appearance.md` showing a complete `NodeRenderer`, a `NodeRendererMap`, and reuse in both `FlowEditor` and `FlowRun`. Explain that the package retains handles and mandatory error rendering, while the host supplies only the card body.

- [ ] **Step 6: Document run inspection**

Write `inspecting-runs.md` with the `FlowRunProps` contract, pinned-version guarantee, read-only boundary, overlay shape, per-output badges, reached-versus-never-reached semantics, active-subject cursor pagination, polling interval and stop conditions, halting HTTP statuses, network retry behavior, and host error-boundary recommendation for malformed initial payloads.

- [ ] **Step 7: Check and commit Task 3**

Run:

```bash
npm run types:check
git diff --check
rg -n "FlowEditorProps|FlowRunProps|draft_revision|__NODEFLOW_NODE__|@nodeflow/editor" docs/gitbook/integration docs/gitbook/editor-and-run-view
```

Expected: TypeScript exits 0 without output; formatting passes; every public frontend seam appears in its intended guide.

Commit:

```bash
git add docs/gitbook/integration/routes-and-inertia.md docs/gitbook/integration/frontend-setup.md docs/gitbook/editor-and-run-view
git commit -m "docs: document editor and run-view integration"
```

## Task 4: Flows, nodes, fields, and publishing

**Files:**

- Create: `docs/gitbook/building-automations/flows-and-versions.md`
- Create: `docs/gitbook/building-automations/writing-nodes.md`
- Create: `docs/gitbook/building-automations/node-fields.md`
- Create: `docs/gitbook/building-automations/publishing-flows.md`
- Read: `src/Models/Flow.php`, `src/Models/FlowVersion.php`
- Read: `src/Editor/SaveDraft.php`, `src/Editor/StaleDraftException.php`
- Read: `src/Nodes/Node.php`, `HandlesSubject.php`, `HandlesAudience.php`, `NodeRegistry.php`
- Read: `src/Execution/SubjectContext.php`, `AudienceContext.php`, `NodeResult.php`, `NodeRunner.php`
- Read: `src/Schema/NodeDefinition.php`, `Field.php`, `FieldType.php`, `OptionSource.php`
- Read: `src/Publishing/PublishFlow.php`, `GraphInvalidException.php`
- Read: `src/Graph/Graph.php`, `GraphValidator.php`, `GraphValidationResult.php`
- Tests: `tests/Feature/SaveDraftTest.php`, `PublishFlowTest.php`, `NodeRunnerTest.php`, `NodeContextSurfaceTest.php`
- Tests: `tests/Unit/FieldTest.php`, `FieldCustomTest.php`, `NodeDefinitionTest.php`, `GraphValidatorTest.php`

- [ ] **Step 1: Document flows and immutable versions**

Write `flows-and-versions.md` explaining draft state versus published versions, `current_version_id`, version pinning by runs, monotonic draft revisions, content hashes, editing while older runs continue, and why published graphs must not be mutated. Include a flow/version/run diagram and a concise Eloquent creation example that does not accept tenant or version ids from request input.

- [ ] **Step 2: Document node authoring**

Write `writing-nodes.md` starting with the complete current generator command and all options. Include one complete `SendMessage` node, then explain `type()`, `definition()`, `defaultConfig()`, `$tries`, subject versus audience cardinality, dual-cardinality preference, every context method, `NodeResult` helpers, per-subject failures, test-mode obligation, idempotency, and registration. End with a node-review checklist.

- [ ] **Step 3: Document node fields**

Write `node-fields.md` covering `text`, `number`, `boolean`, `select`, `multiselect`, `duration`, and `custom`. Show labels, help, required/default values, inline options, `optionsFrom()`, base rules, wire shape, dynamic endpoint behavior, and duration validation. Include one table mapping field type to PHP base validation rule.

- [ ] **Step 4: Document publishing**

Write `publishing-flows.md` with a valid graph payload and `PublishFlow::publish()`. List every current blocking graph rule and the current non-blocking warning. Explain `GraphInvalidException`, `errors()`, `nodeErrors()`, transactionality, draft clearing without revision reset, and the editor HTTP error shape.

- [ ] **Step 5: Check and commit Task 4**

Run:

```bash
vendor/bin/pest tests/Unit/GraphValidatorTest.php tests/Unit/FieldTest.php tests/Unit/FieldCustomTest.php tests/Feature/PublishFlowTest.php tests/Feature/NodeRunnerTest.php
git diff --check
```

Expected: all selected Pest tests pass and formatting exits 0.

Commit:

```bash
git add docs/gitbook/building-automations/flows-and-versions.md docs/gitbook/building-automations/writing-nodes.md docs/gitbook/building-automations/node-fields.md docs/gitbook/building-automations/publishing-flows.md
git commit -m "docs: explain flows nodes and publishing"
```

## Task 5: Triggers, subject attributes, and run starts

**Files:**

- Create: `docs/gitbook/building-automations/writing-triggers.md`
- Create: `docs/gitbook/building-automations/subject-attributes.md`
- Create: `docs/gitbook/building-automations/starting-runs.md`
- Read: `src/Triggers/Trigger.php`, `TriggerDefinition.php`, `TriggerMatch.php`, `TriggerRegistry.php`, `EventTriggerListener.php`, `SubFlowStarter.php`
- Read: `src/Schema/SubjectAttribute.php`, `SubjectAttributeRegistry.php`
- Read: `src/Execution/StartRun.php`, `AudienceMaterialiser.php`, `SubjectExiter.php`
- Read: `src/Nodes/Core/ConditionNode.php`, `StartFlowNode.php`
- Tests: `tests/Feature/EventTriggerTest.php`, `SubFlowStarterTest.php`, `StartRunTest.php`, `AudienceMaterialiserTest.php`, `SubjectExiterTest.php`, `MakeTriggerCommandTest.php`, `MakeSubjectAttributeCommandTest.php`

- [ ] **Step 1: Document triggers**

Write `writing-triggers.md` with the full generator invocation and one complete `FloodAlertFires` trigger. Explain stable type, host event class, definition fields, `resolve()`, one event producing separate tenant audiences, `idempotencyKey()`, `matchesConfig()`, one listener per event class, registration timing, and duplicate-delivery protection.

- [ ] **Step 2: Document subject attributes**

Write `subject-attributes.md` with generator and manual registration examples. Cover allowed types (`boolean`, `text`, `number`), resolver closures, editor exposure as a deliberate allowlist, all current condition operators, coercion rules, null behavior, and the requirement to keep attributes registered while published versions reference them.

- [ ] **Step 3: Document run starts and cancellation**

Write `starting-runs.md` covering:

- manual `StartRun::forFlow()`;
- event-triggered starts;
- `core.start_flow` and maximum subflow depth;
- the current `StartRun` option keys: `idempotency_key`, `correlation_id`, `strategy`, and `is_test`;
- automatic `subject` versus `cohort` strategy;
- all-or-nothing audience materialisation;
- un-published flow rejection;
- idempotent race recovery;
- `SubjectExiter::exit()` and the lack of a packaged subject-to-live-runs lookup.

- [ ] **Step 4: Check and commit Task 5**

Run:

```bash
vendor/bin/pest tests/Feature/EventTriggerTest.php tests/Feature/SubFlowStarterTest.php tests/Feature/StartRunTest.php tests/Feature/AudienceMaterialiserTest.php tests/Feature/SubjectExiterTest.php
git diff --check
```

Expected: all selected tests pass and formatting exits 0.

Commit:

```bash
git add docs/gitbook/building-automations/writing-triggers.md docs/gitbook/building-automations/subject-attributes.md docs/gitbook/building-automations/starting-runs.md
git commit -m "docs: document triggers attributes and run starts"
```

## Task 6: Self-contained example application

**Files:**

- Create: `docs/gitbook/example-application/overview.md`
- Create: `docs/gitbook/example-application/application-setup.md`
- Create: `docs/gitbook/example-application/flood-alert-workflow.md`
- Create: `docs/gitbook/example-application/testing-the-workflow.md`
- Primary evidence: current package contracts, node, trigger, publishing, execution, and testing APIs used in Tasks 1–5
- Legacy evidence: `docs/07-worked-example-rada-yaya.md`
- Test style: `tests/Feature/CanonicalJourneyTest.php`, `tests/Feature/TestModeTest.php`, `tests/Feature/EventTriggerTest.php`

- [ ] **Step 1: Write the example overview**

Write `overview.md` describing an illustrative flood-alert journey:

```text
Flood alert → send alert → wait → send offer → wait → clicked?
    yes → exit
    no  → send follow-up → exit
```

State what the example teaches, its assumptions, and that messaging is represented by a host-owned node. Do not describe the example as a downloadable or hosted demo.

- [ ] **Step 2: Write application setup**

Write `application-setup.md` with the minimum host model columns, event payload, safe tenancy resolver, string-keyed subject resolver, provider bindings, gates, route group, and subject attributes. Use consistent `Organization`, `User`, `FloodAlertDispatched`, and `DemoMessage` names.

- [ ] **Step 3: Write the complete workflow**

Write `flood-alert-workflow.md` with complete `SendMessage` and `FloodAlertFires` classes, their registration, the complete valid graph array, flow creation, publishing, event dispatch, cancellation on conversion, and the queue-worker command. Ensure every node output has at most one outgoing edge and all ids/types match across prose and code.

- [ ] **Step 4: Write example tests**

Write `testing-the-workflow.md` with copyable Pest examples for:

- one event creating isolated runs per tenant;
- the published graph being pinned to a run;
- test mode suppressing message persistence while preserving routing;
- a converting subject exiting before the follow-up;
- an invalid graph being rejected without a version write.

Clearly identify package fakes or host fakes used in each test; do not invent a public testing helper that does not exist.

- [ ] **Step 5: Run a consistency audit and commit Task 6**

Run:

```bash
git diff --check
rg -n "Organization|FloodAlertDispatched|app\.flood_alert|app\.send_message|clicked_offer" docs/gitbook/example-application
```

Expected: formatting passes and the same domain/type names appear consistently across the four pages.

Commit:

```bash
git add docs/gitbook/example-application
git commit -m "docs: add self-contained example application"
```

## Task 7: Operations guides

**Files:**

- Create: `docs/gitbook/operations/durable-execution.md`
- Create: `docs/gitbook/operations/queues-and-workers.md`
- Create: `docs/gitbook/operations/test-mode.md`
- Create: `docs/gitbook/operations/health-checks.md`
- Create: `docs/gitbook/operations/pruning-and-retention.md`
- Create: `docs/gitbook/operations/troubleshooting.md`
- Read: `src/Engine/DurableWorkflowEngine.php`, `WorkflowEngine.php`, `FakeWorkflowEngine.php`
- Read: `src/Workflows/FlowInterpreter.php`, `src/Execution/InterpreterLoop.php`, `src/Execution/Steps/*.php`
- Read: `src/Execution/NodeRunner.php`, `AudienceMaterialiser.php`, `SubjectExiter.php`
- Read: `src/Console/CheckNodeTypesCommand.php`, `CheckNodeTypesResolver.php`, `PruneCommand.php`
- Read: `config/nodeflow.php`
- Tests: `tests/Feature/InterpreterLoopTest.php`, `FlowInterpreterSignalTest.php`, `NodeRunnerTest.php`, `TestModeTest.php`, `PruneCommandTest.php`, `NodeTypeResolutionTest.php`

- [ ] **Step 1: Document durable execution**

Write `durable-execution.md` explaining deterministic workflow code, side-effecting activities, interpreter cursor flow, graph pinning, wait placement, replay, step limits, chunking, convergent-branch deduplication, audience-empty signalling, cancellation semantics, and cohort-relative timing. Include a compact wait/resume sequence diagram with equivalent prose.

- [ ] **Step 2: Document queues and workers**

Write `queues-and-workers.md` covering non-`sync` queue configuration, durable-workflow migrations, workers or Horizon, cache atomic locks, deploy/restart implications, local development, and symptoms of a missing worker. Avoid claiming support for a deployment configuration that the repository has not exercised.

- [ ] **Step 3: Document test mode**

Write `test-mode.md` showing `is_test => true`, the node author's obligation to suppress externally visible side effects, what still executes and persists, a safe node example, a deliberately unsafe counterexample labeled as such, and the absence of an enforced sandbox or audience-wide projection mode.

- [ ] **Step 4: Document health checks**

Write `health-checks.md` covering `nodeflow:install --check`, `nodeflow:check-node-types`, live-run statuses used by the type check, type alias recovery, `check_node_types_on_boot`, error versus warning logs, once-per-process behavior, and suggested deployment placement.

- [ ] **Step 5: Document pruning and retention**

Write `pruning-and-retention.md` with `--dry-run`, configured and overridden windows, the exact terminal statuses currently pruned, explicit child deletion, a scheduler example, why blocked/nonterminal runs remain, and the separate durable-engine table-retention responsibility.

- [ ] **Step 6: Write troubleshooting**

Write `troubleshooting.md` as symptom → cause → verification → correction sections for: runs not moving, empty audiences, missing subject binding, unresolved tenant, missing event runs, stale draft conflicts, publish validation errors, missing styles, duplicate React, option-loading failures, unresolved live node types, halted overlay polling, and stuck active subjects. Link architectural constraints to `experimental/known-limitations.md` instead of presenting them as configuration fixes.

- [ ] **Step 7: Check and commit Task 7**

Run:

```bash
vendor/bin/pest tests/Feature/InterpreterLoopTest.php tests/Feature/FlowInterpreterSignalTest.php tests/Feature/TestModeTest.php tests/Feature/PruneCommandTest.php tests/Feature/NodeTypeResolutionTest.php
git diff --check
```

Expected: all selected tests pass and formatting exits 0.

Commit:

```bash
git add docs/gitbook/operations
git commit -m "docs: add Nodeflow operations guides"
```

## Task 8: Node packages and experimental status

**Files:**

- Create: `docs/gitbook/node-packages/creating-packages.md`
- Create: `docs/gitbook/node-packages/extracting-nodes.md`
- Create: `docs/gitbook/experimental/project-status.md`
- Create: `docs/gitbook/experimental/known-limitations.md`
- Read: `src/Console/MakeNodePackageCommand.php`, `PackageScaffolder.php`, `PackageTarget.php`
- Read: `src/Console/ExtractNodeCommand.php`, `NodeReferenceScanner.php`, `Extract/ExtractJournal.php`, `Extract/ComposerRunner.php`
- Read: `stubs/package/*`
- Read: `docs/superpowers/open-issues.md` and current source/tests for every still-open claim
- Tests: `tests/Feature/MakeNodePackageCommandTest.php`, `ExtractNodeGatesTest.php`, `ExtractNodeMovesTest.php`, `ExtractNodeVerificationTest.php`

- [ ] **Step 1: Document package creation**

Write `creating-packages.md` with the complete `nodeflow:make-node-package` signature, default path and namespace derivation, generated file tree, `--js`, `--path`, `--namespace`, `--force`, Composer path repository behavior, package service-provider registration, editor-control exports, and post-generation verification steps.

- [ ] **Step 2: Document node extraction**

Write `extracting-nodes.md` with the complete command signature, preconditions, statically fixed `type()` requirement, supported class-reference forms, scan boundaries, provider rewrites, test movement, Composer install and fresh-boot verification, rollback guarantees, special recovery exit codes if still present, and accepted inability to detect dynamic or database-stored references.

- [ ] **Step 3: Write the maturity statement**

Write `project-status.md` stating that Nodeflow is experimental pre-release software, what is implemented today, what “experimental” means for adopters, the expectation to test a canonical journey on their own queue/database stack, and a short evaluation checklist. Avoid stale suite counts and production-readiness language.

- [ ] **Step 4: Audit and write current limitations**

Write `known-limitations.md` only after validating each candidate against current source, tests, and unresolved open-issue statuses. Organize limitations by execution, scale, tenancy/security, editor/inspection, tooling, and database/CI. For each item state impact and available mitigation without presenting deferred architectural work as already solved. Exclude resolved issues and obsolete legacy-guide claims.

- [ ] **Step 5: Check and commit Task 8**

Run:

```bash
vendor/bin/pest tests/Feature/MakeNodePackageCommandTest.php tests/Feature/ExtractNodeGatesTest.php tests/Feature/ExtractNodeMovesTest.php tests/Feature/ExtractNodeVerificationTest.php
git diff --check
rg -n "RESOLVED|Plan [0-9]|[0-9]+ tests|production-ready" docs/gitbook/experimental docs/gitbook/node-packages || true
```

Expected: tests pass and formatting passes. The final search should have no matches unless a phrase is used explicitly to reject a claim; remove internal plan language before committing.

Commit:

```bash
git add docs/gitbook/node-packages docs/gitbook/experimental
git commit -m "docs: cover node packages and experimental status"
```

## Task 9: Configuration, commands, core nodes, and contracts reference

**Files:**

- Create: `docs/gitbook/reference/configuration.md`
- Create: `docs/gitbook/reference/artisan-commands.md`
- Create: `docs/gitbook/reference/core-nodes.md`
- Create: `docs/gitbook/reference/contracts.md`
- Read: `config/nodeflow.php`
- Read: all eight registered command classes in `src/Console/`
- Read: `src/Nodes/Core/*.php`
- Read: `src/Contracts/*.php`, `src/Nodes/*.php`, `src/Triggers/*.php`, `src/Schema/*.php`, and public `src/Execution/*Context.php`/`NodeResult.php`
- Tests: command, core-node, schema, and context tests under `tests/Feature` and `tests/Unit`

- [ ] **Step 1: Write configuration reference**

Write `configuration.md` as an exhaustive table of every current key in `config/nodeflow.php`: default, accepted values, runtime effect, and when to change it. Cover table prefix, both retention keys, all limit keys, tenancy, and boot-time node-type checks. Note when an environment variable exists and when the value is config-only.

- [ ] **Step 2: Write Artisan command reference**

Write `artisan-commands.md` with one subsection per registered command:

```text
nodeflow:install
nodeflow:make-node
nodeflow:make-trigger
nodeflow:make-subject-attribute
nodeflow:make-node-package
nodeflow:extract-node
nodeflow:check-node-types
nodeflow:prune
```

For each, transcribe arguments, options, defaults, exit behavior, written files, and common refusal conditions from current source. Link tutorial-oriented commands to their guides.

- [ ] **Step 3: Write core-node reference**

Write `core-nodes.md` with a table for `core.wait`, `core.condition`, `core.start_flow`, and `core.exit`. List label, cardinality, fields, outputs, defaults, behavior, and caveats. Include all condition operators and subflow depth behavior.

- [ ] **Step 4: Write contracts reference**

Write `contracts.md` as concise PHP-signature reference for host interfaces, node interfaces/base class, definitions/builders, trigger types, registries, contexts, `NodeResult`, `StartRun`, `PublishFlow`, and `SubjectExiter`. Separate interfaces a host must implement from classes a host commonly calls. State explicitly that `AudienceResolver` is present but is not currently resolved or called by the runtime, so binding it has no effect. Link each group to its guide.

- [ ] **Step 5: Compare reference names mechanically and commit Task 9**

Run:

```bash
rg -n "protected \$signature|protected \$name" src/Console
rg -n "public (static )?function" src/Contracts src/Nodes src/Triggers src/Schema src/Execution/SubjectContext.php src/Execution/AudienceContext.php src/Execution/NodeResult.php
git diff --check
```

Expected: every public host-facing method and every registered command shown by the source searches is represented or deliberately excluded as internal in the reference; formatting passes.

Commit:

```bash
git add docs/gitbook/reference/configuration.md docs/gitbook/reference/artisan-commands.md docs/gitbook/reference/core-nodes.md docs/gitbook/reference/contracts.md
git commit -m "docs: add configuration and API reference"
```

## Task 10: Routes, graph, statuses, and database reference

**Files:**

- Create: `docs/gitbook/reference/routes.md`
- Create: `docs/gitbook/reference/graph-format.md`
- Create: `docs/gitbook/reference/statuses.md`
- Create: `docs/gitbook/reference/database-schema.md`
- Read: `src/Http/routes.php`, controllers, policies, and `src/Http/ResolvesRouteNames.php`
- Read: `src/Graph/*.php`, `src/Publishing/*.php`
- Read: `database/migrations/2026_08_18_000001_create_nodeflow_tables.php`
- Read: `src/Models/*.php`, `src/Runs/*.php`, and execution status writers
- Tests: route, graph, run-overlay, pruning, execution, and tenancy tests

- [ ] **Step 1: Write route reference**

Write `routes.md` with exact method, URI, canonical name, response kind, authorization ability, and purpose for every package route. Document host prefix/middleware ownership, tenant-scoped route binding, `{node}` as graph id, and the no-route-name-prefix constraint.

- [ ] **Step 2: Write graph-format reference**

Write `graph-format.md` with a complete JSON example and tables for root, node, and edge keys. State optional draft shapes versus required publish meaning, stable ids/types/outputs, position preservation, start-node rules, validation constraints, and current no-cycle/no-parallel-output restrictions.

- [ ] **Step 3: Write status reference**

Write `statuses.md` from actual writers and tests, not only schema defaults. Separate statuses currently written by implementation from reserved or recognized statuses. Cover flow, run, and run-subject states, terminal semantics used by overlay polling and pruning, timestamp/error columns, and invariants such as no active subjects in a completed run.

- [ ] **Step 4: Write database reference**

Write `database-schema.md` with all six package tables, purpose, important columns, relationships, unique constraints, indexes, cascade behavior, tenant-scope ownership, and the distinction from durable-workflow engine tables. Explain why run subjects and node executions are aggregate/current-state records rather than full per-subject history.

- [ ] **Step 5: Compare source tables and routes and commit Task 10**

Run:

```bash
rg -n "Route::" src/Http/routes.php
rg -n "Schema::create|->unique|->index|cascadeOnDelete" database/migrations/2026_08_18_000001_create_nodeflow_tables.php
git diff --check
```

Expected: all seven routes and six package tables are present in the corresponding reference pages; formatting passes.

Commit:

```bash
git add docs/gitbook/reference/routes.md docs/gitbook/reference/graph-format.md docs/gitbook/reference/statuses.md docs/gitbook/reference/database-schema.md
git commit -m "docs: add runtime and storage reference"
```

## Task 11: Contributor documentation

**Files:**

- Create: `docs/gitbook/contributing/architecture.md`
- Create: `docs/gitbook/contributing/local-development.md`
- Create: `docs/gitbook/contributing/testing.md`
- Create: `docs/gitbook/contributing/project-structure.md`
- Read: `composer.json`, `package.json`, `phpunit.xml`, `vitest.config.ts`, `tsconfig.json`
- Read: `src/`, `resources/js/`, `tests/`, `stubs/`, `config/`, `database/`
- Read: `tests/Unit/ArchitectureTest.php`, `tests/Pest.php`, `tests/TestCase.php`
- Read: approved design specs only where code does not explain intent

- [ ] **Step 1: Write contributor architecture**

Write `architecture.md` explaining subsystem boundaries and these flows:

- registration → palettes → editor;
- draft save → revision check → publish validation → immutable version;
- trigger/manual start → audience materialisation → durable interpreter;
- interpreter → activities → node runner → subject advancement;
- run records → overlay → subject drill-down.

Explain why workflow code remains deterministic, why side effects belong in activities/nodes, and why the run client has no editor import. Use diagrams only where they improve multi-step understanding.

- [ ] **Step 2: Write local development setup**

Write `local-development.md` with clone, `composer install`, `npm ci`, supported runtimes, package Testbench context, local editor source compilation model, and focused development commands. Do not give application-specific setup instructions or absolute paths.

- [ ] **Step 3: Write testing workflow**

Write `testing.md` with exact commands:

```bash
vendor/bin/pest
npm test
npm run types:check
composer validate --strict
```

Explain unit versus feature coverage, Testbench, Vitest/jsdom, architecture tests, focused-test examples, when real host/browser/queue/database acceptance is still necessary, and why green unit suites cannot prove host asset wiring.

- [ ] **Step 4: Write project structure**

Write `project-structure.md` as a directory table for `src` subdomains, `resources/js`, `tests`, `stubs`, `config`, `database`, `docs/gitbook`, and legacy `docs`. Identify public entry points and internal implementation areas. State that the JavaScript source is host-compiled and the repository's npm package is private development tooling.

- [ ] **Step 5: Check and commit Task 11**

Run:

```bash
composer validate --strict
npm run types:check
git diff --check
```

Expected: Composer validates, TypeScript exits 0 without output, and formatting passes.

Commit:

```bash
git add docs/gitbook/contributing
git commit -m "docs: add contributor documentation"
```

## Task 12: Navigation and root README handoff

**Files:**

- Create: `docs/gitbook/SUMMARY.md`
- Modify: `README.md`
- Verify: every Markdown file under `docs/gitbook/`

- [ ] **Step 1: Write complete GitBook navigation**

Create `docs/gitbook/SUMMARY.md` with `# Table of contents`, a home link, and these groups in order:

```text
Getting Started
Integrating Nodeflow
Building Automations
Editor and Run View
Example Application
Operations
Node Packages
Reference
Contributing
Experimental
```

List every page from the approved information architecture exactly once. Nest pages under their group and preserve the main learning path at the top.

- [ ] **Step 2: Refresh the root README**

Rewrite `README.md` as a compact repository landing page containing:

- one-paragraph value proposition;
- prominent experimental warning;
- current requirements from `composer.json`;
- correct three-command installation using `nodeflow:install`;
- a concise capabilities list;
- a link to `docs/gitbook/README.md` as canonical documentation;
- links to quick start, example application, experimental status, and contributing pages;
- a short note that older numbered guides are legacy references.

Remove stale test counts, claims that install/package commands do not exist, and outdated real-worker statements.

- [ ] **Step 3: Check navigation coverage**

Run:

```bash
test "$(find docs/gitbook -type f -name '*.md' | wc -l | tr -d ' ')" = "49"
test "$(rg -c '^\s*\* \[[^]]+\]\([^)]+\.md\)' docs/gitbook/SUMMARY.md)" = "48"
git diff --check
```

Expected: 49 Markdown files including `SUMMARY.md`, 48 navigation links for the home page plus 47 content pages, and no whitespace errors.

- [ ] **Step 4: Commit navigation and README**

```bash
git add README.md docs/gitbook/SUMMARY.md
git commit -m "docs: make GitBook the canonical guide"
```

## Task 13: Full documentation verification

**Files:**

- Verify: `README.md`
- Verify: `docs/gitbook/**/*`
- Verify untouched: `src/**/*`, `resources/js/**/*`, `tests/**/*`, `docs/01-*.md` through `docs/09-*.md`

- [ ] **Step 1: Run the internal link and anchor validator**

Run this read-only validator from the repository root:

```bash
php <<'PHP'
<?php

$root = realpath('docs/gitbook');
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

foreach ($iterator as $item) {
    if ($item->isFile() && $item->getExtension() === 'md') {
        $files[] = $item->getPathname();
    }
}

$anchors = [];

foreach ($files as $file) {
    $seen = [];
    $anchors[$file] = [];

    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^#{1,6}\s+(.+)$/u', $line, $match) !== 1) {
            continue;
        }

        $heading = preg_replace('/`([^`]+)`/u', '$1', trim($match[1]));
        $slug = strtolower($heading);
        $slug = preg_replace('/[^\pL\pN _-]/u', '', $slug);
        $slug = preg_replace('/\s+/u', '-', trim($slug));
        $base = $slug;
        $suffix = $seen[$base] ?? 0;
        $seen[$base] = $suffix + 1;

        if ($suffix > 0) {
            $slug .= '-'.$suffix;
        }

        $anchors[$file][$slug] = true;
    }
}

$errors = [];

foreach ($files as $file) {
    $contents = file_get_contents($file);

    preg_match_all('/\[[^\]]+\]\(([^)]+)\)/u', $contents, $matches);

    foreach ($matches[1] as $target) {
        $target = trim(explode(' ', $target, 2)[0], '<>');

        if ($target === '' || preg_match('/^(https?:|mailto:)/', $target) === 1) {
            continue;
        }

        [$path, $fragment] = array_pad(explode('#', $target, 2), 2, '');
        $resolved = $path === '' ? $file : realpath(dirname($file).'/'.urldecode($path));

        if ($resolved === false || ! is_file($resolved)) {
            $errors[] = substr($file, strlen($root) + 1).": missing target {$target}";
            continue;
        }

        if ($fragment !== '' && ! isset($anchors[$resolved][urldecode($fragment)])) {
            $errors[] = substr($file, strlen($root) + 1).": missing anchor {$target}";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);
    exit(1);
}

echo 'All internal GitBook links and anchors resolve.'.PHP_EOL;
PHP
```

Expected: `All internal GitBook links and anchors resolve.` and exit 0.

- [ ] **Step 2: Verify page inventory, navigation, privacy, and formatting**

Run:

```bash
test "$(find docs/gitbook -type f -name '*.md' | wc -l | tr -d ' ')" = "49"
test "$(rg -c '^\s*\* \[[^]]+\]\([^)]+\.md\)' docs/gitbook/SUMMARY.md)" = "48"
! rg -n "/Users/|Sites/|Plan [0-9]|[0-9]+ tests|[0-9]+ assertions" README.md docs/gitbook
git diff --check HEAD~12..HEAD
```

Expected: both counts match, the forbidden-string scan returns no matches, and the documentation commit range has no whitespace errors. If the actual task commit count differs from twelve, replace `HEAD~12` with the commit immediately before Task 1.

- [ ] **Step 3: Verify the current source-facing facts**

Run:

```bash
rg -n "'tables'|'retention'|'limits'|'tenancy'|'check_node_types_on_boot'" config/nodeflow.php
rg -n "protected \$signature|protected \$name" src/Console
rg -n "Route::" src/Http/routes.php
rg -n "Schema::create" database/migrations/2026_08_18_000001_create_nodeflow_tables.php
rg -n "export \{|export type|export function" resources/js/index.ts resources/js/editor/FlowEditor.tsx resources/js/run/FlowRun.tsx resources/js/run/useOverlayPolling.ts
```

Expected: manually compare every emitted public fact to its corresponding reference page and correct any mismatch before proceeding.

- [ ] **Step 4: Run full repository verification**

Run:

```bash
vendor/bin/pest
npm test
npm run types:check
composer validate --strict
```

Expected: Pest exits 0 with no failures, Vitest exits 0 with no failures, TypeScript exits 0 without diagnostics, and Composer reports valid metadata.

- [ ] **Step 5: Inspect scope and make a final correction commit if needed**

Run:

```bash
git status --short
git diff --name-only HEAD~12..HEAD
git log --oneline -13
```

Expected: the implementation changes only `README.md` and `docs/gitbook/**`; pre-existing unrelated working-tree files remain untouched. If verification required documentation corrections, commit only those files:

```bash
git add README.md docs/gitbook
git commit -m "docs: correct GitBook verification findings"
```

Do not include unrelated untracked or modified files in any documentation commit.
