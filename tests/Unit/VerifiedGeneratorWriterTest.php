<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\VerifiedGeneratorWriter;

it('restores every original through verified atomic replacements when a generated write fails', function () {
    $root = sys_get_temp_dir().'/nodeflow-verified-writer-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    $first = $root.'/First.php';
    $second = $root.'/Second.php';
    $firstOriginal = '<?php final class FirstOriginal {}';
    $secondOriginal = '<?php final class SecondOriginal {}';
    file_put_contents($first, $firstOriginal);
    file_put_contents($second, $secondOriginal);

    $files = new class extends Filesystem
    {
        private int $stagedWrites = 0;

        public function put($path, $contents, $lock = false)
        {
            if (str_contains($path, '.nodeflow-tmp-')) {
                $this->stagedWrites++;
                if ($this->stagedWrites === 2) {
                    parent::put($path, substr($contents, 0, -1), $lock);

                    return strlen($contents) - 1;
                }
            }

            return parent::put($path, $contents, $lock);
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $first => '<?php final class FirstGenerated {}',
        $second => '<?php final class SecondGenerated {}',
    ], true))->toThrow(InvalidArgumentException::class)
        ->and(file_get_contents($first))->toBe($firstOriginal)
        ->and(file_get_contents($second))->toBe($secondOriginal);

    foreach (glob($root.'/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($root);
});

it('does not replace snapshotted targets whose generated write was never attempted', function () {
    $root = sys_get_temp_dir().'/nodeflow-verified-writer-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    $first = $root.'/First.php';
    $second = $root.'/Second.php';
    file_put_contents($first, '<?php final class FirstOriginal {}');
    file_put_contents($second, '<?php final class SecondOriginal {}');

    $files = new class($first) extends Filesystem
    {
        /** @var string[] */
        public array $movedTargets = [];

        public function __construct(private string $first) {}

        public function move($path, $target)
        {
            $this->movedTargets[] = $target;

            if ($target === $this->first) {
                return false;
            }

            return parent::move($path, $target);
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $first => '<?php final class FirstGenerated {}',
        $second => '<?php final class SecondGenerated {}',
    ], true))->toThrow(InvalidArgumentException::class)
        ->and($files->movedTargets)->toBe([$first]);

    foreach (glob($root.'/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($root);
});

it('rejects symlink targets and symlink ancestors without touching their outside destination', function (bool $ancestor) {
    if (DIRECTORY_SEPARATOR === '\\') {
        $this->markTestSkipped('Unix symlink semantics are not portable to Windows.');
    }

    $root = sys_get_temp_dir().'/nodeflow-safe-root-'.bin2hex(random_bytes(6));
    $outside = sys_get_temp_dir().'/nodeflow-safe-outside-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    mkdir($outside, 0777, true);
    $outsideFile = $outside.'/Outside.php';
    file_put_contents($outsideFile, '<?php final class OutsideOriginal {}');
    $target = $root.'/Target.php';

    if ($ancestor) {
        symlink($outside, $root.'/linked');
        $target = $root.'/linked/Target.php';
    } else {
        symlink($outsideFile, $target);
    }

    expect(fn () => (new VerifiedGeneratorWriter(new Filesystem, [$root]))->write([
        $target => '<?php final class Generated {}',
    ], true))->toThrow(InvalidArgumentException::class)
        ->and(file_get_contents($outsideFile))->toBe('<?php final class OutsideOriginal {}')
        ->and(file_exists($outside.'/Target.php'))->toBeFalse()
        ->and($ancestor || is_link($target))->toBeTrue();

    if (is_link($root.'/linked')) unlink($root.'/linked');
    if (is_link($root.'/Target.php')) unlink($root.'/Target.php');
    unlink($outsideFile);
    rmdir($outside);
    rmdir($root);
})->with(['symlink target' => [false], 'symlink ancestor' => [true]]);

it('never overwrites or deletes a target raced in after non-force preflight', function () {
    $root = sys_get_temp_dir().'/nodeflow-race-root-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    $target = $root.'/Raced.php';
    $files = new class($target) extends Filesystem
    {
        private bool $raced = false;

        public function __construct(private string $target) {}

        public function put($path, $contents, $lock = false)
        {
            $written = parent::put($path, $contents, $lock);
            if (! $this->raced && str_contains($path, '.nodeflow-tmp-')) {
                $this->raced = true;
                file_put_contents($this->target, '<?php final class ExternalRace {}');
            }

            return $written;
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $target => '<?php final class Generated {}',
    ]))->toThrow(InvalidArgumentException::class)
        ->and(file_get_contents($target))->toBe('<?php final class ExternalRace {}')
        ->and(glob($root.'/*.nodeflow-tmp-*') ?: [])->toBe([]);

    unlink($target);
    rmdir($root);
});

it('aborts force replacement when the target identity changes after snapshot', function () {
    $root = sys_get_temp_dir().'/nodeflow-race-root-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    $target = $root.'/Changed.php';
    file_put_contents($target, '<?php final class Original {}');
    $files = new class($target) extends Filesystem
    {
        private bool $changed = false;

        public function __construct(private string $target) {}

        public function put($path, $contents, $lock = false)
        {
            $written = parent::put($path, $contents, $lock);
            if (! $this->changed && str_contains($path, '.nodeflow-tmp-')) {
                $this->changed = true;
                unlink($this->target);
                file_put_contents($this->target, '<?php final class ExternalReplacement {}');
            }

            return $written;
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $target => '<?php final class Generated {}',
    ], true))->toThrow(InvalidArgumentException::class)
        ->and(file_get_contents($target))->toBe('<?php final class ExternalReplacement {}');

    unlink($target);
    rmdir($root);
});

