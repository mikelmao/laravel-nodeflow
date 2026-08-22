<?php

namespace Nodeflow\Console\Extract;

use Nodeflow\Console\HostPath;
use Throwable;

class ComposerRunner
{
    /** @var array<string, array{packages: string, services: string}> */
    private array $frozenCachePaths = [];

    /**
     * Freezes the read-only G8 observation for M9. The child receives these
     * exact paths as environment values, so a changed ambient shell or .env
     * cannot redirect its discovery caches after the journal boundary was
     * accepted.
     */
    public function freezeCachePaths(string $hostPath, string $packagesPath, string $servicesPath): void
    {
        $this->frozenCachePaths[$this->hostKey($hostPath)] = [
            'packages' => $packagesPath,
            'services' => $servicesPath,
        ];
    }

    public function install(string $hostPath, string $packageName): bool
    {
        $command = is_file($hostPath.'/composer.lock')
            ? ['composer', 'update', $packageName, '--no-scripts', '--no-plugins', '--no-interaction']
            : ['composer', 'install', '--no-scripts', '--no-plugins', '--no-interaction'];

        return $this->run($command, $hostPath)['exit'] === 0;
    }

    /** Regenerates only the already-restored installed state; this is never M8's installation step. */
    public function regenerateAutoload(string $hostPath): bool
    {
        return $this->run([
            'composer',
            'dump-autoload',
            '--no-scripts',
            '--no-plugins',
            '--no-interaction',
        ], $hostPath)['exit'] === 0;
    }

    public function bootAndResolve(string $hostPath, string $type): ?string
    {
        try {
            $vendorPath = $this->composerVendorPath($hostPath);
            $autoloadPath = $vendorPath.'/autoload.php';
            $resultPrefix = 'NODEFLOW_EXTRACT_RESULT_'.bin2hex(random_bytes(16)).':';
            $cachePaths = $this->frozenCachePaths[$this->hostKey($hostPath)] ?? [
                'packages' => $hostPath.'/bootstrap/cache/packages.php',
                'services' => $hostPath.'/bootstrap/cache/services.php',
            ];
        } catch (Throwable) {
            return null;
        }

        $probe = <<<'PHP'
        $autoloadPath = $argv[1];
        $hostPath = $argv[2];
        $type = $argv[3];
        $resultPrefix = $argv[4];

        try {
            require $autoloadPath;
            $app = require $hostPath.'/bootstrap/app.php';
            $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();
            $registry = $app->make(\Nodeflow\Nodes\NodeRegistry::class);
            $classes = $registry->all();
            $class = $classes[$type] ?? null;

            if (! is_string($class) || ! class_exists($class) || $class::type() !== $type) {
                $class = null;
            }

            echo $resultPrefix.json_encode(['class' => $class]).PHP_EOL;
        } catch (\Throwable $e) {
            fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
            exit(70);
        }
        PHP;

        $result = $this->run(
            [PHP_BINARY, '-r', $probe, '--', $autoloadPath, $hostPath, $type, $resultPrefix],
            $hostPath,
            [
                'COMPOSER_VENDOR_DIR' => $vendorPath,
                'APP_PACKAGES_CACHE' => $cachePaths['packages'],
                'APP_SERVICES_CACHE' => $cachePaths['services'],
            ],
        );

        if ($result['exit'] !== 0) {
            return null;
        }

        $lines = preg_split('/\R/', $result['stdout']) ?: [];

        foreach (array_reverse($lines) as $line) {
            if (! str_starts_with($line, $resultPrefix)) {
                continue;
            }

            $decoded = json_decode(substr($line, strlen($resultPrefix)), true);
            $class = is_array($decoded) ? ($decoded['class'] ?? null) : null;

            return is_string($class) ? $class : null;
        }

        return null;
    }

    private function composerVendorPath(string $hostPath): string
    {
        $contents = @file_get_contents($hostPath.'/composer.json');
        $composer = is_string($contents) ? json_decode($contents, true) : null;
        $configured = is_array($composer)
            && is_array($composer['config'] ?? null)
            && is_string($composer['config']['vendor-dir'] ?? null)
            && trim($composer['config']['vendor-dir']) !== ''
                ? $composer['config']['vendor-dir']
                : 'vendor';

        return HostPath::root($hostPath)->resolveWithin($configured);
    }

