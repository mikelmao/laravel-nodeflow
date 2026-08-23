# First-class trigger nodes

**Date:** 2026-08-24
**Status:** Approved design; implementation has not started

## 1. Goal

Make initiation an explicit, versioned part of every Nodeflow graph. A publishable flow must begin
with exactly one trigger node, and Nodeflow must ship three trigger nodes by default:

1. a signed webhook trigger;
2. an Eloquent model-observer trigger; and
3. a Laravel event trigger.

The defaults must be reference implementations of public extension contracts. Host applications and
third-party packages must be able to add specialized trigger nodes, reuse a built-in trigger driver,
register allowlisted domain sources, or introduce an entirely new activation driver without changing
Nodeflow itself.

This is a deliberate pre-release break. No application uses the package yet, so the design does not
retain the current flow-level trigger columns, current `Trigger` class contract, or legacy published
graphs.

## 2. Verified starting state

The design session inspected the current graph, publisher, run starter, trigger registry and listener,
database migration, editor controller and React editor.

The relevant current facts are:

- `nodeflow_flows` stores mutable `trigger_type` and `trigger_config` values.
- Published flow versions contain only `start`, executable nodes and edges.
- `graph.start` names the first executable node; there is no trigger node.
- `EventTriggerListener` finds active flows by tenant and `trigger_type`, applies the registered
  trigger's config matcher and calls `StartRun`.
- `StartRun` pins the current version, materializes the audience at `graph.start` and starts the
  durable interpreter.
- The trigger palette reaches the editor, but the current editor only displays the selected trigger's
  label. It cannot add, replace or configure a trigger on the canvas.
- Package routes contain editing, publishing and run-inspection endpoints, but no manual-start or
  webhook endpoint.
- The executable `NodeRegistry` requires subject or audience cardinality, which a non-executable
  trigger cannot truthfully implement.
- The graph validator assumes every stored node resolves through the executable registry.
- The package is tenant-scoped and requires every run audience member to pass
  `TenantResolver::ownsSubject()`.
- There is no `almanac/` directory in this repository, so no CodeAlmanac context was available.

## 3. Chosen architecture

### 3.1 Trigger nodes are graph entry components

Every publishable graph contains exactly one registered trigger node:

```text
Trigger --started--> First executable node
```

The existing `graph.start` field points to the trigger node. A trigger node:

- accepts no incoming edges;
- exposes exactly one output named `started`;
- has exactly one outgoing `started` edge;
- is validated through the trigger-node registry;
- is persisted in the immutable flow-version graph; and
- is never dispatched through `NodeRunner` or recorded as a node execution.

The durable interpreter begins at the executable node targeted by the trigger's `started` edge.
Manual and sub-flow starts also bypass trigger execution and enter through that edge.

Drafts may temporarily contain no trigger or other invalid structure because autosave must preserve
work in progress. Validation and publication enforce exactly one trigger, require `graph.start` to
equal its ID and reject an executable node as the graph start. They also reject multiple trigger
nodes, incoming trigger edges, missing or extra outgoing edges, a non-`started` trigger output and an
edge from a trigger to another trigger.

### 3.2 Activation is an indexed projection

The immutable graph is the authoring source of truth. External dispatch cannot efficiently search
JSON graphs, so publication compiles the current trigger node into an indexed activation projection.

`nodeflow_trigger_activations` contains one current activation per flow:

- `id`;
- unique `flow_id`;
- unique `flow_version_id`;
- indexed `tenant_id`;
- indexed stable `driver` key;
- indexed stable `source` key;
- nullable indexed `qualifier`, used by the model driver for its lifecycle event and available to
  custom drivers;
- `trigger_node_id`;
- JSON `descriptor`, containing only driver-owned routing metadata; and
- timestamps.

The activation repository returns only activations whose parent flow is `active`. Pausing a flow
therefore takes effect without duplicating flow status into the projection.

