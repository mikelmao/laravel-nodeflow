# Creating node packages

Use `nodeflow:make-node-package` to create an ordinary Composer package that registers Nodeflow nodes through Laravel package discovery. The generated package is ready for local development; publishing it to a Composer repository remains your release process.

## Create a package

**From the host application root:**

```bash
php artisan nodeflow:make-node-package acme/widgets
```

The complete command signature is:

```text
nodeflow:make-node-package {name}
    {--namespace=}
    {--path=}
    {--js}
    {--force}
```

`name` is a lowercase Composer package name such as `acme/widgets`. The default target is `packages/acme/widgets`. The default PHP namespace is the StudlyCase form of the vendor and package segments, so `acme/my-cool-nodes` becomes `Acme\MyCoolNodes`.

The command requires the host application's `composer.json` to require `atram/laravel-nodeflow`. It copies that exact constraint into the package so the host and package use compatible Nodeflow versions.

> **Important:** The name must be valid both as a Composer package name and as the basis for a PHP namespace. Pass `--namespace` when a Composer-valid name would produce an invalid PHP identifier, such as a segment beginning with a number.

## Generated files

Without `--js`, the generated tree is:

```text
packages/acme/widgets/
├── composer.json
├── README.md
├── src/
│   ├── WidgetsServiceProvider.php
│   └── Nodes/
└── tests/
    └── ExampleTest.php
```

With `--js`, it also contains:

```text
packages/acme/widgets/
├── package.json
├── tsconfig.json
└── resources/
    └── js/
        └── index.ts
```

The package manifest declares `php` `^8.3`, requires the host's Nodeflow constraint, maps its namespace to `src/`, and lists its provider in `extra.laravel.providers`. Laravel discovers that provider when the package is installed; do not register it again in the host provider.

The generated provider owns a `$nodes` array and calls `Nodeflow::register($this->nodes)` from `boot()`. Add package node classes to that array:

**File: `packages/acme/widgets/src/WidgetsServiceProvider.php`**

```php
protected array $nodes = [
    \Acme\Widgets\Nodes\SendWidget::class,
];
```

The scaffold intentionally has no package manifest beyond Composer metadata, and it does not generate a publishing workflow for Packagist or another registry.

## Choose a namespace or location

Use `--namespace` to set the package namespace. The provider class name uses the final namespace segment. Use PHP namespace separators in the option value:

```bash
php artisan nodeflow:make-node-package acme/widgets \
  --namespace='Acme\Widgets'
```

Use `--path` for a different host-relative destination:

```bash
php artisan nodeflow:make-node-package acme/widgets \
  --path=modules/widgets
```

The path must stay inside the host application. Absolute paths, parent-directory traversal, paths escaping through symlinks, and the host root itself are refused.

## Add editor code

Pass `--js` when the package will ship editor-side definitions:

```bash
php artisan nodeflow:make-node-package acme/widgets --js
```

The generated `resources/js/index.ts` intentionally exports nothing until you add package-owned editor exports. It is an integration entry point, not an automatically registered client bundle.

The command may print Vite and TypeScript path snippets for `@nodeflow/editor`. It never edits the host's `vite.config.*` or `tsconfig.json`. Add the applicable snippets yourself, then wire your package exports into the host's editor setup as described in [Frontend setup](../integration/frontend-setup.md) and [Custom node appearance](../editor-and-run-view/custom-node-appearance.md).

## Existing directories and overwrites

An empty target is created. A non-empty target is refused unless either:

- its `composer.json` already names the same package, in which case the command can be run again; or
- you pass `--force`.

`--force` permits writing the generated files into a foreign occupied directory. Review that target carefully first. It never permits using the host application root as the package target.

For an existing matching package, the scaffold preserves existing Composer values and adds any missing defaults. It ensures the generated provider is present in `extra.laravel.providers`. Other generated files may be refreshed when they differ.

The command validates rendered PHP and destination containment before it starts writing. That prevents the validation failures it can detect from leaving a partial scaffold, but it is not a filesystem transaction: an unexpected write failure after output begins does not provide a rollback command.

## Use the package locally

The generator does not change the host's Composer repositories or dependencies. Add a path repository and require the package in the host before expecting Laravel discovery:

**File: `composer.json` in the host application**

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "packages/acme/widgets",
      "options": {
        "versions": {
          "acme/widgets": "1.0.0"
        }
      }
    }
  ],
  "require": {
    "acme/widgets": "*"
  }
}
```

Then install it from the host root:

```bash
composer update acme/widgets
```

## Verify the result

Run these checks after creating or changing a package:

```bash
composer validate --strict --working-dir=packages/acme/widgets
php -l packages/acme/widgets/src/WidgetsServiceProvider.php
composer update acme/widgets
php artisan about
```

The final command performs a normal Laravel boot, which is the useful discovery check after installation. Then add a package node to the provider, publish a flow that references its fixed `type()`, and verify it through your application’s normal editor and worker stack.

## Next step

Move an existing host node with [Extracting nodes](extracting-nodes.md), or write a new one with [Writing nodes](../building-automations/writing-nodes.md).
