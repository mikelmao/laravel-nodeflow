# Portia, Yaya, and Rada Workflow Architecture

**Date:** 2026-08-24  
**Status:** Approved design  
**Scope:** Weather-triggered, FSP-configured customer workflows orchestrated by Portia with customer interactions executed by Yaya

## Summary

Portia is the workflow control plane and runtime. It uses Nodeflow to own workflow definitions, immutable published versions, runs, subject progress, waits, retries, branches, and terminal status. Rada supplies weather-alert facts. Yaya remains the system of record for users, personally identifiable information, messaging, customer-facing offer pages, and offer acceptance.

Portia does not delegate workflow durability to Yaya. When a workflow reaches a Yaya-facing node, Nodeflow executes one durable node activity whose adapter submits bounded, idempotent domain commands to Yaya. Yaya durably accepts or rejects each command, performs its local work, and reports later customer events back to Portia. Yaya never chooses the next node or owns the workflow timer.

This boundary lets each service own the behavior closest to its data while preserving Nodeflow's principal value: durable orchestration in Portia.

## Goals

- Let an FSP configure and publish a complete workflow in Portia.
- Trigger that workflow from a Rada weather alert.
- Resolve and message audiences of roughly 10,000–100,000 Yaya users without copying their profiles into Portia.
- Support durable waits such as “send an offer after one hour.”
- Branch or suppress later actions when a user has accepted an offer.
- Preserve tenant isolation where one Portia organization maps to one Yaya FSP.
- Survive retries, duplicate events, worker crashes, network ambiguity, and delayed callbacks without duplicate customer actions.
- Keep Portia-owned message and offer content versioned and reproducible while allowing Yaya to send and render it independently.

## Non-goals for the first version

- Mirroring Yaya user profiles or PII into Portia.
- Replacing Yaya's messaging queues, provider integrations, delivery logs, or offer pages.
- Requiring a message broker before the HTTP integration is proven.
- Branching on carrier delivery, read receipts, or link views.
- Waking arbitrary per-user workflow waits from arbitrary external events.
- Letting Yaya author or mutate Portia workflow content.

## Architectural principles

### Orchestration and domain execution are separate

Portia owns when and why a step runs. Yaya owns how a Yaya domain action is performed. A Yaya-facing workflow node is therefore an adapter around a durable Nodeflow activity, not a second workflow engine.

### Commands and facts cross service boundaries

Portia sends commands such as “accept this SMS campaign for this audience chunk.” Yaya returns an acknowledgement after recording the command durably. Yaya later emits facts such as “offer accepted.” Neither service reaches directly into the other's database.

### At-least-once transport, effectively-once domain effects

HTTP calls and callbacks may be repeated. Every cross-service message has a stable identity, and each receiver records that identity before applying the effect. Duplicate transport is expected; duplicate customer effects are not.

### Published workflows are immutable

An active run is pinned to a published workflow version and immutable content artifact versions. Editing a draft cannot change a running workflow or the page a customer is already viewing.

## Service ownership

| Concern | Rada | Portia | Yaya |
|---|---|---|---|
| Weather data and alert decision | Owns | Reads alert facts | Does not own |
| Alert occurrence and source identity | Owns | Records trigger receipt | May receive only through commands |
| Workflow authoring and published versions | Does not own | Owns | Does not own |
| Workflow execution, waits, retry policy, branching | Does not own | Owns through Nodeflow | Does not own |
| FSP workflow content and offer configuration | Does not own | Owns canonical editable content | Stores immutable execution projection |
| Users, FSP customer membership, PII | Does not own | Stores opaque IDs only | Owns |
| Town-to-user audience resolution | Supplies affected towns | Requests and consumes snapshot | Owns and freezes snapshot |
| SMS provider integration and delivery state | Does not own | Does not own | Owns |
| Secure customer links and landing-page rendering | Does not own | Defines versioned content | Owns runtime generation and rendering |
| Offer instance and acceptance transaction | Does not own | Stores minimal workflow fact | Owns authoritative state |
| Workflow run observability | Does not own | Owns | Exposes command/event correlation |

