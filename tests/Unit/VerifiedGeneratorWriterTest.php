<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\AtomicFileWriter;
use Nodeflow\Console\VerifiedGeneratorWriter;

it('reports malformed generated PHP at the intended boundary without leaking contents or paths', function () {
    $root = sys_get_temp_dir().'/nodeflow-parse-boundary-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    $target = $root.'/SecretNamedTarget.php';

    expect(fn () => (new VerifiedGeneratorWriter(new Filesystem, [$root]))->write([
        $target => '<?php TOP_SECRET malformed {',
    ]))->toThrow(InvalidArgumentException::class, 'generator stub did not produce parseable PHP');

    try {
        (new VerifiedGeneratorWriter(new Filesystem, [$root]))->write([$target => '<?php TOP_SECRET malformed {']);
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->not->toContain('TOP_SECRET')->not->toContain($target);
    }
    expect($target)->not->toBeFile();
    rmdir($root);
});

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

it('detects an ancestor swapped to a symlink during rename and removes only its installed inode', function () {
    if (DIRECTORY_SEPARATOR === '\\') $this->markTestSkipped('Unix symlink semantics are not portable to Windows.');

    $root = sys_get_temp_dir().'/nodeflow-ancestor-swap-'.bin2hex(random_bytes(6));
    $outside = sys_get_temp_dir().'/nodeflow-ancestor-outside-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $root.'/nested-parked';
    mkdir($parent, 0777, true);
    mkdir($outside, 0777, true);
    $target = $parent.'/Target.php';
    $files = new class($parent, $parked, $outside) extends Filesystem
    {
        public function __construct(private string $parent, private string $parked, private string $outside) {}

        public function move($path, $target)
        {
            rename($this->parent, $this->parked);
            symlink($this->outside, $this->parent);

            return rename($this->parked.'/'.basename($path), $target);
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $target => '<?php final class Generated {}',
    ]))->toThrow(InvalidArgumentException::class, 'manual recovery')
        ->and(file_get_contents($outside.'/Target.php'))->toBe('<?php final class Generated {}')
        ->and(glob($outside.'/*.nodeflow-tmp-*') ?: [])->toBe([]);

    is_link($parent) ? unlink($parent) : rmdir($parent);
    unlink($outside.'/Target.php');
    rmdir($parked);
    rmdir($outside);
    rmdir($root);
});

it('never deletes a staged temporary through a swapped symlink ancestor', function () {
    if (DIRECTORY_SEPARATOR === '\\') {
        $this->markTestSkipped('Unix symlink semantics are not portable to Windows.');
    }

    $root = sys_get_temp_dir().'/nodeflow-staged-symlink-'.bin2hex(random_bytes(6));
    $outside = sys_get_temp_dir().'/nodeflow-staged-symlink-outside-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $outside.'/nested-parked';
    mkdir($parent, 0777, true);
    mkdir($outside, 0777, true);
    $first = $parent.'/First.php';
    $second = $root.'/Second.php';
    $files = new class($parent, $parked) extends Filesystem
    {
        private int $temporaryWrites = 0;

        public function __construct(private string $parent, private string $parked) {}

        public function put($path, $contents, $lock = false)
        {
            $written = parent::put($path, $contents, $lock);
            if (str_contains($path, '.nodeflow-tmp-') && ++$this->temporaryWrites === 2) {
                rename($this->parent, $this->parked);
                symlink($this->parked, $this->parent);
                throw new RuntimeException('staging failed after ancestor swap');
            }

            return $written;
        }
    };

    expect(fn () => (new AtomicFileWriter($files))->write(
        [
            $first => '<?php final class FirstGenerated {}',
            $second => '<?php final class SecondGenerated {}',
        ],
        [$root],
        false,
        static function (): void {},
    ))->toThrow(InvalidArgumentException::class, 'manual recovery')
        ->and(count(glob($parked.'/*.nodeflow-tmp-*') ?: []))->toBe(1)
        ->and($first)->not->toBeFile();

    unlink($parent);
    foreach (glob($parked.'/*') ?: [] as $path) unlink($path);
    rmdir($parked);
    rmdir($outside);
    rmdir($root);
});