Publication validates and compiles the trigger before writing. In one database transaction it
creates the immutable version, replaces the prior activation, updates `current_version_id`, clears
the published draft and makes the flow active. Any validation, compilation or projection failure
rolls the whole operation back and leaves the previous version and activation live.

An occurrence uses the exact `flow_version_id` from the activation snapshot it matched. A concurrent
publication cannot move that occurrence to a newer graph whose trigger configuration it never
matched.

### 3.3 Stable webhook identity is separate from versioned configuration

`nodeflow_webhook_endpoints` contains at most one endpoint per flow:

- `id`;
- unique `flow_id`;
- a unique cryptographically random opaque URL token;
- an encrypted signing secret;
- `secret_rotated_at`; and
- timestamps.

The endpoint is created on the first webhook-trigger publication. Republishing retains the token and
secret. Publishing another trigger driver makes the endpoint inactive because no current webhook
activation exists, but does not delete its identity. Publishing a webhook trigger again reuses it.

The URL token is an identifier rather than the request credential and may be stored in recoverable
form so the editor can continue displaying the URL. The signing secret is encrypted at rest because
the server needs the original key to verify HMAC signatures. The editor reveals the plaintext only
in the response that creates or rotates it. A lost secret is rotated, not recovered through a read
endpoint.

### 3.4 Rejected alternatives

#### A separate `graph.trigger` object rendered as a node

This keeps triggers away from executable nodes but creates two graph concepts that the editor
pretends are one. Layout, selection, validation, exports and future tooling would carry permanent
special cases.

#### Executing the trigger through the interpreter

The external occurrence must create a run before the interpreter exists, so executing the trigger
inside that run reverses causality. It would add fictitious execution records and still require a
hidden pre-run trigger system.

#### Searching published graph JSON during dispatch

This avoids a projection table but produces database-specific JSON queries, weak indexes and race
ambiguity around current versions. The activation projection is deliberately derived and
transactional instead.

## 4. Public extension model

### 4.1 Type families and collision safety

Executable and trigger nodes share the graph's type namespace but have separate behavior contracts.
A central graph-type catalog records each stable type as either `executable` or `trigger` and rejects
duplicates across both families. `NodeRegistry` and `TriggerNodeRegistry` delegate type ownership and
alias resolution to that catalog.

No graph, activation, URL or request accepts a PHP class name. Persisted nodes, drivers and sources
use stable registered string keys only.

### 4.2 TriggerNode

`TriggerNode` is the authoring contract for a non-executable graph entry. The package provides an
abstract base class for field-backed validation and default configuration, but extensions depend on
the interface rather than being required to inherit from a built-in concrete class.

The contract provides:

- a static stable node `type()`;
- a `TriggerDefinition` for label, description, icon and fields;
- a stable `driver()` key;
- default configuration and validation;
- compilation of validated config into a `TriggerActivationDescriptor`; and
- the compatible source key selected by that configuration.

`TriggerActivationDescriptor` is an immutable JSON-safe value containing `driver`, `source`, optional
`qualifier` and driver-owned `metadata`. The descriptor cannot contain class names, closures, models,
events or requests.

A custom node may compile to a built-in driver. For example, a Stripe-specific trigger can hard-code
an allowlisted Stripe source and compile a webhook descriptor while presenting narrower fields than
the generic webhook trigger.

### 4.3 TriggerDriver

`TriggerDriver` owns an activation mechanism. Its public lifecycle covers:

- a static stable driver key;
- validation of compatible activation descriptors;
- notification when a compatible source is registered;
- bootstrapping any listeners or external entry points;
- looking up candidate activations through `TriggerActivationRepository`; and
- handing occurrences to the common occurrence dispatcher.

Driver registration is order-safe. Built-in drivers register before host extensions. Registering a
source notifies its driver immediately, allowing model and event drivers to attach a listener when a
host provider boots after Nodeflow's provider. Each driver must deduplicate its listeners by source
class or external event identity.

A driver never creates `Run`, `RunSubject` or engine workflow records directly. It passes occurrences
through the common dispatcher and `TriggerRunStarter`, which preserve versioning, tenancy,
idempotency and error isolation.

