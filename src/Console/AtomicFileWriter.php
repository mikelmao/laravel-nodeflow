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
 *
 * Portable PHP has no openat(2)-style directory handle API, so it cannot keep
 * a renamed ancestor pinned by descriptor. We snapshot and repeatedly verify
 * every ancestor inode instead; when a post-rename swap is detected, cleanup
 * follows the visible path only when it still resolves to our installed inode.
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
        $ancestors = [];
        $staged = [];
        $reservations = [];
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
                $ancestors[$path] = $this->snapshotAncestors($path, $roots);
                $staged[$path] = $this->stage($path, $contents, $snapshots[$path]['mode'] ?? null, $validator);
            }

            foreach ($writes as $path => $contents) {
                $this->assertAncestors($path, $ancestors[$path], $roots);
                $snapshot = $snapshots[$path];
                $stagedIdentity = $staged[$path]['identity'];
                $committed[$path] = $this->expectedCommit($staged[$path]['path'], $contents, $stagedIdentity, $snapshot);
                if ($snapshot === null) {
                    $placeholder = $this->exclusiveFile($path);
                    $reservations[$path] = ['path' => $path, 'identity' => $placeholder];
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
                $this->assertAncestors($path, $ancestors[$path], $roots);
                unset($staged[$path]);
                unset($reservations[$path]);

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
                $this->assertAncestors($path, $ancestors[$path], $roots);
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
            foreach (array_keys($writes) as $path) {
                $this->assertAncestors($path, $ancestors[$path], $roots);
                if (! $this->matchesSnapshot($path, $committed[$path])) {
                    throw new InvalidArgumentException("Committed target [{$path}] changed before final verification.");
                }
            }
        } catch (Throwable $failure) {
            $cleanupFailures = $this->cleanup([...array_values($staged), ...array_values($reservations)], $roots);
            $rollbackFailures = $this->rollback($snapshots, $committed, $ancestors, $roots);
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

        $cleanupFailures = $this->cleanup([...array_values($staged), ...array_values($reservations)], $roots);
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

    /** @param list<string> $roots @return array<string, array<string,int>> */
    private function snapshotAncestors(string $path, array $roots): array
    {
        $parent = dirname($path);
        $realParent = realpath($parent);
        if ($realParent === false) {
            throw new InvalidArgumentException("Target [{$path}] has no canonical parent.");
        }
        $root = $this->rootFor($realParent, $roots);
        $paths = [$root];
        $relative = trim(substr($realParent, strlen($root)), DIRECTORY_SEPARATOR);
        $cursor = $root;
        if ($relative !== '') {
            foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
                $cursor .= DIRECTORY_SEPARATOR.$segment;
                $paths[] = $cursor;
            }
        }

        $snapshot = [];
        foreach ($paths as $ancestor) {
            $stat = @lstat($ancestor);
            if ($stat === false || is_link($ancestor) || ! is_dir($ancestor)) {
                throw new InvalidArgumentException("Target [{$path}] has an unsafe ancestor [{$ancestor}].");
            }
            $snapshot[$ancestor] = $this->identity($stat);
        }
        if ($realParent === false || ! $this->withinRoot($realParent, $root)) {
            throw new InvalidArgumentException("Target [{$path}] escaped its allowed root while ancestors were inspected.");
        }

        return $snapshot;
    }

    /** @param array<string, array<string,int>> $snapshot @param list<string> $roots */
    private function assertAncestors(string $path, array $snapshot, array $roots): void
    {
        $this->assertSafePath($path, $roots);
        foreach ($snapshot as $ancestor => $identity) {
            $stat = @lstat($ancestor);
            if ($stat === false || is_link($ancestor) || ! is_dir($ancestor) || $this->identity($stat) !== $identity) {
                throw new InvalidArgumentException("Target [{$path}] ancestor [{$ancestor}] changed during the atomic operation.");
            }
        }
        $root = array_key_first($snapshot);
        $realParent = realpath(dirname($path));
        if ($root === null || $realParent === false || ! $this->withinRoot($realParent, $root)) {
            throw new InvalidArgumentException("Target [{$path}] escaped its allowed root during the atomic operation.");
        }
    }

    /** @param list<string> $roots */
    private function rootFor(string $path, array $roots): string
    {
        foreach ($roots as $root) {
            if ($this->withinRoot($path, $root)) return $root;
        }

        throw new InvalidArgumentException("Target [{$path}] is outside every allowed root.");
    }

    private function withinRoot(string $path, string $root): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
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
            if ($this->withinRoot($realAncestor, $root)) {
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
        return ['dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino'], 'type' => (int) $stat['mode'] & 0170000];
    }

    /** @return array{contents:string,mode:int,uid:int,gid:int,identity:array<string,int>} */
    private function expectedCommit(string $staged, string $contents, array $identity, ?array $original): array
    {
        $stat = @lstat($staged);
        if ($stat === false || $this->identity($stat) !== $identity) {
            throw new InvalidArgumentException("Staged target [{$staged}] changed before commit.");
        }

        return [
            'contents' => $contents,
            'mode' => $original['mode'] ?? (int) $stat['mode'],
            'uid' => $original['uid'] ?? (int) $stat['uid'],
            'gid' => $original['gid'] ?? (int) $stat['gid'],
            'identity' => $identity,
        ];
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
     * @param array<string, array<string, array<string,int>>> $ancestors
     * @param list<string> $roots
     * @return list<string>
     */
    private function rollback(array $snapshots, array $committed, array $ancestors, array $roots): array
    {
        $failures = [];
        foreach (array_reverse(array_keys($committed)) as $path) {
            try {
                $original = $snapshots[$path];
                try {
                    $this->assertAncestors($path, $ancestors[$path], $roots);
                } catch (Throwable) {
                    if ($this->matchesSnapshot($path, $committed[$path])) {
                        if (! @unlink($path) || @lstat($path) !== false) {
                            throw new InvalidArgumentException('Could not remove the operation-owned target after an ancestor swap.');
                        }
                    } elseif (@lstat($path) !== false) {
                        throw new InvalidArgumentException('An external target occupies the path after an ancestor swap.');
                    }
                    if ($original !== null) {
                        throw new InvalidArgumentException('The original target requires manual recovery after an ancestor swap.');
                    }
                    continue;
                }
                if (! $this->matchesSnapshot($path, $committed[$path])) {
                    if (@lstat($path) !== false) {
                        throw new InvalidArgumentException('The committed target changed externally.');
                    }
                    if ($original === null) {
                        continue;
                    }
                    $this->restoreMissing($path, $original, $ancestors[$path], $roots);
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
                $this->assertAncestors($path, $ancestors[$path], $roots);
                if (! $this->matchesSnapshot($path, $committed[$path])) {
                    $this->cleanup([$staged], $roots);
                    throw new InvalidArgumentException('The committed target changed before rollback.');
                }
                $this->moveOrVerify($staged['path'], $path, $staged['identity']);
                $this->assertAncestors($path, $ancestors[$path], $roots);
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
    private function restoreMissing(string $path, array $original, array $ancestors, array $roots): void
    {
        $staged = $this->stage($path, $original['contents'], $original['mode'], static function (): void {});
        try {
            $this->assertAncestors($path, $ancestors, $roots);
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
            $this->assertAncestors($path, $ancestors, $roots);
            $this->applyMetadata($path, $original);
            $restored = $this->snapshot($path);
            if ($restored === null
                || $restored['contents'] !== $original['contents']
                || ($restored['mode'] & 0777) !== ($original['mode'] & 0777)) {
                throw new InvalidArgumentException('The restored target did not verify.');
            }
        } catch (Throwable $failure) {
            // Once the sibling temporary has been renamed, its inode is the
            // restored target. A root-wide identity search here would mistake
            // that successful restore for an orphan and delete it.
            $cleanupFailures = $this->cleanup([$staged]);
            if ($cleanupFailures !== []) {
                throw new InvalidArgumentException('Rollback temporary requires manual recovery.', previous: $failure);
            }
            throw $failure;
        }
    }

    /** @param array<int|string, array{path:string, identity:array<string,int>}> $staged @return list<string> */
    private function cleanup(array $staged, array $roots = []): array
    {
        $failures = [];
        foreach ($staged as $temporary) {
            try {
                $owned = $this->sameIdentity($temporary['path'], $temporary['identity'])
                    ? $temporary['path']
                    : $this->findIdentity($temporary['identity'], $roots);
                if ($owned === null) {
                    continue;
                }
                if (! @unlink($owned) || @lstat($owned) !== false) {
                    $failures[] = $owned;
                }
            } catch (Throwable) {
                $failures[] = $temporary['path'];
            }
        }

        return $failures;
    }

    /** Find an operation-owned inode stranded by an ancestor rename inside an allowed root. */
    private function findIdentity(array $identity, array $roots): ?string
    {
        $match = null;
        foreach ($roots as $root) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST,
                );
                foreach ($iterator as $entry) {
                    $path = $entry->getPathname();
                    $stat = @lstat($path);
                    if ($stat === false || $this->identity($stat) !== $identity) continue;
                    if ($match !== null) return null;
                    $match = $path;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return $match;
    }
}