it('revalidates root confinement immediately before ordinary rollback deletion', function () {
    if (DIRECTORY_SEPARATOR === '\\') {
        $this->markTestSkipped('Unix symlink semantics are not portable to Windows.');
    }

    $root = sys_get_temp_dir().'/nodeflow-rollback-delete-symlink-'.bin2hex(random_bytes(6));
    $outside = sys_get_temp_dir().'/nodeflow-rollback-delete-symlink-outside-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $root.'/nested-parked';
    mkdir($parent, 0777, true);
    mkdir($outside, 0777, true);
    $first = $parent.'/First.php';
    $second = $root.'/Second.php';
    $files = new class($first, $parent, $parked, $outside) extends Filesystem
    {
        private int $moves = 0;
        private bool $rollingBack = false;
        private bool $swapped = false;

        public function __construct(
            private string $first,
            private string $parent,
            private string $parked,
            private string $outside,
        ) {}

        public function move($path, $target)
        {
            if (++$this->moves === 2) {
                $this->rollingBack = true;

                return false;
            }

            return parent::move($path, $target);
        }

        public function get($path, $lock = false)
        {
            $contents = parent::get($path, $lock);
            if ($this->rollingBack && ! $this->swapped && $path === $this->first) {
                $this->swapped = true;
                rename($this->parent, $this->parked);
                rename($this->parked.'/First.php', $this->outside.'/First.php');
                symlink($this->outside, $this->parent);
            }

            return $contents;
        }
    };

    expect(fn () => (new AtomicFileWriter($files))->write(
        [
            $first => '<?php final class FirstGenerated {}',
            $second => '<?php final class SecondGenerated {}',
        ],
        [$root],
        false,
        static function (): void {},
    ))->toThrow(InvalidArgumentException::class, 'manual recovery')
        ->and(file_get_contents($outside.'/First.php'))->toBe('<?php final class FirstGenerated {}');

    unlink($parent);
    unlink($outside.'/First.php');
    rmdir($parked);
    rmdir($outside);
    rmdir($root);
});

it('detects a replaced parent after rename without deleting an external target', function () {
    $root = sys_get_temp_dir().'/nodeflow-parent-replace-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $root.'/nested-parked';
    mkdir($parent, 0777, true);
    $target = $parent.'/Target.php';
    $external = '<?php final class ExternalReplacement {}';
    $files = new class($parent, $parked, $external) extends Filesystem
    {
        public function __construct(private string $parent, private string $parked, private string $external) {}

        public function move($path, $target)
        {
            $moved = parent::move($path, $target);
            rename($this->parent, $this->parked);
            mkdir($this->parent);
            file_put_contents($target, $this->external);

            return $moved;
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $target => '<?php final class Generated {}',
    ]))->toThrow(InvalidArgumentException::class)
        ->and(file_get_contents($target))->toBe($external)
        ->and($parked.'/Target.php')->not->toBeFile();

    unlink($target);
    rmdir($parent);
    rmdir($parked);
    rmdir($root);
});

it('detects a parent that disappears after rename and cleans the stranded operation inode', function () {
    $root = sys_get_temp_dir().'/nodeflow-parent-disappear-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $root.'/nested-parked';
    mkdir($parent, 0777, true);
    $target = $parent.'/Target.php';
    $files = new class($parent, $parked) extends Filesystem
    {
        public function __construct(private string $parent, private string $parked) {}

        public function move($path, $target)
        {
            $moved = parent::move($path, $target);
            rename($this->parent, $this->parked);

            return $moved;
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $target => '<?php final class Generated {}',
    ]))->toThrow(InvalidArgumentException::class)
        ->and($target)->not->toBeFile()
        ->and($parked.'/Target.php')->not->toBeFile()
        ->and(glob($parked.'/*.nodeflow-tmp-*') ?: [])->toBe([]);

    rmdir($parked);
    rmdir($root);
});