    private function hostKey(string $hostPath): string
    {
        return realpath($hostPath) ?: $hostPath;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environmentOverrides
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function run(array $command, string $hostPath, array $environmentOverrides = []): array
    {
        try {
            $composerHome = $this->temporaryComposerHome();
        } catch (Throwable $e) {
            return ['exit' => -1, 'stdout' => '', 'stderr' => $e->getMessage()];
        }

        $stdout = tmpfile();
        $stderr = tmpfile();

        if ($stdout === false || $stderr === false) {
            if (is_resource($stdout)) {
                fclose($stdout);
            }

            if (is_resource($stderr)) {
                fclose($stderr);
            }

            return $this->cleanupComposerHome($composerHome, [
                'exit' => -1,
                'stdout' => '',
                'stderr' => 'Could not allocate process output streams.',
            ]);
        }

        $pipes = [];
        $environment = getenv();

        if (is_array($environment)) {
            unset(
                $environment['COMPOSER'],
                $environment['COMPOSER_VENDOR_DIR'],
                $environment['COMPOSER_BIN_DIR'],
                $environment['COMPOSER_HOME'],
                $environment['COMPOSER_CACHE_DIR'],
                $environment['APP_PACKAGES_CACHE'],
                $environment['APP_SERVICES_CACHE'],
            );

            // Composer loads global config.json from COMPOSER_HOME. Point it
            // at a private empty directory for every invocation so ambient
            // vendor-dir/plugin/repository settings cannot redirect M8 beyond
            // the host paths the command journaled. Cache writes are scoped
            // there too and deleted deterministically with the home.
            $environment['COMPOSER_HOME'] = $composerHome;
            $environment['COMPOSER_CACHE_DIR'] = $composerHome.'/cache';

            foreach ($environmentOverrides as $name => $value) {
                $environment[$name] = $value;
            }
        } else {
            fclose($stdout);
            fclose($stderr);

            return $this->cleanupComposerHome($composerHome, [
                'exit' => -1,
                'stdout' => '',
                'stderr' => 'Could not read the process environment safely.',
            ]);
        }

        $process = @proc_open($command, [
            0 => ['pipe', 'r'],
            1 => $stdout,
            2 => $stderr,
        ], $pipes, $hostPath, $environment, ['bypass_shell' => true]);

        if (! is_resource($process)) {
            fclose($stdout);
            fclose($stderr);

            return $this->cleanupComposerHome($composerHome, [
                'exit' => -1,
                'stdout' => '',
                'stderr' => 'Could not start process.',
            ]);
        }

        fclose($pipes[0]);
        $exitCode = proc_close($process);
        rewind($stdout);
        rewind($stderr);
        $stdoutContents = stream_get_contents($stdout);
        $stderrContents = stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);

        return $this->cleanupComposerHome($composerHome, [
            'exit' => $exitCode,
            'stdout' => $stdoutContents === false ? '' : $stdoutContents,
            'stderr' => $stderrContents === false ? '' : $stderrContents,
        ]);
    }

    private function temporaryComposerHome(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR.'nodeflow-composer-home-'.getmypid().'-'.bin2hex(random_bytes(8));

            if (@mkdir($path, 0700)) {
                return $path;
            }
        }

        throw new \RuntimeException('Could not create an isolated Composer home directory.');
    }

    /**
     * @param  array{exit: int, stdout: string, stderr: string}  $result
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function cleanupComposerHome(string $path, array $result): array
    {
        try {
            $this->deleteTemporaryTree($path);
        } catch (Throwable $e) {
            $result['exit'] = -1;
            $result['stderr'] .= ($result['stderr'] === '' ? '' : PHP_EOL)
                .'Isolated Composer configuration cleanup failed: '.$e->getMessage();
        }

        return $result;
    }

    /** Recursively removes private process state without following any symlink it contains. */
    private function deleteTemporaryTree(string $path): void
    {
        if (is_link($path)) {
            if (! @unlink($path) || is_link($path)) {
                throw new \RuntimeException("[{$path}] could not be removed.");
            }

            return;
        }

        if (is_dir($path)) {
            @chmod($path, 0700);
            $entries = @scandir($path);

            if ($entries === false) {
                throw new \RuntimeException("[{$path}] could not be enumerated for cleanup.");
            }

            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->deleteTemporaryTree($path.'/'.$entry);
                }
            }

            if (! @rmdir($path) || is_dir($path)) {
                throw new \RuntimeException("[{$path}] could not be removed.");
            }

            return;
        }

        if (file_exists($path) && (! @unlink($path) || file_exists($path))) {
            throw new \RuntimeException("[{$path}] could not be removed.");
        }
    }
}
