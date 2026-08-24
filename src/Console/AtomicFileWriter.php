<?php

namespace Nodeflow\Console;

use Closure;
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
    private const RECOVERY_SCAN_LIMIT = 10_000;

    private ?Closure $recoveryEntries;

    /**
     * @param  (callable(string): iterable<object>)|null  $recoveryEntries
     */
    public function __construct(private Filesystem $files, ?callable $recoveryEntries = null)
    {
        $this->recoveryEntries = $recoveryEntries === null
            ? null
            : Closure::fromCallable($recoveryEntries);
    }

    /**
     * @param  array<string, string>  $writes
     * @param  list<string>  $allowedRoots
     * @param  callable(string, string): void  $validator
     * @param  list<string>  $alwaysReplace Existing transaction-owned files that may be replaced even when force is false.
     * @param  array<string, string|null>  $expectedOriginals Exact bytes observed by a preflight planner.
     * @param  array<string, array{contents:string, validator:callable(string,string):void}>  $guards Read-only files that must remain unchanged.
     */
    public function write(
        array $writes,
        array $allowedRoots,
        bool $force,
        callable $validator,
        array $alwaysReplace = [],
        array $expectedOriginals = [],
        array $guards = [],
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
        $guardSnapshots = [];

        try {
            foreach ($guards as $path => $guard) {
                if (array_key_exists($path, $writes)) {
                    throw new InvalidArgumentException("Guarded target [{$path}] is also present in the write set.");
                }
                $this->assertSafePath($path, $roots);
                ($guard['validator'])($guard['contents'], $path);
                $snapshot = $this->snapshot($path);
                if ($snapshot === null || $snapshot['contents'] !== $guard['contents']) {
                    throw new InvalidArgumentException("Guarded target [{$path}] changed after it was planned.");
                }
                $guardSnapshots[$path] = $snapshot;
            }

            foreach ($writes as $path => $contents) {
                if (! is_string($path) || $path === '' || ! is_string($contents)) {
                    throw new InvalidArgumentException('Atomic writes require absolute file paths and string contents.');
                }

                $this->assertSafePath($path, $roots);
                $validator($contents, $path);
                $snapshot = $this->snapshot($path);
                if (array_key_exists($path, $expectedOriginals)
                    && ($snapshot === null || $snapshot['contents'] !== $expectedOriginals[$path])) {
                    throw new InvalidArgumentException("Target [{$path}] changed after it was planned; it was not overwritten.");
                }
                if ($snapshot !== null && ! $force && ! in_array($path, $alwaysReplace, true)) {
                    throw new InvalidArgumentException("Target [{$path}] already exists; no files were changed.");
                }
                $snapshots[$path] = $snapshot;
            }

            foreach ($writes as $path => $contents) {
                $this->files->ensureDirectoryExists(dirname($path));
                $this->assertSafePath($path, $roots);
                $ancestors[$path] = $this->snapshotAncestors($path, $roots);
                $staged[$path] = $this->stage($path, $contents, $snapshots[$path]['mode'] ?? null, $validator);
                $staged[$path]['ancestors'] = $ancestors[$path];
            }

            foreach ($writes as $path => $contents) {
                $this->assertAncestors($path, $ancestors[$path], $roots);
                $snapshot = $snapshots[$path];
                $stagedIdentity = $staged[$path]['identity'];
                $committed[$path] = $this->expectedCommit($staged[$path]['path'], $contents, $stagedIdentity, $snapshot);
                if ($snapshot === null) {
                    $placeholder = $this->exclusiveFile($path);
                    $reservations[$path] = [
                        'path' => $path,
                        'identity' => $placeholder,
                        'basename' => basename($path),
                        'reservation' => true,
                        'ancestors' => $ancestors[$path],
                        'provenRemoved' => false,
                    ];
                    if (! $this->sameIdentity($path, $placeholder)) {
                        throw new InvalidArgumentException("Target [{$path}] changed during generation; it was not overwritten.");
                    }
                    $this->moveOrVerify($staged[$path]['path'], $path, $stagedIdentity);
                    $this->assertAncestors($path, $ancestors[$path], $roots);
                    $reservations[$path]['provenRemoved'] = true;
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
                unset($staged[$path]);
                $this->assertAncestors($path, $ancestors[$path], $roots);
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
                $this->assertGuards($guards, $guardSnapshots, $roots);
            }

            $this->assertGuards($guards, $guardSnapshots, $roots);
            foreach (array_keys($writes) as $path) {
                $this->assertAncestors($path, $ancestors[$path], $roots);
                if (! $this->matchesSnapshot($path, $committed[$path])) {
                    throw new InvalidArgumentException("Committed target [{$path}] changed before final verification.");
                }
            }
        } catch (Throwable $failure) {
            $provenAbsent = [];
            $cleanupFailures = $this->cleanup(
                [...array_values($staged), ...array_values($reservations)],
                $roots,
                $provenAbsent,
            );
            $rollbackFailures = $this->rollback($snapshots, $committed, $ancestors, $roots, $provenAbsent);
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

        $provenAbsent = [];
        $cleanupFailures = $this->cleanup(
            [...array_values($staged), ...array_values($reservations)],
            $roots,
            $provenAbsent,
        );
        if ($cleanupFailures !== []) {
            throw new InvalidArgumentException(
                'Atomic write completed but temporary files require manual recovery ['.implode(', ', $cleanupFailures).'].',
            );
        }
    }

    /**
     * @param array<string, array{contents:string, validator:callable(string,string):void}> $guards
     * @param array<string, array> $snapshots
     * @param list<string> $roots
     */
    private function assertGuards(array $guards, array $snapshots, array $roots): void
    {
        foreach ($guards as $path => $guard) {
            $this->assertSafePath($path, $roots);
            if (! isset($snapshots[$path]) || ! $this->matchesSnapshot($path, $snapshots[$path])) {
                throw new InvalidArgumentException("Guarded target [{$path}] changed during the atomic operation.");
            }
            ($guard['validator'])($guard['contents'], $path);
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

    /** @return array{path:string, identity:array<string,int>, basename:string} */
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

        return ['path' => $temporary, 'identity' => $identity, 'basename' => basename($temporary)];
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
     * @param array<string, true> $provenAbsent
     * @return list<string>
     */
    private function rollback(
        array $snapshots,
        array $committed,
        array $ancestors,
        array $roots,
        array $provenAbsent,
    ): array
    {
        $failures = [];
        foreach (array_reverse(array_keys($committed)) as $path) {
            try {
                $original = $snapshots[$path];
                try {
                    $this->assertAncestors($path, $ancestors[$path], $roots);
                } catch (Throwable) {
                    $search = $this->matchesSnapshot($path, $committed[$path])
                        ? $this->verifiedRecoveryCandidate($path, $committed[$path]['identity'], $roots)
                        : $this->findIdentity($committed[$path]['identity'], $roots);

                    if ($search->status === RecoveryIdentityStatus::Inconclusive) {
                        $failures[] = $this->recoveryFailure('target', $path, $roots, $search);

                        continue;
                    }

                    if ($search->status === RecoveryIdentityStatus::Found) {
                        $verified = $this->verifiedRecoveryCandidate(
                            $search->foundPath(),
                            $committed[$path]['identity'],
                            $roots,
                        );
                        if ($verified->status !== RecoveryIdentityStatus::Found) {
                            $failures[] = $this->recoveryFailure(
                                'target',
                                $path,
                                $roots,
                                $verified,
                            );

                            continue;
                        }
                        $owned = $verified->foundPath();
                        if (! @unlink($owned) || @lstat($owned) !== false) {
                            throw new InvalidArgumentException('Could not remove the operation-owned target after an ancestor swap.');
                        }
                    }
                    if ($search->status === RecoveryIdentityStatus::Absent
                        && ! isset($provenAbsent[$this->identityKey($committed[$path]['identity'])])) {
                        $failures[] = $this->recoveryFailure(
                            'target',
                            $path,
                            $roots,
                            RecoveryIdentityResult::inconclusive('original ancestor chain changed before absence could be proven'),
                        );

                        continue;
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
                        $absence = $this->verifiedRecoveryAbsence(
                            $path,
                            $ancestors[$path],
                            $roots,
                            isset($provenAbsent[$this->identityKey($committed[$path]['identity'])]),
                        );
                        if ($absence->status === RecoveryIdentityStatus::Inconclusive) {
                            $failures[] = $this->recoveryFailure('target', $path, $roots, $absence);
                        }

                        continue;
                    }
                    $this->restoreMissing($path, $original, $ancestors[$path], $roots);
                    continue;
                }
                if ($original === null) {
                    $verified = $this->verifiedRecoveryCandidate(
                        $path,
                        $committed[$path]['identity'],
                        $roots,
                    );
                    if ($verified->status !== RecoveryIdentityStatus::Found) {
                        $failures[] = $this->recoveryFailure('target', $path, $roots, $verified);

                        continue;
                    }
                    $owned = $verified->foundPath();
                    if (! @unlink($owned) || @lstat($owned) !== false) {
                        throw new InvalidArgumentException('Could not remove a newly committed target.');
                    }
                    continue;
                }

                // Original bytes are restoration data, not a newly rendered
                // artifact. They may pre-date today's validator and must be
                // restored exactly even when they are malformed/legacy.
                $staged = null;
                $moved = false;
                try {
                    $staged = $this->stage($path, $original['contents'], $original['mode'], static function (): void {});
                    $this->assertAncestors($path, $ancestors[$path], $roots);
                    if (! $this->matchesSnapshot($path, $committed[$path])) {
                        throw new InvalidArgumentException('The committed target changed before rollback.');
                    }
                    $this->moveOrVerify($staged['path'], $path, $staged['identity']);
                    $moved = true;
                    $this->assertAncestors($path, $ancestors[$path], $roots);
                    $this->applyMetadata($path, $original);
                    $restored = $this->snapshot($path);
                    if ($restored === null
                        || $restored['contents'] !== $original['contents']
                        || ($restored['mode'] & 0777) !== ($original['mode'] & 0777)) {
                        throw new InvalidArgumentException('The restored target did not verify.');
                    }
                } finally {
                    if ($staged !== null && ! $moved) {
                        $cleanup = $this->cleanupRestorationTemporary($staged, $roots, $ancestors[$path]);
                        if ($cleanup !== []) {
                            throw new InconclusiveRecoveryException('Rollback temporary requires manual recovery ['.implode(', ', $cleanup).'].');
                        }
                    }
                }
            } catch (InconclusiveRecoveryException $e) {
                $failures[] = $e->getMessage();
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
        $moved = false;
        $placeholder = null;
        try {
            $this->assertAncestors($path, $ancestors, $roots);
            $placeholder = $this->exclusiveFile($path);
            if (! $this->sameIdentity($path, $placeholder)) {
                throw new InvalidArgumentException('The missing target was raced during rollback.');
            }
            $this->moveOrVerify($staged['path'], $path, $staged['identity']);
            $moved = true;
            $this->assertAncestors($path, $ancestors, $roots);
            $this->applyMetadata($path, $original);
            $restored = $this->snapshot($path);
            if ($restored === null
                || $restored['contents'] !== $original['contents']
                || ($restored['mode'] & 0777) !== ($original['mode'] & 0777)) {
                throw new InvalidArgumentException('The restored target did not verify.');
            }
        } catch (Throwable $failure) {
            $cleanupFailures = $moved ? [] : $this->cleanupRestorationTemporary($staged, $roots, $ancestors);
            if ($placeholder !== null && ! $moved) {
                $placeholderProof = [];
                $cleanupFailures = [
                    ...$cleanupFailures,
                    ...$this->cleanup([[
                        'path' => $path,
                        'identity' => $placeholder,
                        'basename' => basename($path),
                        'reservation' => true,
                        'ancestors' => $ancestors,
                        'provenRemoved' => false,
                    ]], $roots, $placeholderProof),
                ];
            }
            if ($cleanupFailures !== []) {
                throw new InconclusiveRecoveryException(
                    'Rollback temporary requires manual recovery ['.implode(', ', $cleanupFailures).'].',
                    previous: $failure,
                );
            }
            throw $failure;
        }
    }

    /**
     * @param array<int|string, array{path:string, identity:array<string,int>, ancestors?:array<string,array<string,int>>, basename?:string, reservation?:bool, provenRemoved?:bool}> $staged
     * @param list<string> $roots
     * @param array<string, true> $provenAbsent
     * @return list<string>
     */
    private function cleanup(array $staged, array $roots, array &$provenAbsent): array
    {
        $failures = [];
        foreach ($staged as $temporary) {
            try {
                $search = $this->sameIdentity($temporary['path'], $temporary['identity'])
                    ? $this->verifiedRecoveryCandidate($temporary['path'], $temporary['identity'], $roots)
                    : (($temporary['reservation'] ?? false)
                        ? $this->findIdentity($temporary['identity'], $roots)
                        : $this->findTemporary($temporary['identity'], $temporary['basename'] ?? basename($temporary['path']), $roots));

                if ($search->status === RecoveryIdentityStatus::Absent) {
                    $absence = $this->verifiedRecoveryAbsence(
                        $temporary['path'],
                        $temporary['ancestors'] ?? null,
                        $roots,
                        $temporary['provenRemoved'] ?? false,
                    );
                    if ($absence->status === RecoveryIdentityStatus::Inconclusive) {
                        $failures[] = $this->recoveryFailure(
                            ($temporary['reservation'] ?? false) ? 'reservation' : 'temporary',
                            $temporary['path'],
                            $roots,
                            $absence,
                        );

                        continue;
                    }
                    // Scoped absence is valid only while this artifact's
                    // original ancestor chain remains intact. Do not promote
                    // it to a transaction-wide inode proof: a later rollback
                    // action may move that ancestor outside the allowed root.
                    // Only an observed unlink or verified atomic replacement
                    // is safe to reuse after a subsequent ancestor change.
                    if ($temporary['provenRemoved'] ?? false) {
                        $provenAbsent[$this->identityKey($temporary['identity'])] = true;
                    }

                    continue;
                }
                if ($search->status === RecoveryIdentityStatus::Inconclusive) {
                    $failures[] = $this->recoveryFailure(
                        ($temporary['reservation'] ?? false) ? 'reservation' : 'temporary',
                        $temporary['path'],
                        $roots,
                        $search,
                    );

                    continue;
                }

                $verified = $this->verifiedRecoveryCandidate(
                    $search->foundPath(),
                    $temporary['identity'],
                    $roots,
                );
                if ($verified->status !== RecoveryIdentityStatus::Found) {
                    $failures[] = $this->recoveryFailure(
                        ($temporary['reservation'] ?? false) ? 'reservation' : 'temporary',
                        $temporary['path'],
                        $roots,
                        $verified,
                    );

                    continue;
                }
                $owned = $verified->foundPath();
                if (! @unlink($owned) || @lstat($owned) !== false) {
                    $failures[] = $owned;
                } else {
                    $provenAbsent[$this->identityKey($temporary['identity'])] = true;
                }
            } catch (Throwable) {
                $failures[] = $temporary['path'];
            }
        }

        return $failures;
    }

    /**
     * Clean only the exact restoration temporary. If its inode has already
     * been renamed under a non-temporary basename, it is the restored target
     * and must never be deleted. A completed scan that finds neither form is
     * definitive only while the original ancestor chain is still intact;
     * interrupted, bounded, ambiguous, or escaped scans remain explicit
     * manual-recovery failures.
     *
     * @return list<string>
     */
    private function cleanupRestorationTemporary(array $temporary, array $roots, array $ancestors): array
    {
        $temporarySearch = $this->sameIdentity($temporary['path'], $temporary['identity'])
            ? $this->verifiedRecoveryCandidate($temporary['path'], $temporary['identity'], $roots)
            : $this->findTemporary($temporary['identity'], $temporary['basename'], $roots);

        if ($temporarySearch->status === RecoveryIdentityStatus::Inconclusive) {
            return [$this->recoveryFailure('temporary', $temporary['path'], $roots, $temporarySearch)];
        }

        if ($temporarySearch->status === RecoveryIdentityStatus::Found) {
            $verified = $this->verifiedRecoveryCandidate(
                $temporarySearch->foundPath(),
                $temporary['identity'],
                $roots,
            );
            if ($verified->status !== RecoveryIdentityStatus::Found) {
                return [$this->recoveryFailure(
                    'temporary',
                    $temporary['path'],
                    $roots,
                    $verified,
                )];
            }
            $owned = $verified->foundPath();

            return @unlink($owned) && @lstat($owned) === false ? [] : [$owned];
        }

        // A successful rename changes the basename from the private temporary
        // to the real target. Preserve it even if an ancestor moved afterward.
        $installedSearch = $this->findIdentity($temporary['identity'], $roots);
        if ($installedSearch->status === RecoveryIdentityStatus::Inconclusive) {
            return [$this->recoveryFailure('temporary', $temporary['path'], $roots, $installedSearch)];
        }
        if ($installedSearch->status === RecoveryIdentityStatus::Found) {
            $verified = $this->verifiedRecoveryCandidate(
                $installedSearch->foundPath(),
                $temporary['identity'],
                $roots,
            );
            if ($verified->status !== RecoveryIdentityStatus::Found) {
                return [$this->recoveryFailure(
                    'temporary',
                    $temporary['path'],
                    $roots,
                    $verified,
                )];
            }

            return [];
        }

        $absence = $this->verifiedRecoveryAbsence($temporary['path'], $ancestors, $roots);

        return $absence->status === RecoveryIdentityStatus::Absent
            ? []
            : [$this->recoveryFailure('temporary', $temporary['path'], $roots, $absence)];
    }

    /** Find an exact operation temp by inode and unguessable basename inside allowed roots. */
    private function findTemporary(array $identity, string $basename, array $roots): RecoveryIdentityResult
    {
        if (! str_contains($basename, '.nodeflow-tmp-')) return RecoveryIdentityResult::absent();

        return $this->findIdentity($identity, $roots, $basename);
    }

    /** Find an operation-owned inode stranded by an ancestor rename inside an allowed root. */
    private function findIdentity(array $identity, array $roots, ?string $basename = null): RecoveryIdentityResult
    {
        $match = null;
        $inspected = 0;
        $rootIdentities = [];
        foreach ($roots as $root) {
            $rootStat = @lstat($root);
            if ($rootStat === false || is_link($root) || ! is_dir($root)) {
                return RecoveryIdentityResult::inconclusive('allowed root is inaccessible or changed');
            }
            $rootIdentities[$root] = $this->identity($rootStat);

            try {
                $iterator = $this->recoveryEntries !== null
                    ? ($this->recoveryEntries)($root)
                    : new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::SELF_FIRST,
                    );
                foreach ($iterator as $entry) {
                    if (++$inspected > self::RECOVERY_SCAN_LIMIT) {
                        return RecoveryIdentityResult::inconclusive('entry limit reached');
                    }
                    $path = $entry->getPathname();
                    $realParent = realpath(dirname($path));
                    if ($realParent === false || ! $this->withinRoot($realParent, $root)) {
                        return RecoveryIdentityResult::inconclusive('iterator entry escaped its allowed root');
                    }
                    if ($basename !== null && $entry->getBasename() !== $basename) continue;
                    $stat = @lstat($path);
                    if ($stat === false || $this->identity($stat) !== $identity) continue;
                    if ($match !== null) {
                        return RecoveryIdentityResult::inconclusive('ambiguous identity matches');
                    }
                    $match = $path;
                }
            } catch (Throwable) {
                return RecoveryIdentityResult::inconclusive('iterator error');
            }

            $after = @lstat($root);
            if ($after === false || is_link($root) || ! is_dir($root) || $this->identity($after) !== $rootIdentities[$root]) {
                return RecoveryIdentityResult::inconclusive('allowed root is inaccessible or changed');
            }
        }

        foreach ($rootIdentities as $root => $rootIdentity) {
            $after = @lstat($root);
            if ($after === false || is_link($root) || ! is_dir($root) || $this->identity($after) !== $rootIdentity) {
                return RecoveryIdentityResult::inconclusive('allowed root is inaccessible or changed');
            }
        }

        return $match === null
            ? RecoveryIdentityResult::absent()
            : RecoveryIdentityResult::found($match);
    }

    /** @param list<string> $roots */
    private function recoveryFailure(
        string $kind,
        string $path,
        array $roots,
        RecoveryIdentityResult $result,
    ): string {
        $root = $roots[0] ?? '[unavailable root]';
        foreach ($roots as $candidate) {
            if ($this->withinRoot($path, $candidate)) {
                $root = $candidate;

                break;
            }
        }

        return sprintf(
            '%s [%s] under allowed root [%s]: recovery scan was inconclusive (%s)',
            $kind,
            basename($path),
            $root,
            $result->reason ?? 'unknown reason',
        );
    }

    /**
     * Revalidate both ownership and root confinement immediately before a
     * recovery action. A matching inode reached through a swapped symlink is
     * not safe to delete even though the operation originally created it.
     *
     * @param  array<string, int>  $identity
     * @param  list<string>  $roots
     */
    private function verifiedRecoveryCandidate(string $path, array $identity, array $roots): RecoveryIdentityResult
    {
        try {
            $this->assertSafePath($path, $roots);
        } catch (Throwable) {
            return RecoveryIdentityResult::inconclusive('found path escaped its allowed root');
        }

        if (! $this->sameIdentity($path, $identity)) {
            return RecoveryIdentityResult::inconclusive('identity changed after recovery scan');
        }

        return RecoveryIdentityResult::found($path);
    }

    /**
     * An allowed-root scan proves only scoped absence. It becomes definitive
     * for an operation-owned path when the original ancestor chain is intact
     * and that exact lexical path remains absent, or after this transaction
     * has already verified an atomic replacement/deletion of the inode.
     *
     * @param  array<string, array<string, int>>|null  $ancestors
     * @param  list<string>  $roots
     */
    private function verifiedRecoveryAbsence(
        string $path,
        ?array $ancestors,
        array $roots,
        bool $provenRemoved = false,
    ): RecoveryIdentityResult {
        if ($provenRemoved) {
            return RecoveryIdentityResult::absent();
        }
        if ($ancestors === null) {
            return RecoveryIdentityResult::inconclusive('original ancestor identity is unavailable');
        }

        try {
            $this->assertAncestors($path, $ancestors, $roots);
        } catch (Throwable) {
            return RecoveryIdentityResult::inconclusive('original ancestor chain changed before absence could be proven');
        }

        if (@lstat($path) !== false) {
            return RecoveryIdentityResult::inconclusive('original lexical path is no longer absent');
        }

        return RecoveryIdentityResult::absent();
    }

    /** @param array<string, int> $identity */
    private function identityKey(array $identity): string
    {
        return $identity['dev'].':'.$identity['ino'].':'.$identity['type'];
    }
}
