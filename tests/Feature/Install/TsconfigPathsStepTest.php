<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\TsconfigPathsStep;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-tsconfig-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0777, true);

    $this->write = fn (string $contents) => file_put_contents($this->root.'/tsconfig.json', $contents);
    $this->step = new TsconfigPathsStep(new Filesystem, $this->root);
});

afterEach(function () {
    foreach (glob($this->root.'/*') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($this->root);
});

it('accepts the accepted host\'s jsonc tsconfig, comments and all', function () {
    // Measured: json_decode on the demo's real tsconfig.json returns null with
    // "Syntax error". Counterfactual: json_decode the raw file and this fails,
    // reporting the one installed host as unwired.
    ($this->write)(<<<'JSONC'
    {
        "compilerOptions": {
            /* Modules */
            // "rootDir": "./",
            "baseUrl": ".",
            "paths": {
                "@/*": ["./resources/js/*"],
                "@nodeflow/editor": ["./vendor/atram/laravel-nodeflow/resources/js/index.ts"],
                "@nodeflow/editor/*": ["./vendor/atram/laravel-nodeflow/resources/js/*"]
            },
        }
    }
    JSONC);

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('accepts the directory form the docs print', function () {
    // docs/08-editor-client.md prints .../resources/js; the accepted host has
    // .../resources/js/index.ts. Both are correct. Counterfactual: compare to one
    // byte string and one of the two real-world forms is called broken.
    ($this->write)(json_encode(['compilerOptions' => ['paths' => [
        '@nodeflow/editor' => ['./vendor/atram/laravel-nodeflow/resources/js'],
        '@nodeflow/editor/*' => ['./vendor/atram/laravel-nodeflow/resources/js/*'],
    ]]]));

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('rejects a mapping that points somewhere else', function () {
    // Counterfactual: assert only that the two keys exist and this fails — a
    // mapping to the host's own resources/js type-checks against the wrong files
    // and silently resolves the wrong module.
    ($this->write)(json_encode(['compilerOptions' => ['paths' => [
        '@nodeflow/editor' => ['./resources/js/nodeflow'],
        '@nodeflow/editor/*' => ['./resources/js/nodeflow/*'],
    ]]]));

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
});

it('rejects a tsconfig with only the base mapping', function () {
    // Both mappings are required: docs/08-editor-client.md says so, and without
    // the wildcard a subpath import fails tsc while Vite still builds.
    ($this->write)(json_encode(['compilerOptions' => ['paths' => [
        '@nodeflow/editor' => ['./vendor/atram/laravel-nodeflow/resources/js'],
    ]]]));

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect($this->step->snippet())->toContain('@nodeflow/editor/*');
});

it('rejects a commented-out mapping', function () {
    ($this->write)(<<<'JSONC'
    {
        "compilerOptions": {
            "paths": {
                // "@nodeflow/editor": ["./vendor/atram/laravel-nodeflow/resources/js"],
                // "@nodeflow/editor/*": ["./vendor/atram/laravel-nodeflow/resources/js/*"]
            }
        }
    }
    JSONC);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
});

it('cannot wire a missing or unparseable tsconfig', function () {
    expect($this->step->check())->toBe(InstallOutcome::CannotWire);

    ($this->write)('{ this is not json at all ');

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
});

it('never writes to the tsconfig', function () {
    // E20: a JSON round-trip destroys the starter kit's ninety-line comment
    // block, which is documentation the host owns.
    ($this->write)(<<<'JSONC'
    {
        // keep me
        "compilerOptions": { "paths": {} }
    }
    JSONC);

    $before = file_get_contents($this->root.'/tsconfig.json');

    $this->step->apply();

    expect(file_get_contents($this->root.'/tsconfig.json'))->toBe($before);
});
