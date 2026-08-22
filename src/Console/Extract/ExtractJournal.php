<?php

namespace Nodeflow\Console\Extract;

use Illuminate\Filesystem\Filesystem;

/**
 * Records every mutation `ExtractNodeCommand`'s moves (M1-M9, plus M6a's own
 * abort path) make to the host tree, in the order they happen, so a failure
 * anywhere in that sequence can undo everything rather than leave the host
 * half-moved. This is the ONLY thing standing between a bad extraction and a
 * host tree that no longer matches what the developer had on disk.
 *
 * FOUR KINDS OF ENTRY, because each undoes differently:
 *   - a WRITE (recordWrite) of a path that already existed: undone by
 *     restoring the ORIGINAL bytes captured at record time. The caller MUST
 *     call this BEFORE performing the write it is journaling — by the time
 *     restore() runs, the write has already happened and the pre-write bytes
 *     are gone from disk unless this class already captured them.
 *   - a CREATE (recordCreate) of a path that did not exist before: undone by
 *     removing it — unlink() for a file, a recursive delete for a directory,
 *     tolerant of the path already being gone (a nested create's own removal
 *     may have already taken it with it; see restore()'s own note).
 *   - a DELETE (recordDelete) of a path the caller is about to remove: undone
 *     by recreating it from the bytes the caller already read before
 *     deleting — the same "capture before you mutate" rule as recordWrite,
 *     just for the opposite operation.
 *   - a SNAPSHOT (recordTree) of a complete path external code may mutate:
 *     undone by replacing its current file, symlink, or recursive directory
 *     tree with the exact state captured before the external process ran.
 *
 * WHY REPLAY IN REVERSE (restore()'s own rule, and the one most worth
 * getting wrong). The moves are not independent: M4 registers a class into a
 * provider M1 only just created; M6 edits a composer.json that already
 * existed before any move ran. Undoing them in the SAME order they
 * happened — oldest first — would try to, say, restore a file's original
 * bytes after an EARLIER (in undo order) step had already deleted the
 * directory that file lives in. Undoing LAST-first is what keeps every
 * single undo operating on ground that still exists at the moment it runs.
 */
final class ExtractJournal
{
    /** restore() completed every undo, but private rollback-storage deletion failed. */
    public const RESTORE_CLEANUP_FAILED = 75;

    /** @var list<array{op: string, path: string, contents?: ?string, mode?: ?int, snapshot?: array<string, mixed>}> */
    private array $entries = [];

    /** Disk-backed tree snapshots live here so a large vendor tree never sits on PHP's heap. */
    private ?string $backupRoot = null;

    private int $snapshotSequence = 0;

    /** Failed restoration keeps the last recovery bytes alive for a deliberate retry/manual recovery. */
    private bool $preserveBackupOnDestruct = false;

    public function __construct(private Filesystem $files) {}

    /**
     * Captures $path's CURRENT bytes before the caller is about to overwrite
     * them. Must be called before the write it journals — the whole point is
     * to have the pre-write bytes in hand for restore() once the real write
     * has already happened on disk.
     *
     * A path that does not yet exist is recorded with null contents rather
     * than refused: this call site's own caller may not always know in
     * advance whether a given path is a first-time write or a genuine
     * overwrite, and treating "did not exist" as "restore by deleting" (see
     * putBack()) is the correct undo for either.
     */
    public function recordWrite(string $path): void
    {
        $exists = $this->files->exists($path);

        $this->entries[] = [
            'op' => 'write',
            'path' => $path,
            'contents' => $exists ? $this->files->get($path) : null,
            'mode' => $exists ? $this->mode($path) : null,
        ];
    }

    /** Records that $path did not exist before and is about to be created (a file or a directory). */
    public function recordCreate(string $path): void
    {
        $this->entries[] = ['op' => 'create', 'path' => $path, 'contents' => null];
    }

    /**
     * Records that $path is about to be deleted, capturing $contents — which
     * the caller must read BEFORE deleting, the same "capture before you
     * mutate" rule recordWrite() follows — so restore() can recreate it
     * byte-for-byte.
     */
    public function recordDelete(string $path, string $contents): void
    {
        $this->entries[] = [
            'op' => 'delete',
            'path' => $path,
            'contents' => $contents,
            'mode' => $this->mode($path),
        ];
    }