### 4.4 TriggerSource

`TriggerSource` is the allowlisted boundary between domain objects and Nodeflow. Every source
declares:

- a stable source key unique within its driver;
- its driver key;
- a definition containing source-specific authoring fields and rules; and
- how a compatible `TriggerOccurrence` and candidate activation configuration become a
  `TriggerMatch`.

The common occurrence DTO identifies the driver and source, carries a driver-specific in-memory
payload and provides the activation snapshot. Typed webhook, model and Laravel-event occurrence
payloads prevent the built-ins from passing unstructured arrays internally. A custom driver may add
its own typed occurrence payload while still using the common envelope.

The source definition's fields are merged into the trigger inspector. Their values remain flat in
the trigger node's `config` object so existing field controls do not need a nested-path language.
The combined authoring-schema builder rejects a source field key that collides with the selected
trigger node's reserved driver fields. This check happens when the pair is exposed or compiled,
because one source may be compatible with several custom nodes whose reserved fields differ.

### 4.5 TriggerMatch and TriggerRunStarter

`TriggerMatch` is immutable and carries zero or more tenant matches. Each tenant match contains:

- one subject type;
- normalized string subject IDs;
- a sanitized JSON-safe run-level trigger-data array; and
- an optional stable occurrence identity.

`TriggerRunStarter` accepts an activation and one tenant match. It verifies that the match tenant is
the activation tenant, verifies every subject through `TenantResolver::ownsSubject()`, resolves the
trigger's sole `started` target, creates a run pinned to the activation's version and starts the
durable interpreter.

Occurrence identities are namespaced by driver and source and normalized to a fixed hash before
being used as the run idempotency key. This prevents collisions between drivers and keeps arbitrary
external keys within the database column limit.

One activation's failure is reported through Laravel's exception handler and does not prevent other
matching activations or tenants from starting.

### 4.6 Registration API

The facade exposes:

```php
Nodeflow::registerTriggerNodes([...]);
Nodeflow::registerTriggerDrivers([...]);
Nodeflow::registerTriggerSources([...]);
```

The host provider has explicit registration arrays for the same three families. It registers custom
drivers first, then nodes, then sources. The three built-in drivers and trigger nodes register
unconditionally in `NodeflowServiceProvider`; host applications register only their allowlisted
domain sources unless they are extending the system.

Invalid classes, duplicate keys, cross-family node-type collisions, unknown drivers and incompatible
sources throw descriptive registration exceptions rather than silently replacing an entry.

## 5. Built-in trigger nodes and drivers

### 5.1 Webhook trigger

`WebhookTriggerNode` uses `WebhookTriggerDriver`. Its required base configuration is `source`. The
selected webhook source may contribute additional filter and validation fields.

The host opts into webhook routes separately from authenticated editor routes:

```php
Route::prefix('nodeflow')->group(fn () => Nodeflow::webhookRoutes());
```

This preserves host ownership of prefix, domain, rate limiting, body-size middleware and other HTTP
policy. The webhook route must not inherit session authentication or CSRF requirements intended for
the editor.

The endpoint shape is `POST hooks/{token}`. Every request requires:

- `X-Nodeflow-Timestamp`, expressed as Unix seconds;
- `X-Nodeflow-Signature`, formatted as `sha256=<hex digest>`;
- `Idempotency-Key`; and
- a request timestamp inside `nodeflow.webhooks.replay_window_seconds`, defaulting to 300 seconds.

The signature input is the timestamp, one literal period and the exact raw request body. Verification
uses HMAC-SHA256 and `hash_equals()`. JSON decoding, source validation and audience resolution happen
only after the token, timestamp and signature pass.

Responses are:

- `404` for an unknown token, inactive flow or non-webhook current activation;
- `401` for a missing, malformed, expired or invalid signature;
- `422` for a missing idempotency key, malformed JSON or source validation failure;
- `202` with run identity and `duplicate: false` for an accepted occurrence; and
- `202` with the existing run identity and `duplicate: true` for a retry.

