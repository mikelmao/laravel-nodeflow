<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\ViteAliasStep;
use Nodeflow\Console\Install\ViteDedupeStep;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-vite-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0777, true);

    $this->write = function (string $contents) {
        file_put_contents($this->root.'/vite.config.ts', $contents);
    };

    $this->alias = new ViteAliasStep(new Filesystem, $this->root);
    $this->dedupe = new ViteDedupeStep(new Filesystem, $this->root);
});

afterEach(function () {
    foreach (glob($this->root.'/*') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($this->root);
});

/** The accepted host's config, reduced to the two settings under test. */
function wiredViteConfig(): string
{
    return <<<'TS'
    import path from 'node:path'
    export default defineConfig({
        resolve: {
            alias: {
                '@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js'),
            },
            dedupe: ['react', 'react-dom', '@xyflow/react'],
        },
    })
    TS;
}

it('accepts the accepted host\'s configuration', function () {
    ($this->write)(wiredViteConfig());

    expect($this->alias->check())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->dedupe->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('rejects a commented-out alias', function () {
    // The test that distinguishes E22 from naive text matching. Counterfactual:
    // drop SourceText::withoutJsComments() from the step and this fails, because
    // the raw text contains the alias — so a host who commented it out while
    // debugging is told they are wired.
    ($this->write)("// '@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js'),\nexport default defineConfig({})");

    expect($this->alias->check())->toBe(InstallOutcome::CannotWire);
    expect($this->alias->snippet())->toContain('@nodeflow/editor');
});

it('rejects an alias pointing at a sibling packages/ directory without the vendor/ prefix', function () {
    // The E41 discriminator: PACKAGE_SOURCE used to be the short
    // 'atram/laravel-nodeflow/resources/js' tail, which is also a substring of
    // this path. A host who aliased the package's un-vendored source tree
    // (e.g. a workspace symlink under packages/, not vendor/) read as
    // correctly wired under the old constant. The full 'vendor/...' form does
    // not match this path, so it must report CannotWire.
    ($this->write)(<<<'TS'
    import path from 'node:path'
    export default defineConfig({
        resolve: {
            alias: {
                '@nodeflow/editor': path.resolve(__dirname, 'packages/atram/laravel-nodeflow/resources/js'),
            },
        },
    })
    TS);

    expect($this->alias->check())->toBe(InstallOutcome::CannotWire);
});

it('rejects a commented-out dedupe', function () {
    ($this->write)("/* dedupe: ['react', 'react-dom', '@xyflow/react'], */\nexport default defineConfig({})");

    expect($this->dedupe->check())->toBe(InstallOutcome::CannotWire);
});

it('rejects a dedupe list missing one of the three packages', function () {
    // G-4 is specifically all three. Counterfactual: check only that `dedupe`
    // appears and this fails — a list with react alone still mounts two copies of
    // @xyflow/react, which is an invalid hook call that looks like a React bug.
    ($this->write)(str_replace(
        "dedupe: ['react', 'react-dom', '@xyflow/react'],",
        "dedupe: ['react', 'react-dom'],",
        wiredViteConfig(),
    ));

    expect($this->dedupe->check())->toBe(InstallOutcome::CannotWire);
});

it('does not accept the three package names from outside the dedupe list', function () {
    // Counterfactual: search the whole file for the three names rather than the
    // dedupe array's own text, and this fails — every Vite config that imports
    // @vitejs/plugin-react and lists react in optimizeDeps reads as wired.
    ($this->write)(<<<'TS'
    import react from '@vitejs/plugin-react'
    export default defineConfig({
        optimizeDeps: { include: ['react', 'react-dom', '@xyflow/react'] },
        resolve: { dedupe: ['lodash'] },
    })
    TS);

    expect($this->dedupe->check())->toBe(InstallOutcome::CannotWire);
});

it('cannot wire when there is no vite config at all', function () {
    expect($this->alias->check())->toBe(InstallOutcome::CannotWire);
    expect($this->dedupe->check())->toBe(InstallOutcome::CannotWire);
});

it('never writes to the vite config', function () {
    // These two steps verify only (E20). Counterfactual: give either an apply()
    // that edits the file and this fails — and a regex insertion into an
    // arbitrary vite.config.ts is exactly the edit E11 forbids, because a
    // passing re-read would not prove it landed in the exported config.
    ($this->write)(wiredViteConfig());

    $before = file_get_contents($this->root.'/vite.config.ts');

    $this->alias->apply();
    $this->dedupe->apply();

    expect(file_get_contents($this->root.'/vite.config.ts'))->toBe($before);
});
