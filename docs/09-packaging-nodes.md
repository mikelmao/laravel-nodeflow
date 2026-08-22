# Packaging nodes

Nodeflow packages are ordinary Composer packages. There is no Nodeflow manifest, build step or
second discovery mechanism: Composer declares compatibility and autoloading, Laravel package
discovery boots the service provider, and each node keeps its own stable `type()` plus explicit
registration.

## Scaffold a package

```bash
php artisan nodeflow:make-node-package acme/messaging
```

The default target is `packages/acme/messaging`. The command mirrors the host's existing
`atram/laravel-nodeflow` constraint and creates:

```text
packages/acme/messaging/
├── composer.json
├── README.md
├── src/
│   ├── MessagingServiceProvider.php
│   └── Nodes/
├── tests/
│   └── ProviderTest.php
├── package.json          (--js)
├── tsconfig.json         (--js)
└── resources/js/index.ts (--js)
```

The package's `composer.json` owns PSR-4 autoloading and
`extra.laravel.providers`; `MessagingServiceProvider` owns a `$nodes` array and registers it from
`boot()`. Add package node classes there.

The provider deliberately carries `$nodes` only. A trigger's `event()` names an event class from
the host application, and a subject attribute's resolver receives a host model. Neither contract
travels cleanly in a package that does not know its eventual host, so triggers and subject
attributes stay in the host provider.

Options:

| Option | Meaning |
|---|---|
| `--namespace=Acme\Messaging` | Override the PHP namespace derived from the Composer name |
| `--path=packages/acme/messaging` | Override the in-repository target path; paths outside the host are refused |
| `--js` | Also scaffold `package.json`, `tsconfig.json` and `resources/js/index.ts` |
| `--force` | Overwrite an occupied target that is not already this package |

Composer names and PHP namespace segments are validated independently: Composer permits some names
that PHP cannot use as identifiers. An existing directory is reusable when its `composer.json`
already names the requested package; otherwise the command refuses unless `--force` is explicit.

`nodeflow:make-node` still targets the host application's provider. Generate a node in the host and
use `nodeflow:extract-node`, or move it into the package by hand and add it to the package provider.

## Ship editor controls

Pass `--js` when the package also supplies controls for custom field types:

```bash
php artisan nodeflow:make-node-package acme/messaging --js
```

That adds the minimal TypeScript project alongside an initially empty
`resources/js/index.ts`. Export a controls object from that entry point:

```ts
import { TownPicker } from './TownPicker'

export const controls = {
    town: TownPicker,
}
```

Then import it through a host-defined alias or relative path and spread it into the `controls` prop
at the host's thin page:

```tsx
import { FlowEditor } from '@nodeflow/editor'
import type { FlowEditorProps } from '@nodeflow/editor'
import { controls as messagingControls } from '@acme/messaging'

export default function Page(props: FlowEditorProps) {
    return <FlowEditor {...props} controls={{ ...messagingControls }} />
}
```

The package scaffold does not edit host Vite or TypeScript configuration. Under `--js`, the command
prints the missing Nodeflow editor wiring for you to add:

```ts
// vite.config.ts
import path from 'node:path'

export default defineConfig({
    resolve: {
        alias: {
            '@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js'),
        },
    },
})
```

```jsonc
// tsconfig.json — compilerOptions.paths
{
  "compilerOptions": {
    "paths": {
      "@nodeflow/editor": ["./vendor/atram/laravel-nodeflow/resources/js"],
      "@nodeflow/editor/*": ["./vendor/atram/laravel-nodeflow/resources/js/*"]
    }
  }
}
```

Also give `@acme/messaging` in the example a Vite alias and matching TypeScript path that resolve to
the package's `resources/js/index.ts`. The generated package TypeScript is not verified in the host
until that wiring exists. See [Editor client](08-editor-client.md#wire-the-host-application) for the
host's complete five-part editor setup.

## Extract an existing node

```bash
php artisan nodeflow:extract-node \
    'App\Nodeflow\Nodes\SendMessage' \
    --package=acme/messaging
```

The extraction scaffolds or reuses the package, rewrites the node namespace and a matching generated
test when present, registers the new class in the package provider, removes the old host-provider
entry, and adds a relative Composer path repository plus the requirement to the host. It accepts the
same `--namespace`, `--path` and `--force` options as the scaffold command. It moves one node per
invocation and does not rename the class.

Extraction is deliberately stricter than runtime registration. A node registered from another
provider's `boot()` still runs, but extraction refuses it because it cannot prove which host entry to
remove. It also refuses unresolved references to the old class rather than leave the host with a
stale autoload target.

### Keep `type()` statically fixed

Published versions and runs waiting in the middle of a flow resolve through `type()` forever. Before
moving anything, extraction proves that the method has exactly one of these shapes:

```php
public static function type(): string
{
    return 'app.send_message';
}
```

Or a literal constant declared on the same class:

```php
public const TYPE = 'app.send_message';

public static function type(): string
{
    return self::TYPE; // static::TYPE is accepted too
}
```

Everything else is refused, including concatenation, interpolation, a constant inherited or declared
on another class, a trait-supplied method, and values derived from `static::class`. The error names
both fixes: inline the finished type as a single literal, or declare a literal constant on the node
class and return it.

This static proof is necessary even though extraction compares the value again after the move. A
basename-derived expression can return the same value before and after a namespace change while
still making the next class rename orphan every stored graph.

### Know what the reference scan can see

The command scans host-owned PHP-like source across the project, resolves namespaces and imports,
and detects direct names, aliases, group imports, class strings and `class_alias()` calls. Comments
do not count. It re-scans the written tree before deleting the original class.

A static scan cannot see a class name assembled at runtime or stored in a database. The final fresh
host boot catches one of those only when normal application boot executes the relevant path. Search
and migrate dynamic or database-held class references yourself before extracting; there is no
`--allow-references` escape hatch.

### Composer verification and rollback

After the source move, the command installs the new path dependency for real: a scoped
`composer update acme/messaging` when a lock file exists, or `composer install` when it does not.
Scripts and plugins are disabled, and Composer runs with isolated configuration so ambient settings
cannot redirect writes outside the journalled host paths.

It then boots the Laravel host in a fresh PHP process. Success requires package discovery itself to
have registered the new class for the original type; the command does not manually register it to
make the check pass.

Every mutation is journalled before it happens, including Composer's effective vendor and bin trees,
lock state, generated autoload state, Laravel's effective discovery caches, bytes, modes, empty
directories and symlinks. A failure restores in reverse and, after a Composer attempt, regenerates
the restored autoloader without scripts or plugins. A restore or temporary-storage cleanup failure is
reported loudly with the retained recovery path instead of claiming the host is clean.