    /** Captures a file, symlink, or complete directory tree before an external process mutates it. */
    public function recordTree(string $path): void
    {
        $snapshot = $this->snapshot($path);

        $this->entries[] = [
            'op' => 'snapshot',
            'path' => $path,
            'snapshot' => $snapshot,
        ];
    }

    /** Commits the journal by dropping its rollback data and deleting every disk-backed snapshot. */
    public function discard(): void
    {
        // Committing is deliberately one-way. Once cleanup starts, a failed
        // recursive delete may already have destroyed SOME snapshot bytes;
        // those entries must never remain replayable over the now-verified
        // host. Drop them first and suppress destructor cleanup until the
        // explicit cleanup below has either completed or reported the exact
        // private directory left for manual removal.
        $this->entries = [];
        $this->preserveBackupOnDestruct = $this->backupRoot !== null;

        try {
            $this->deleteBackupRoot();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "The journal was committed, but rollback storage [{$this->backupRoot}] "
                ."could not be removed: {$e->getMessage()}",
                0,
                $e,
            );
        }

        $this->preserveBackupOnDestruct = false;
    }

    public function __destruct()
    {
        if ($this->preserveBackupOnDestruct) {
            return;
        }

        // Explicit discard()/restore() is the ownership contract. This is
        // only a best-effort crash/exception safety net and must never turn
        // object destruction into a second exception.
        try {
            $this->deleteBackupRoot();
        } catch (\Throwable) {
            // Intentionally ignored here; explicit cleanup paths are loud.
        }
    }

    /**
     * Undoes every recorded entry, LAST first (see this class's own docblock
     * for why order matters), then clears the journal.
     *
     * A 'create' entry is undone by DELETING $path, tolerant of it already
     * being gone: an earlier-undone (later-recorded) 'create' for a path
     * NESTED inside this one may already have removed it as part of a
     * recursive directory delete, and re-deleting an already-absent path
     * must be a no-op rather than an error.
     *
     * ONE entry failing to undo must never stop the REST from being
     * attempted: a caller with, say, six recorded mutations and a failure
     * undoing the third still needs the other five undone, or a single bad
     * entry turns a mostly-recoverable failure into a fully unrecovered
     * one. Every failure is collected and raised together, once every
     * entry has had its own chance, rather than either swallowing it
     * silently (which would report success over a host this call could not
     * actually restore) or letting the first one abort the loop.
     *
     * @throws \RuntimeException naming every path that could not be undone
     */
    public function restore(): void
    {
        $failures = [];

        foreach (array_reverse($this->entries) as $entry) {
            try {
                match ($entry['op']) {
                    'write', 'delete' => $this->putBack(
                        $entry['path'],
                        $entry['contents'],
                        $entry['mode'] ?? null,
                    ),
                    'create' => $this->removeCreated($entry['path']),
                    'snapshot' => $this->restoreSnapshot($entry['path'], $entry['snapshot']),
                    default => null,
                };
            } catch (\Throwable $e) {
                $failures[] = "{$entry['path']}: {$e->getMessage()}";
            }
        }

        if ($failures !== []) {
            $this->preserveBackupOnDestruct = $this->backupRoot !== null;
            $recovery = $this->backupRoot === null
                ? 'Rollback entries remain available on this journal; correct the failure and call restore() again.'
                : "Disk-backed rollback snapshots retained at [{$this->backupRoot}]; correct the failure "
                    .'and call restore() again, or use that path for manual recovery.';

            throw new \RuntimeException(
                "restore() could not undo every recorded change; inspect the host by hand:\n"
                .implode("\n", $failures)."\n{$recovery}"
            );
        }

        try {
            $this->deleteBackupRoot();
        } catch (\Throwable $e) {
            // The host entries all restored successfully, so replaying a
            // now-partially-cleaned snapshot would be unsafe. Clear those
            // entries but preserve whatever private temp path remains for
            // explicit manual cleanup, and make that cleanup failure loud.
            $this->entries = [];
            $this->preserveBackupOnDestruct = true;

            throw new \RuntimeException(
                "restore() restored the host but could not remove rollback storage "
                ."[{$this->backupRoot}]: {$e->getMessage()}",
                self::RESTORE_CLEANUP_FAILED,
                $e,
            );
        }

        $this->entries = [];
        $this->preserveBackupOnDestruct = false;
    }

    private function putBack(string $path, ?string $contents, ?int $mode): void
    {
        if ($contents === null) {
            // recordWrite() captured a path that turned out not to exist yet
            // — the correct undo for a write that was really a first-time
            // create in disguise is the same as undoing a genuine 'create'.
            $this->removeCreated($path);

            return;
        }

        // Skip the write entirely when $path already holds exactly these
        // bytes — not merely an optimisation. The mutation this journal
        // exists to undo commonly FAILED before it ever touched the file
        // (a write refused by the filesystem, caught by one of
        // ExtractNodeCommand's own re-verify checks), in which case the
        // file already carries its original, untouched content and a
        // write attempted here would fail for the EXACT SAME reason the
        // original one did — turning a successful, no-op restore into a
        // second failure over bytes that were never actually wrong.
        if ($this->files->exists($path) && $this->files->get($path) === $contents) {
            if ($mode !== null && $this->mode($path) !== $mode) {
                $this->applyMode($path, $mode);
            }

            return;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $written = $this->files->put($path, $contents);

        if ($written === false
            || ! $this->files->exists($path)
            || $this->files->get($path) !== $contents) {
            throw new \RuntimeException("[{$path}] could not be restored byte-for-byte.");
        }

        if ($mode !== null) {
            $this->applyMode($path, $mode);
        }
    }

    private function removeCreated(string $path): void
    {
        if (is_link($path)) {
            $this->files->delete($path);
        } elseif ($this->files->isDirectory($path)) {
            $this->files->deleteDirectory($path);
        } elseif ($this->files->exists($path)) {
            $this->files->delete($path);
        }

        if ($this->pathExists($path)) {
            throw new \RuntimeException("[{$path}] could not be removed completely during restoration.");
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(string $path): array
    {
        if (! $this->pathExists($path)) {
            return ['type' => 'missing'];
        }

        $this->assertBackupStorageDoesNotOverlap($path);
        $directory = $this->snapshotDirectory();
        $manifestPath = $directory.'/manifest.jsonl';
        $manifest = @fopen($manifestPath, 'wb');

        if ($manifest === false) {
            throw new \RuntimeException("[{$manifestPath}] could not be created for rollback storage.");
        }

        $fileSequence = 0;

        try {
            $this->capturePath($path, '', $directory, $manifest, $fileSequence);
        } finally {
            if (! fclose($manifest)) {
                throw new \RuntimeException("[{$manifestPath}] could not be closed after snapshotting.");
            }
        }

        return ['type' => 'stored', 'directory' => $directory];
    }

    /**
     * A TMPDIR beneath (or equal to) the source would make capture recurse
     * into its own growing backup and make restore delete the only recovery
     * bytes before reading them. Allocate the private root, then refuse
     * before creating a snapshot directory or copying a single source byte.
     */
    private function assertBackupStorageDoesNotOverlap(string $path): void
    {
        $backup = $this->backupRoot();

        if ($this->pathContains($path, $backup) || $this->pathContains($backup, $path)) {
            throw new \RuntimeException(
                "Rollback storage [{$backup}] overlaps the tree being snapshotted [{$path}]; "
                .'no source bytes were copied.'
            );
        }
    }

    private function pathContains(string $ancestor, string $candidate): bool
    {
        $ancestor = str_replace('\\', '/', realpath($ancestor) ?: $ancestor);
        $candidate = str_replace('\\', '/', realpath($candidate) ?: $candidate);
        $ancestor = rtrim($ancestor, '/');
        $candidate = rtrim($candidate, '/');

        if (DIRECTORY_SEPARATOR === '\\') {
            $ancestor = strtolower($ancestor);
            $candidate = strtolower($candidate);
        }

        return $candidate === $ancestor || str_starts_with($candidate, $ancestor.'/');
    }

    /** @param array<string, mixed> $snapshot */
    private function restoreSnapshot(string $path, array $snapshot): void
    {
        $this->removeCreated($path);

        if ($snapshot['type'] === 'missing') {
            return;
        }

        if (($snapshot['type'] ?? null) !== 'stored' || ! is_string($snapshot['directory'] ?? null)) {
            throw new \RuntimeException("[{$path}] has an invalid rollback snapshot.");
        }

        $manifestPath = $snapshot['directory'].'/manifest.jsonl';
        $manifest = @fopen($manifestPath, 'rb');

        if ($manifest === false) {
            throw new \RuntimeException("[{$manifestPath}] could not be read during restoration.");
        }

        try {
            while (($line = fgets($manifest)) !== false) {
                try {
                    $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new \RuntimeException("[{$manifestPath}] contains invalid rollback metadata.", 0, $e);
                }

                if (! is_array($entry)) {
                    throw new \RuntimeException("[{$manifestPath}] contains invalid rollback metadata.");
                }

                $this->restoreManifestEntry($path, $snapshot['directory'], $entry);
            }

            if (! feof($manifest)) {
                throw new \RuntimeException("[{$manifestPath}] could not be read completely during restoration.");
            }
        } finally {
            fclose($manifest);
        }
    }

    /**
     * Writes a post-order, newline-delimited manifest. File bytes go into
     * separate data files, so even a multi-gigabyte vendor tree uses only
     * one directory listing and one copied file at a time on PHP's heap.
     * Directories are post-order so restore can populate them while they are
     * writable, then apply their exact original modes after every child.
     *
     * @param resource $manifest
     */
    private function capturePath(
        string $path,
        string $relative,
        string $snapshotDirectory,
        mixed $manifest,
        int &$fileSequence,
    ): void {
        if (is_link($path)) {
            $target = readlink($path);

            if ($target === false) {
                throw new \RuntimeException("[{$path}] is a symlink whose target could not be read.");
            }

            $this->writeManifest($manifest, ['kind' => 'link', 'relative' => $relative, 'target' => $target]);

            return;
        }

        if (is_dir($path)) {
            $mode = $this->mode($path);
            $names = @scandir($path);

            if ($names === false) {
                throw new \RuntimeException("[{$path}] could not be enumerated for restoration.");
            }

            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $childRelative = $relative === '' ? $name : $relative.'/'.$name;
                $this->capturePath($path.'/'.$name, $childRelative, $snapshotDirectory, $manifest, $fileSequence);
            }

            $this->writeManifest($manifest, [
                'kind' => 'directory',
                'relative' => $relative,
                'mode' => $mode,
            ]);

            return;
        }

        if (is_file($path)) {
            $dataDirectory = $snapshotDirectory.'/data';

            if (! is_dir($dataDirectory) && ! @mkdir($dataDirectory, 0700, true) && ! is_dir($dataDirectory)) {
                throw new \RuntimeException("[{$dataDirectory}] could not be created for rollback storage.");
            }

            $dataName = sprintf('%08d', ++$fileSequence);
            $backup = $dataDirectory.'/'.$dataName;

            if (! @copy($path, $backup)) {
                throw new \RuntimeException("[{$path}] could not be copied into rollback storage.");
            }

            @chmod($backup, 0600);

            if (! $this->sameFileBytes($path, $backup)) {
                throw new \RuntimeException("[{$path}] changed or was copied incompletely while being snapshotted.");
            }

            $this->writeManifest($manifest, [
                'kind' => 'file',
                'relative' => $relative,
                'mode' => $this->mode($path),
                'data' => $dataName,
            ]);

            return;
        }

        throw new \RuntimeException("[{$path}] is not a regular file, directory, or symlink and cannot be snapshotted safely.");
    }

    /** @param array<string, mixed> $entry */
    private function restoreManifestEntry(string $root, string $snapshotDirectory, array $entry): void
    {
        $relative = $entry['relative'] ?? null;
        $kind = $entry['kind'] ?? null;

        if (! is_string($relative) || ! is_string($kind) || $this->unsafeRelativePath($relative)) {
            throw new \RuntimeException("[{$root}] has unsafe rollback metadata.");
        }

        $path = $relative === '' ? $root : $root.'/'.$relative;

        if ($kind === 'link') {
            $target = $entry['target'] ?? null;

            if (! is_string($target)) {
                throw new \RuntimeException("[{$path}] has invalid symlink rollback metadata.");
            }

            $this->ensureParentDirectory($path);

            if (! @symlink($target, $path) || readlink($path) !== $target) {
                throw new \RuntimeException("[{$path}] could not be restored as a symlink.");
            }

            return;
        }

        if ($kind === 'file') {
            $data = $entry['data'] ?? null;
            $mode = $entry['mode'] ?? null;

            if (! is_string($data) || ! preg_match('/^\d{8}$/', $data) || ! is_int($mode)) {
                throw new \RuntimeException("[{$path}] has invalid file rollback metadata.");
            }

            $backup = $snapshotDirectory.'/data/'.$data;
            $this->ensureParentDirectory($path);

            if (! @copy($backup, $path) || ! $this->sameFileBytes($backup, $path)) {
                throw new \RuntimeException("[{$path}] could not be restored from its disk-backed snapshot.");
            }

            $this->applyMode($path, $mode);

            return;
        }

        if ($kind === 'directory') {
            $mode = $entry['mode'] ?? null;

            if (! is_int($mode)) {
                throw new \RuntimeException("[{$path}] has invalid directory rollback metadata.");
            }

            if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
                throw new \RuntimeException("[{$path}] could not be recreated as a directory.");
            }

            $this->applyMode($path, $mode);

            return;
        }

        throw new \RuntimeException("[{$path}] has an unknown rollback entry type [{$kind}].");
    }

    /** @param resource $manifest */
    private function writeManifest(mixed $manifest, array $entry): void
    {
        try {
            $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } catch (\JsonException $e) {
            throw new \RuntimeException('A rollback path could not be encoded safely.', 0, $e);
        }

        $written = fwrite($manifest, $line);

        if ($written !== strlen($line)) {
            throw new \RuntimeException('Rollback metadata could not be written completely.');
        }
    }

    private function snapshotDirectory(): string
    {
        $root = $this->backupRoot();
        $directory = $root.'/snapshot-'.sprintf('%08d', ++$this->snapshotSequence);

        if (! @mkdir($directory, 0700)) {
            throw new \RuntimeException("[{$directory}] could not be created for rollback storage.");
        }

        return $directory;
    }

    private function backupRoot(): string
    {
        if ($this->backupRoot !== null) {
            return $this->backupRoot;
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR.'nodeflow-extract-snapshot-'.getmypid().'-'.bin2hex(random_bytes(8));

            if (@mkdir($candidate, 0700)) {
                return $this->backupRoot = $candidate;
            }
        }

        throw new \RuntimeException('A private directory for extraction rollback snapshots could not be created.');
    }

    private function deleteBackupRoot(): void
    {
        if ($this->backupRoot === null) {
            return;
        }

        $root = $this->backupRoot;
        $this->removeCreated($root);
        $this->backupRoot = null;
    }

    private function ensureParentDirectory(string $path): void
    {
        $parent = dirname($path);
        $this->files->ensureDirectoryExists($parent, 0700);

        if (! is_dir($parent) || is_link($parent)) {
            throw new \RuntimeException("[{$parent}] could not be prepared for restoration.");
        }
    }

    private function mode(string $path): int
    {
        $mode = @fileperms($path);

        if ($mode === false) {
            throw new \RuntimeException("[{$path}] permissions could not be read for restoration.");
        }

        return $mode & 07777;
    }

    private function applyMode(string $path, int $mode): void
    {
        if (! @chmod($path, $mode)) {
            throw new \RuntimeException("[{$path}] permissions could not be restored.");
        }

        clearstatcache(true, $path);

        if ($this->mode($path) !== $mode) {
            throw new \RuntimeException("[{$path}] permissions differ after restoration.");
        }
    }

    private function sameFileBytes(string $left, string $right): bool
    {
        $leftHash = @hash_file('sha256', $left);
        $rightHash = @hash_file('sha256', $right);

        return is_string($leftHash) && hash_equals($leftHash, (string) $rightHash);
    }

    private function pathExists(string $path): bool
    {
        return is_link($path) || file_exists($path);
    }

    private function unsafeRelativePath(string $relative): bool
    {
        return str_contains($relative, "\0")
            || str_starts_with($relative, '/')
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $relative) === 1;
    }
}
