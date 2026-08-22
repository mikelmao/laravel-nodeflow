# Extracting nodes

Use `nodeflow:extract-node` to move one host-owned node class into a local Composer package, update its registration, install that package, and verify discovery from a fresh Laravel boot.

## Run the command

**From the host application root:**

```bash
php artisan nodeflow:extract-node 'App\Nodeflow\Nodes\SendWidget' \
  --package=acme/widgets
```

The complete command signature is:

```text
nodeflow:extract-node {class}
    {--package=}
    {--namespace=}
    {--path=}
    {--force}
```

`class` is the fully-qualified node class to move. `--package` is required. `--namespace` and `--path` use the same derivation and defaults as [Creating node packages](creating-packages.md): the default path is `packages/vendor/name`, and the default namespace is the StudlyCase form of the package name.

The command exits `0` only after installation and fresh-host verification succeed. Any refusal, move failure, or verification failure exits `1`.

## Meet the preconditions

The class must already load in the host, extend `Node`, and implement `HandlesSubject`, `HandlesAudience`, or both. Its source file must:

- be inside a real host PSR-4 directory declared in `autoload` or `autoload-dev`;
- not be under any `vendor` directory;
- declare only that one top-level named symbol; and
- implement `type()` as a statically provable string literal.

The fixed `type()` requirement preserves the identity stored in published flow versions and active runs after the namespace changes. A type derived from the class name, reflection, configuration, or runtime computation is refused.

The target package name and namespace must be valid. The host must require `atram/laravel-nodeflow`, `composer.json` must be readable, Composer must be invocable, and Laravel package discovery must not be disabled for the destination package.

Before a move, create a normal backup or commit your current work. A clean Git worktree makes the resulting changes easy to review and revert with your usual workflow; do not rely on a destructive cleanup command as a substitute for review.

## Understand the reference scan

Extraction refuses when it finds a host reference to the old class that it cannot rewrite safely. It understands resolved PHP names in imports, aliases, class constants, inheritance and other PHP name positions; exact quoted class strings; `class_alias()` references; and fully-qualified references in Blade or heredoc/nowdoc text.

It scans host-owned PHP-family files with these extensions:

```text
.php, .blade.php, .phtml, .inc
```

The scan covers host top-level source directories and files, plus declared PSR-4 roots. It excludes dependency trees and generated framework/cache directories. It follows nested source symlinks so an autoloadable linked source tree is not silently missed; unreadable or cyclic scan paths cause a refusal.

The command rewrites only the moved class file, its conventional Nodeflow feature test when that test actually references the class, and the matching registration/import in the host Nodeflow provider. Any other detected reference stops the command before changes begin.

> **Important:** Static analysis cannot find a class name assembled at runtime, discovered through reflection, read from a database, loaded from a configuration value, or written only in a file type outside the scan. Search for and migrate those references yourself before extraction.

## What the move changes

After all read-only checks pass, the command:

1. Scaffolds the destination package without JavaScript files.
2. Writes the class to `src/Nodes/{ShortClass}.php`, changes its namespace, and rewrites its own supported self-references.
3. Moves `tests/Feature/Nodeflow/{ShortClass}Test.php` to `tests/{ShortClass}Test.php` when that conventional test exists and references the class.
4. Registers the moved class in the package provider.
5. Removes the original registration from the host provider and removes a now-unused single-class import when that is safe.
6. Adds a host-relative Composer path repository. It gives the package a local `1.0.0` repository version and adds `"package/name": "*"` to `require` when it is not already required.
7. Re-scans the changed tree for old references, then deletes the original class and moved test.
8. Runs Composer without scripts or plugins. With a lock file it updates the package; otherwise it installs dependencies.
9. Invalidates the cached package manifest and starts a fresh host process. The fresh process must resolve the original node type to the new package class.

The host path repository remains portable because it records the relative path, not an absolute workstation path.

## Target and dependency conflicts

The destination directory may be absent, empty, or already be the same package. A foreign non-empty target is refused unless you pass `--force`.

The host cannot already require the destination package from another source. Repoint or remove that dependency first. Likewise, remove a matching `extra.laravel.dont-discover` entry before extraction: discovery is how the new provider registers the node after the host registration is removed.

## Rollback and recovery

Every mutation is journaled before it happens, including Composer-generated state. If an operation after the read-only gates fails, the command restores recorded files, created paths, deleted originals, and Composer state in reverse order. If Composer installation had started, it also attempts a script-free autoload regeneration against the restored state.

That guarantee applies when restoration itself succeeds. If the command reports that rollback storage could not be cleaned up, that storage is retained for manual inspection. If it reports that restoration or autoload proof failed, stop and inspect the host before retrying; it may not be safe to assume the tree is fully restored. These recovery failures still use exit code `1`.

On success, review the Composer and moved-source diff, run the relevant application checks, and keep the old node type unchanged in every existing graph.

## Next step

Read [Creating node packages](creating-packages.md) for the generated package layout, then run your application's editor, queue worker, and durable-workflow checks against the extracted node.
