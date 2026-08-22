<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\PublishConfigStep;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-config-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/config', 0777, true);

    $this->step = new PublishConfigStep(new Filesystem, $this->root);
    $this->path = $this->root.'/config/nodeflow.php';
});

afterEach(function () {
    foreach (glob($this->root.'/config/*.php') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($this->root.'/config');
    @rmdir($this->root);
});

it('reports healthy and writes nothing when config is intentionally unpublished', function () {
    expect($this->path)->not->toBeFile();
    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->step->apply())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->path)->not->toBeFile();
});

it('never overwrites a config the host has edited', function () {
    file_put_contents($this->path, "<?php return ['tenancy' => 'resolver'];");
    $before = file_get_contents($this->path);

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
    expect(file_get_contents($this->path))->toBe($before);
    expect($this->step->apply())->toBe(InstallOutcome::AlreadyPresent);
    expect(file_get_contents($this->path))->toBe($before);
});

it('describes config as optional', function () {
    expect($this->step->describe())->toContain('optional');
});
