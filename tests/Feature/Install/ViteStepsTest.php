<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\ViteAliasStep;
use Nodeflow\Console\Install\ViteAliasValue;
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
    $delete = function (string $dir) use (&$delete) {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $delete($path) : unlink($path);
        }
        rmdir($dir);
    };

    if (is_dir($this->root)) {
        $delete($this->root);
    }
});

function viteSelectedConfig(string $root): string
{
    $output = [];
    $command = 'node '
        .escapeshellarg(__DIR__.'/../../Support/resolve-vite-config.mjs').' '
        .escapeshellarg($root).' 2>&1';

    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(0, implode(PHP_EOL, $output));

    return trim(implode(PHP_EOL, $output));
}

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

it('inspects the same config file Vite loads when candidates coexist', function () {
    file_put_contents($this->root.'/vite.config.js', <<<'JS'
    export default {
        resolve: {
            alias: { '@nodeflow/editor': 'vendor/atram/laravel-nodeflow/resources/js' },
            dedupe: ['react', 'react-dom', '@xyflow/react'],
        },
    }
    JS);

    file_put_contents($this->root.'/vite.config.ts', <<<'TS'
    export default {
        resolve: {
            alias: { '@nodeflow/editor': 'resources/js' },
            dedupe: ['lodash'],
        },
    }
    TS);

    expect(viteSelectedConfig($this->root))->toBe('vite.config.js');
    expect($this->alias->check())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->dedupe->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('accepts the Vite-selected CommonJS config candidates', function (string $filename, string $contents) {
    file_put_contents($this->root.'/'.$filename, $contents);

    expect(viteSelectedConfig($this->root))->toBe($filename);
    expect($this->alias->check())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->dedupe->check())->toBe(InstallOutcome::AlreadyPresent);
})->with([
    'cjs' => ['vite.config.cjs', <<<'CJS'
    module.exports = {
        resolve: {
            alias: { '@nodeflow/editor': 'vendor/atram/laravel-nodeflow/resources/js' },
            dedupe: ['react', 'react-dom', '@xyflow/react'],
        },
    }
    CJS],
    'cts' => ['vite.config.cts', <<<'CTS'
    export default {
        resolve: {
            alias: { '@nodeflow/editor': 'vendor/atram/laravel-nodeflow/resources/js' },
            dedupe: ['react', 'react-dom', '@xyflow/react'],
        },
    }
    CTS],
]);

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

it('does not combine a wrong alias with the package path elsewhere in the file', function () {
    ($this->write)(<<<'TS'
    export default defineConfig({
        resolve: {
            alias: {
                '@nodeflow/editor': path.resolve(__dirname, 'resources/js'),
            },
        },
    })

    const documentationPath = 'vendor/atram/laravel-nodeflow/resources/js'
    TS);

    expect($this->alias->check())->toBe(InstallOutcome::CannotWire);
});

it('accepts every single- and double-quoted alias key and package-path combination', function (string $keyQuote, string $pathQuote) {
    ($this->write)(<<<TS
    export default defineConfig({
        resolve: {
            alias: {
                {$keyQuote}@nodeflow/editor{$keyQuote}: {$pathQuote}vendor/atram/laravel-nodeflow/resources/js{$pathQuote},
            },
        },
    })
    TS);

    expect($this->alias->check())->toBe(InstallOutcome::AlreadyPresent);
})->with([
    'single key and single path' => ["'", "'"],
    'single key and double path' => ["'", '"'],
    'double key and single path' => ['"', "'"],
    'double key and double path' => ['"', '"'],
]);

it('scans an alias path.resolve value through its inner comma', function () {
    ($this->write)(<<<'TS'
    export default defineConfig({
        resolve: {
            alias: {
                '@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js'),
            },
        },
    })
    TS);

    expect($this->alias->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('rejects two live nodeflow editor alias properties', function () {
    ($this->write)(<<<'TS'
    export default defineConfig({
        resolve: {
            alias: {
                '@nodeflow/editor': 'vendor/atram/laravel-nodeflow/resources/js',
                '@nodeflow/editor': 'vendor/atram/laravel-nodeflow/resources/js',
            },
        },
    })
    TS);

    expect($this->alias->check())->toBe(InstallOutcome::CannotWire);
});

it('returns null for an alias property with no value', function (string $source) {
    expect(ViteAliasValue::extract($source))->toBeNull();
})->with([
    'before a comma' => ['{"@nodeflow/editor":,}'],
    'before an enclosing object end' => ['{"@nodeflow/editor":}'],
    'at end of file' => ['{"@nodeflow/editor":'],
]);

it('returns null for a nested duplicate alias property', function () {
    expect(ViteAliasValue::extract(
        '{"@nodeflow/editor":{"@nodeflow/editor":"vendor/atram/laravel-nodeflow/resources/js"}}',
    ))->toBeNull();
});

it('rejects a nested duplicate nodeflow editor alias property', function () {
    ($this->write)(<<<'TS'
    export default defineConfig({
        resolve: {
            alias: {
                '@nodeflow/editor': {
                    '@nodeflow/editor': 'vendor/atram/laravel-nodeflow/resources/js',
                },
            },
        },
    })
    TS);

    expect($this->alias->check())->toBe(InstallOutcome::CannotWire);
});

it('does not let a package path in another nested property rescue a wrong alias', function () {
    ($this->write)(<<<'TS'
    export default defineConfig({
        resolve: {
            alias: {
                '@nodeflow/editor': 'resources/js',
            },
        },
        metadata: {
            documentationPath: path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js'),
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