it('revalidates each parent before a multi-file commit and rolls back earlier files', function () {
    $root = sys_get_temp_dir().'/nodeflow-multi-parent-swap-'.bin2hex(random_bytes(6));
    $firstParent = $root.'/first';
    $secondParent = $root.'/second';
    $parked = $root.'/second-parked';
    mkdir($firstParent, 0777, true);
    mkdir($secondParent, 0777, true);
    $first = $firstParent.'/First.php';
    $second = $secondParent.'/Second.php';
    $external = $secondParent.'/External.php';
    $files = new class($secondParent, $parked, $external) extends Filesystem
    {
        private int $moves = 0;
        public function __construct(private string $secondParent, private string $parked, private string $external) {}
        public function move($path, $target)
        {
            $moved = parent::move($path, $target);
            if (++$this->moves === 1) {
                rename($this->secondParent, $this->parked);
                mkdir($this->secondParent);
                file_put_contents($this->external, 'external');
            }

            return $moved;
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $first => '<?php final class FirstGenerated {}',
        $second => '<?php final class SecondGenerated {}',
    ]))->toThrow(InvalidArgumentException::class)
        ->and($first)->not->toBeFile()
        ->and($second)->not->toBeFile()
        ->and(file_get_contents($external))->toBe('external')
        ->and(glob($parked.'/*.nodeflow-tmp-*') ?: [])->toBe([]);

    unlink($external);
    rmdir($firstParent);
    rmdir($secondParent);
    rmdir($parked);
    rmdir($root);
});

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

it('cleans a rollback restoration temp when its move throws before installing it', function () {
    $root = sys_get_temp_dir().'/nodeflow-rollback-temp-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);
    $first = $root.'/First.php';
    $second = $root.'/Second.php';
    file_put_contents($first, '<?php final class Original {}');
    $files = new class extends Filesystem
    {
        private int $moves = 0;
        public function move($path, $target)
        {
            $this->moves++;
            if ($this->moves === 2) return false;
            if ($this->moves === 3) throw new RuntimeException('rollback move failed before rename');

            return parent::move($path, $target);
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $first => '<?php final class Generated {}',
        $second => '<?php final class SecondGenerated {}',
    ], true))->toThrow(InvalidArgumentException::class, 'manual recovery')
        ->and(glob($root.'/*.nodeflow-tmp-*') ?: [])->toBe([])
        ->and($second)->not->toBeFile();

    unlink($first);
    rmdir($root);
});

it('finds and cleans a rollback temp parked by a replaced or symlinked parent', function (string $mode) {
    if ($mode === 'symlink' && DIRECTORY_SEPARATOR === '\\') {
        $this->markTestSkipped('Unix symlink semantics are not portable to Windows.');
    }

    $root = sys_get_temp_dir().'/nodeflow-rollback-parent-'.bin2hex(random_bytes(6));
    $outside = sys_get_temp_dir().'/nodeflow-rollback-parent-outside-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $root.'/nested-parked';
    mkdir($parent, 0777, true);
    mkdir($outside, 0777, true);
    $first = $parent.'/First.php';
    $second = $root.'/Second.php';
    $external = '<?php final class External {}';
    file_put_contents($first, '<?php final class Original {}');
    $files = new class($parent, $parked, $outside, $external, $mode) extends Filesystem
    {
        private int $moves = 0;
        public function __construct(
            private string $parent,
            private string $parked,
            private string $outside,
            private string $external,
            private string $mode,
        ) {}
        public function move($path, $target)
        {
            $this->moves++;
            if ($this->moves === 2) return false;
            if ($this->moves === 3) {
                rename($this->parent, $this->parked);
                if ($this->mode === 'symlink') {
                    file_put_contents($this->outside.'/First.php', $this->external);
                    symlink($this->outside, $this->parent);
                } else {
                    mkdir($this->parent);
                    file_put_contents($this->parent.'/First.php', $this->external);
                }

                return false;
            }

            return parent::move($path, $target);
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $first => '<?php final class Generated {}',
        $second => '<?php final class SecondGenerated {}',
    ], true))->toThrow(InvalidArgumentException::class, 'manual recovery')
        ->and(glob($parked.'/*.nodeflow-tmp-*') ?: [])->toBe([])
        ->and(file_get_contents($mode === 'symlink' ? $outside.'/First.php' : $parent.'/First.php'))->toBe($external);

    if ($mode === 'symlink') {
        unlink($parent);
    } else {
        unlink($parent.'/First.php');
        rmdir($parent);
    }
    if (file_exists($outside.'/First.php')) unlink($outside.'/First.php');
    foreach (glob($parked.'/*') ?: [] as $path) unlink($path);
    rmdir($parked);
    rmdir($outside);
    rmdir($root);
})->with(['replaced parent' => ['replace'], 'symlinked parent' => ['symlink']]);

