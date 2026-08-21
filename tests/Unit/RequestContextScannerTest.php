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

it('detects a raw table query that bypasses the model entirely', function () {
    // The gap a reviewer walked straight through: this exact file passed the
    // architecture test. AudienceMaterialiser already writes
    // DB::table('nodeflow_run_subjects')->insert(...), so the raw-table form is
    // the idiom a future author copies — and Plan 4's
    // runs/{run}/nodes/{node}/subjects drill-down is where they will copy it.
    //
    // Counterfactual: drop the DB::table branch from the pattern and this
    // returns [].
    file_put_contents(
        $this->root.'/Http/SubjectsController.php',
        '<?php DB::table("nodeflow_run_subjects")->where("run_id", 1)->get();'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))
        ->toBe(['Http/SubjectsController.php: RunSubject']);
});

it('detects a raw table query against node executions too', function () {
    // Both untenanted tables, not just the one the reviewer probed. Single
    // quotes here, double quotes in the test above: the pattern accepts either.
    //
    // Counterfactual: drop the DB::table branch from the pattern and this
    // returns [].
    file_put_contents(
        $this->root.'/Http/TimelineController.php',
        "<?php DB::table('nodeflow_node_executions')->where('run_id', 1)->get();"
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))
        ->toBe(['Http/TimelineController.php: NodeExecution']);
});

it('detects a raw table query reached through a connection', function () {
    // DB::connection(...)->table(...) is the same query with a different
    // prefix, and a read replica is a plausible reason to write it.
    //
    // Counterfactual: narrow the pattern to `DB::table(` only and this
    // returns [].
    file_put_contents(
        $this->root.'/Http/ReplicaController.php',
        '<?php DB::connection("replica")->table("nodeflow_run_subjects")->count();'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))
        ->toBe(['Http/ReplicaController.php: RunSubject']);
});

it('allows a raw table query inside an allowed path', function () {
    // Mirrors AudienceMaterialiser's real bulk insert: allowed, because
    // /Execution/ is where crossing these tables directly is the design.
    file_put_contents(
        $this->root.'/Execution/AudienceMaterialiser.php',
        '<?php DB::table("nodeflow_run_subjects")->insert($chunk);'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))->toBe([]);
});

it('detects a lowercased static call', function () {
    // PHP resolves class names case-insensitively, so runsubject::where(...) is
    // a working query. A case-sensitive pattern reads it as prose.
    //
    // Counterfactual: drop the /i modifier and this returns [].
    file_put_contents(
        $this->root.'/Http/SloppyController.php',
        '<?php runsubject::where("run_id", 1)->get();'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))
        ->toBe(['Http/SloppyController.php: RunSubject']);
});

it('ignores an uppercased ::CLASS reference', function () {
    // The other half of case-insensitivity: ::CLASS is the same class constant
    // as ::class, so flagging it would be a false positive that teaches the
    // next author the scanner is noise.
    //
    // Counterfactual: drop the /i modifier and this reports a violation,
    // because `CLASS` no longer matches the `(?!class\b)` lookahead.
    file_put_contents(
        $this->root.'/Http/Shouty.php',
        '<?php class Run { public function subjects() { return $this->hasMany(RunSubject::CLASS); } }'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))->toBe([]);
});

it('ignores a table name mentioned in a comment', function () {
    // GraphValidator explains a fan-out restriction by naming
    // nodeflow_run_subjects' unique constraint. Prose about a table is not a
    // query against it, and a guard that fires on prose gets switched off.
    file_put_contents(
        $this->root.'/Http/Fine2.php',
        '<?php // nodeflow_run_subjects carries unique(run_id, subject_type, subject_id)'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))->toBe([]);
});

it('reports one violation per file even when both forms appear', function () {
    // The output is keyed on (file, model) so a file mixing both idioms reads
    // as one thing to fix, not two.
    file_put_contents(
        $this->root.'/Http/MixedController.php',
        '<?php RunSubject::count(); DB::table("nodeflow_run_subjects")->count();'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))
        ->toBe(['Http/MixedController.php: RunSubject']);
});

it('catches an aliased raw table name, which the method-anchored pattern missed', function () {
    // G-1's first evasion. Counterfactual: keep the exact-string pattern
    // DB::table('nodeflow_run_subjects') and this returns [].
    file_put_contents(
        $this->root.'/Http/AliasController.php',
        "<?php DB::table('nodeflow_run_subjects as rs')->where('rs.run_id', 1)->get();"
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))
        ->toBe(['Http/AliasController.php: RunSubject']);
});

it('catches a joined table name', function () {
    // G-1's second evasion.
    file_put_contents(
        $this->root.'/Http/JoinController.php',
        "<?php DB::table('nodeflow_runs')->join('nodeflow_node_executions', 'a', '=', 'b')->get();"
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))
        ->toBe(['Http/JoinController.php: NodeExecution']);
});

it('catches a from() table name', function () {
    // G-1's third evasion.
    file_put_contents(
        $this->root.'/Http/FromController.php',
        "<?php DB::query()->from('nodeflow_run_subjects')->count();"
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))
        ->toBe(['Http/FromController.php: RunSubject']);
});

it('catches a table named only inside raw sql', function () {
    // The evasion no list of builder method names can ever cover, which is why
    // E18 stopped listing method names. Counterfactual: anchor the pattern to
    // table()/join()/from() and this returns [].
    file_put_contents(
        $this->root.'/Http/RawController.php',
        "<?php DB::select('select count(*) from nodeflow_node_executions where run_id = ?', [1]);"
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))
        ->toBe(['Http/RawController.php: NodeExecution']);
});

it('does not flag a comment or docblock that merely names the model or the table', function () {
    // The over-matching guard, and it protects real prose: GraphValidator.php
    // legitimately explains nodeflow_run_subjects' unique constraint in a
    // comment. Counterfactual: match the bright-line rule against raw source
    // instead of comment-stripped source and this returns two violations,
    // creating pressure to delete a useful comment to appease a test.
    file_put_contents(
        $this->root.'/Http/DocumentedController.php',
        <<<'PHP'
        <?php
        /**
         * Counts live in nodeflow_run_subjects, and RunSubject::where() is the
         * thing this class deliberately does not do.
         */
        class DocumentedController
        {
            // nodeflow_node_executions is aggregated by the reader, not here.
            public function __invoke($run) { return $run->subjects()->count(); }
        }
        PHP
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))->toBe([]);
});
