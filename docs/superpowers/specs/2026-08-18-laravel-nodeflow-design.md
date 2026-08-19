# laravel-nodeflow — Design

**Date:** 2026-08-18
**Status:** Approved design, pending implementation plan

A standalone Laravel package that gives any Laravel + Inertia + React application a
customer-facing visual workflow builder backed by a durable execution engine.

---

## 1. Context

Three systems are in play. **Rada** is the flood-forecasting product; it emits weather
and flood alerts scoped to towns. **Yaya Engine** is the messaging platform; it delivers
messages across channels and owns per-user delivery state, channel accounts, and
conversations. **Portia** (`portia-engine`) is the FSP-facing platform and the first host
app for this package.

Today automation logic is written in Laravel by us. The goal is for FSPs — financial
service providers, our customers — to build and edit their own journeys inside Portia,
self-serve, without us shipping code for each one.

### The canonical flow

This is the v1 acceptance test. Nothing ships as done until it runs end to end, authored
in the editor, with a real multi-day wait and a real cancellation.

A Rada alert fires for a town. Portia consumes it, and for each affected user of that FSP:

1. Send the weather alert message.
2. Wait 5 minutes, then send a marketing message for a loan product.
3. Wait 1 day, then if the user has not clicked, send a follow-up message.
4. If at any point the user confirms interest in the loan, cancel the pending follow-up
   and start a different workflow — the loan application journey.

### Host environment as it actually is

Verified against `~/Sites/portia-engine` on 2026-08-18, and it differs from the original
brief in ways that matter:

| | |
|---|---|
| Framework | Laravel **13.8**, PHP 8.3 (not Laravel 12) |
| Frontend | Inertia 3.3, React 19.2, Tailwind v4, Vite 8 |
| Tenant model | **`Organization`** — carries `fsp_type`, `fsp_confirmed`, `country`. There is no `Fsp` model. |
| Existing tenancy plumbing | `BelongsToOrganization` concern, `OrganizationPolicy` |
| Also installed | Filament 5.6 — so "no Filament" is a product choice, not a technical constraint |

The absence of an `Fsp` model is the direct justification for the tenancy resolver: the
package must never name a tenant class.

### Engine

