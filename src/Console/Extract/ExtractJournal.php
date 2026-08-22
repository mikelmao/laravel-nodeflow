<?php

namespace Nodeflow\Console\Extract;

use Illuminate\Filesystem\Filesystem;

/**
 * Records every mutation `ExtractNodeCommand`'s moves (M1-M7, plus M6a's own
 * abort path) make to the host tree, in the order they happen, so a failure
 * anywhere in that sequence can undo everything rather than leave the host
 * half-moved. This is the ONLY thing standing between a bad extraction and a
 * host tree that no longer matches what the developer had on disk.
 *
 * THREE KINDS OF ENTRY, because each undoes differently:
 *   - a WRITE (recordWrite) of a path that already existed: undone by
 *     restoring the ORIGINAL bytes captured at record time. The caller MUST
 *     call this BEFORE performing the write it is journaling — by the time
 *     restore() runs, the write has already happened and the pre-write bytes
 *     are gone from disk unless this class already has them in memory.
 *   - a CREATE (recordCreate) of a path that did not exist before: undone by
 *     removing it — unlink() for a file, a recursive delete for a directory,
 *     tolerant of the path already being gone (a nested create's own removal
 *     may have already taken it with it; see restore()'s own note).
 *   - a DELETE (recordDelete) of a path the caller is about to remove: undone
 *     by recreating it from the bytes the caller already read before
 *     deleting — the same "capture before you mutate" rule as recordWrite,
 *     just for the opposite operation.
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
    /** @var list<array{op: string, path: string, contents: ?string}> */
    private array $entries = [];

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
        $this->entries[] = [
            'op' => 'write',
            'path' => $path,
            'contents' => $this->files->exists($path) ? $this->files->get($path) : null,
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
        $this->entries[] = ['op' => 'delete', 'path' => $path, 'contents' => $contents];
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
                    'write', 'delete' => $this->putBack($entry['path'], $entry['contents']),
                    'create' => $this->removeCreated($entry['path']),
                    default => null,
                };
            } catch (\Throwable $e) {
                $failures[] = "{$entry['path']}: {$e->getMessage()}";
            }
        }

        $this->entries = [];

        if ($failures !== []) {
            throw new \RuntimeException(
                "restore() could not undo every recorded change; inspect the host by hand:\n"
                .implode("\n", $failures)
            );
        }
    }

    private function putBack(string $path, ?string $contents): void
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
            return;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $contents);
    }

    private function removeCreated(string $path): void
    {
        if ($this->files->isDirectory($path)) {
            $this->files->deleteDirectory($path);

            return;
        }

        if ($this->files->exists($path)) {
            $this->files->delete($path);
        }
    }
}
