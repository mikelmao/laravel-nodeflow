# Testing

Use the PHP and TypeScript suites together: the package has server-side behavior, React clients, and integration assumptions that each need a different kind of evidence.

## Run the full checks

From the repository root, run:

```bash
vendor/bin/pest
npm test
npm run types:check
composer validate --strict
```

## Outcome

These commands exercise the PHP suite, the browser-like editor and run-view suite, static TypeScript checks, and Composer metadata validation.

## PHP tests

Pest discovers the `Unit` and `Feature` test suites from the PHPUnit configuration. Unit tests cover focused values, graph and registration rules, source transformations, and architectural boundaries. Feature tests exercise package behavior through Laravel, including HTTP endpoints, persistence, publishing, starts, and execution interactions.

The shared Testbench base case loads the package provider, migrates an in-memory SQLite database, and binds a fake workflow engine. That setup makes package behavior repeatable without booting a consuming application. See [Local development](local-development.md) for the boundaries of that test context.

Architecture tests are intentional constraints, not style checks. They reject direct durable-workflow imports or fully qualified references outside the engine and workflow directories. They also scan the relevant source areas for static `RunSubject` or `NodeExecution` access and literal access to their tables, preserving the rule that tenantless records are reached through a scoped run. These are targeted guards, not an exhaustive proof of isolation. When moving code across those boundaries, run the architecture test and decide whether the boundary should change before changing its allowlist.

Run a focused PHP test file with:

```bash
vendor/bin/pest tests/Unit/ArchitectureTest.php
```

## TypeScript tests

Vitest runs TypeScript and React tests under jsdom, using the shared JavaScript test setup. These tests cover client contracts such as canvas changes, autosave, structured publish errors, overlay rendering, polling, and subject-panel behavior without requiring a full browser.

Run a focused client test file with:

```bash
npm test -- resources/js/run/FlowRun.test.tsx
```

`npm run types:check` runs TypeScript with no emitted output. Keep it in the normal edit loop: strict compiler options catch contract drift that a runtime test may not reach.

## Add acceptance evidence when the boundary changes

Green package tests do not prove that a consuming application compiles and serves the editor assets. They also do not replace acceptance checks against a real host, browser, queue worker, and database when your change depends on any of those systems.

Perform that additional verification when changing host-facing Vite wiring, Inertia pages, authorization or tenancy bindings, durable-worker behavior, database-specific queries, or real node side effects. Keep those checks scoped to the host and environment that own them; the package suite remains the fast regression layer.

## Next step

Use [Project structure](project-structure.md) to find the owning area before adding or updating a test.
