<?php

namespace Nodeflow\Console;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Throwable;

/** Writes one generator kit only when every rendered PHP file survives byte-for-byte. */
final class VerifiedGeneratorWriter
{
    public function __construct(private Filesystem $files) {}

    /** @param  array<string, string>  $files */
    public function write(array $files, bool $force = false): void
    {
        $originals = [];
        $attempted = [];

        try {
            foreach ($files as $path => $contents) {
                $exists = $this->files->exists($path);
                if ($exists && ! $force) {
                    throw new InvalidArgumentException("Generator target [{$path}] already exists; no files were changed.");
                }
                $this->assertParseable($contents);
                $originals[$path] = $exists ? $this->files->get($path) : null;
            }

            foreach ($files as $path => $contents) {
                $this->files->ensureDirectoryExists(dirname($path));
                $attempted[$path] = true;
                $writtenBytes = $this->files->put($path, $contents);
                $written = $this->files->exists($path) ? $this->files->get($path) : null;
                if ($writtenBytes !== strlen($contents) || $written !== $contents) {
                    throw new InvalidArgumentException("Generator write verification failed for [{$path}]; no registrations were changed.");
                }
                $this->assertParseable($written);
            }
        } catch (Throwable $e) {
            $rollbackFailures = $this->restore(array_intersect_key($originals, $attempted));
            if ($rollbackFailures !== []) {
                throw new InvalidArgumentException(
                    'Generator write failed and rollback could not restore ['.implode(', ', $rollbackFailures).']; manual recovery is required.',
                    previous: $e,
                );
            }

            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }

            throw new InvalidArgumentException('Generator write verification failed; no registrations were changed.', previous: $e);
        }
    }

    /**
     * Restore each target independently and verify the final bytes. Existing
     * files use a sibling temporary followed by rename, so a Filesystem whose
     * direct target writes failed cannot corrupt the rollback in the same way.
     *
     * @param  array<string, string|null>  $originals
     * @return string[] Paths that could not be restored exactly.
     */
    private function restore(array $originals): array
    {
        $failures = [];

        foreach ($originals as $path => $original) {
            $temporary = null;

            try {
                if ($original === null) {
                    if ($this->files->exists($path) && ! $this->files->delete($path)) {
                        throw new InvalidArgumentException('Could not remove a partial generator file.');
                    }
                    if ($this->files->exists($path)) {
                        throw new InvalidArgumentException('A partial generator file remains.');
                    }

                    continue;
                }

                $temporary = $path.'.nodeflow-rollback-'.bin2hex(random_bytes(8));
                $writtenBytes = $this->files->put($temporary, $original);
                $written = $this->files->exists($temporary) ? $this->files->get($temporary) : null;
                if ($writtenBytes !== strlen($original) || $written !== $original) {
                    throw new InvalidArgumentException('Could not stage the original generator file.');
                }
                if (! $this->files->move($temporary, $path)) {
                    throw new InvalidArgumentException('Could not atomically restore the original generator file.');
                }
                $temporary = null;
                if (! $this->files->exists($path) || $this->files->get($path) !== $original) {
                    throw new InvalidArgumentException('The restored generator file did not match its original bytes.');
                }
            } catch (Throwable) {
                $failures[] = $path;
            } finally {
                if ($temporary !== null && $this->files->exists($temporary)) {
                    try {
                        $this->files->delete($temporary);
                    } catch (Throwable) {
                        // The target failure above is already actionable; keep
                        // attempting restoration for every remaining target.
                    }
                }
            }
        }

        return $failures;
    }

    private function assertParseable(string $contents): void
    {
        try {
            token_get_all($contents, TOKEN_PARSE);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('A generator stub did not produce parseable PHP; no files were changed.', previous: $e);
        }
    }
}
