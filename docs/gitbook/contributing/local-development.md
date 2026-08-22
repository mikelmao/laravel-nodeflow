# Local development

Set up the package workspace to run the PHP package tests and the editor-source checks locally. Nodeflow is a Laravel package tested through Testbench; it is not an application starter kit.

## Supported environment

The Composer manifest supports PHP `^8.3` and Laravel Illuminate components `^12.0|^13.0`. The JavaScript manifest declares React and React DOM peer ranges of `^18.0.0 || ^19.0.0`.

The lockfile's current development dependency set requires Node.js `22.22.2` or later. The repository does not declare an npm `engines` field or make that a public runtime contract; use a compatible Node.js release for local tooling and your host application's Vite toolchain.

## Create a workspace

Clone the repository and install its locked development dependencies:

```bash
git clone https://github.com/mikelmao/laravel-nodeflow.git
cd laravel-nodeflow
composer install
npm ci
```

## Outcome

At this point, Composer can load the package and the local TypeScript toolchain is installed from the lockfile.

## Understand the test context

The PHP suite extends Orchestra Testbench. Its base test case loads the Nodeflow service provider, uses an in-memory SQLite test connection, loads the package migrations, and container-binds the `WorkflowEngine` contract to `FakeWorkflowEngine`. This gives package tests a small Laravel application without asking contributors to create or configure a host application.

Use a real host application when checking integration details that Testbench cannot establish, such as a host's authentication middleware, route placement, tenancy bindings, queue backend, or deployment asset build.

## Work on the editor source

The React and TypeScript source lives in `resources/js` and is compiled by the host application's Vite build. The repository's `@nodeflow/editor` package is private development tooling; it is not a separately published browser package.

When changing the editor, use the repository commands to test and type-check the source. Then verify it through a representative host build, because a passing local source suite cannot prove that the host's aliases, peer dependencies, CSS processing, or asset entry points are wired correctly. See [Frontend setup](../integration/frontend-setup.md) for the host contract.

## Run focused checks while you work

Run one relevant Pest file while changing PHP behavior:

```bash
vendor/bin/pest tests/Feature/PublishFlowTest.php
```

Run one relevant Vitest file while changing the editor:

```bash
npm test -- resources/js/editor/FlowEditor.test.tsx
```

Before handing work off, use the full commands in [Testing](testing.md).

## Next step

Run the package checks described in [Testing](testing.md).
