<?php

namespace Nodeflow\Console;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Throwable;

/**
 * Failure-atomic, root-confined replacement of one or more rendered files.
 *
 * Every file is staged and verified before the first target changes. Targets
 * are then identity-checked immediately before their sibling temporary is
 * renamed into place. If a later commit (or the caller's transaction callback)
 * fails, only targets still carrying this operation's committed inode/bytes are
 * rolled back; concurrent external replacements are never overwritten.
 */
final class AtomicFileWriter
{
    public function __construct(private Filesystem $files) {}

    /**
     * @param  array<string, string>  $writes
     * @param  list<string>  $allowedRoots
     * @param  callable(string, string): void  $validator
     */
    public function write(
        array $writes,
        array $allowedRoots,
        bool $force,
        callable $validator,
        ?callable $afterCommit = null,
    ): void {
        if ($writes === []) {
            return;
        }

        $roots = $this->canonicalRoots($allowedRoots);
        $snapshots = [];
        $staged = [];
        $committed = [];

        try {
            foreach ($writes as $path => $contents) {
                if (! is_string($path) || $path === '' || ! is_string($contents)) {
                    throw new InvalidArgumentException('Atomic writes require absolute file paths and string contents.');
                }

                $this->assertSafePath($path, $roots);
                $validator($contents, $path);
                $snapshot = $this->snapshot($path);
                if ($snapshot !== null && ! $force) {
                    throw new InvalidArgumentException("Target [{$path}] already exists; no files were changed.");
                }
                $snapshots[$path] = $snapshot;
            }

            foreach ($writes as $path => $contents) {
                $this->files->ensureDirectoryExists(dirname($path));
                $this->assertSafePath($path, $roots);
                $staged[$path] = $this->stage($path, $contents, $snapshots[$path]['mode'] ?? null, $validator);
            }

            foreach ($writes as $path => $contents) {
                $this->assertSafePath($path, $roots);
                $snapshot = $snapshots[$path];
                $stagedIdentity = $staged[$path]['identity'];
                if ($snapshot === null) {
                    $placeholder = $this->exclusiveFile($path);
                    try {
                        if (! $this->sameIdentity($path, $placeholder)) {
                            throw new InvalidArgumentException("Target [{$path}] changed during generation; it was not overwritten.");
                        }
                        $this->moveOrVerify($staged[$path]['path'], $path, $stagedIdentity);
                    } catch (Throwable $e) {
                        if ($this->sameIdentity($path, $placeholder)) {
                            @unlink($path);
                        }
                        throw $e;
                    }
                } else {
                    if (! $this->matchesSnapshot($path, $snapshot)) {
                        throw new InvalidArgumentException("Target [{$path}] changed during generation; it was not overwritten.");
                    }
                    // Track the expected installed inode before rename. If a
                    // filesystem adapter installs it and another actor then
                    // removes/replaces it before move() returns, rollback can
                    // restore a now-missing original or report the external
                    // replacement without ever applying metadata to it.
                    $committed[$path] = [
                        'contents' => $contents,
                        'mode' => $snapshot['mode'],
                        'uid' => $snapshot['uid'],
                        'gid' => $snapshot['gid'],
                        'identity' => $stagedIdentity,
                    ];
                    try {
                        $this->moveOrVerify($staged[$path]['path'], $path, $stagedIdentity);
                    } catch (Throwable $e) {
                        if ($this->matchesSnapshot($path, $snapshot)) {
                            unset($committed[$path]);
                        }
                        throw $e;
                    }
                }
                $installedIdentity = $stagedIdentity;
                unset($staged[$path]);

                // Record ownership of the inode before any post-rename read or
                // metadata operation. A hostile/injected filesystem can change
                // the target as move() returns; rollback must then recognise
                // that it cannot safely overwrite that external change.
                $installedStat = @lstat($path);
                if ($installedStat === false) {
                    throw new InvalidArgumentException("Installed target [{$path}] disappeared before verification.");
                }
                if (! $this->sameIdentity($path, $installedIdentity)) {
                    throw new InvalidArgumentException("Installed target [{$path}] changed before verification.");
                }
                $committed[$path] ??= [
                    'contents' => $contents,
                    'mode' => (int) $installedStat['mode'],
                    'uid' => (int) $installedStat['uid'],
                    'gid' => (int) $installedStat['gid'],
                    'identity' => $installedIdentity,
                ];

                $this->applyMetadata($path, $snapshot);
                $installed = $this->snapshot($path);
                if ($installed === null || $installed['contents'] !== $contents) {
                    throw new InvalidArgumentException("Atomic write verification failed for [{$path}].");
                }
                $validator($installed['contents'], $path);
                $committed[$path] = $installed;
            }

            if ($afterCommit !== null) {
                $afterCommit();
            }
        } catch (Throwable $failure) {
            $rollbackFailures = $this->rollback($snapshots, $committed, $roots);
            $cleanupFailures = $this->cleanup($staged);
            $manual = array_values(array_unique([...$rollbackFailures, ...$cleanupFailures]));

            if ($manual !== []) {
                throw new InvalidArgumentException(
                    'Atomic write failed and could not safely recover ['.implode(', ', $manual).']; manual recovery is required.',
                    previous: $failure,
                );
            }

            if ($failure instanceof InvalidArgumentException) {
                throw $failure;
            }

            throw new InvalidArgumentException('Atomic write failed; no committed files remain changed.', previous: $failure);
        }

        $cleanupFailures = $this->cleanup($staged);
        if ($cleanupFailures !== []) {
            throw new InvalidArgumentException(
                'Atomic write completed but temporary files require manual recovery ['.implode(', ', $cleanupFailures).'].',
            );
        }
    }