A start failure returns a non-success response and is reported, allowing the sender to retry with the
same idempotency key. The endpoint does not log raw bodies or signing secrets.

A webhook source must return exactly one non-empty audience for the activation's tenant. Returning no
audience, another tenant or several tenants is a `422` source-resolution failure. Model and event
sources may legitimately return no match or several tenant matches because those drivers fan one
domain occurrence across candidate activations.

### 5.2 Model-observer trigger

`ModelObserverTriggerNode` uses `ModelObserverTriggerDriver`. Its reserved configuration fields are:

- required `source`;
- required singular `event`, one of `created`, `updated`, `deleted` or `restored`; and
- optional `changed_fields`, valid only for `updated`.

Each model source declares one allowlisted Eloquent model class. Source registration attaches only
the four supported post-event listeners to that class. Pre-events and overlapping `saving` / `saved`
aliases are deliberately excluded.

At model-event emission, the driver snapshots candidate activations and an immutable
`ModelOccurrence`: model class and string key, connection name, lifecycle event, attributes,
original values and changed fields. It does not retain the mutable model instance for deferred work.
For `updated`, an activation whose configured changed fields do not intersect the occurrence changes
is skipped.

The occurrence is processed through the model connection's after-commit facility. With no active
transaction the callback runs immediately; after a successful outer commit it runs once; after a
rollback it never runs. The activation snapshot pins the trigger version that was active when the
model event was emitted.

Model sources decide what subset becomes persisted trigger data. Capturing an internal occurrence
does not automatically persist all model attributes. Query-builder and mass-update operations do not
emit Eloquent model events and do not activate this driver.

### 5.3 Laravel-event trigger

`LaravelEventTriggerNode` uses `LaravelEventTriggerDriver`. Its required base configuration is
`source`; the selected source contributes any event filters.

Each Laravel-event source declares exactly one allowlisted event class. Source registration attaches
one listener per distinct event class. One event listener snapshots matching activations and fans out
through every compatible source without double-processing when several sources share the event.

The source extracts tenant audiences, a stable occurrence identity where delivery can repeat,
filters and sanitized trigger data. Nodeflow never reflects over or automatically serializes the
event object.

The listener follows Laravel's normal dispatch timing. Hosts that dispatch an event within a database
transaction and require committed state use Laravel's after-commit event contract. Nodeflow does not
silently change the timing of general application events.

## 6. Run model and execution contexts

The base migration removes `trigger_type` and `trigger_config` from `nodeflow_flows`. It adds the two
trigger tables described above and these run columns:

- non-null `started_via`, containing a driver key or the reserved `manual` / `subflow` values;
- non-null `trigger_node_id`; and
- nullable JSON `trigger_data`.

Driver registration rejects the reserved `manual` and `subflow` keys.

`StartRun::forFlow()` remains the authorized host-facing manual-start API. It selects the current
version, resolves the trigger's `started` target and records `started_via = manual`. It does not
require the configured external trigger to fire. `SubFlowStarter` performs the same bypass with
`started_via = subflow` and carries the parent run's trigger data into the child run because the child
continues the same occurrence context within the same tenant.

Triggered starts use an internal exact-version API reached only through `TriggerRunStarter`.

`SubjectContext` and `AudienceContext` expose the run-level snapshot through:

```php
public function triggerData(?string $key = null, mixed $default = null): mixed;
```

With no key it returns the complete array. With a key it performs a direct top-level lookup and
returns the supplied default when absent. This release does not introduce dot-path expressions,
template interpolation, per-subject trigger data or arbitrary variables.

The run overlay includes the trigger card without inventing a `NodeExecution`. A run started through
its driver shows an entry-occurrence badge. A manual or sub-flow run shows the trigger as bypassed.
The pinned graph and `trigger_node_id` provide all information needed for that decoration.

## 7. Editor behavior

### 7.1 Library and canvas

