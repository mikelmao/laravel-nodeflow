<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\TailwindSourceStep;
use Nodeflow\Console\SourceText;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-tailwind-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/resources/css', 0777, true);

    $this->step = new TailwindSourceStep(new Filesystem, $this->root);
    $this->entry = $this->root.'/resources/css/app.css';
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

it('writes the source line after the tailwind import', function () {
    file_put_contents($this->entry, "@import 'tailwindcss';\n\n@source '../views';\n");

    expect($this->step->check())->toBe(InstallOutcome::Writable);
    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    $css = file_get_contents($this->entry);

    expect($css)->toContain("@source '../../vendor/atram/laravel-nodeflow/resources/js';")
        ->toContain("@source '../views';");

    // Order matters to Tailwind only in that the import must come first.
    expect(strpos($css, "@import 'tailwindcss';"))
        ->toBeLessThan(strpos($css, 'atram/laravel-nodeflow'));
});

it('computes the relative path from wherever the entry actually is', function () {
    // Counterfactual: hardcode '../../' and this fails — the emitted @source
    // points outside the project and Tailwind silently matches nothing, which is
    // the exact failure mode this step exists to prevent.
    mkdir($this->root.'/resources/assets/styles/main', 0777, true);

    // beforeEach only creates the resources/css directory, not $this->entry
    // itself — nothing has written app.css yet in this test, so there is
    // nothing to unlink. Only the (empty) directory needs removing.
    if (file_exists($this->entry)) {
        unlink($this->entry);
    }
    rmdir($this->root.'/resources/css');

    $deep = $this->root.'/resources/assets/styles/main/entry.css';
    file_put_contents($deep, "@import 'tailwindcss';\n");

    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    expect(file_get_contents($deep))
        ->toContain("@source '../../../../vendor/atram/laravel-nodeflow/resources/js';");
});

it('is idempotent and never writes the line twice', function () {
    file_put_contents($this->entry, "@import 'tailwindcss';\n");

    $this->step->apply();
    $before = file_get_contents($this->entry);

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->step->apply())->toBe(InstallOutcome::AlreadyPresent);
    expect(file_get_contents($this->entry))->toBe($before);
    expect(substr_count($before, 'atram/laravel-nodeflow/resources/js'))->toBe(1);
});

it('treats a commented-out source line as absent', function () {
    // Counterfactual: check raw text and this fails, leaving a host who commented
    // the line out with no utilities and a green install.
    file_put_contents($this->entry, "@import 'tailwindcss';\n/* @source '../../vendor/atram/laravel-nodeflow/resources/js'; */\n");

    expect($this->step->check())->toBe(InstallOutcome::Writable);

    $this->step->apply();

    // The commented line is left alone; a live one is added.
    $css = SourceText::withoutCssComments(file_get_contents($this->entry));
    expect(substr_count($css, 'atram/laravel-nodeflow/resources/js'))->toBe(1);
});

/**
 * C3. check() and its E11 re-verify used to compare only the tail
 * ('vendor/atram/laravel-nodeflow/resources/js'), never the '../' prefix that
 * decides whether Tailwind actually resolves the path. All three hosts below
 * currently read AlreadyPresent while Tailwind matches nothing.
 */
it('does not accept a nested entry carrying the docs\' literal two-level prefix', function () {
    // The docs' own literal '../../vendor/...' is correct ONLY for an entry at
    // resources/css/app.css. From one directory deeper it must be
    // '../../../vendor/...' — one more level — and the two-level string points
    // outside the project.
    mkdir($this->root.'/resources/css/nodeflow', 0777, true);

    // beforeEach only creates the resources/css directory, not $this->entry
    // itself — nothing has written app.css yet in this test, so the
    // conventional path is simply absent and entry() falls back to its
    // recursive search under resources/.

    $nested = $this->root.'/resources/css/nodeflow/app.css';
    file_put_contents($nested, "@import 'tailwindcss';\n\n@source '../../vendor/atram/laravel-nodeflow/resources/js';\n");

    expect($this->step->check())->toBe(InstallOutcome::Writable);

    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    expect(file_get_contents($nested))
        ->toContain("@source '../../../vendor/atram/laravel-nodeflow/resources/js';");

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('does not accept a source line with no ../ prefix at all', function () {
    file_put_contents($this->entry, "@import 'tailwindcss';\n\n@source 'vendor/atram/laravel-nodeflow/resources/js';\n");

    expect($this->step->check())->toBe(InstallOutcome::Writable);

    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    expect(file_get_contents($this->entry))
        ->toContain("@source '../../vendor/atram/laravel-nodeflow/resources/js';");

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('does not accept a source line pointing at an unrelated absolute-looking path', function () {
    file_put_contents($this->entry, "@import 'tailwindcss';\n\n@source '/nowhere/vendor/atram/laravel-nodeflow/resources/js';\n");

    expect($this->step->check())->toBe(InstallOutcome::Writable);

    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    expect(file_get_contents($this->entry))
        ->toContain("@source '../../vendor/atram/laravel-nodeflow/resources/js';");

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('accepts the correct computed line outright', function () {
    file_put_contents($this->entry, "@import 'tailwindcss';\n\n@source '../../vendor/atram/laravel-nodeflow/resources/js';\n");

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('cannot wire when no css entry contains the tailwind import', function () {
    file_put_contents($this->entry, "body { color: red }\n");

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect($this->step->snippet())->toContain('@source');
});

it('cannot wire when two css files both look like the entry', function () {
    // Counterfactual: pick the first match and this fails — install would write
    // into whichever file globbed first, which is not a decision it can make.
    file_put_contents($this->entry, "@import 'tailwindcss';\n");
    file_put_contents($this->root.'/resources/css/admin.css', "@import 'tailwindcss';\n");

    // resources/css/app.css is the convention, so it wins outright rather than
    // being ambiguous. Renaming it is what creates the ambiguity.
    expect($this->step->check())->toBe(InstallOutcome::Writable);

    unlink($this->entry);

    file_put_contents($this->root.'/resources/css/site.css', "@import 'tailwindcss';\n");

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
});

it('refuses a file whose tailwind import appears twice', function () {
    file_put_contents($this->entry, "@import 'tailwindcss';\n@import 'tailwindcss';\n");

    $before = file_get_contents($this->entry);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect(file_get_contents($this->entry))->toBe($before);
});
