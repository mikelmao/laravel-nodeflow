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

    $files = new class($first, $second) extends Filesystem
    {
        private int $targetWrites = 0;

        public function __construct(private string $first, private string $second) {}

        public function put($path, $contents, $lock = false)
        {
            if ($path === $this->first || $path === $this->second) {
                $this->targetWrites++;

                if ($this->targetWrites === 2) {
                    parent::put($path, substr($contents, 0, -1), $lock);

                    return strlen($contents) - 1;
                }

                if ($this->targetWrites > 2) {
                    throw new RuntimeException('Direct rollback writes are forbidden.');
                }
            }

            return parent::put($path, $contents, $lock);
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files))->write([
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

        public function put($path, $contents, $lock = false)
        {
            if ($path === $this->first) {
                parent::put($path, substr($contents, 0, -1), $lock);

                return strlen($contents) - 1;
            }

            return parent::put($path, $contents, $lock);
        }

        public function move($path, $target)
        {
            $this->movedTargets[] = $target;

            return parent::move($path, $target);
        }
    };

    expect(fn () => (new VerifiedGeneratorWriter($files))->write([
        $first => '<?php final class FirstGenerated {}',
        $second => '<?php final class SecondGenerated {}',
    ], true))->toThrow(InvalidArgumentException::class)
        ->and($files->movedTargets)->toBe([$first]);

    foreach (glob($root.'/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($root);
});
