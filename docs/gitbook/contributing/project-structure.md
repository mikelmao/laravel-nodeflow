# Project structure

This repository keeps Laravel package code, host-compiled React source, tests, generators, configuration, migrations, and canonical documentation in separate areas. Use this map to start a contribution in the owner of the behavior you are changing.

## Outcome

You can identify whether a change belongs to a public integration surface, an internal implementation subsystem, a client source area, or its supporting test and documentation files.

## Repository directories

| Directory | Purpose |
| --- | --- |
| `src/Console` | Artisan commands plus installation, extraction, and generator support. |
| `src/Contracts` | Host-facing resolver contracts. |
| `src/Editor` | Draft persistence and editor-specific server behavior. |
| `src/Engine` | Adapter boundary for the durable workflow engine and its test fake. |
| `src/Execution` | Run starts, audience materialization, node execution, subject advancement, and interpreter steps. |
| `src/Graph` | Graph representation and publish-time validation. |
| `src/Http` | Package routes, controllers, and route-name resolution. |
| `src/Models` | Eloquent records and model concerns. |
| `src/Nodes` | Node contracts, registry, and built-in nodes. |
| `src/Policies` | Gate-backed flow and run authorization. |
| `src/Publishing` | Validated immutable flow-version publishing. |
| `src/Runs` | Read models for overlays and subject drill-down. |
| `src/Schema` | Subject attributes, fields, and field-option sources. |
| `src/Tenancy` | Tenant resolution and tenancy guard support. |
| `src/Triggers` | Trigger contracts, registry, and event listener dispatch. |
| `src/Workflows` | Deterministic durable interpreter and its side-effecting activities. |
| `resources/js` | Host-compiled React and TypeScript editor, graph, controls, canvas, and run-view source. |
| `tests` | Pest unit, feature, and shared-support tests. |
| `stubs` | Source templates used by package generators and extraction tooling. |
| `config` | Package configuration defaults. |
| `database` | Package migrations loaded by the provider and Testbench suite. |
| `docs/gitbook` | Canonical GitBook documentation. |
| `docs` | Legacy numbered guides and documentation-planning material; the numbered guides are references, not the canonical path. |

## Public surface and internals

`src/NodeflowServiceProvider.php` and `src/Nodeflow.php` are package entry points. The service provider loads configuration and migrations, registers the package services, and exposes commands; the facade offers the registration entry point. The contracts, node and trigger definitions, service APIs used by integrations, configuration, routes, and React exports are public surfaces that need compatibility-minded changes.

The remaining `src` subsystems implement those surfaces. In particular, engine adapters, execution and workflow internals, graph validation, models, and read models should change behind their established boundaries unless a new integration capability requires an explicit public contract. The architecture constraints described in [Architecture](architecture.md) are part of those boundaries.

## JavaScript source is host-compiled

The `resources/js` tree is source consumed by a host application's Vite build. The repository's npm package is private development tooling for testing and type-checking that source, not a distributable editor artifact. Treat host build configuration as an integration concern and verify it where the host owns it; see [Frontend setup](../integration/frontend-setup.md).

## Where to place tests and docs

Put focused PHP behavior tests in `tests/Unit` when they do not need a broader Laravel request or persistence interaction, and in `tests/Feature` when they do. Keep shared fixtures and helpers in `tests/Support`. Place React tests beside the source in `resources/js`.

Add contributor guidance here under `docs/gitbook/contributing`, and update the most relevant user-facing GitBook guide when a public contract changes. Do not update a legacy numbered guide merely to mirror canonical documentation.

## Next step

Return to [Architecture](architecture.md) to trace a change across subsystem boundaries, or use [Testing](testing.md) to verify it.