it('never deletes an original restored before the rollback move throws and its parent is replaced', function () {
    $root = sys_get_temp_dir().'/nodeflow-restored-parent-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $root.'/nested-parked';
    mkdir($parent, 0777, true);
    $first = $parent.'/First.php';
    $second = $root.'/Second.php';
    $original = '<?php final class Original {}';
    $external = '<?php final class External {}';
    file_put_contents($first, $original);
    $files = new class($parent, $parked, $external) extends Filesystem
    {
        private int $moves = 0;
        public function __construct(private string $parent, private string $parked, private string $external) {}
        public function move($path, $target)
        {
            $this->moves++;
            if ($this->moves === 2) return false;
            if ($this->moves === 3) {
                parent::move($path, $target);
                rename($this->parent, $this->parked);
                mkdir($this->parent);
                file_put_contents($this->parent.'/First.php', $this->external);
                throw new RuntimeException('rollback move threw after rename');
            }

            return parent::move($path, $target);
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $first => '<?php final class Generated {}',
        $second => '<?php final class SecondGenerated {}',
    ], true))->toThrow(InvalidArgumentException::class, 'manual recovery')
        ->and(file_get_contents($parked.'/First.php'))->toBe($original)
        ->and(file_get_contents($first))->toBe($external)
        ->and(glob($parked.'/*.nodeflow-tmp-*') ?: [])->toBe([]);

    unlink($first);
    unlink($parked.'/First.php');
    rmdir($parent);
    rmdir($parked);
    rmdir($root);
});

it('reports manual recovery without traversing outside roots when a rollback temp is parked outside', function () {
    $root = sys_get_temp_dir().'/nodeflow-rollback-outside-root-'.bin2hex(random_bytes(6));
    $outside = sys_get_temp_dir().'/nodeflow-rollback-outside-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $outside.'/nested-parked';
    mkdir($parent, 0777, true);
    mkdir($outside, 0777, true);
    $first = $parent.'/First.php';
    $second = $root.'/Second.php';
    $external = '<?php final class External {}';
    file_put_contents($first, '<?php final class Original {}');
    $files = new class($parent, $parked, $external) extends Filesystem
    {
        private int $moves = 0;
        public function __construct(private string $parent, private string $parked, private string $external) {}
        public function move($path, $target)
        {
            $this->moves++;
            if ($this->moves === 2) return false;
            if ($this->moves === 3) {
                rename($this->parent, $this->parked);
                mkdir($this->parent);
                file_put_contents($this->parent.'/First.php', $this->external);

                return false;
            }

            return parent::move($path, $target);
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $first => '<?php final class Generated {}',
        $second => '<?php final class SecondGenerated {}',
    ], true))->toThrow(InvalidArgumentException::class, 'manual recovery')
        ->and(file_get_contents($first))->toBe($external)
        ->and(count(glob($parked.'/*.nodeflow-tmp-*') ?: []))->toBe(1);

    unlink($first);
    foreach (glob($parked.'/*') ?: [] as $path) unlink($path);
    rmdir($parent);
    rmdir($parked);
    rmdir($outside);
    rmdir($root);
});

it('handles a rollback temp that disappears after its parent is parked', function () {
    $root = sys_get_temp_dir().'/nodeflow-rollback-disappear-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $root.'/nested-parked';
    mkdir($parent, 0777, true);
    $first = $parent.'/First.php';
    $second = $root.'/Second.php';
    $external = '<?php final class External {}';
    file_put_contents($first, '<?php final class Original {}');
    $files = new class($parent, $parked, $external) extends Filesystem
    {
        private int $moves = 0;
        public function __construct(private string $parent, private string $parked, private string $external) {}
        public function move($path, $target)
        {
            $this->moves++;
            if ($this->moves === 2) return false;
            if ($this->moves === 3) {
                rename($this->parent, $this->parked);
                unlink($this->parked.'/'.basename($path));
                mkdir($this->parent);
                file_put_contents($this->parent.'/First.php', $this->external);

                return false;
            }

            return parent::move($path, $target);
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $first => '<?php final class Generated {}',
        $second => '<?php final class SecondGenerated {}',
    ], true))->toThrow(InvalidArgumentException::class, 'manual recovery')
        ->and(file_get_contents($first))->toBe($external)
        ->and(glob($parked.'/*.nodeflow-tmp-*') ?: [])->toBe([]);

    unlink($first);
    unlink($parked.'/First.php');
    rmdir($parent);
    rmdir($parked);
    rmdir($root);
});

