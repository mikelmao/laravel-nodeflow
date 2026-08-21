<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\ProviderRegistrationStep;
use Nodeflow\Console\NodeRegistrationWriter;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-bootstrap-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/bootstrap', 0777, true);

    $this->path = $this->root.'/bootstrap/providers.php';

    file_put_contents($this->path, <<<'PHP'
    <?php

    return [
        App\Providers\AppServiceProvider::class,
    ];
    PHP);

    $this->step = new ProviderRegistrationStep(
        new Filesystem,
        $this->root,
        'App\\',
        new NodeRegistrationWriter(new Filesystem),
    );
});

afterEach(function () {
    foreach (glob($this->root.'/bootstrap/*.php') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($this->root.'/bootstrap');
    @rmdir($this->root);
});

it('reports writable when the provider is not listed', function () {
    expect($this->step->check())->toBe(InstallOutcome::Writable);
});

it('lists the provider and leaves the file parseable', function () {
    // Counterfactual: skip this step entirely and everything else still passes
    // while the host's nodes silently never register. This test is the only thing
    // that says the sixth wiring requirement exists.
    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    $contents = file_get_contents($this->path);

    expect($contents)->toContain('App\Providers\NodeflowServiceProvider::class,')
        ->toContain('App\Providers\AppServiceProvider::class,');

    expectParseablePhp($this->path);

    // The array must still be an array of class strings the framework can use.
    expect(require $this->path)->toContain('App\Providers\NodeflowServiceProvider');
});

it('is idempotent and never lists the provider twice', function () {
    $this->step->apply();
    $before = file_get_contents($this->path);

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->step->apply())->toBe(InstallOutcome::AlreadyPresent);
    expect(file_get_contents($this->path))->toBe($before);

    expect(substr_count($before, 'NodeflowServiceProvider::class'))->toBe(1);
});

it('cannot wire a missing bootstrap file and offers the snippet', function () {
    unlink($this->path);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect($this->step->snippet())->toContain('NodeflowServiceProvider::class');
});

it('cannot wire a bootstrap file with two return arrays', function () {
    file_put_contents($this->path, "<?php\n\nreturn [\n];\n\n// return [\n");

    $before = file_get_contents($this->path);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect(file_get_contents($this->path))->toBe($before);
});