## Tenant model and identity

One Portia organization maps to exactly one Yaya FSP. Portia stores the external `yaya_fsp_id` on the organization integration configuration. All commands, audience requests, artifacts, and callbacks carry both the Portia organization identity and the mapped Yaya FSP identity.

Credentials are scoped to this mapping. Yaya must derive or validate the permitted FSP from the authenticated integration, rather than trusting an arbitrary FSP identifier in the request body. Portia likewise resolves callbacks through the configured integration and rejects events whose FSP mapping does not match.

Portia may persist:

- Opaque Yaya user IDs used as Nodeflow subject identifiers.
- External audience snapshot IDs and cursors.
- Offer/action correlation IDs.
- Minimal workflow facts such as `offer_accepted_at`.
- Command, event, and trace identifiers.

Portia must not persist customer names, phone numbers, addresses, or other Yaya-owned profile data merely to execute these workflows.

## Content and artifact ownership

FSP users create message templates, offers, landing-page fields, calls to action, and translations in Portia. These are canonical Portia resources.

Publishing a workflow freezes all referenced content into an immutable `delivery_artifact_version`. Portia synchronizes that version to Yaya using an idempotent artifact command. Yaya validates it, performs any provider-specific binding, stores a read-only execution projection, and returns a `yaya_artifact_version_id`. The published Portia workflow version pins both identities.

This projection is deliberate, not shared authorship:

- Portia remains the only place where the FSP edits the content.
- Yaya cannot mutate the projection.
- Yaya can send messages and render an already-issued page while Portia is unavailable.
- Existing runs and links continue to use the version they were issued with.
- New edits require a new artifact version and a newly published workflow version.

Publishing fails if Yaya does not accept every required artifact. A workflow version cannot become active while its runtime dependencies are unresolved.

## End-to-end flow

### 1. Rada triggers Portia

When an alert reaches its configured push time, Rada sends a signed `rada.alert.triggered` event to Portia. The event includes a stable Rada alert ID, affected town identifiers, relevant alert facts, and occurrence time.

Portia records the event in an inbound event inbox using the Rada event ID as the unique key. A duplicate request returns the prior acknowledgement and does not create another run. Portia starts each matching active FSP workflow with correlation key `rada:{rada_alert_id}`.

The current direct Rada-to-Yaya alert path remains available during rollout but is not part of the target architecture.

### 2. Portia obtains a frozen audience from Yaya

For each organization and workflow, Portia asks Yaya to create an audience snapshot for the mapped FSP and the affected towns. Yaya resolves membership against authoritative user data and freezes the resulting opaque user IDs under an immutable snapshot ID.

Portia consumes the snapshot through cursor-based pages or a streaming endpoint. It feeds IDs incrementally into Nodeflow rather than materializing the complete 10,000–100,000 member audience in PHP memory. Re-reading a page or resuming a cursor must be safe.

The snapshot establishes reproducibility: membership changes after the alert do not silently alter the original audience. Any product rule that intentionally uses live membership must be represented as a different audience strategy.

### 3. Nodeflow runs the initial warning node

Portia creates the Nodeflow run and its subject cohort. Nodeflow invokes one durable activity for the initial warning node. Inside that activity, the Portia node adapter divides active subjects into bounded chunks and submits each chunk to Yaya. The durable activity completes only after every chunk has a stable result.

The activity derives a deterministic idempotency key from stable execution data, conceptually:

```text
portia:{run_id}:{node_id}:{node_execution_id}:{chunk_fingerprint}
```

`node_execution_id` identifies the logical activation of the node and remains unchanged across transport or activity retries. `chunk_fingerprint` must be based on a canonical ordered set of subject IDs, not incidental pagination or an attempt number. The command includes the pinned artifact version, FSP mapping, opaque user IDs, Rada context required by the content, and trace identifiers.

Yaya validates the command, records the idempotency key and command payload atomically, creates its local message work, and returns a stable command ID. Provider dispatch may continue asynchronously in Yaya.

