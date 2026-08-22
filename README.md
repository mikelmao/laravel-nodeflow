# Laravel Nodeflow

Laravel Nodeflow is a visual workflow builder and durable execution engine that lets your application users compose approved, long-running automations while your application retains control of its tenants, subjects, authorization rules, and domain-specific actions.

> **Experimental:** Nodeflow is pre-release software. Review the [experimental project status](docs/gitbook/experimental/project-status.md) and [known limitations](docs/gitbook/experimental/known-limitations.md), then test it carefully before relying on it for production automations.

## Requirements

Nodeflow requires PHP `^8.3`, Laravel 12 or 13 (`illuminate/console`, `filesystem`, `support`, and `database` `^12.0|^13.0`), and `durable-workflow/workflow ^2.0@rc`. Editor routes additionally need `inertiajs/inertia-laravel ^2.0`; durable execution needs a queue connection other than `sync`.

## Install

```bash
composer require atram/laravel-nodeflow
php artisan nodeflow:install
php artisan migrate
```

## Capabilities

- Durable waits, resumption, and cancellation for long-running workflows.
- Package-managed published-version snapshots that keep existing runs on their original graphs.
- Custom nodes, triggers, and subject attributes for application-defined behavior.
- Opt-in Inertia editor and run-inspection clients.
- Health checks, pruning, package scaffolding, and node extraction tooling.

## Documentation

The [GitBook documentation](docs/gitbook/README.md) is the canonical guide. Start with the [quick start](docs/gitbook/getting-started/quick-start.md), follow the [flood-alert example application](docs/gitbook/example-application/overview.md), review the [experimental status](docs/gitbook/experimental/project-status.md), or see [contributing](docs/gitbook/contributing/architecture.md).

The numbered guides in `docs/01-*.md` through `docs/09-*.md` remain as legacy references; use the GitBook for current documentation.
