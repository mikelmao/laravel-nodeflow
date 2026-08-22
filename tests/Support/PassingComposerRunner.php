<?php

namespace Tests\Support;

use Nodeflow\Console\Extract\ComposerRunner;
use Nodeflow\Console\NodeTypeLiteral;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Keeps pre-M8 extraction tests focused on their own move/gate behavior. */
final class PassingComposerRunner extends ComposerRunner
{
    public function install(string $hostPath, string $packageName): bool
    {
        return true;
    }

    public function bootAndResolve(string $hostPath, string $type): ?string
    {
        foreach (glob($hostPath.'/packages/*/*/composer.json') ?: [] as $composerPath) {
            $composer = json_decode(file_get_contents($composerPath), true);
            $psr4 = is_array($composer) ? ($composer['autoload']['psr-4'] ?? []) : [];

            if (! is_array($psr4)) {
                continue;
            }

            foreach ($psr4 as $prefix => $relativeRoot) {
                if (! is_string($prefix) || ! is_string($relativeRoot)) {
                    continue;
                }

                $sourceRoot = dirname($composerPath).'/'.trim($relativeRoot, '/');

                if (! is_dir($sourceRoot)) {
                    continue;
                }

                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                    $sourceRoot,
                    RecursiveDirectoryIterator::SKIP_DOTS,
                ));

                foreach ($files as $file) {
                    if (! $file->isFile() || $file->getExtension() !== 'php') {
                        continue;
                    }

                    $relative = substr($file->getPathname(), strlen($sourceRoot) + 1);
                    $class = rtrim($prefix, '\\').'\\'.str_replace('/', '\\', substr($relative, 0, -4));
                    $result = NodeTypeLiteral::resolve(file_get_contents($file->getPathname()), class_basename($class));

                    if ($result->type === $type) {
                        return $class;
                    }
                }
            }
        }

        return null;
    }
}