The Nodeflow node succeeds only after Yaya has durably accepted every chunk. Here, **sent** means accepted by Yaya for execution; it does not mean delivered by the carrier. Carrier outcomes stay in Yaya unless a future workflow node explicitly waits for a delivery event.

### 4. Nodeflow owns the wait

Portia advances to a Nodeflow wait node for one hour. The durable workflow runtime owns the timer and restores the run after deploys, queue restarts, or worker crashes. Yaya does not schedule the next workflow step.

### 5. Portia commands the offer action

When the wait completes, Portia executes the offer node for active subjects using the pinned artifact version. Yaya creates per-user offer instances and secure links, queues the marketing SMS, and renders the page when the customer opens it.

As with the initial warning, the node calls Yaya through deterministic, idempotent chunk commands and advances only after durable acceptance.

### 6. Yaya records and reports acceptance

The customer's browser interacts only with Yaya. When the customer selects **Accept**, Yaya records the acceptance transactionally against its authoritative offer instance. Only after that transaction commits does Yaya publish an `offer.accepted` callback to Portia.

Yaya uses an outbound event ID and retries delivery until Portia acknowledges it. Portia stores that ID in its inbound event inbox before applying it. Processing the fact updates the subject's minimal workflow state, such as `offer_accepted_at`, and can:

- Exit that subject from a still-running follow-up path.
- Start a separate accepted-offer workflow if the product requires one.
- Make the fact available to later condition nodes.

A callback informs workflow progress; it is not the sole safety mechanism.

### 7. Yaya enforces the final authoritative precondition

Before accepting any later command whose business rule is “only if the offer has not been accepted,” Yaya checks the authoritative offer state in the same transaction that records the command's effect. The command expresses this as a precondition such as `only_if_offer_not_accepted`.

If already accepted, Yaya returns a stable `skipped_precondition` result rather than dispatching the message. Portia treats that result as a valid business outcome and routes the subject through the appropriate Nodeflow output.

This closes the race where acceptance is committed in Yaya but its callback is delayed while Portia reaches the follow-up node.

## Portia-to-Yaya node contract

A Yaya-facing node has one responsibility: submit domain commands and translate Yaya's stable per-subject results into Nodeflow outputs. A conceptual chunk request is:

```json
{
  "command_id": "deterministic-idempotency-key",
  "command_type": "send_offer",
  "portia_organization_id": "org-id",
  "yaya_fsp_id": "fsp-id",
  "workflow_version_id": "workflow-version-id",
  "run_id": "run-id",
  "node_id": "node-id",
  "artifact_version_id": "portia-artifact-version-id",
  "yaya_artifact_version_id": "yaya-artifact-version-id",
  "subject_ids": ["opaque-user-id"],
  "context": {
    "rada_alert_id": "alert-id"
  },
  "preconditions": ["only_if_offer_not_accepted"],
  "trace_id": "trace-id"
}
```

A conceptual response is:

```json
{
  "command_id": "deterministic-idempotency-key",
  "status": "completed",
  "subject_outcomes": [
    {
      "subject_id": "opaque-user-id",
      "outcome": "accepted",
      "yaya_action_id": "stable-yaya-id"
    },
    {
      "subject_id": "another-opaque-user-id",
      "outcome": "skipped_precondition",
      "reason": "offer_already_accepted"
    }
  ]
}
```

Conceptual stable subject outcomes are:

- `accepted`: Yaya durably recorded the command and local work.
- `skipped_precondition`: no action was created because authoritative state made it ineligible.
- `rejected`: the command is permanently invalid and requires a modeled failure output or run failure.
- A timeout, connection error, or `5xx`: no authoritative outcome is known; the durable activity retries with the same command ID.

Yaya returns the same complete per-subject result for a repeated command ID. It must reject reuse of that ID with a materially different payload. Every requested subject must have exactly one outcome before the chunk is complete, allowing Portia to route accepted and skipped subjects through different Nodeflow outputs.

HTTP `2xx`, `4xx`, and `5xx` alone are insufficient as workflow semantics. The response body contains the stable domain result, while transport status communicates whether a request can be understood or retried.

## Durability and failure semantics

### Portia and Nodeflow