it('aborts force replacement when target metadata changes after snapshot', function () {
    if (DIRECTORY_SEPARATOR === '\\') $this->markTestSkipped('Unix modes are not portable to Windows.');

    $root = sys_get_temp_dir().'/nodeflow-mode-race-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    $target = $root.'/ChangedMode.php';
    file_put_contents($target, '<?php final class OriginalMode {}');
    chmod($target, 0600);
    $files = new class($target) extends Filesystem
    {
        private bool $changed = false;

        public function __construct(private string $target) {}

        public function put($path, $contents, $lock = false)
        {
            $written = parent::put($path, $contents, $lock);
            if (! $this->changed && str_contains($path, '.nodeflow-tmp-')) {
                $this->changed = true;
                chmod($this->target, 0640);
            }

            return $written;
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $target => '<?php final class GeneratedMode {}',
    ], true))->toThrow(InvalidArgumentException::class)
        ->and(file_get_contents($target))->toBe('<?php final class OriginalMode {}')
        ->and(fileperms($target) & 0777)->toBe(0640);

    unlink($target);
    rmdir($root);
});

it('restores an existing target that disappears immediately after atomic rename', function () {
    $root = sys_get_temp_dir().'/nodeflow-post-rename-disappear-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    $target = $root.'/Target.php';
    $original = '<?php final class Original {}';
    file_put_contents($target, $original);
    $files = new class extends Filesystem
    {
        private bool $intercept = true;

        public function move($path, $target)
        {
            $moved = parent::move($path, $target);
            if ($this->intercept) {
                $this->intercept = false;
                unlink($target);
            }

            return $moved;
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $target => '<?php final class Generated {}',
    ], true))->toThrow(InvalidArgumentException::class)
        ->and(file_get_contents($target))->toBe($original)
        ->and(glob($root.'/*.nodeflow-tmp-*') ?: [])->toBe([]);

    unlink($target);
    rmdir($root);
});

it('never applies original metadata to an external inode installed immediately after rename', function () {
    if (DIRECTORY_SEPARATOR === '\\') $this->markTestSkipped('Unix modes are not portable to Windows.');

    $root = sys_get_temp_dir().'/nodeflow-post-rename-replace-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    $target = $root.'/Target.php';
    file_put_contents($target, '<?php final class Original {}');
    chmod($target, 0600);
    $files = new class extends Filesystem
    {
        public function move($path, $target)
        {
            $moved = parent::move($path, $target);
            unlink($target);
            file_put_contents($target, '<?php final class ExternalReplacement {}');
            chmod($target, 0644);

            return $moved;
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $target => '<?php final class Generated {}',
    ], true))->toThrow(InvalidArgumentException::class, 'manual recovery')
        ->and(file_get_contents($target))->toBe('<?php final class ExternalReplacement {}')
        ->and(fileperms($target) & 0777)->toBe(0644)
        ->and(glob($root.'/*.nodeflow-tmp-*') ?: [])->toBe([]);

    unlink($target);
    rmdir($root);
});

it('preserves an existing files mode on successful force and rollback', function (bool $failSecond) {
    if (DIRECTORY_SEPARATOR === '\\') {
        $this->markTestSkipped('Unix modes are not portable to Windows.');
    }

    $root = sys_get_temp_dir().'/nodeflow-mode-root-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    $first = $root.'/First.php';
    $second = $root.'/Second.php';
    file_put_contents($first, '<?php final class Original {}');
    chmod($first, 0600);
    $files = new class($failSecond) extends Filesystem
    {
        private int $moves = 0;

        public function __construct(private bool $failSecond) {}

        public function move($path, $target)
        {
            $this->moves++;
            if ($this->failSecond && $this->moves === 2) return false;

            return parent::move($path, $target);
        }
    };
    $writes = [$first => '<?php final class Generated {}'];
    if ($failSecond) $writes[$second] = '<?php final class SecondGenerated {}';

    $operation = fn () => (new VerifiedGeneratorWriter($files, [$root]))->write($writes, true);
    if ($failSecond) {
        try {
            $operation();
            $this->fail('The injected second rename failure should abort the kit.');
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->not->toContain('manual recovery');
        }
        expect(file_get_contents($first))->toBe('<?php final class Original {}');
    } else {
        $operation();
        expect(file_get_contents($first))->toBe('<?php final class Generated {}');
    }
    expect(fileperms($first) & 0777)->toBe(0600);

    unlink($first);
    if (file_exists($second)) unlink($second);
    rmdir($root);
})->with(['successful force' => [false], 'rollback after later failure' => [true]]);

it('reports rollback failure without overwriting a concurrently changed committed target or leaving temps', function () {
    $root = sys_get_temp_dir().'/nodeflow-rollback-race-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    $first = $root.'/First.php';
    $second = $root.'/Second.php';
    file_put_contents($first, '<?php final class Original {}');
    $files = new class($first) extends Filesystem
    {
        private int $moves = 0;

        public function __construct(private string $first) {}

        public function move($path, $target)
        {
            $this->moves++;
            if ($this->moves === 1) {
                $moved = parent::move($path, $target);
                file_put_contents($this->first, '<?php final class ExternalAfterCommit {}');

                return $moved;
            }
            if ($this->moves === 2) return false;

            return parent::move($path, $target);
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $first => '<?php final class Generated {}',
        $second => '<?php final class SecondGenerated {}',
    ], true))->toThrow(InvalidArgumentException::class, 'manual recovery')
        ->and(file_get_contents($first))->toBe('<?php final class ExternalAfterCommit {}')
        ->and(glob($root.'/*.nodeflow-tmp-*') ?: [])->toBe([]);

    unlink($first);
    rmdir($root);
});
