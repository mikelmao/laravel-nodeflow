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

it('publishes the config when it is absent', function () {
    expect($this->step->check())->toBe(InstallOutcome::Writable);
    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    // The published file must be usable as config, not merely present.
    expect(require $this->path)->toHaveKey('tenancy');
});

it('never overwrites a config the host has edited', function () {
    // Counterfactual: publish unconditionally in apply() and this fails, having
    // destroyed the host's own tenancy setting.
    file_put_contents($this->path, "<?php return ['tenancy' => 'resolver'];");

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->step->apply())->toBe(InstallOutcome::AlreadyPresent);
    expect(require $this->path)->toBe(['tenancy' => 'resolver']);
});