Nodeflow owns:

- Workflow history and the current graph cursor.
- Subject activation and exit state.
- Durable timers and wake-up.
- Activity retry, backoff, and timeout.
- Replay after process failure.
- Business routing from stable Yaya outcomes.
- Final run status and operator visibility.

A transient Yaya outage is not a business `failed` edge. The Nodeflow activity retries according to node policy. Only an exhausted retry policy or permanent rejection produces a modeled failure.

### Yaya

Yaya owns only its local domain transaction and asynchronous delivery pipeline. For a command it must atomically persist enough information to return the same answer after a lost response. It may use its own outbox to deliver callbacks reliably, but it does not store Portia's graph cursor, timer, or next-node decision.

### Ambiguous network outcome

If Yaya commits a command and the response is lost, Nodeflow retries the activity with the same idempotency key. Yaya returns the previously recorded result. Portia then advances once. This is the primary reason the idempotency identity belongs to the logical node/chunk execution rather than the HTTP attempt.

### Partial activity progress

Nodeflow's durable history records completion of the node activity as a whole; Portia does not build a second workflow history for its chunks. If the activity is retried after processing some chunks, the adapter may resubmit those chunks using the same deterministic command IDs. Yaya returns their recorded outcomes without repeating customer effects. The node does not expose partial success as overall success.

### Callback ordering

Callbacks can be duplicated or arrive out of order. Event IDs deduplicate transport, and event occurrence timestamps/version numbers prevent an older fact from overwriting newer local workflow state. Yaya's command-time precondition remains authoritative even when Portia's fact projection is stale.

## Nodeflow capabilities to leverage

The design intentionally uses existing Nodeflow concepts:

- Published immutable flow versions.
- Per-subject execution and subject exits.
- Durable wait nodes.
- Durable workflow/activity history and replay.
- Condition nodes using workflow facts.
- Subject resolution through an external adapter.
- Run and node observability.

Portia should implement Yaya actions as installable Portia node types/adapters. The generic Nodeflow package should not acquire Portia-, Rada-, or Yaya-specific domain concepts.

## Nodeflow gaps to address before production

Repository inspection identified three generic runtime gaps that matter to this use case:

1. **Streaming audience ingestion.** Current run start and trigger matching paths materialize iterables into arrays. Audience handling must remain lazy or paged for cohorts of 10,000–100,000 subjects. Tenant ownership checks should support set-based batches rather than one lookup per user.
2. **Durable activity policy wiring.** The installed durable-workflow runtime supports activity retry, backoff, and timeout, but Nodeflow's generic `RunNodeActivity` does not yet project node policy into those options. Node `$tries` alone is not currently sufficient.
3. **Terminal status reconciliation.** A terminal durable engine failure must reliably project into the Nodeflow run status. The run must not remain shown as `running` after the underlying workflow has irrecoverably failed.

These are improvements to Nodeflow's execution foundation, not reasons to move durability into Yaya.

## Security

- Use service-to-service credentials scoped to a single integration/FSP mapping where practical.
- Sign Rada events and Yaya callbacks; validate timestamp and replay window in addition to inbox deduplication.
- Authorize every artifact, snapshot, subject, command, and callback against the Portia organization/Yaya FSP mapping.
- Never accept a caller-supplied tenant identifier without comparing it to authenticated scope.
- Use opaque external user IDs; keep PII and secure offer tokens out of Portia logs and traces.
- Encrypt secrets at rest and support independent credential rotation.
- Record actor and version history for artifact publication and workflow activation.
- Rate-limit inbound endpoints without making valid retries unsafe.

## Observability

Every alert and derived action carries a trace ID across Rada, Portia, and Yaya. Operators must be able to navigate from:

```text
Rada alert → Portia trigger receipt → workflow run → node/chunk command → Yaya command → message or offer instance → acceptance callback
```

Portia reports orchestration state and Yaya reports domain execution/delivery state. The UI must label these distinctly; “accepted by Yaya” must not be displayed as “delivered to customer.”

Key metrics include:

