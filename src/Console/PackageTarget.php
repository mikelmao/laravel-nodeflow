<?php

namespace Nodeflow\Console;

/**
 * Everything `PackageScaffolder::scaffold()` needs to emit one shareable node
 * package. The `nodeflow:make-node-package` command constructs this —
 * validating the Composer name, deriving or accepting `--namespace`,
 * resolving `--path` through `HostPath::resolveWithin()`, and reading the
 * host's own `atram/laravel-nodeflow` constraint — so nothing here is
 * re-derived or re-validated by the scaffolder itself.
 *
 * Readonly and public: this is a plain data carrier crossing a task boundary,
 * not a class with behaviour of its own.
 */
final class PackageTarget
{
    public function __construct(
        /** e.g. `acme/widgets`, already validated against Composer's own name pattern. */
        public readonly string $composerName,
        /** e.g. `Acme\Widgets` — no leading or trailing namespace separator. */
        public readonly string $namespace,
        /** Absolute, already-resolved path to the package root. */
        public readonly string $absolutePath,
        /** Path to the package root, relative to the host's own root. */
        public readonly string $relativePath,
        /** Fully-qualified provider class, e.g. `Acme\Widgets\WidgetsServiceProvider`. */
        public readonly string $providerClass,
        /** The host's own `atram/laravel-nodeflow` require constraint, mirrored verbatim. */
        public readonly string $nodeflowConstraint,
        /** Whether to also emit `package.json`, `tsconfig.json`, and `resources/js/index.ts` (frontend scaffolding option). */
        public readonly bool $withJs,
    ) {}
}
