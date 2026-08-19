# 1. Overview

## The problem this solves

You have a product where automation logic currently lives in your code: when X happens, message these
people, wait, check something, message them again. Every new variation is a deployment. Your customers
want to change the timing, the copy, the conditions — and they have to ask you.

nodeflow moves that authoring to your customers while keeping the *capabilities* in your code. They
assemble journeys from a palette of nodes; you write the nodes.

## The five things you need to hold in your head

**A flow** is a named journey belonging to one tenant. It has a trigger and a pointer to its current
published version.

**A flow version** is an immutable snapshot of the graph — nodes, edges, and each node's config —
frozen at publish time. Versions are never edited. This is the whole of the versioning strategy: a
customer can republish a flow forty times while a run from last Tuesday keeps executing the graph it
started on.

**A run** is one execution. It pins its `flow_version_id` when it starts and never consults the flow
again. A run belongs to one tenant.

**A subject** is a person (or whatever your domain calls them) moving through a run. The package never
knows what a subject *is* — it stores a `subject_type` string and a `subject_id` string, and asks your
application to resolve them when a node needs the real thing.

**An audience** is the set of subjects in a run. It is materialised into a table when the run starts
and referenced by run id thereafter — never passed around by value, because a six-figure list does not
fit in a workflow payload.

## One run, many subjects

This is the design decision that shapes everything else, so it is worth being explicit.

A naive engine gives each person their own workflow instance. That is simple and correct, and it falls
over at scale: a hundred thousand people means a hundred thousand durable instances, each with its own
event history, for a single alert.

nodeflow instead runs **one workflow per audience**. The interpreter walks the graph once; at each node
it loads the subjects currently sitting there and processes them in chunks. A run over one subject and
a run over a hundred thousand are the same code path — a per-person journey is simply a cohort of one.

The part that makes this bearable to work with: **you still write single-subject code.** A node says
"for this person, did they click? then `yes`, else `no`" and the runtime groups the answers and moves
each group down its own edge. You never write a `groupBy`. See
[Writing nodes](03-writing-nodes.md#cardinality-and-partitioning).

The cost is that waits are **cohort-relative**: "wait one day" means one day after the step ran for the
group, not one day after each person's own message landed. For messaging journeys that difference is
invisible. It matters if you need genuinely per-person anchoring, in which case trigger a per-subject
run instead.

## What the package owns, and what you supply

| The package owns | You supply |
|---|---|
| Storage: flows, immutable versions, runs, subjects, execution log, templates | **Tenancy resolution** — who the current tenant is, and whether they own a given subject |
| The durable interpreter and its activities | **Subject resolution** — turning `('user', ['1','2'])` into your models |
| The node and trigger contracts, and the registries | **Nodes** — the things that actually do work |
| Config-field schema, compiled to both validation rules and editor JSON | **Triggers** — which of your events start journeys |
| Publish-time graph validation | **Subject attributes** — which fields a non-technical author may build conditions on |
| Tenant scoping, and the mandatory audience ownership check | Queue infrastructure |

The package never names one of your classes. There is no `User`, no `Organization`, no `Tenant`
anywhere in `src/`. That is what makes it installable in a second application.

## The four node types shipped

Everything domain-specific is yours to write. The package ships only the domain-free primitives:

- **`core.wait`** — pause for a duration
- **`core.condition`** — branch `yes`/`no` per subject on a registered subject attribute
- **`core.start_flow`** — launch another flow for the subjects that reach it
- **`core.exit`** — subjects leave the journey successfully

There is deliberately **no split/parallel-branch node**. See
[Execution model](05-execution-model.md#no-parallel-branches).

## Where to go next

If you are wiring this into an application, read [Integration](02-integration.md) next. If you want to
understand the runtime before you commit, read [Execution model](05-execution-model.md). If you learn
best from a complete example, jump to the [worked example](07-worked-example-rada-yaya.md) and refer
back.