- Duplicate command effects, with a target of zero.
- Audience snapshot count versus subjects admitted to the run.
- Alert-to-Yaya-command-acceptance latency.
- Durable activity retry and exhaustion counts.
- Run status mismatches against terminal durable-engine state.
- Acceptance callback lag.
- Follow-up commands skipped by Yaya preconditions.
- Provider delivery outcomes, owned and reported by Yaya.

## Rollout plan

### Phase 0: Foundations

- Add Portia organization-to-Yaya FSP mapping and scoped authentication.
- Build immutable artifact publication and Yaya projection sync.
- Add streaming audience support, durable activity policy wiring, and terminal status reconciliation to Nodeflow.
- Define and version the inter-service command and event schemas.
- Add cross-service trace and idempotency conventions.

### Phase 1: Shadow execution

Rada copies eligible alert events to Portia while the current production Rada-to-Yaya path remains authoritative. Portia creates test/shadow runs with external side effects disabled. Compare audience counts, tenant mapping, artifact resolution, and route decisions against the current behavior.

### Phase 2: Initial warning for one test FSP

Cut over one internal or test FSP to `Rada → Portia → Yaya` for the initial alert. Keep the old direct route available as a controlled rollback path, but ensure only one path is active for that FSP and alert. Rollback must preserve the same logical command identity or otherwise prove it cannot duplicate an alert.

### Phase 3: Offer loop

Enable the durable one-hour wait, offer command, Yaya page, acceptance callback, subject exit, and authoritative no-follow-up precondition. Exercise delayed and missing callbacks before expanding.

### Phase 4: Gradual expansion

Add FSPs incrementally behind configuration. Retire the direct Rada-to-Yaya route only after the safety and latency gates remain healthy at production volume.

## Verification strategy

### Contract tests

- Versioned request, response, and event schemas shared as test fixtures across all three repositories.
- Authentication, signature, tenant mismatch, replay, and incompatible-version cases.
- Idempotency-key reuse with identical and conflicting payloads.

### Cross-service journey tests

- Alert to audience snapshot to initial SMS command.
- One-hour wait to offer command.
- Offer acceptance to Portia fact/subject exit.
- Acceptance committed before a delayed callback, followed by a command-time precondition skip.
- Artifact edit after publication does not alter an existing run or link.

### Failure injection

- Response lost after Yaya commits.
- Repeated `503` responses followed by recovery.
- Portia worker termination in the middle of a chunked node.
- Queue restart during a durable wait.
- Duplicate and out-of-order Rada/Yaya events.
- Callback unavailable long enough to exercise Yaya's retry/outbox path.

### Scale and security tests

- A 100,000-subject snapshot against PostgreSQL and real queue workers, verifying bounded memory and acceptable database load.
- Cross-tenant artifact, snapshot, subject, command, and callback attacks.
- Browser-level author, publish, run inspection, and acceptance journeys with no PII exposed in Portia.

## Production gates

The first production expansion requires:

- Zero observed duplicate Yaya command effects during replay testing.
- Exact audience snapshot/admitted-subject reconciliation or an explicitly explained exclusion count.
- Alert-to-Yaya-acceptance latency within the agreed safety SLO.
- Correct terminal Portia run status for every injected engine failure.
- Measured callback lag and command-time precondition skips visible to operators.
- End-to-end traceability across all three services.
- A rehearsed rollback that cannot send the same alert twice.

## Key decisions

1. Portia is both the workflow control plane and the durable runtime.
2. Every business workflow step, including the initial warning, belongs to Portia's graph.
3. A Yaya-facing node is one durable Nodeflow activity whose adapter may submit bounded, idempotent domain commands; Yaya does not host a shadow workflow.
4. Yaya remains authoritative for users, PII, messaging, pages, and acceptance.
5. Portia owns canonical templates and offers; Yaya stores immutable runtime projections.
6. Large audiences are frozen in Yaya and streamed into Portia as opaque subject IDs.
7. Acceptance uses both an event for prompt orchestration and an authoritative command-time precondition for safety.
8. Initial transport is signed, idempotent HTTP. A broker can be introduced later without changing ownership or message semantics.
