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
        if (is_link($path)) {
            unlink($path);

            return;
        }

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

it('restores a created directory symlink without deleting the source it points at', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir.'/package-source', 0777, true);
    file_put_contents($dir.'/package-source/Thing.php', 'source bytes');

    $link = $dir.'/vendor-package';
    $journal = new ExtractJournal(new Filesystem());
    $journal->recordCreate($link);
    symlink($dir.'/package-source', $link);

    $journal->restore();

    expect(is_link($link))->toBeFalse();
    expect(file_get_contents($dir.'/package-source/Thing.php'))->toBe('source bytes');
});

it('restores an entire directory snapshot including overwritten deleted and newly created files', function () {
    $dir = journalFixtureDirectory();
    $state = $dir.'/vendor/composer';
    mkdir($state.'/nested', 0777, true);
    file_put_contents($state.'/autoload.php', 'original autoload');
    file_put_contents($state.'/nested/installed.json', 'original installed');

    $journal = new ExtractJournal(new Filesystem());
    $journal->recordTree($state);

    file_put_contents($state.'/autoload.php', 'changed autoload');
    unlink($state.'/nested/installed.json');
    file_put_contents($state.'/new.php', 'new file');

    $journal->restore();

    expect(file_get_contents($state.'/autoload.php'))->toBe('original autoload');
    expect(file_get_contents($state.'/nested/installed.json'))->toBe('original installed');
    expect($state.'/new.php')->not->toBeFile();
});

it('restores file and directory modes from a tree snapshot', function () {
    $dir = journalFixtureDirectory();
    $state = $dir.'/vendor';
    mkdir($state.'/bin', 0777, true);
    file_put_contents($state.'/bin/tool', '#!/bin/sh');
    chmod($state, 0711);
    chmod($state.'/bin', 0750);
    chmod($state.'/bin/tool', 0755);

    $journal = new ExtractJournal(new Filesystem());
    $journal->recordTree($state);

    chmod($state, 0755);
    chmod($state.'/bin', 0755);
    chmod($state.'/bin/tool', 0644);

    $journal->restore();

    clearstatcache(true);
    expect(fileperms($state) & 07777)->toBe(0711)
        ->and(fileperms($state.'/bin') & 07777)->toBe(0750)
        ->and(fileperms($state.'/bin/tool') & 07777)->toBe(0755);
});

it('keeps large snapshot bytes off the PHP heap', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir, 0777, true);
    $path = $dir.'/large.bin';
    file_put_contents($path, str_repeat('x', 16 * 1024 * 1024));

    gc_collect_cycles();
    $before = memory_get_usage(true);
    $journal = new ExtractJournal(new Filesystem());
    $journal->recordTree($path);
    $growth = memory_get_usage(true) - $before;

    $journal->discard();

    expect($growth)->toBeLessThan(8 * 1024 * 1024);
});

it('removes disk-backed snapshot storage explicitly on discard', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/state.txt', 'state');
    $before = glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [];

    $journal = new ExtractJournal(new Filesystem());
    $journal->recordTree($dir.'/state.txt');
    $during = array_values(array_diff(
        glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [],
        $before,
    ));

    expect($during)->toHaveCount(1);

    $journal->discard();

    expect($during[0])->not->toBeDirectory();
});

it('refuses before copying when rollback storage is inside the tree being snapshotted', function () {
    $dir = journalFixtureDirectory();
    $state = $dir.'/vendor';
    $nestedTemp = $state.'/tmp';
    mkdir($nestedTemp, 0777, true);
    file_put_contents($state.'/original.txt', 'original bytes');

    $probe = <<<'PHP'
    require $argv[1];

    $journal = new \Nodeflow\Console\Extract\ExtractJournal(new \Illuminate\Filesystem\Filesystem());

    try {
        $journal->recordTree($argv[2]);
        fwrite(STDERR, 'snapshot unexpectedly succeeded');
        exit(3);
    } catch (\RuntimeException $e) {
        echo $e->getMessage();
    }
    PHP;
    $environment = getenv();
    $environment['TMPDIR'] = $nestedTemp;
    $process = proc_open(
        [PHP_BINARY, '-r', $probe, '--', dirname(__DIR__, 2).'/vendor/autoload.php', $state],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $dir,
        $environment,
        ['bypass_shell' => true],
    );

    expect($process)->toBeResource();
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0)
        ->and($stdout)->toContain('overlaps the tree being snapshotted')
        ->and($stderr)->toBe('')
        ->and(file_get_contents($state.'/original.txt'))->toBe('original bytes')
        ->and(glob($nestedTemp.'/nodeflow-extract-snapshot-*') ?: [])->toBe([]);
});