it('cleans the rollback reservation when a missing target parent is replaced before restore', function () {
    $root = sys_get_temp_dir().'/nodeflow-rollback-reservation-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $root.'/nested-parked';
    mkdir($parent, 0777, true);
    $target = $parent.'/Target.php';
    $external = '<?php final class External {}';
    file_put_contents($target, '<?php final class Original {}');
    $files = new class($parent, $parked, $external) extends Filesystem
    {
        private int $moves = 0;
        public function __construct(private string $parent, private string $parked, private string $external) {}
        public function move($path, $target)
        {
            $this->moves++;
            if ($this->moves === 1) {
                $moved = parent::move($path, $target);
                unlink($target);

                return $moved;
            }
            if ($this->moves === 2) {
                rename($this->parent, $this->parked);
                mkdir($this->parent);
                file_put_contents($this->parent.'/Target.php', $this->external);

                return false;
            }

            return parent::move($path, $target);
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files, [$root]))->write([
        $target => '<?php final class Generated {}',
    ], true))->toThrow(InvalidArgumentException::class, 'manual recovery')
        ->and(file_get_contents($target))->toBe($external)
        ->and($parked.'/Target.php')->not->toBeFile()
        ->and(glob($parked.'/*.nodeflow-tmp-*') ?: [])->toBe([]);

    unlink($target);
    rmdir($parent);
    rmdir($parked);
    rmdir($root);
});

it('reports an inconclusive bounded scan for a newly committed inode without deleting it', function () {
    $root = sys_get_temp_dir().'/nodeflow-recovery-limit-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $root.'/nested-parked';
    mkdir($parent, 0777, true);
    $target = $parent.'/Target.php';
    $external = '<?php final class External {}';
    $files = new class($parent, $parked, $external) extends Filesystem
    {
        public function __construct(private string $parent, private string $parked, private string $external) {}

        public function move($path, $target)
        {
            $moved = parent::move($path, $target);
            rename($this->parent, $this->parked);
            mkdir($this->parent);
            file_put_contents($target, $this->external);

            return $moved;
        }
    };
    $entries = static function (string $scanRoot) use ($parked): iterable {
        for ($i = 0; $i <= 10_000; $i++) {
            yield new SplFileInfo($scanRoot.'/missing-'.$i);
        }
        yield new SplFileInfo($parked.'/Target.php');
    };

    $operation = fn () => (new AtomicFileWriter($files, $entries))->write(
        [$target => '<?php final class Generated {}'],
        [$root],
        false,
        static function (): void {},
    );

    expect($operation)->toThrow(InvalidArgumentException::class, 'entry limit')
        ->and($parked.'/Target.php')->toBeFile()
        ->and(file_get_contents($target))->toBe($external);

    unlink($target);
    unlink($parked.'/Target.php');
    rmdir($parent);
    rmdir($parked);
    rmdir($root);
});