The Node Library receives a dedicated Triggers section before executable-node groups. It contains the
three built-ins and every registered custom trigger node.

Trigger cards are visually distinct but use the package's standard card wrapper. They have:

- no target handle;
- one `started` source handle;
- `TRIGGER` and `START` badges; and
- a concise driver/source summary.

The editor allows at most one trigger during normal interactions. With no trigger, clicking or
dragging a trigger adds and selects it and sets `graph.start`. With one present, choosing another
offers an explicit Replace trigger action; replacement preserves the existing trigger's outgoing
target when possible and resets incompatible configuration. Deleting the trigger clears
`graph.start` and leaves a saveable but unpublishable draft.

Ordinary nodes no longer expose Set as start. Connections targeting a trigger and trigger-to-trigger
connections are refused in the client and independently rejected by server validation.

### 7.2 Inspector and palette

The editor payload includes separate typed palettes for trigger nodes, drivers and compatible
sources. No client branch checks a built-in PHP class name or assumes only three drivers.

The trigger inspector renders:

1. fields declared by the trigger node;
2. its compatible allowlisted source selector;
3. source-contributed fields and filters; and
4. driver-specific fields such as model lifecycle event and changed fields.

Source-contributed values remain flat alongside reserved node fields. The combined field definition
is the authority for default config, field rendering and validation.

The three built-in trigger nodes remain visible when the host has registered no compatible sources.
They render a clear empty state and cannot publish until a source exists and is selected.

### 7.3 Publication and webhook management

Draft autosave persists the whole graph, including trigger position and configuration, but does not
change live activation.

Validate and Publish return structured errors attached to the trigger node and source field where
possible. Successful first webhook publication additionally returns the one-time plaintext signing
secret and stable endpoint URL. Later edit responses return URL, activation status and rotation time,
not the secret.

Authenticated webhook-management endpoints use the flow's existing update/publish authorization
boundary. Rotate secret creates a new random secret transactionally, returns it once and immediately
invalidates the prior value.

## 8. Security and operational behavior

- Allowlisted stable keys are the only bridge from persisted configuration to application classes.
- Trigger registration, graph validation and activation compilation independently enforce type and
  driver compatibility.
- The activation tenant comes from the already-authorized flow/version lineage, never request or
  event input.
- A source tenant match must equal the activation tenant before audience materialization.
- `TenantResolver::ownsSubject()` still checks every normalized subject ID.
- Trigger data must be JSON-safe and is size-limited by a new
  `nodeflow.limits.trigger_data_bytes` setting before the run transaction begins.
- Webhook secrets and endpoint tokens use cryptographically secure randomness.
- HMAC comparison is constant-time and happens against the exact raw request bytes.
- Raw webhook bodies, model snapshots, Laravel event objects and signing secrets are excluded from
  package logs and exception context.
- Triggered fan-out reports failures per activation and continues other activations.
- A missing node, driver or source registration for an active projection is a health-check failure;
  runtime dispatch reports it and does not guess or fall back.
- Existing engine-start failure semantics remain unchanged: a committed pending run can remain when
  workflow-engine startup fails and must be operationally monitored.

The node-type health command expands to verify trigger node, driver and source registrations
referenced by active projections and live pinned versions.

## 9. Tooling and host integration

The installer scaffolds host provider anchors:

```php
protected array $triggerNodes = [];
protected array $triggerDrivers = [];
protected array $triggerSources = [];
```

It registers arrays in driver, node, source order. Existing node and subject-attribute anchors remain.

Generator behavior becomes:

- `nodeflow:make-trigger NAME --driver=KEY` scaffolds a custom `TriggerNode` and registration;
- `nodeflow:make-trigger-source NAME --driver=KEY` scaffolds a compatible allowlisted source and
  registration; and
- `nodeflow:make-trigger-driver NAME --key=KEY` scaffolds a driver, a minimal reference trigger node,
  registration entries and contract tests.

