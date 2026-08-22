<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Extract\ExtractJournal;

/**
 * ExtractJournal's own contract, tested directly rather than only through
 * ExtractNodeCommand's much larger black-box scenarios: recordWrite(),
 * recordCreate(), recordDelete(), and restore()'s two defining rules --
 * undo in REVERSE of recording order, and never let one entry's undo
 * failure stop every OTHER entry from still being attempted.
 */
function journalFixtureDirectory(): string
{
    return sys_get_temp_dir().'/nodeflow-extract-journal-'.getmypid();
}

afterEach(function () {
    $dir = journalFixtureDirectory();

    if (! is_dir($dir)) {
        return;
    }

    $walk = function (string $path) use (&$walk) {
        if (is_dir($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $walk($path.'/'.$entry);
                }
            }

            @chmod($path, 0755);
            rmdir($path);

            return;
        }

        @chmod($path, 0644);
        unlink($path);
    };

    $walk($dir);
});

it('restores a write by putting the original bytes back', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir, 0777, true);

    $path = $dir.'/a.txt';
    file_put_contents($path, 'original');

    $journal = new ExtractJournal(new Filesystem());
    $journal->recordWrite($path);
    file_put_contents($path, 'changed');

    $journal->restore();

    expect(file_get_contents($path))->toBe('original');
});

it('restores a create by deleting the path', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir, 0777, true);

    $path = $dir.'/created.txt';

    $journal = new ExtractJournal(new Filesystem());
    $journal->recordCreate($path);
    file_put_contents($path, 'new content');

    $journal->restore();

    expect($path)->not->toBeFile();
});

it('restores a create of a DIRECTORY by deleting it recursively', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir, 0777, true);

    $created = $dir.'/created-dir';

    $journal = new ExtractJournal(new Filesystem());
    $journal->recordCreate($created);
    mkdir($created, 0777, true);
    mkdir($created.'/nested', 0777, true);
    file_put_contents($created.'/nested/file.txt', 'x');

    $journal->restore();

    expect($created)->not->toBeDirectory();
});

it('restores a delete by recreating the path with its captured bytes', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir, 0777, true);

    $path = $dir.'/deleted.txt';
    file_put_contents($path, 'will be deleted');

    $journal = new ExtractJournal(new Filesystem());
    $journal->recordDelete($path, file_get_contents($path));
    unlink($path);

    $journal->restore();

    expect($path)->toBeFile();
    expect(file_get_contents($path))->toBe('will be deleted');
});

it('undoes entries in REVERSE of recording order', function () {
    // A create of a DIRECTORY recorded first, then a create of a FILE
    // inside it recorded second -- reverse order matters whenever a later
    // entry's own undo depends on an earlier one's target still existing
    // (ExtractJournal's own docblock: M4 registers into a provider M1 only
    // just created). Modelled here with a directory and a file inside it
    // that must be removed file-first, directory-second: deleting the
    // directory RECURSIVELY handles that regardless of order in THIS
    // specific case, so what this test actually pins down is that
    // restore() does not throw or skip anything when undoing the outer
    // directory create after the inner file create has already been
    // processed.
    $dir = journalFixtureDirectory();
    mkdir($dir, 0777, true);

    $created = $dir.'/package';

    $journal = new ExtractJournal(new Filesystem());
    $journal->recordCreate($created);
    mkdir($created, 0777, true);

    $journal->recordCreate($created.'/file.php');
    file_put_contents($created.'/file.php', 'x');

    $journal->restore();

    expect($created)->not->toBeDirectory();
});

it('continues undoing every OTHER entry even when one fails to restore, then raises the failure (Important 5, review round)', function () {
    // Mutating restore() to `break` after the first failed undo survives
    // the whole suite -- this is the persisted regression test the review
    // asked for. Entry A is recorded FIRST, so restore()'s own reverse
    // order undoes it LAST; entry B is recorded SECOND, so it is undone
    // FIRST, and B's own undo is made to fail (its file is chmod'd
    // read-only, so putBack()'s in-place overwrite cannot land). A
    // `break`-on-first-failure implementation would stop at B and never
    // even attempt A's own undo, leaving A's file un-restored.
    $dir = journalFixtureDirectory();
    mkdir($dir, 0777, true);

    $fileA = $dir.'/a.txt';
    file_put_contents($fileA, 'original-a');

    $fileB = $dir.'/b.txt';
    file_put_contents($fileB, 'original-b');

    $journal = new ExtractJournal(new Filesystem());

    $journal->recordWrite($fileA);
    file_put_contents($fileA, 'changed-a');

    $journal->recordWrite($fileB);
    file_put_contents($fileB, 'changed-b');

    // Blocks $fileB's own restore: putBack() does an IN-PLACE overwrite,
    // which needs write permission on the FILE itself (not merely its
    // directory) once the file already exists.
    chmod($fileB, 0444);

    try {
        expect(fn () => $journal->restore())->toThrow(RuntimeException::class);
    } finally {
        chmod($fileB, 0644);
    }

    expect(file_get_contents($fileA))->toBe('original-a');
});

it('raises a RuntimeException naming the path that could not be restored', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir, 0777, true);

    $fileB = $dir.'/b.txt';
    file_put_contents($fileB, 'original-b');

    $journal = new ExtractJournal(new Filesystem());
    $journal->recordWrite($fileB);
    file_put_contents($fileB, 'changed-b');

    chmod($fileB, 0444);

    try {
        $journal->restore();
        expect(false)->toBeTrue('restore() was expected to throw.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain($fileB);
    } finally {
        chmod($fileB, 0644);
    }
});

it('clears its own entries after restore(), so a second call is a no-op', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir, 0777, true);

    $path = $dir.'/a.txt';
    file_put_contents($path, 'original');

    $journal = new ExtractJournal(new Filesystem());
    $journal->recordWrite($path);
    file_put_contents($path, 'changed');

    $journal->restore();
    expect(file_get_contents($path))->toBe('original');

    // A second restore() must not re-apply anything (there is nothing left
    // to undo) -- proven by changing the file again and confirming a
    // second restore() call leaves it untouched.
    file_put_contents($path, 'changed-again');
    $journal->restore();

    expect(file_get_contents($path))->toBe('changed-again');
});