it('reports bounded scans for stranded rollback temporaries and reservations without deleting them', function () {
    $root = sys_get_temp_dir().'/nodeflow-recovery-rollback-limit-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $root.'/nested-parked';
    mkdir($parent, 0777, true);
    $target = $parent.'/Target.php';
    $external = '<?php final class External {}';
    file_put_contents($target, '<?php final class Original {}');
    $files = new class($parent, $parked, $external) extends Filesystem
    {
        private int $moves = 0;

        public function __construct(private string $parent, private string $parked, private string $external) {}

        public function move($path, $target)
        {
            if (++$this->moves === 1) {
                $moved = parent::move($path, $target);
                unlink($target);

                return $moved;
            }
            if ($this->moves === 2) {
                rename($this->parent, $this->parked);
                mkdir($this->parent);
                file_put_contents($target, $this->external);

                return false;
            }

            return parent::move($path, $target);
        }
    };
    $entries = static function (string $scanRoot) use ($parked): iterable {
        for ($i = 0; $i <= 10_000; $i++) {
            yield new SplFileInfo($scanRoot.'/missing-'.$i);
        }
        foreach (glob($parked.'/*') ?: [] as $path) {
            yield new SplFileInfo($path);
        }
    };

    $operation = fn () => (new AtomicFileWriter($files, $entries))->write(
        [$target => '<?php final class Generated {}'],
        [$root],
        true,
        static function (): void {},
    );

    try {
        $operation();
        $this->fail('The bounded recovery scan must fail the transaction.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('entry limit')
            ->toContain('manual recovery')
            ->not->toContain('<?php');
    }
    expect(file_get_contents($target))->toBe($external)
        ->and($parked.'/Target.php')->toBeFile()
        ->and(count(glob($parked.'/*.nodeflow-tmp-*') ?: []))->toBe(1);

    unlink($target);
    foreach (glob($parked.'/*') ?: [] as $path) unlink($path);
    rmdir($parent);
    rmdir($parked);
    rmdir($root);
});

it('reports duplicate recovery identity matches without deleting either inode name', function () {
    if (DIRECTORY_SEPARATOR === '\\') {
        $this->markTestSkipped('Hard-link identity semantics are not portable to Windows.');
    }

    $root = sys_get_temp_dir().'/nodeflow-recovery-duplicate-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $root.'/nested-parked';
    mkdir($parent, 0777, true);
    $probe = $root.'/probe';
    $probeLink = $root.'/probe-link';
    file_put_contents($probe, 'probe');
    if (! @link($probe, $probeLink)) {
        unlink($probe);
        rmdir($parent);
        rmdir($root);
        $this->markTestSkipped('This filesystem does not support hard links.');
    }
    unlink($probeLink);
    unlink($probe);

    $target = $parent.'/Target.php';
    $duplicate = $root.'/Duplicate.php';
    $files = new class($parent, $parked, $duplicate) extends Filesystem
    {
        public function __construct(private string $parent, private string $parked, private string $duplicate) {}

        public function move($path, $target)
        {
            $moved = parent::move($path, $target);
            rename($this->parent, $this->parked);
            link($this->parked.'/Target.php', $this->duplicate);
            mkdir($this->parent);

            return $moved;
        }
    };

    expect(fn () => (new AtomicFileWriter($files))->write(
        [$target => '<?php final class Generated {}'],
        [$root],
        false,
        static function (): void {},
    ))->toThrow(InvalidArgumentException::class, 'ambiguous identity')
        ->and($parked.'/Target.php')->toBeFile()
        ->and($duplicate)->toBeFile();

    unlink($duplicate);
    unlink($parked.'/Target.php');
    rmdir($parent);
    rmdir($parked);
    rmdir($root);
});

it('reports a recovery iterator error without claiming rollback was clean', function () {
    $root = sys_get_temp_dir().'/nodeflow-recovery-iterator-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $root.'/nested-parked';
    mkdir($parent, 0777, true);
    $target = $parent.'/Target.php';
    $files = new class($parent, $parked) extends Filesystem
    {
        public function __construct(private string $parent, private string $parked) {}

        public function move($path, $target)
        {
            $moved = parent::move($path, $target);
            rename($this->parent, $this->parked);
            mkdir($this->parent);

            return $moved;
        }
    };
    $entries = static function (): iterable {
        throw new RuntimeException('sensitive iterator failure');
        yield;
    };

    try {
        (new AtomicFileWriter($files, $entries))->write(
            [$target => '<?php final class Generated {}'],
            [$root],
            false,
            static function (): void {},
        );
        $this->fail('The inconclusive iterator scan must fail the transaction.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('iterator error')
            ->toContain('manual recovery')
            ->toContain('Target.php')
            ->toContain($root)
            ->not->toContain('sensitive iterator failure');
    }

    expect($parked.'/Target.php')->toBeFile();
    unlink($parked.'/Target.php');
    rmdir($parent);
    rmdir($parked);
    rmdir($root);
});

it('treats any recovery iterator entry outside its allowed root as inconclusive', function () {
    $root = sys_get_temp_dir().'/nodeflow-recovery-iterator-escape-'.bin2hex(random_bytes(6));
    $outside = sys_get_temp_dir().'/nodeflow-recovery-iterator-escape-outside-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $root.'/nested-parked';
    mkdir($parent, 0777, true);
    mkdir($outside, 0777, true);
    $target = $parent.'/Target.php';
    $files = new class($parent, $parked) extends Filesystem
    {
        public function __construct(private string $parent, private string $parked) {}

        public function move($path, $target)
        {
            $moved = parent::move($path, $target);
            rename($this->parent, $this->parked);
            mkdir($this->parent);

            return $moved;
        }
    };
    $entries = static function () use ($outside): iterable {
        yield new SplFileInfo($outside.'/non-matching-missing-file');
    };

    expect(fn () => (new AtomicFileWriter($files, $entries))->write(
        [$target => '<?php final class Generated {}'],
        [$root],
        false,
        static function (): void {},
    ))->toThrow(InvalidArgumentException::class, 'iterator entry escaped its allowed root')
        ->and($parked.'/Target.php')->toBeFile()
        ->and($outside.'/non-matching-missing-file')->not->toBeFile();

    unlink($parked.'/Target.php');
    rmdir($parent);
    rmdir($parked);
    rmdir($outside);
    rmdir($root);
});

it('reports an allowed root changed while recovery was scanning', function () {
    $root = sys_get_temp_dir().'/nodeflow-recovery-root-change-'.bin2hex(random_bytes(6));
    $movedRoot = $root.'-moved';
    $parent = $root.'/nested';
    $parked = $root.'/nested-parked';
    mkdir($parent, 0777, true);
    $target = $parent.'/Target.php';
    $files = new class($parent, $parked) extends Filesystem
    {
        public function __construct(private string $parent, private string $parked) {}

        public function move($path, $target)
        {
            $moved = parent::move($path, $target);
            rename($this->parent, $this->parked);
            mkdir($this->parent);

            return $moved;
        }
    };
    $swapped = false;
    $entries = static function (string $scanRoot) use (&$swapped, $root, $movedRoot): iterable {
        if (! $swapped) {
            $swapped = true;
            rename($root, $movedRoot);
            mkdir($root);
        }
        if (false) yield new SplFileInfo($scanRoot.'/never');
    };

    expect(fn () => (new AtomicFileWriter($files, $entries))->write(
        [$target => '<?php final class Generated {}'],
        [$root],
        false,
        static function (): void {},
    ))->toThrow(InvalidArgumentException::class, 'allowed root is inaccessible or changed')
        ->and($movedRoot.'/nested-parked/Target.php')->toBeFile();

    unlink($movedRoot.'/nested-parked/Target.php');
    rmdir($movedRoot.'/nested');
    rmdir($movedRoot.'/nested-parked');
    rmdir($movedRoot);
    rmdir($root);
});

it('reports both a generated temporary and reservation moved outside the allowed root', function () {
    $root = sys_get_temp_dir().'/nodeflow-absent-outside-'.bin2hex(random_bytes(6));
    $outside = sys_get_temp_dir().'/nodeflow-absent-outside-destination-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $outside.'/nested-parked';
    mkdir($parent, 0777, true);
    mkdir($outside, 0777, true);
    $target = $parent.'/Target.php';
    $files = new class($parent, $parked) extends Filesystem
    {
        public function __construct(private string $parent, private string $parked) {}

        public function move($path, $target)
        {
            rename($this->parent, $this->parked);
            mkdir($this->parent);

            return false;
        }
    };

    try {
        (new AtomicFileWriter($files))->write(
            [$target => '<?php final class Generated {}'],
            [$root],
            false,
            static function (): void {},
        );
        $this->fail('An unprovable outside-root absence must require manual recovery.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('manual recovery')
            ->toContain('temporary [')
            ->toContain('reservation [Target.php]')
            ->not->toContain($outside)
            ->not->toContain('<?php');
    }

    expect($parked.'/Target.php')->toBeFile()
        ->and(count(glob($parked.'/*.nodeflow-tmp-*') ?: []))->toBe(1)
        ->and($target)->not->toBeFile();

    foreach (glob($parked.'/*') ?: [] as $path) unlink($path);
    rmdir($parent);
    rmdir($parked);
    rmdir($outside);
    rmdir($root);
});

it('does not reuse a scoped absence proof after the owned ancestor moves again', function () {
    $root = sys_get_temp_dir().'/nodeflow-scoped-absence-'.bin2hex(random_bytes(6));
    $outside = sys_get_temp_dir().'/nodeflow-scoped-absence-outside-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $outside.'/nested-parked';
    mkdir($parent, 0777, true);
    mkdir($outside, 0777, true);
    $target = $parent.'/Target.php';
    $files = new class($parent, $parked) extends Filesystem
    {
        public function __construct(private string $parent, private string $parked) {}

        public function move($path, $target)
        {
            $moved = parent::move($path, $target);
            rename($this->parent, $this->parked);
            mkdir($this->parent);

            return $moved;
        }
    };
    $scans = 0;
    $entries = static function (string $scanRoot) use (&$scans, $parent, $parked): iterable {
        if (++$scans === 1) {
            rmdir($parent);
            rename($parked, $parent);
        } elseif ($scans === 2) {
            rename($parent, $parked);
            mkdir($parent);
        }

        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
    };

    try {
        (new AtomicFileWriter($files, $entries))->write(
            [$target => '<?php final class Generated {}'],
            [$root],
            false,
            static function (): void {},
        );
        $this->fail('A scoped absence proof must not survive a later ancestor move.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('manual recovery')
            ->toContain('target [Target.php]')
            ->not->toContain($outside)
            ->not->toContain('<?php');
    }

    expect($parked.'/Target.php')->toBeFile();

    unlink($parked.'/Target.php');
    rmdir($parent);
    rmdir($parked);
    rmdir($outside);
    rmdir($root);
});

it('reports a rollback restoration temporary moved outside the allowed root', function () {
    $root = sys_get_temp_dir().'/nodeflow-restoration-absent-outside-'.bin2hex(random_bytes(6));
    $outside = sys_get_temp_dir().'/nodeflow-restoration-absent-destination-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    $parked = $outside.'/nested-parked';
    mkdir($parent, 0777, true);
    mkdir($outside, 0777, true);
    $first = $parent.'/First.php';
    $second = $root.'/Second.php';
    file_put_contents($first, '<?php final class Original {}');
    $files = new class($parent, $parked) extends Filesystem
    {
        private int $moves = 0;

        public function __construct(private string $parent, private string $parked) {}

        public function move($path, $target)
        {
            if (++$this->moves === 2) {
                return false;
            }
            if ($this->moves === 3) {
                rename($this->parent, $this->parked);
                mkdir($this->parent);

                return false;
            }

            return parent::move($path, $target);
        }
    };

    try {
        (new AtomicFileWriter($files))->write(
            [
                $first => '<?php final class Generated {}',
                $second => '<?php final class SecondGenerated {}',
            ],
            [$root],
            true,
            static function (): void {},
        );
        $this->fail('A stranded outside-root restoration temporary must require manual recovery.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('manual recovery')
            ->toContain('temporary [')
            ->not->toContain($outside)
            ->not->toContain('<?php');
    }

    expect(file_get_contents($parked.'/First.php'))->toBe('<?php final class Generated {}')
        ->and(count(glob($parked.'/*.nodeflow-tmp-*') ?: []))->toBe(1)
        ->and($second)->not->toBeFile();

    foreach (glob($parked.'/*') ?: [] as $path) unlink($path);
    rmdir($parent);
    rmdir($parked);
    rmdir($outside);
    rmdir($root);
});

it('accepts definitive absence after an adapter unlinks owned files under unchanged ancestors', function () {
    $root = sys_get_temp_dir().'/nodeflow-definitive-absence-'.bin2hex(random_bytes(6));
    $parent = $root.'/nested';
    mkdir($parent, 0777, true);
    $target = $parent.'/Target.php';
    $files = new class extends Filesystem
    {
        public function move($path, $target)
        {
            unlink($path);
            unlink($target);

            return false;
        }
    };

    try {
        (new AtomicFileWriter($files))->write(
            [$target => '<?php final class Generated {}'],
            [$root],
            false,
            static function (): void {},
        );
        $this->fail('The injected move failure must abort the transaction.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->not->toContain('manual recovery');
    }

    expect($target)->not->toBeFile()
        ->and(glob($parent.'/*.nodeflow-tmp-*') ?: [])->toBe([]);

    rmdir($parent);
    rmdir($root);
});
