# Portia, Yaya, and Rada Workflow Program Roadmap

**Design:** `docs/superpowers/specs/2026-08-24-portia-yaya-rada-workflow-architecture-design.md`

This architecture crosses four independently deployed codebases. It must not be implemented as one
cross-repository branch: each phase below ends with a deployable, testable boundary and supplies the
contracts required by the next phase.

## Dependency order

1. **First-class Nodeflow triggers**  
   Execute `docs/superpowers/plans/2026-08-24-first-class-trigger-nodes.md`. This replaces the current
   mutable flow-level trigger system and extracts `CreateRun`, `TriggerTenantMatch`, and the generic
   occurrence dispatcher used by every later phase.

2. **Nodeflow production readiness**  
   Execute `docs/superpowers/plans/2026-08-24-nodeflow-portia-readiness.md`. This adds replayable,
   streaming audiences; batched tenant validation; immutable node activity policies; and durable
   engine-failure projection. Release a Nodeflow version containing both phases 1 and 2.

3. **Portia–Yaya capability foundation**  
   Write and execute a plan in `portia-engine` after the Nodeflow release is installed. It covers the
   organization-to-Yaya-FSP mapping, scoped service authentication, the typed capability client,
   immutable delivery artifacts, Portia's inbound event inbox, Yaya's idempotent command endpoint,
   and Yaya audience snapshots. It ends with a side-effect-free test workflow that can publish an
   artifact, stream a frozen audience, and obtain stable per-subject command outcomes.

4. **Rada-triggered initial warning**  
   Write and execute one coordinated contract plan across `rada` and `portia-engine`. Rada emits a
   signed, stable `rada.alert.triggered` event; Portia deduplicates it and dispatches the matching
   Nodeflow activation; the first Yaya message capability runs in shadow mode before one test FSP is
   cut over. The existing direct Rada-to-Yaya route remains the rollback path and is mutually
   exclusive per FSP.

5. **Offer and acceptance loop**  
   Write and execute one coordinated plan across `portia-engine` and `yaya-engine`. It adds the
   Portia-owned offer artifact, durable wait and offer nodes, Yaya secure page/offer instances,
   transactional acceptance, reliable `offer.accepted` callbacks, Portia subject exits, and Yaya's
   authoritative `only_if_offer_not_accepted` command precondition.

6. **Scale rollout and retirement**  
   Run the 100,000-subject PostgreSQL/worker load suite, failure-injection journey, tenant attack
   suite, and rollback rehearsal. Expand FSPs behind configuration. Retire the direct Rada-to-Yaya
   alert route only after duplicate-effect, audience-reconciliation, latency, callback-lag, and
   terminal-status gates remain healthy.

## Capability rule for future nodes

Portia editor nodes map to typed domain capabilities; Yaya must not implement Portia node classes.
Portia-only control-flow nodes and conditions over already-projected facts require no Yaya change.
Several Portia nodes may reuse one Yaya capability such as `send_message`. Yaya changes only when a
node needs a genuinely new Yaya-owned action, authoritative predicate, or artifact-rendering feature.
Inbox/outbox and durable activities standardize delivery and replay, but do not turn unknown domain
semantics into data automatically.

## Planning gates

Do not write the detailed phase 3 plan until phases 1 and 2 establish the actual released Nodeflow
API. Do not write the initial-warning plan until Portia and Yaya agree on versioned request, response,
event, authentication, and idempotency fixtures. Do not write the offer-loop plan until the initial
warning proves command replay and audience reconciliation in shadow and test-FSP environments.