it('commits entries before discard cleanup so a partial cleanup can never roll back from damaged snapshots', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir, 0777, true);
    $state = $dir.'/state.txt';
    file_put_contents($state, 'committed state');

    $files = new class extends Filesystem
    {
        public bool $failSnapshotCleanup = true;

        public function deleteDirectory($directory, $preserve = false)
        {
            if ($this->failSnapshotCleanup && str_contains(basename($directory), 'nodeflow-extract-snapshot-')) {
                foreach (scandir($directory) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        parent::deleteDirectory($directory.'/'.$entry);
                        break;
                    }
                }

                return false;
            }

            return parent::deleteDirectory($directory, $preserve);
        }
    };
    $journal = new ExtractJournal($files);
    $journal->recordTree($state);

    expect(fn () => $journal->discard())
        ->toThrow(RuntimeException::class, 'could not be removed');

    file_put_contents($state, 'changed after commit');
    $files->failSnapshotCleanup = false;
    $journal->restore();

    expect(file_get_contents($state))->toBe('changed after commit');
});

it('distinguishes cleanup-only restore failure and never replays restored entries on cleanup retry', function () {
    $dir = journalFixtureDirectory();
    $state = $dir.'/vendor';
    mkdir($state, 0777, true);
    file_put_contents($state.'/original.txt', 'original state');

    $files = new class extends Filesystem
    {
        public bool $failSnapshotCleanup = true;

        public function deleteDirectory($directory, $preserve = false)
        {
            if ($this->failSnapshotCleanup && str_contains(basename($directory), 'nodeflow-extract-snapshot-')) {
                return false;
            }

            return parent::deleteDirectory($directory, $preserve);
        }
    };
    $journal = new ExtractJournal($files);
    $journal->recordTree($state);
    file_put_contents($state.'/original.txt', 'forward partial state');

    try {
        $journal->restore();
        $failure = null;
    } catch (RuntimeException $e) {
        $failure = $e;
    }

    expect($failure)->toBeInstanceOf(RuntimeException::class)
        ->and($failure?->getCode())->toBe(ExtractJournal::RESTORE_CLEANUP_FAILED)
        ->and($failure?->getMessage())->toContain('restored the host but could not remove rollback storage')
        ->and(file_get_contents($state.'/original.txt'))->toBe('original state');

    file_put_contents($state.'/original.txt', 'changed after completed restore');
    $files->failSnapshotCleanup = false;
    $journal->restore();

    expect(file_get_contents($state.'/original.txt'))->toBe('changed after completed restore');
});

it('refuses to snapshot a directory it cannot enumerate', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir.'/unreadable', 0777, true);
    file_put_contents($dir.'/unreadable/state.txt', 'state');
    chmod($dir.'/unreadable', 0111);

    try {
        $journal = new ExtractJournal(new Filesystem());

        expect(fn () => $journal->recordTree($dir.'/unreadable'))
            ->toThrow(RuntimeException::class, 'could not be enumerated');
    } finally {
        chmod($dir.'/unreadable', 0755);
    }
});

it('reports a created path that survives a failed deletion during restore', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir, 0777, true);
    $created = $dir.'/created';

    $files = new class extends Filesystem
    {
        public function deleteDirectory($directory, $preserve = false)
        {
            return false;
        }
    };

    $journal = new ExtractJournal($files);
    $journal->recordTree($created);
    mkdir($created, 0777, true);
    file_put_contents($created.'/survivor.txt', 'still here');

    expect(fn () => $journal->restore())
        ->toThrow(RuntimeException::class, $created);
    expect($created.'/survivor.txt')->toBeFile();
});

