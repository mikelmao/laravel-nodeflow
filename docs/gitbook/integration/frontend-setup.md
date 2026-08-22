# Frontend setup

Configure the five host frontend requirements so Vite can compile Nodeflow's React source, TypeScript can resolve its imports, and Tailwind includes its utilities.

Install the Composer package normally, then wire the host frontend:

```bash
composer require atram/laravel-nodeflow
npm install @xyflow/react
php artisan nodeflow:install
```

`@xyflow/react` belongs in the host application's npm dependencies because the host Vite build compiles the aliased package source. Composer and the alias do not install npm packages for that build.

## Add the five requirements

Merge the following settings into the existing host files; preserve any existing aliases, plugins, and path mappings.

1. Vite must resolve `@nodeflow/editor` to the package source.

   ```ts
   // Partial snippet: vite.config.ts.
   import path from 'node:path'

   export default defineConfig({
       resolve: {
           alias: {
               '@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js'),
           },
       },
   })
   ```

2. TypeScript needs both the package's base import and its wildcard subpaths.

   ```jsonc
   // Partial snippet: tsconfig.json compilerOptions.paths.
   {
     "compilerOptions": {
       "paths": {
         "@nodeflow/editor": ["./vendor/atram/laravel-nodeflow/resources/js"],
         "@nodeflow/editor/*": ["./vendor/atram/laravel-nodeflow/resources/js/*"]
       }
     }
   }
   ```

   The installer treats `baseUrl` as `.` when it is omitted. If your project sets `baseUrl`, those paths must still resolve under `vendor/atram/laravel-nodeflow/resources/js`; do not point them above the project root.

3. Tailwind v4 must scan the package source. For the conventional `resources/css/app.css` entry, place this directly after the Tailwind import:

   ```css
   @import 'tailwindcss';
   @source '../../vendor/atram/laravel-nodeflow/resources/js';
   ```

   The `../../` prefix is relative to the CSS entry. If the entry is elsewhere under `resources/`, compute the number of parent directories from that entry back to the project root before `vendor/...`; a wrong depth silently omits package-only utilities.

4. Install `@xyflow/react` in the host application, as shown above.

5. Ask Vite to use one copy of React, React DOM, and XYFlow:

   ```ts
   // Partial snippet: vite.config.ts.
   export default defineConfig({
       resolve: {
           dedupe: ['react', 'react-dom', '@xyflow/react'],
       },
   })
   ```

The Vite snippets are partial because a host normally has one `defineConfig()` call. Merge their `resolve.alias` and `resolve.dedupe` keys into that exported configuration rather than adding competing exports.

| Missing setting | Typical symptom |
| --- | --- |
| Vite alias | Vite cannot resolve `@nodeflow/editor` during the build or dev server run. |
| Base TypeScript path | Type checking cannot resolve the package's base import. |
| Wildcard TypeScript path | A subpath import can fail TypeScript checking even if Vite builds. |
| Tailwind `@source` | The editor renders but package-only utility classes are unstyled. |
| `@xyflow/react` | Build-time module-resolution failure for XYFlow. |
| React dedupe | With a symlinked local package, the page can hit React's “Invalid hook call” error from two React copies. |

## Verify what the installer can change

Run a read-only check after making the changes:

```bash
php artisan nodeflow:install --check
```

The installer may write only steps it can safely re-check, including the package configuration/provider registration and a uniquely identifiable Tailwind `@source` entry. In `--check` mode, **Writable** means it found a safe change it would make but wrote nothing, so the command remains non-zero until it is applied.

TypeScript paths, Vite alias, Vite dedupe, and the `@xyflow/react` dependency are **verify-only**. The installer prints the required snippet or npm command instead of rewriting an arbitrary `vite.config.ts`, JSONC file, or package manifest. A **CannotWire** result means it could not verify the setting and is non-zero in either mode.

## Use a normal installation or a local package deliberately

For ordinary use, `composer require` installs the package under `vendor/atram/laravel-nodeflow`, which is the path in these settings. For local Composer path-repository or symlink development, preserve an installation that resolves to that vendor path and use the React dedupe setting above. Vite resolves a symlink to its real path; without dedupe, imports inside the package can resolve React from the package's own `node_modules` instead of the application's copy.

The package does not impose an additional filesystem-allow-list or file-watch configuration. If your host Vite setup has custom filesystem or watch restrictions, evaluate those restrictions against the actual Composer vendor path it aliases.

## Next step

Mount the package routes and lower-case Inertia adapters in [Routes and Inertia](routes-and-inertia.md).
