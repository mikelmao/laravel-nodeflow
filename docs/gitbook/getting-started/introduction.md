# Introduction

This page helps you decide whether Nodeflow fits your application and clarifies the boundary between its workflow engine and your domain code.

## The deployment-per-automation problem

Long-running customer journeys often change more quickly than application code. A team might need to revise a notification sequence, a follow-up delay, or an eligibility branch without waiting for an engineer to implement, review, and deploy a one-off change.

Nodeflow separates those concerns. Developers provide the permitted capabilities, while application users assemble those capabilities into journeys in the visual editor. A journey can wait and resume later without keeping a web request open.

## When to use Nodeflow

Nodeflow is suited to workflows such as:

- Customer onboarding, reminders, and re-engagement journeys.
- Incident or flood-alert communications that wait for updates and stop for resolved recipients.
- Approval and follow-up processes that run across a group of users.
- Event-driven automations where a domain event chooses an audience and starts a flow.

It is a poor fit for a single synchronous request, for arbitrary code that end users should be allowed to execute, or for a workflow that must support parallel fan-out of the same subject today. Use ordinary Laravel jobs or application services when a visual, durable journey does not add value.

## Responsibilities at a glance

| Nodeflow provides | Your application provides |
| --- | --- |
| Flow graphs, draft editing, and immutable published versions | The users, organizations, and data that make up your domain |
| Durable execution, waits, resume behavior, and run records | `TenantResolver` and `SubjectResolver` implementations |
| Subject and cohort routing through registered nodes | Nodes that send messages, call services, or perform domain work |
| Trigger infrastructure and editor/run-view integration points | Domain events, trigger registration, routes, frontend wiring, and authorization gates |
| Graph validation and inspection of runs and subjects | Tenant ownership checks and the policies your users require |

The package intentionally does not decide who can publish a flow or which people an organization owns. Those are host-application decisions.

## The built-in nodes

Nodeflow ships four domain-free nodes:

- `core.wait` pauses the cohort for a relative duration, then passes still-active subjects through `default`.
- `core.condition` evaluates a registered subject attribute for each subject and routes to `yes` or `no`.
- `core.start_flow` starts another flow for the subjects that reach it; it can also end their current flow.
- `core.exit` ends the flow successfully for the subjects that reach it.

Your application registers nodes for domain work, such as `app.send_message`. The core nodes provide control flow, not business actions.

> **Experimental:** Nodeflow is pre-release software. Read the [experimental project status](../experimental/project-status.md) before making it part of a production workflow.

## Next step

Install the package with [Installation](installation.md), then use [Quick start](quick-start.md) to build a minimal integration.
