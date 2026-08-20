<?php

use Tests\Support\RequestContextScanner;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-scan-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/Http', 0777, true);
    mkdir($this->root.'/Execution', 0777, true);
});

afterEach(function () {
    foreach (['Http', 'Execution'] as $dir) {
        foreach (glob($this->root.'/'.$dir.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->root.'/'.$dir);
    }
    rmdir($this->root);
});

it('detects a forbidden model queried outside the allowed paths', function () {
    // The counterfactual for the whole scanner: if violations() returned [] here,
    // the architecture test built on it would be decorative.
    file_put_contents(
        $this->root.'/Http/FlowController.php',
        '<?php RunSubject::where("run_id", 1)->get();'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))
        ->toBe(['Http/FlowController.php: RunSubject']);
});

it('allows the same query inside an allowed path', function () {
    file_put_contents(
        $this->root.'/Execution/NodeRunner.php',
        '<?php RunSubject::where("run_id", 1)->get();'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))->toBe([]);
});

it('detects both forbidden models and reports each once per file', function () {
    file_put_contents(
        $this->root.'/Http/RunController.php',
        '<?php NodeExecution::sum("subject_count"); RunSubject::count(); NodeExecution::count();'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))
        ->toBe(['Http/RunController.php: NodeExecution', 'Http/RunController.php: RunSubject']);
});

it('ignores a mention that is not a static call', function () {
    // A docblock or a type import naming the model is not a query.
    file_put_contents(
        $this->root.'/Http/Fine.php',
        '<?php use Nodeflow\Models\RunSubject; /** returns RunSubject rows */'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))->toBe([]);
});

it('ignores a ::class reference used as a relation target', function () {
    // Mirrors Run::subjects(): a hasMany(RunSubject::class) declaration names
    // the class as relation metadata; it never executes a query itself, so it
    // must read the same as an import, not as a static call.
    file_put_contents(
        $this->root.'/Http/Run.php',
        '<?php class Run { public function subjects() { return $this->hasMany(RunSubject::class); } }'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))->toBe([]);
});