`durable-workflow/workflow`, latest **2.0.0-rc.32** (released 2026-08-14). MIT, ~181k
installs, supports Laravel 9–13. The v1 line (`laravel-workflow/laravel-workflow`) is
abandoned; this is its successor. **The v2 API is pre-stable and lives under
`Workflow\V2\`.** See §14 for how that risk is contained.

---

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | Package owns mechanism; host owns domain | Requirement 8, standalone and reusable |
| D2 | Editor ships as **source** consumed by the host's Vite build, not a prebuilt bundle | Only way to inherit the host's React, Tailwind tokens, and dark mode |
| D3 | Templates are **fork-on-install**, link severed | No sync engine, no drift diffing, no fleet-push blast radius |
| D4 | **Cohort runs for fan-out triggers, per-user runs for individual triggers**, one node contract serving both | Six-figure audiences make per-user-everywhere untenable; per-user-nowhere breaks node ergonomics |
| D5 | Waits are **cohort-relative**, not user-relative | Accepted semantic loss, bounded by batch drain time at six figures; see §7.3 |
| D6 | Node cardinality is opt-in: `forSubject()` default, `forAudience()` for natively-batching nodes | Preserves the one-hour node while allowing batch efficiency |
| D7 | Per-subject branch returns are **automatically partitioned** into sub-audiences | The mechanic that makes cohort execution feel per-user to the node author |
| D8 | Flow versions are **immutable**; runs pin `flow_version_id` at start | Requirement 7, without diffing or migration machinery |
| D9 | Node bodies run in a generic activity, never in workflow code | Engine's boot-time guardrail scan rejects `DB::`/`Http::` in workflow code |
| D10 | **One wait primitive for both strategies:** `self::awaitWithTimeout($duration, 'audienceEmptied')`, signalled only when the remaining subject count hits zero (corrected form — see §18) | Engine caps pending signals at 5,000; audience-empty signalling is bounded at one per wait regardless of audience size |
| D11 | `node_executions` logs one row per (run, node, output) with counts, not per subject | 18 rows vs 600,000 at 100k subjects |
| D12 | Audience subject-ownership check against run tenant is **mandatory and non-disableable** | Cross-FSP leakage in regulated financial messaging is not a config flag |
| D13 | Conditions are **curated only** in v1 — no expression language | Non-technical authors in scope; expression-over-host-data is an injection and isolation surface |
| D14 | All engine calls go through a thin package-internal facade | Engine is at rc.32; a breaking change should be one file |

---

## 3. Scale analysis

Measured against Yaya production on 2026-08-18.

| | |
|---|---|
| FSPs | 4 — Fortune Credit 10,605 users; Atram Ethiopia 3,139; Atram 13; Bancamia 5 |
| Alert fan-out, median (last 20 alerts) | ~85 users |
| Alert fan-out, max observed | 2,986 (Kenya Yellow, 2026-08-02) |
| Alert cadence | roughly 1–3 per day |
| Stated trajectory | FSP user bases uploaded into Portia and Yaya; **a single alert can reach six figures** |

Why per-user-everywhere fails at that trajectory: 100k users × ~25 history events is
2–3M rows per alert across six durable tables, at 1–3 alerts a day — roughly 250M rows a
month, plus 100k queue jobs per step. Survivable only with relentless pruning, and it
makes the event store the dominant cost centre of the system. At a million it does not
work at all.

Why per-user semantics are not actually required by the canonical flow:

- **Send the alert** — batch.
- **Wait 5 min → marketing** — per-user anchoring would spread firing times by seconds
  across a batch. Indistinguishable.
- **Wait 1 day → if not clicked, follow up** — "has not clicked" is a filter at send
  time, not a per-user timer.
- **Confirms interest → cancel follow-up, start loan journey** — the cancel is that same
  filter; the loan journey is individually triggered with an audience of only converters,
  where per-user runs are cheap and exact.

Hence D4.

### Relevant engine limits

| Limit | Value | Consequence here |
|---|---|---|
| Pending child workflows | 1,000 | Per-user runs must be **root** runs, never children |
| Items per `all()` fan-out | 1,000 | Parallel branch cap; chunked dispatch |
| Pending timers | 2,000 | Fine under cohort model |
| Pending signals | 5,000 | Forces D10 — signal on audience-empty, never per subject |
| Argument payload | 2 MiB | Audience passed by handle, never by value |
| History events per workflow task | 5,000 | Bounded by cohort model |

Exceeding any of these records `failure_category = structural_limit` and terminates the run.

---

## 4. Boundaries

### The package owns

Storage schema; the interpreter workflow class and its activities; the node contract and
registry; the config-schema compiler (PHP definition → JSON for the editor); the React
editor components; Inertia controllers and routes; and a policy layer that delegates every
decision outward.

### The host provides

1. **Tenancy resolver** — `currentTenantId()`, `tenantForRun($run)`. The package stores an
   opaque `tenant_id` and asks. It never mentions `Organization`.
2. **Authorization** — package policies call host-registered gates: `nodeflow.viewAny`,
   `nodeflow.update`, `nodeflow.publish`, `nodeflow.runManually`. Default deny unless a
   gate exists. A host wanting finer control replaces the policy class.
3. **Node classes** — the domain palette (Send Yaya Message, Read Rada Severity, Check
   Loan Eligibility).
4. **Audience resolvers** — how a trigger payload becomes a materialized subject set.
5. **A page layout** — the Inertia page renders inside a host-supplied shell. Defaults to
   a bare page.

Realistically the host writes **two** small classes (tenancy resolver, one audience
resolver) plus its nodes to get running.

### The package explicitly does not own

The tenant model, the user model, authentication, any Rada or Yaya integration, or queue
infrastructure. Each is either a resolver or a node.

### Delivery mechanism (D2)

The package's `resources/js` is consumed by the host's Vite build through an alias, so the
editor compiles against the host's React, its Tailwind v4 tokens, and its `.dark` class.
A prebuilt bundle would carry its own React and its own styling and would look like an
iframe without being one.

- Cost: the host adds one line to `vite.config.js`; package JS upgrades arrive via
  `composer update` rather than `npm update`.
- Escape hatch: `vendor:publish` for hosts that want to fork a component, with the usual
  caveat that published files stop receiving updates.

Routes, migrations, and the editor page publish separately. A host that wants only the
engine — running flows with no editor — must be able to have that, and that case is
tested rather than theoretical.

---

## 5. Node contract

A node is one class.

```php
class SendYayaMessage extends Node
{
    public static function type(): string { return 'yaya.send_message'; }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Send Message')
            ->group('Messaging')
            ->outputs(['sent', 'failed'])
            ->fields([
                Field::select('template')->optionsFrom(YayaTemplates::class)->required(),
                Field::select('channel')->options(['sms' => 'SMS', 'whatsapp' => 'WhatsApp']),
            ]);
    }

    public function defaultConfig(): array { return ['channel' => 'sms']; }

    public function forSubject(SubjectContext $c): NodeResult
    {
        $result = app(Yaya::class)->send($c->subject(), $c->config('template'));

        return $result->ok ? $c->continue('sent') : $c->continue('failed');
    }
}
```

**Cardinality is opt-in (D6).** Implement `forSubject()` and the runtime handles chunking,
iteration, and per-subject failure isolation — the author never sees a batch. Implement
`forAudience()` and you receive the audience handle for nodes that batch natively. Send
Yaya Message will likely implement both: `forSubject()` for correctness and small runs,
`forAudience()` for the six-figure case. A node implementing only `forSubject()` still
works at any scale; it is merely slower.

**Branching partitions the audience automatically (D7).** A condition node returns
`$c->continue('yes')` or `$c->continue('no')` *per subject*; the runtime groups subjects by
returned output and walks each outgoing edge with its own sub-audience. The author writes
single-user code and never writes a `groupBy`.

**Node bodies never run in workflow code (D9).** They run inside one generic
`RunNodeActivity`. The interpreter workflow does only control flow. A node may therefore
call `DB::`, `Http::`, `now()`, anything. Node retry policy is per-node
(`public int $tries = 3`) and the idempotency key is (run, node, subject, attempt), so a
retried batch cannot double-send.

**Field options can be tenant-dynamic.** `optionsFrom(YayaTemplates::class)` resolves
server-side at edit time through a package endpoint scoped by the tenancy resolver, so an
FSP sees only its own message templates. Static arrays are also supported.

**Registration is explicit** — `Nodeflow::register([SendYayaMessage::class, ...])` in the
host's service provider. No directory auto-discovery: it is magic, slow to boot, and
explicit registration gives a natural place to gate nodes by tenant plan or feature flag
later.

### Node type resolution across deploys

Graph versions are immutable, but node **classes** are host code that ships independently.
If a host renames or removes `SendYayaMessage` while runs sit mid-wait on a version
referencing it, those runs would otherwise fail at resume with an unresolvable class and no
defined behaviour.

Policy:

- **Boot-time check.** The registry verifies that every node type referenced by any flow
  version with live runs still resolves. A missing type is a startup-visible error, not a
  runtime surprise at 3am on day 12 of a wait.
- **Publish-time check.** A version cannot be published referencing an unregistered type.
- **Runtime fallback.** If a type is unresolvable at resume anyway, the run enters a
  `blocked` state with a typed operator error naming the missing type and the affected
  version — recoverable by re-registering the class, not a dead run.
- **Renames** are handled by keeping `type()` stable and independent of the class name, and
  by an explicit alias map in the registry for genuine renames.

`type()` being a stable string rather than a class name is the load-bearing part; the
registry maps string to class, so refactoring the PHP class is free.

### Known weakness

`NodeResult` does double duty as branch selector and data passer, and the
audience-partitioning behaviour is subtle enough that an author who skips the docs may
expect `forSubject()` to be a loop they control. The trade is accepted — the alternative
exposes set semantics to every author — but it must be validated by writing three real
nodes early in implementation.

---

## 6. Storage

Six tables.

| Table | Contents |
|---|---|
| `nodeflow_flows` | tenant, name, trigger type, status, pointer to current version |
| `nodeflow_flow_versions` | **immutable** graph JSON, published_at/by, content hash |
| `nodeflow_runs` | pinned `flow_version_id`, tenant, correlation id, engine workflow id, strategy |
| `nodeflow_run_subjects` | the materialized audience **and** per-subject progress (current node, status, last error, exited_at) |
| `nodeflow_node_executions` | one row per (run, node, output) with subject count, duration, error |
| `nodeflow_templates` | publisher scope (global / org-local), graph JSON, version |

### Versioning (D8)

Editing writes a draft. Publishing freezes a new `flow_versions` row and repoints the flow.
A run pins its `flow_version_id` at start and never consults the flow again — so an FSP
editing a journey cannot disturb runs sitting mid-24-hour wait on the previous version.
A version with live runs cannot be deleted, only superseded.

The graph reaches the workflow through an activity —
`yield activity(LoadGraph::class, $versionId)` — rather than as a start argument. Both are
deterministic (the result lands in history and replays from there), but the activity form
keeps the start payload small, allows external payload storage if a graph grows, and keeps
`version_id` as the queryable link on the run row. The engine's guardrail scan forbids
`DB::` inside workflow code, so this is the sanctioned shape rather than a preference.

Changes to *our interpreter's own PHP code* while runs are in flight use the engine's
`getVersion()` / `patched()` with a unique change id per logical change. Because the
interpreter is a single class, this is the one place the engine's versioning feature earns
its keep.

---

## 7. Interpretation

### 7.1 The loop

One workflow class, control flow only: load graph, hold a cursor of (node, audience) pairs,
and for each — if it is a wait, yield a timer; otherwise yield `RunNodeActivity`, then
follow outgoing edges carrying the partitioned sub-audiences. Parallel branches use
`all()`, capped at 1,000 per fan-out.

Graphs are validated acyclic at publish time and runs carry a max-step guard, so a
malformed graph cannot spin forever.

### 7.2 Two strategies, one code path

The trigger type picks the strategy. A per-user run is a **cohort of one** travelling the
same code path — not a parallel implementation. This discipline must hold; two
implementations would defeat the point.

Per-user runs are started as **root** workflows by a chunked dispatcher job, never as
children, because pending children cap at 1,000.

### 7.3 Waits (D5, D10)

**One primitive, both strategies.** Every wait compiles to:

```php
// Correct form for durable-workflow/workflow v2 — see §18.
self::awaitWithTimeout($duration, 'audienceEmptied');
```

The signal that sets `audienceEmpty` fires only when the run's remaining subject count
reaches zero. Subject exit itself is a plain DB write — a host event listener sets
`exited_at` on `run_subjects` — and the interpreter re-resolves the remaining audience at
wake. This yields:

| Strategy | Behaviour |
|---|---|
| Per-user run (cohort of one) | The subject exiting *is* the audience emptying, so the signal fires and the wait wakes early. Exact per-user cancellation semantics. |
| Cohort run | The signal fires at most once per wait — when the last subject exits. Otherwise the timer expires and the interpreter proceeds with whoever remains. |

**Why not a signal per exit:** the engine caps pending signals at 5,000, which a six-figure
conversion wave would blow straight through. Signalling only on audience-empty is bounded
at one signal per wait regardless of audience size, so the cap is structurally unreachable.

**Why this matters beyond elegance:** an earlier draft specified a real signal wait for
per-user runs and a bare timer for cohort runs. That was two implementations of the same
primitive, and it silently contradicted the rule in §7.2 that a per-user run is a cohort of
one on the same code path. There is now one implementation. Flow-level control (pause,
resume, cancel) uses separate signals and is cardinality-1 by nature.

Functionally, subject exit **is** cancellation — the follow-up never sends to those
subjects.

**Accepted semantic loss:** cohort waits are relative to when the step ran for the cohort,
not to each user's own message landing. Genuinely per-user anchoring ("3 days after *this
user's* disbursement") is an individually-triggered journey, which runs per-user and keeps
exact timing.

**Caveat on the size of that loss.** The original justification — that per-user and cohort
anchoring differ "by seconds across a batch" — holds at low thousands and **fails at six
figures**, where the divergence is bounded by Yaya's batch drain time, not by scheduling
jitter. If a 100k batch takes 40 minutes to drain, the last user's "wait 5 minutes" step
fires roughly 45 minutes after the first user's, and may precede their own alert if
ordering is not guaranteed. Two consequences for implementation: confirm Yaya's drain
throughput and ordering guarantees before relying on short cohort waits, and treat any wait
shorter than the expected drain time as a design smell in the editor (warn at publish).

**Contract for host implementors:** re-resolution at wake is a filter over the materialized
`run_subjects` set, not a fresh query against host data. Audience resolution must be cheap
and stable. A resolver that is expensive or non-repeatable is punished at exactly the worst
moment.

---

## 8. Triggers

Triggers are declared like nodes, because they should feel the same to write.

```php
class RadaAlertDispatched extends Trigger
{
    public static function event(): string { return AlertDispatched::class; }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Rada Alert Fires')
            ->fields([
                Field::multiselect('severity')->options(['yellow','orange','red']),
                Field::multiselect('hazard')->options(['rainfall','flood']),
            ]);
    }

    public function resolve(AlertDispatched $e): TriggerMatch
    {
        return TriggerMatch::forEachTenant(
            fn ($tenantId) => User::whereIn('town_id', $e->townIds)
                ->where('organization_id', $tenantId)
        );
    }
}
```

The package registers a real listener per registered trigger — no wildcard subscriber, so
boot stays cheap and the event map is inspectable. On fire: find active flows whose trigger
matches after filters, materialize an audience per flow, create a run, start the workflow.
Any event the host app already dispatches becomes a trigger surface without new plumbing.

**One Rada alert produces many runs.** Alerts are scoped to towns and towns cut across
FSPs, so a single `AlertDispatched` fans to one run per (tenant × matching flow). Fortune
Credit's journey and Atram Ethiopia's journey are independent, isolated, separately
cancellable, and separately versioned.

### Four trigger kinds in v1

- **Event** — as above.
- **Manual** — an FSP clicks Run with a chosen audience. Non-negotiable; it is how anyone
  tests anything.
- **Sub-flow** — a `StartFlow` node launches another flow's run for the sub-audience that
  reached it. This is how canonical step 4 works. Carries a depth limit and a cycle check,
  because flow A starting flow B starting flow A is a fork bomb with durable state.
- **Scheduled** — cron expression, audience from a resolver.

### Idempotency

Runs carry a uniqueness key of (flow version + trigger identity), so a redelivered event
cannot double-run. A trigger firing while a previous run of the same flow is still live is
a per-flow setting — re-enter, skip, or queue — defaulting to **re-enter**, since alerts
are genuinely independent occurrences.

---

## 9. Multi-tenancy and authorization

Defence in depth, three layers:

1. Every package model carries a global scope bound to the tenancy resolver.
2. Runs denormalize `tenant_id`; `RunNodeActivity` asserts it matches before executing.
3. **The package validates that every subject an audience resolver returns belongs to the
   run's tenant before materializing it (D12).**

Layer 3 is the one that matters. It is the difference between a bug in a host-written
resolver being a bug, and it being a cross-FSP breach where one bank's customers receive
another bank's loan offers. It is mandatory and non-disableable. The cost is one indexed
query per run at materialization time.

Authorization is delegated entirely: package policies call host gates
(`nodeflow.viewAny`, `nodeflow.update`, `nodeflow.publish`, `nodeflow.runManually`),
default deny. In Portia these wire to `OrganizationPolicy`.

Solution engineers building journeys "for" an FSP is purely an authorization question —
an SE gets access to the org and authors a flow there — not a data model question. This is
a direct consequence of D3.

---

## 10. Config schema: declared once in PHP

`definition()` is the single source of truth. It compiles to JSON served to the editor;
React renders fields generically from a field-type → component map. The same definition
produces the Laravel validation rules used at publish time, so browser and server cannot
disagree about what is valid.

When a host needs a bespoke control — a town picker with a map — it registers a React
component against a custom field type. Escape hatch, not the default path.

---

## 11. Editor

One Inertia page: React Flow canvas centre, palette grouped by node group left, config
panel right rendered from the field schema.

- Drafts autosave on a debounce.
- Publish runs server-side validation — every node's `validate()`, plus graph-level checks
  for acyclicity, orphans, and unreachable branches — then freezes a version.
- Client-side validation exists for responsiveness; the server is the authority, from the
  same `definition()`.

**Run view — canvas overlays.** A run overlays live subject counts on the canvas: entered,
sent, failed, waiting at the timer, branched. One indexed query against `node_executions`
and `run_subjects`. Per-user runs would have made this an aggregation over thousands of
histories; the cohort model makes it free. This is the observability payoff of D4 and the
strongest feature to lead with.

### Conditions (D13)

A `Condition` node with field / operator / value, where fields come from host-registered
**subject attributes** — a small declaration (key, label, type, resolver) written the same
way a node is. No expression language, no free-text DSL, in v1.

Rationale: non-technical FSP staff are in scope, so an expression language raises the floor
rather than lowering it; expressions evaluated over host data are an injection and
tenant-isolation surface on a system sending regulated financial messages; and the escape
hatch is already excellent — anything the curated builder cannot express becomes a domain
condition node in PHP, an hour's work by design. An expression language can come later
against real evidence rather than a hypothetical author.

---

## 12. Authoring audience

Both audiences are in scope, staged (approved):

- **v1 acceptance criteria are written against our own solution engineers** authoring the
  canonical flow, because that is what proves the system.
- **Non-technical FSP staff are the target**, so the palette, validation messages, and
  guardrails are designed for them from day one — reachable without rewriting the editor.

The trap avoided: spending the v1 budget on editor affordances for a graph shape not yet
validated in production.

---

## 13. Templates (D3)

Three publisher scopes: **global** (ours, seeded), **engineer-authored** (built by our
solution engineers for a specific FSP), **org-local** (built by the FSP, private). All
three behave identically on install — the scope is a provenance label, not a lifecycle.

**Fork on install, link severed.** The template is copied into the org's flows and the FSP
owns it outright. Template improvements never reach installed copies — that is the accepted
price. No sync engine, no drift tracking, no fleet-push blast radius.

**Scope note:** the table and install path are in v1; the curated library and browsing UX
are phase 2. Templates are only valuable once three or four journeys exist worth
templating, which is after v1 by definition. Building a browsing UX for an empty shelf is
waste.

---

## 14. Risks

| Risk | Mitigation |
|---|---|
| **Engine is pre-stable** — `2.0.0-rc.32`, API under `Workflow\V2\` | Every engine call goes through a thin package-internal facade so a breaking change is one file (D14). **This risk materialised immediately: the API this spec was drafted against, from the published docs, was wrong in two separate ways — see §18.** The facade contained the blast radius to five files, which is the strongest available evidence that D14 was the right call. |
| **Yaya's send API may be batch-oriented**; 100k independent single-sends would be far worse than one batch call | Solved by `forAudience()` on the send node (D6), not by changing the fan-out model. Yaya's actual send contract must be confirmed during implementation. |
| **Durable-table growth** — the engine ships no end-to-end prune command; archive and prune are separate | Archive-then-prune job is **in v1 scope**, not deferred. Two retention clocks: package tables on a tenant-configurable window, engine tables on our own job. |
| **Two execution strategies drift apart** | Per-user run is a cohort of one on the same code path. Enforced by tests that run the same graph both ways. |
| **Cohort re-resolution cost at wake** | Re-resolution filters the materialized `run_subjects` set, never re-queries host data. Stated as a loud contract for host implementors. |
| **Node authors misunderstand audience partitioning** | Validate by writing three real domain nodes early; docs lead with the mechanic. |
| **Host deletes or renames a node class with runs in flight** | Stable `type()` strings, registry alias map, boot-time and publish-time resolution checks, recoverable `blocked` state at runtime (§5). |
| **Cohort wait shorter than batch drain time** | Confirm Yaya drain throughput and ordering; warn at publish when a wait is shorter than expected drain (§7.3). |
| **A host node ignores test mode and sends real messages during a test run** | `$c->isTest()` is part of the node contract; package nodes honour it; covered by the node authoring checklist and review. |

---

## 15. Scope

### v1 — defined by one acceptance test

**The canonical Rada→Yaya flow runs end to end in Portia, authored in the editor, with a
real multi-day wait and a real cancellation.** Nothing ships as done until that passes.

In scope:

- Storage and immutable versioning
- Interpreter with both execution strategies
- Node contract: `forSubject()` / `forAudience()`, automatic branch partitioning, field schema
- Package-supplied domain-free nodes: Wait, Condition, Split, Start Flow, Exit
- **Test mode** — run a published or draft version against an operator-chosen subject, or
  against a recording sink that captures what *would* have been sent without dispatching
  (see below)
- Triggers: event, manual, sub-flow, scheduled
- Editor: canvas, palette, config panel, publish validation
- Run view: canvas overlays, subject drill-down
- Tenancy resolver, authorization gates, mandatory audience ownership check
- Templates: table and fork-on-install path
- Archive-and-prune command
- Engine facade

Explicitly out of v1:

- Expression language / freely authored conditions
- Webhook triggers
- Full dry-run simulation of an entire audience (test mode covers the single-subject case)
- A/B split, throttle, rate-limit nodes
- Template drift tracking and browsing UX
- Conversion analytics

**On test mode being in v1.** It was initially deferred to phase 2 alongside full dry-run
simulation. That was wrong: with test mode deferred, the only way an author validates a
journey is a manual trigger against a real audience, which dispatches real SMS to real
customers. The target author is explicitly non-technical and the domain is regulated
financial messaging. A minimal test mode — one chosen subject, or a sink that records
intended sends — is a fraction of the cost of full simulation and removes a live hazard.
Node authors opt in by honouring `$c->isTest()`; the package-supplied nodes honour it, and
a host node that ignores it is a reviewable bug.

### Phase 2

Full dry-run simulation across a whole audience, with projected counts per branch — the
natural head of phase 2 because it needs the node contract stable first. Plus richer nodes,
the curated template library and its browsing UX.

### Phase 3

Expression language, if FSPs demonstrably hit the curated ceiling. Webhook triggers. Cohort
analytics.

---

## 16. Rejected, with reasons

**Voodflow** (Filament workflow plugin). Its Delay node calls `usleep()` and blocks a queue
worker for the full duration; their own docs recommend a queue-based approach for multi-day
waits. No paused or waiting execution state (`pending → running → success → failed →
cancelled`), so no signal-wait or resume-on-event. For Each is sequential within a single
job, default cap 100 items. The editor is a Filament/Livewire plugin and cannot live in a
React/Inertia page. Its license bars offering the software as a service to third parties
without a written agreement, and FSPs are third parties.

Its custom-node contract is good prior art and is borrowed here: `type()`,
`defaultConfig()`, `validate()`, `definition()` returning a plain config-field array, and
`execute(ExecutionContext): ExecutionResult` with multi-output branching.

**n8n Embed** (~$50k/yr, and you embed n8n's UI). **Activepieces embed** (iframe,
~$800–2,500/mo). **Embedded iPaaS generally** (Paragon, Appmixer, Embed Workflow). All sell
third-party SaaS connectors; the value here is domain nodes over our own data, which none
of them have.

**Per-user runs everywhere** — dies at six figures (§3).
**Cohort runs everywhere** — forces set semantics on every node author, undercutting the
primary ergonomic goal.
**Hybrid with per-node dual handling of subject-or-segment** — turns the one-hour node into
a three-hour node.

---

## 17. Requirements traceability

| Requirement | Where satisfied |
|---|---|
| 1. Durable, non-blocking timers | §7.1, §7.3 — engine timers; workflow hibernates |
| 2. Signal waits with cancellation | §7.3 — signals per-user, audience exit for cohort |
| 3. Per-user instances at scale | §3, §7.2 — cohort for fan-out, per-user roots for individual triggers |
| 4. Easy custom nodes in PHP | §5 — one class, one declarative definition |
| 5. Native React/Inertia editor | §4 (D2), §11 |
| 6. Multi-tenant | §9 — three layers, mandatory ownership check |
| 7. Graph versioning | §6 (D8) — immutable versions, runs pin at start |
| 8. Standalone and reusable | §4 — five extension points, realistically two classes plus nodes |


---

## 18. Engine API corrections established during implementation

This spec and its implementation plan were drafted from `durable-workflow/workflow`'s
published documentation. Reading the installed source of **2.0.0-rc.32** during
implementation showed that documentation describes the v1 API, while the package ships two
parallel APIs and only the v2 one has the capabilities this design needs. Both corrections
below were verified against vendor source with file and line citations, then confirmed
independently.

### 18.1 The stub class (found building the engine facade)

| | Drafted against (wrong) | Actually installed |
|---|---|---|
| Class | `Workflow\WorkflowStub` | `Workflow\V2\WorkflowStub` |
| Cancel | assumed `cancel()` | v1 has **no `cancel()`** — only a magic `__call` |
| Signal | `$stub->{$method}(...$args)` | `$stub->signal(string $name, ...$args)` |

The v1 stub's `__call` silently no-ops for an unrecognised method. Had the drafted form
shipped, `cancel()` would have compiled, run, and done nothing — and cancellation is one of
this system's hard requirements. Real v2 signatures: `id(): string` (`V2/WorkflowStub.php:634`),
`running(): bool` (`:724`), `start(...$arguments): StartResult` (`:890`),
`cancel(?string $reason = null): CommandResult` (`:1233`),
`signal(string $name, ...$arguments): CommandResult` (`:1248`).

Its public `signal()`/`cancel()` check rejection internally and **throw** `LogicException`,
so a signal that fails to land propagates rather than being swallowed.

### 18.2 The workflow shape (found building the interpreter)

This is the deeper correction: not a renamed symbol but a different execution model.

| | Drafted against (wrong) | Actually installed |
|---|---|---|
| Workflow body | generator, `yield activity(...)` | **non-generator `handle(): mixed`**, run inside a Fiber |
| Base classes | `Workflow\Workflow`, `Workflow\Activity` | `Workflow\V2\Workflow`, `Workflow\V2\Activity` (both `handle()`) |
| Signals | `#[SignalMethod]` on a method | class-level `#[Workflow\V2\Attributes\Signal('name')]` — **no `SignalMethod` exists in v2** |
| Wait | `yield awaitWithTimeout($d, fn () => $this->flag)` | `self::awaitWithTimeout($d, 'signalName')` — static; condition may be a signal-name string |

`awaitWithTimeout`'s real signature (`V2/Workflow.php:413`) is
`(int|string|CarbonInterval $timeout, callable|string $condition, ?string $conditionKey = null): mixed`.
Note the argument order — timeout first, condition second. Both accept strings, so reversing
them is syntactically valid and would produce a wait that never fires correctly.

**Why this was dangerous rather than merely wrong.** Both `src/functions.php` and
`src/V2/functions.php` exist in the package, as do v1 and v2 workflow classes. The wrong
import resolves silently. Nothing fails at boot; the failure is a workflow that misbehaves
at runtime, and no test in the foundation plan can catch it because these classes cannot be
exercised without a real engine and queue.

### 18.3 Consequences for later work

- The interpreter is written to the fiber shape. **Any future workflow or activity class must
  use `handle()`, not a generator.** Carry this into the Portia integration plan.
- The docs-versus-source gap should be assumed to persist. Verify against
  `vendor/durable-workflow/workflow/src/V2/` rather than the published examples.
- An integration test using `Queue::fake()` plus the vendor's own migrations and a trivial
  workflow class would catch a future rename that source-reading cannot. Deferred to the
  integration plan, where a real queue is available.
