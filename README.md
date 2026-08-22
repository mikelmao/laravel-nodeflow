# laravel-nodeflow

A visual workflow builder and durable execution engine for Laravel applications, built so your
**customers** can author their own multi-day automated journeys without you shipping code for each one.

The package owns the mechanism — storage, versioning, the node contract, the durable interpreter.
Your application owns the domain — what a "subject" is, who the tenants are, and what the nodes
actually do.

> **Status: tooling shipped; final browser acceptance remains open.** The durable engine, node
> generator, installer, opt-in editor, run view, node-package scaffolder and node extractor all
> ship. The package is verified by 937 PHP tests with 7,538 assertions and 160 client Vitest tests
> across 17 files. Earlier acceptance work exercised the interpreter locally with a real queue
> worker; real-queue execution is not yet part of CI. See
> [Known limitations](docs/05-execution-model.md#known-limitations) before you depend on it.

## What it gives you

- **Durable, non-blocking waits.** A journey can wait five minutes or thirty days. Nothing holds a
  queue worker while it waits; the workflow hibernates and resumes across restarts and deploys.
- **Cancellation as a first-class primitive.** "Wait one day, unless the customer converts first" is
  expressed once and works. A converting subject stops receiving the rest of the journey.
- **Fan-out at scale.** One event can produce a run per tenant, each over an audience of six figures,
  without one run per person.
- **Domain nodes in about an hour.** A node is one class plus one declarative definition, and
  `php artisan nodeflow:make-node` writes the first draft of it. You never touch the interpreter.
- **Immutable versioning.** A customer editing a journey cannot disturb the runs currently sitting
  mid-24-hour wait on the previous version.
- **Multi-tenancy that fails closed.** Tenant isolation is enforced in three layers, and the audience
  ownership check is mandatory and cannot be switched off.

## Install

```bash
composer require atram/laravel-nodeflow
php artisan nodeflow:install
php artisan migrate
```

Requires PHP 8.3+, Laravel 12 or 13, and a queue driver that is not `sync`
(Redis, SQS, Beanstalkd or database).

Then implement two small contracts and register your nodes — see
[Integration](docs/02-integration.md). Nothing works until you do; the shipped defaults deliberately
fail closed rather than guess.

`nodeflow:install` creates or wires the package provider and checks the required host integration.
Your domain nodes remain explicit:

```bash
php artisan nodeflow:make-node SendSms --type=yaya.send_sms --outputs='sent, failed' --test
```

That writes one class and one Pest test, and either registers the node for you or prints the exact
line to paste. See [Writing nodes](docs/03-writing-nodes.md).

## Documentation

| | |
|---|---|
| [1. Overview](docs/01-overview.md) | The mental model: flows, versions, runs, subjects, audiences. Read this first. |
| [2. Integration](docs/02-integration.md) | Install, the two contracts you must implement, service-provider wiring, queue setup. |
| [3. Writing nodes](docs/03-writing-nodes.md) | The node contract, cardinality, config fields, test mode, failure isolation. |
| [4. Writing triggers](docs/04-writing-triggers.md) | Turning any Laravel event into an authoring surface. |
| [5. Execution model](docs/05-execution-model.md) | How a stored graph becomes a durable run. Waits, cancellation, limitations. |
| [6. Operations](docs/06-operations.md) | Test mode, health checks, pruning, status lifecycles. |
| [7. Worked example](docs/07-worked-example-rada-yaya.md) | A complete flood-alert journey, end to end. |
| [8. Editor client](docs/08-editor-client.md) | The five host-wiring requirements, thin Inertia pages, extension props, and the run view's overlay and polling contract. |
| [9. Packaging nodes](docs/09-packaging-nodes.md) | Scaffold Composer node packages, ship editor controls, and safely extract existing nodes. |

## Design documents

`docs/superpowers/specs/` holds the architectural spec, including the numbered decisions behind the
design and a record of two engine-API corrections found during implementation. Read it when you want
to know *why*; read the guides above when you want to know *how*.
