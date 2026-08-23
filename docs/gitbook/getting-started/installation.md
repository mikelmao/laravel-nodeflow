# Installation

This page installs Nodeflow, explains what its installer verifies, and identifies the queue and cache capabilities needed to execute durable workflows.

## Requirements

Nodeflow requires PHP `^8.3` (PHP 8.3 or later releases before PHP 9.0). Its `illuminate/console`, `illuminate/filesystem`, `illuminate/support`, and `illuminate/database` constraints are each `^12.0|^13.0`, which supports Laravel 12 or 13; its durable execution dependency is `durable-workflow/workflow ^2.0@rc`.

Use a queue connection other than `sync`. A durable workflow must be able to yield for a wait and later resume in a real worker process.

For production, an atomic-lock-capable cache and a shared cache backend are strongly recommended acceleration and coordination capabilities, not a correctness prerequisite. The durable-workflow dependency uses the database as the correctness substrate for durable history, projections, and task leases; a missing or unsupported cache does not discard work. It can, however, degrade wake acceleration, repair-loop throttles, and cache-backed fleet fallbacks.

Run the dependency's diagnostic after configuring infrastructure:

```bash
php artisan workflow:v2:doctor
```

On one node, cache limitations affect local acceleration and diagnostics. For multi-node wake acceleration, the dependency documents a shared cache such as Redis, database cache, or Memcached; a per-node file cache cannot propagate wake signals between nodes.

## Install the package

Run these commands from your Laravel application:

```bash
composer require atram/laravel-nodeflow
php artisan nodeflow:install
php artisan migrate
```

The package loads its migrations itself, so `php artisan migrate` discovers them without publishing copies into your application.

The package also merges its configuration defaults when the application has no `config/nodeflow.php`. Publish an application-owned copy only when you need to customize those defaults:

```bash
php artisan vendor:publish --tag=nodeflow-config
```

> **Note:** `nodeflow:install` is idempotent. Re-running it keeps already-wired files unchanged and reports their status.

## Understand migration publication

Migrations are package-loaded by default. Only publish them if you deliberately want to own and maintain copies in your application's migration directory:

```bash
php artisan nodeflow:install --publish-migrations
```

If an already-published copy has drifted and you intentionally want to replace it, use:

```bash
php artisan nodeflow:install --force-migrations
```

Published copies take precedence over the package's files during migration discovery. The installer checks those copies for drift and reports a problem rather than silently treating the package and application copies as interchangeable.

## Verify the installation

Use this command in continuous integration or whenever you want a read-only check:

```bash
php artisan nodeflow:install --check
```

The installer treats configuration publication as optional and read-only: an absent host copy uses the merged package defaults, while a customized host copy remains untouched. It can create or update an application Nodeflow provider, register that provider in `bootstrap/providers.php`, and add the Tailwind source entry. It verifies, but does not safely rewrite, the TypeScript path mappings, Vite alias and React deduplication settings, or the `@xyflow/react` dependency. When it cannot make one of those changes, it prints the exact snippet or command for you to apply and exits non-zero until the wiring is complete.

The installer also reports whether the four Nodeflow authorization gates are defined. Undefined gates are denied by the package, but their absence is a report rather than an installer failure so you can add application-specific rules next.

## Next step

Continue to [Quick start](quick-start.md) for a complete organization-and-user integration, or read [Frontend setup](../integration/frontend-setup.md) before embedding the editor.