it('retains disk snapshots and retryable entries after a failed restore, then cleans them after retry succeeds', function () {
    $dir = journalFixtureDirectory();
    $state = $dir.'/vendor';
    mkdir($state, 0777, true);
    file_put_contents($state.'/original.txt', 'original');
    $snapshotsBefore = glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [];

    $files = new class extends Filesystem
    {
        public ?string $blockedDirectory = null;

        public function deleteDirectory($directory, $preserve = false)
        {
            if ($directory === $this->blockedDirectory) {
                return false;
            }

            return parent::deleteDirectory($directory, $preserve);
        }
    };
    $journal = new ExtractJournal($files);
    $journal->recordTree($state);
    file_put_contents($state.'/original.txt', 'changed');
    file_put_contents($state.'/partial.txt', 'partial');
    $files->blockedDirectory = $state;

    try {
        $journal->restore();
        expect(false)->toBeTrue('The first restore was expected to fail.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('rollback snapshots retained at');
        preg_match('/rollback snapshots retained at \[([^]]+)\]/', $e->getMessage(), $match);
        $recoveryPath = $match[1] ?? null;
        expect($recoveryPath)->toBeString();
        expect($recoveryPath)->toBeDirectory();
    }

    $files->blockedDirectory = null;
    $journal->restore();

    expect(file_get_contents($state.'/original.txt'))->toBe('original');
    expect($state.'/partial.txt')->not->toBeFile();
    expect($recoveryPath)->not->toBeDirectory();
    expect(glob(sys_get_temp_dir().'/nodeflow-extract-snapshot-*') ?: [])->toBe($snapshotsBefore);
});

it('restores a snapshotted directory symlink as the same link without copying or changing its source', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir.'/package-source', 0777, true);
    mkdir($dir.'/other-source', 0777, true);
    file_put_contents($dir.'/package-source/Thing.php', 'original source');
    file_put_contents($dir.'/other-source/Other.php', 'other source');

    $link = $dir.'/vendor-package';
    symlink('package-source', $link);

    $journal = new ExtractJournal(new Filesystem());
    $journal->recordTree($link);

    unlink($link);
    symlink('other-source', $link);

    $journal->restore();

    expect(is_link($link))->toBeTrue();
    expect(readlink($link))->toBe('package-source');
    expect(file_get_contents($dir.'/package-source/Thing.php'))->toBe('original source');
    expect(file_get_contents($dir.'/other-source/Other.php'))->toBe('other source');
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

it('restores the exact mode of a deleted file', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir, 0777, true);

    $path = $dir.'/executable-node.php';
    file_put_contents($path, '#!/usr/bin/env php');
    chmod($path, 0755);

    $journal = new ExtractJournal(new Filesystem());
    $journal->recordDelete($path, file_get_contents($path));
    unlink($path);
    $journal->restore();

    clearstatcache(true, $path);
    expect(file_get_contents($path))->toBe('#!/usr/bin/env php')
        ->and(fileperms($path) & 07777)->toBe(0755);
});

it('reports a failed delete restoration write instead of claiming success over missing bytes', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir, 0777, true);
    $path = $dir.'/deleted.txt';
    file_put_contents($path, 'original bytes');

    $files = new class extends Filesystem
    {
        public function put($path, $contents, $lock = false)
        {
            return false;
        }
    };
    $journal = new ExtractJournal($files);
    $journal->recordDelete($path, file_get_contents($path));
    unlink($path);

    expect(fn () => $journal->restore())
        ->toThrow(RuntimeException::class, $path);
    expect($path)->not->toBeFile();
});

it('reports a partial delete restoration write instead of claiming exact bytes', function () {
    $dir = journalFixtureDirectory();
    mkdir($dir, 0777, true);
    $path = $dir.'/deleted.txt';
    file_put_contents($path, 'original bytes');

    $files = new class extends Filesystem
    {
        public function put($path, $contents, $lock = false)
        {
            return file_put_contents($path, substr($contents, 0, 4));
        }
    };
    $journal = new ExtractJournal($files);
    $journal->recordDelete($path, file_get_contents($path));
    unlink($path);

    expect(fn () => $journal->restore())
        ->toThrow(RuntimeException::class, $path);
    expect(file_get_contents($path))->toBe('orig');
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
