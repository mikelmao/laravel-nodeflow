# Installation

This page installs Nodeflow, explains what its installer verifies, and identifies the queue and cache capabilities needed to execute durable workflows.

## Requirements

Nodeflow requires PHP 8.3 or newer (the Composer constraint is `^8.3`). Its `illuminate/console`, `illuminate/filesystem`, `illuminate/support`, and `illuminate/database` constraints are each `^12.0|^13.0`, which supports Laravel 12 or 13; its durable execution dependency is `durable-workflow/workflow ^2.0@rc`.

Use a queue connection other than `sync`. A durable workflow must be able to yield for a wait and later resume in a real worker process. The durable-workflow dependency also uses Laravel atomic cache locks while coordinating workflow work, so configure a cache store that supports atomic locks.

## Install the package

Run these commands from your Laravel application:

```bash
composer require atram/laravel-nodeflow
php artisan nodeflow:install
php artisan migrate
```

The package loads its migrations itself, so `php artisan migrate` discovers them without publishing copies into your application.

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

The installer can create or update the package configuration, an application Nodeflow provider, its registration in `bootstrap/providers.php`, and the Tailwind source entry. It verifies, but does not safely rewrite, the TypeScript path mappings, Vite alias and React deduplication settings, or the `@xyflow/react` dependency. When it cannot make one of those changes, it prints the exact snippet or command for you to apply and exits non-zero until the wiring is complete.

The installer also reports whether the four Nodeflow authorization gates are defined. Undefined gates are denied by the package, but their absence is a report rather than an installer failure so you can add application-specific rules next.

## Next step

Continue to [Quick start](quick-start.md) for a complete organization-and-user integration, or read [Frontend setup](../integration/frontend-setup.md) before embedding the editor.
