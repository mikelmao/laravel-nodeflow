<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\XyflowDependencyStep;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-xyflow-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0777, true);

    $this->write = fn (array $manifest) => file_put_contents(
        $this->root.'/package.json',
        json_encode($manifest, JSON_PRETTY_PRINT),
    );

    $this->step = new XyflowDependencyStep(new Filesystem, $this->root);
});

afterEach(function () {
    foreach (glob($this->root.'/*') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($this->root);
});

it('accepts the dependency in dependencies', function () {
    ($this->write)(['dependencies' => ['@xyflow/react' => '^12.11.3']]);

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('accepts the dependency in devDependencies', function () {
    ($this->write)(['devDependencies' => ['@xyflow/react' => '^12.0.0']]);

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('cannot wire a manifest without it, and says to npm install', function () {
    // Counterfactual: write the dependency into package.json here and this test
    // would pass while leaving the manifest, the lockfile and node_modules
    // disagreeing — a worse state than the one before the edit.
    ($this->write)(['dependencies' => ['react' => '^19.0.0']]);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect($this->step->snippet())->toContain('npm install @xyflow/react');
});

it('cannot wire a missing manifest', function () {
    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
});

it('cannot wire a manifest whose dependencies key is not an object, instead of crashing', function () {
    // Fix round 1, finding 2: a malformed-but-valid manifest like
    // {"dependencies": "oops"} used to make array_merge() throw a TypeError,
    // because "oops" is a string, not an array. A step contracted to return an
    // InstallOutcome must not crash the install command over an input this
    // ordinary — CannotWire, not a fatal error, is the correct report.
    ($this->write)(['dependencies' => 'oops']);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
});

it('never writes to the manifest', function () {
    ($this->write)(['dependencies' => ['react' => '^19.0.0']]);

    $before = file_get_contents($this->root.'/package.json');

    $this->step->apply();

    expect(file_get_contents($this->root.'/package.json'))->toBe($before);
});