    /** A throwing/false adapter may still have completed rename(2); verify it. */
    private function moveOrVerify(string $temporary, string $target, array $identity): void
    {
        try {
            $moved = $this->files->move($temporary, $target);
        } catch (Throwable $e) {
            if ($this->sameIdentity($target, $identity)) {
                return;
            }
            throw $e;
        }

        if (! $moved && ! $this->sameIdentity($target, $identity)) {
            throw new InvalidArgumentException("Could not atomically install [{$target}].");
        }
        if (! $this->sameIdentity($target, $identity)) {
            throw new InvalidArgumentException("Installed target [{$target}] changed before verification.");
        }
    }

    /** @param list<string> $roots @return list<string> */
    private function canonicalRoots(array $roots): array
    {
        if ($roots === []) {
            throw new InvalidArgumentException('Atomic writes require at least one explicit allowed root.');
        }

        $canonical = [];
        foreach ($roots as $root) {
            $real = realpath($root);
            if ($real === false || ! is_dir($real) || is_link($root)) {
                throw new InvalidArgumentException("Allowed root [{$root}] is not a canonical directory.");
            }
            $canonical[] = rtrim($real, DIRECTORY_SEPARATOR);
        }

        return array_values(array_unique($canonical));
    }

    /** @param list<string> $roots */
    private function assertSafePath(string $path, array $roots): void
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException("Target [{$path}] must be absolute.");
        }

        $normalised = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (in_array('..', explode(DIRECTORY_SEPARATOR, $normalised), true)) {
            throw new InvalidArgumentException("Target [{$path}] may not traverse parent directories.");
        }

        if (is_link($path)) {
            throw new InvalidArgumentException("Target [{$path}] is a symbolic link.");
        }

        $cursor = dirname($path);
        while (! file_exists($cursor) && ! is_link($cursor)) {
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }

        for ($ancestor = dirname($path); $ancestor !== dirname($ancestor); $ancestor = dirname($ancestor)) {
            if (is_link($ancestor)) {
                throw new InvalidArgumentException("Target [{$path}] has a symbolic-link ancestor [{$ancestor}].");
            }
            if (file_exists($ancestor) && $ancestor === $cursor) {
                break;
            }
        }

        $realAncestor = realpath($cursor);
        if ($realAncestor === false) {
            throw new InvalidArgumentException("Target [{$path}] has no canonical existing ancestor.");
        }

        foreach ($roots as $root) {
            if ($realAncestor === $root || str_starts_with($realAncestor, $root.DIRECTORY_SEPARATOR)) {
                return;
            }
        }

        throw new InvalidArgumentException("Target [{$path}] is outside every allowed root.");
    }

    /** @return array{path:string, identity:array<string,int>} */
    private function stage(string $target, string $contents, ?int $mode, callable $validator): array
    {
        $temporary = $target.'.nodeflow-tmp-'.bin2hex(random_bytes(8));
        $identity = $this->exclusiveFile($temporary);

        try {
            $written = $this->files->put($temporary, $contents);
            if ($written !== strlen($contents) || ! $this->sameIdentity($temporary, $identity)) {
                throw new InvalidArgumentException("Could not stage exact bytes for [{$target}].");
            }
            $read = $this->files->get($temporary);
            if ($read !== $contents) {
                throw new InvalidArgumentException("Staged bytes for [{$target}] did not verify.");
            }
            $validator($read, $target);
            if ($mode !== null && DIRECTORY_SEPARATOR !== '\\' && ! @chmod($temporary, $mode & 0777)) {
                throw new InvalidArgumentException("Could not preserve permissions for [{$target}].");
            }
        } catch (Throwable $e) {
            if ($this->sameIdentity($temporary, $identity)) {
                @unlink($temporary);
            }
            throw $e;
        }

        return ['path' => $temporary, 'identity' => $identity];
    }

    /** @return array<string,int> */
    private function exclusiveFile(string $path): array
    {
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new InvalidArgumentException("Target [{$path}] appeared during the atomic operation; it was not overwritten.");
        }
        fclose($handle);

        $stat = @lstat($path);
        if ($stat === false || is_link($path)) {
            @unlink($path);
            throw new InvalidArgumentException("Could not reserve a safe file at [{$path}].");
        }

        return $this->identity($stat);
    }

    /** @return array{contents:string,mode:int,uid:int,gid:int,identity:array<string,int>}|null */
    private function snapshot(string $path): ?array
    {
        $before = @lstat($path);
        if ($before === false) {
            return null;
        }
        if (is_link($path) || ! is_file($path)) {
            throw new InvalidArgumentException("Target [{$path}] is not a regular non-symbolic-link file.");
        }
        $contents = $this->files->get($path);
        $after = @lstat($path);
        if ($after === false || $this->identity($before) !== $this->identity($after)) {
            throw new InvalidArgumentException("Target [{$path}] changed while it was inspected.");
        }

        return [
            'contents' => $contents,
            'mode' => (int) $before['mode'],
            'uid' => (int) $before['uid'],
            'gid' => (int) $before['gid'],
            'identity' => $this->identity($before),
        ];
    }

    private function matchesSnapshot(string $path, array $snapshot): bool
    {
        $stat = @lstat($path);

        return $stat !== false
            && $this->identity($stat) === $snapshot['identity']
            && ((int) $stat['mode'] & 0777) === ((int) $snapshot['mode'] & 0777)
            && (DIRECTORY_SEPARATOR === '\\' || ((int) $stat['uid'] === (int) $snapshot['uid'] && (int) $stat['gid'] === (int) $snapshot['gid']))
            && is_file($path)
            && ! is_link($path)
            && $this->files->get($path) === $snapshot['contents'];
    }

    /** @param array<string,int> $identity */
    private function sameIdentity(string $path, array $identity): bool
    {
        $stat = @lstat($path);

        return $stat !== false && $this->identity($stat) === $identity;
    }

    /** @return array<string,int> */
    private function identity(array $stat): array
    {
        return ['dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino']];
    }

    private function applyMetadata(string $path, ?array $snapshot): void
    {
        if ($snapshot === null || DIRECTORY_SEPARATOR === '\\') {
            return;
        }
        if (! @chmod($path, $snapshot['mode'] & 0777)) {
            throw new InvalidArgumentException("Could not preserve permissions for [{$path}].");
        }
        // Ownership restoration is best effort: non-root users cannot chown,
        // while rename normally keeps the current user's ownership unchanged.
        @chown($path, $snapshot['uid']);
        @chgrp($path, $snapshot['gid']);
    }

    /**
     * @param array<string, array|null> $snapshots
     * @param array<string, array> $committed
     * @param list<string> $roots
     * @return list<string>
     */
    private function rollback(array $snapshots, array $committed, array $roots): array
    {
        $failures = [];
        foreach (array_reverse(array_keys($committed)) as $path) {
            try {
                $this->assertSafePath($path, $roots);
                $original = $snapshots[$path];
                if (! $this->matchesSnapshot($path, $committed[$path])) {
                    if (@lstat($path) !== false) {
                        throw new InvalidArgumentException('The committed target changed externally.');
                    }
                    if ($original === null) {
                        continue;
                    }
                    $this->restoreMissing($path, $original);
                    continue;
                }
                if ($original === null) {
                    if (! @unlink($path) || @lstat($path) !== false) {
                        throw new InvalidArgumentException('Could not remove a newly committed target.');
                    }
                    continue;
                }

                // Original bytes are restoration data, not a newly rendered
                // artifact. They may pre-date today's validator and must be
                // restored exactly even when they are malformed/legacy.
                $staged = $this->stage($path, $original['contents'], $original['mode'], static function (): void {});
                if (! $this->matchesSnapshot($path, $committed[$path])) {
                    $this->cleanup([$staged]);
                    throw new InvalidArgumentException('The committed target changed before rollback.');
                }
                $this->moveOrVerify($staged['path'], $path, $staged['identity']);
                $this->applyMetadata($path, $original);
                $restored = $this->snapshot($path);
                if ($restored === null
                    || $restored['contents'] !== $original['contents']
                    || ($restored['mode'] & 0777) !== ($original['mode'] & 0777)) {
                    throw new InvalidArgumentException('The restored target did not verify.');
                }
            } catch (Throwable) {
                $failures[] = $path;
            }
        }

        return $failures;
    }

    /** Restore an overwritten original only while the target remains absent. */
    private function restoreMissing(string $path, array $original): void
    {
        $staged = $this->stage($path, $original['contents'], $original['mode'], static function (): void {});
        try {
            $placeholder = $this->exclusiveFile($path);
            try {
                if (! $this->sameIdentity($path, $placeholder)) {
                    throw new InvalidArgumentException('The missing target was raced during rollback.');
                }
                $this->moveOrVerify($staged['path'], $path, $staged['identity']);
            } catch (Throwable $e) {
                if ($this->sameIdentity($path, $placeholder)) {
                    @unlink($path);
                }
                throw $e;
            }
            $this->applyMetadata($path, $original);
            $restored = $this->snapshot($path);
            if ($restored === null
                || $restored['contents'] !== $original['contents']
                || ($restored['mode'] & 0777) !== ($original['mode'] & 0777)) {
                throw new InvalidArgumentException('The restored target did not verify.');
            }
        } catch (Throwable $failure) {
            $cleanupFailures = $this->cleanup([$staged]);
            if ($cleanupFailures !== []) {
                throw new InvalidArgumentException('Rollback temporary requires manual recovery.', previous: $failure);
            }
            throw $failure;
        }
        if ($this->cleanup([$staged]) !== []) {
            throw new InvalidArgumentException('Rollback temporary requires manual recovery.');
        }
    }

    /** @param array<int|string, array{path:string, identity:array<string,int>}> $staged @return list<string> */
    private function cleanup(array $staged): array
    {
        $failures = [];
        foreach ($staged as $temporary) {
            try {
                if (! $this->sameIdentity($temporary['path'], $temporary['identity'])) {
                    if (@lstat($temporary['path']) !== false) {
                        $failures[] = $temporary['path'];
                    }
                    continue;
                }
                if (! @unlink($temporary['path']) || @lstat($temporary['path']) !== false) {
                    $failures[] = $temporary['path'];
                }
            } catch (Throwable) {
                $failures[] = $temporary['path'];
            }
        }

        return $failures;
    }
}