Commands validate names and stable keys, refuse collisions and print an exact manual registration
fallback when the provider anchor cannot be edited safely. Generated classes use public contracts
and immutable DTOs; they do not subclass one of the three concrete built-ins.

Documentation covers:

- the three default triggers;
- webhook signing and retry examples;
- model after-commit and bulk-update limitations;
- Laravel event timing;
- source allowlisting and trigger-data sanitization;
- specialized trigger nodes on built-in drivers;
- complete custom driver implementation; and
- manual and sub-flow bypass semantics.

## 10. Testing strategy

Implementation follows test-driven slices. Required coverage includes:

### Registries and contracts

- valid built-in and custom registrations;
- invalid classes and incompatible sources;
- duplicate driver/source keys and cross-family graph-type collisions;
- provider ordering and listener deduplication; and
- a fake custom driver/node/source proving there are no built-in type switches.

### Graph and publication

- zero, one and multiple trigger nodes;
- start identity, edge direction, output and trigger-to-trigger rules;
- combined node/source field validation;
- immutable trigger configuration in flow versions;
- atomic activation replacement and rollback;
- active-flow filtering; and
- exact-version dispatch during a concurrent publication.

### Webhooks

- token lookup and inactive endpoints;
- signature format, raw-body verification, timestamp window and constant-time comparison boundary;
- required idempotency key and duplicate responses;
- malformed JSON, source rejection and size limits;
- stable URL across publications and trigger changes;
- one-time secret issuance and transactional rotation; and
- retry behavior after run-start failures.

### Model observers

- all four lifecycle events;
- update changed-field filters;
- immediate no-transaction handling;
- successful outer commit, nested transactions and rollback;
- immutable snapshots, including deleted and restored models;
- exact activation snapshot semantics; and
- explicit proof that query-builder mass updates do not fire.

### Laravel events and runs

- real dispatcher registration, shared event listener deduplication and source filtering;
- multi-tenant fan-out and isolated failures;
- source occurrence idempotency;
- trigger-data persistence and size rejection;
- both execution contexts' `triggerData()` API;
- manual and sub-flow bypass, including child trigger-data propagation; and
- run-overlay entry and bypass decorations without trigger node executions.

### Editor and package integration

- trigger library grouping and empty-source states;
- add, replace, delete, reconnect and configure behavior;
- automatic `graph.start` ownership;
- client and server validation parity;
- custom trigger rendering without built-in checks;
- webhook URL and secret-rotation UI;
- PHP and TypeScript public-boundary tests;
- installer and all three generators; and
- the complete package test, type-check and build suites.

## 11. Scope boundaries

This feature does not include:

- schedules as a fourth built-in driver;
- arbitrary model or event class names entered by authors;
- unsigned webhooks;
- automatic event/model/request serialization;
- expression syntax, template interpolation or a general variable system;
- per-subject trigger payloads;
- multiple triggers in one flow;
- trigger nodes executed as durable activities;
- automatic interception of query-builder model updates; or
- legacy schema, API or graph migration.

A custom schedule, queue, Kafka or other driver is supported through the extension contracts, but its
implementation is outside this feature.

## 12. Acceptance criteria

The feature is complete when:

1. every publishable graph contains exactly one valid trigger node and one executable entry edge;
2. webhook, model-observer and Laravel-event trigger nodes work end to end;
3. trigger configuration is immutable in the published graph and live dispatch uses its atomic
   activation projection;
4. stable signed webhook URLs, replay protection and idempotent retries behave as specified;
5. model triggers fire after commit and never for rolled-back changes;
6. sanitized trigger data is pinned to the run and available in both node contexts;
7. manual and sub-flow starts bypass the trigger without executing or fabricating it;
8. a third-party custom driver, node and source register and start a run without package edits;
9. editor authoring, validation, webhook management and run overlays expose the new model clearly;
10. the rewritten installer, generators, reference docs and integration guides describe the public
    contracts accurately; and
11. all PHP, JavaScript/TypeScript, installation, architecture and build checks pass.
