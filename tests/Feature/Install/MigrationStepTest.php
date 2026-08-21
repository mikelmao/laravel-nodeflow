<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\MigrationStep;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-migrations-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/database/migrations', 0777, true);

    $this->packageMigration = realpath(__DIR__.'/../../../database/migrations/2026_08_18_000001_create_nodeflow_tables.php');
    $this->hostCopy = $this->root.'/database/migrations/2026_08_18_000001_create_nodeflow_tables.php';
});

afterEach(function () {
    foreach (glob($this->root.'/database/migrations/*.php') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($this->root.'/database/migrations');
    @rmdir($this->root.'/database');
    @rmdir($this->root);
});

it('reports already present when the host published nothing', function () {
    // E19's intended state. Counterfactual: report Writable here and every fresh
    // install publishes a copy that then shadows the package's own file forever.
    $step = new MigrationStep(new Filesystem, $this->root);

    expect($step->check())->toBe(InstallOutcome::AlreadyPresent);
    expect(glob($this->root.'/database/migrations/*.php'))->toBe([]);
});

it('publishes on request and reports what publishing means', function () {
    $step = new MigrationStep(new Filesystem, $this->root, publish: true);

    expect($step->check())->toBe(InstallOutcome::Writable);
    expect($step->apply())->toBe(InstallOutcome::Wired);

    expect($this->hostCopy)->toBeFile();
    expect(sha1_file($this->hostCopy))->toBe(sha1_file($this->packageMigration));
});

it('reports already present when a published copy matches', function () {
    copy($this->packageMigration, $this->hostCopy);

    $step = new MigrationStep(new Filesystem, $this->root);

    expect($step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('cannot wire a published copy that has drifted, and names both paths', function () {
    // The Plan 4 failure, reproduced. The package's copy gained a fourth index
    // column while the demo's published copy silently kept three, and no test
    // anywhere could see it: the index assertion lives in the package's suite
    // while the demo's tests run against the demo's copy.
    //
    // Counterfactual: compare file existence instead of content hash and this
    // fails, reporting a drifted host as correctly installed.
    copy($this->packageMigration, $this->hostCopy);
    file_put_contents($this->hostCopy, file_get_contents($this->hostCopy)."\n// host edit\n");

    $step = new MigrationStep(new Filesystem, $this->root);

    expect($step->check())->toBe(InstallOutcome::CannotWire);
    expect($step->snippet())
        ->toContain($this->hostCopy)
        ->toContain($this->packageMigration)
        ->toContain('--force-migrations');
});

it('treats --force-migrations as implying --publish-migrations, so apply() never hands back Writable', function () {
    // Fix round 1, Finding 1. The brief asserted "--force-migrations implies
    // --publish-migrations" and enforced it nowhere: apply() short-circuited on
    // `! $this->publish` and handed back check()'s own Writable, a value
    // InstallOutcome's docblock says apply() must never return.
    //
    // Counterfactual: drop the `$this->publish = $publish || $force;` line from
    // the constructor and this fails, with apply() returning Writable instead of
    // Wired.
    copy($this->packageMigration, $this->hostCopy);
    file_put_contents($this->hostCopy, file_get_contents($this->hostCopy)."\n// host edit\n");

    $step = new MigrationStep(new Filesystem, $this->root, publish: false, force: true);

    expect($step->check())->toBe(InstallOutcome::Writable);
    expect($step->apply())->toBe(InstallOutcome::Wired)
        ->not->toBe(InstallOutcome::Writable);
    expect(sha1_file($this->hostCopy))->toBe(sha1_file($this->packageMigration));
});

it('re-publishes over a drifted copy under --force-migrations', function () {
    copy($this->packageMigration, $this->hostCopy);
    file_put_contents($this->hostCopy, file_get_contents($this->hostCopy)."\n// host edit\n");

    $step = new MigrationStep(new Filesystem, $this->root, publish: true, force: true);

    expect($step->check())->toBe(InstallOutcome::Writable);
    expect($step->apply())->toBe(InstallOutcome::Wired);
    expect(sha1_file($this->hostCopy))->toBe(sha1_file($this->packageMigration));
});

it('ignores host migrations that are not ours', function () {
    // Counterfactual: compare by directory contents rather than by matching
    // basename against the package's own files, and this fails — the host's
    // unrelated migration reads as drift.
    file_put_contents(
        $this->root.'/database/migrations/2026_01_01_000000_create_users_table.php',
        '<?php // the host\'s own',
    );

    $step = new MigrationStep(new Filesystem, $this->root);

    expect($step->check())->toBe(InstallOutcome::AlreadyPresent);
});
