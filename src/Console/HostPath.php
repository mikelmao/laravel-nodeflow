<?php

namespace Nodeflow\Console;

/**
 * The one place path arithmetic lives.
 *
 * WHY. Two of Plan 5's fix-round defects (R13, R15) were path bugs in separate
 * install steps whose logic was copy-pasted rather than shared (G-6), and the
 * characteristic bug of this codebase is a substring test standing in for real
 * path handling — it has now appeared eight times. Every comparison here is
 * segment-wise, and containment is CANONICAL (E51): an in-host symlink whose
 * target escapes the root is not "inside" it, because E29 requires a scaffolded
 * package to be committed with the host.
 */
final class HostPath
{
    private function __construct(private readonly string $root) {}

    /** @throws \InvalidArgumentException when the root does not exist */
    public static function root(string $path): self
    {
        $real = realpath($path);

        if ($real === false) {
            throw new \InvalidArgumentException("Host root [{$path}] does not exist.");
        }

        return new self($real);
    }

    /**
     * Segments, dropping '' and '.' but KEEPING '..'.
     *
     * Keeping '..' is deliberate and matches TsconfigPathsStep's rule rather
     * than TailwindSourceStep's: a caller must be able to see a climb-out in
     * order to refuse it. Dropping it is how R12 turned '../vendor/…' into a
     * match.
     *
     * @return list<string>
     */
    public static function segments(string $path): array
    {
        return array_values(array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));
    }

    /** True only when $candidate canonically resolves inside this root. */
    public function contains(string $candidate): bool
    {
        $resolved = self::canonicalise($candidate);

        if ($resolved === null || in_array('..', self::segments($resolved), true)) {
            return false;
        }

        $root = self::segments($this->root);
        $path = self::segments($resolved);

        // Segment-wise prefix compare, never str_starts_with(): the raw string
        // '/Users/me/project' IS a prefix of '/Users/me/project-evil/app/Foo.php'.
        return count($path) >= count($root)
            && array_slice($path, 0, count($root)) === $root;
    }

    /** How many '../' it takes to get from $directory back to this root. */
    public function relativeDepth(string $directory): int
    {
        $root = self::segments($this->root);
        $path = self::segments(self::canonicalise($directory) ?? $directory);

        $matched = 0;

        while ($matched < count($root) && ($path[$matched] ?? null) === $root[$matched]) {
            $matched++;
        }

        // Only a full prefix match gives a boundary to strip; otherwise the whole
        // directory counts, which over-counts rather than under-counts.
        return $matched === count($root) ? count($path) - $matched : count($path);
    }

    /** @throws \InvalidArgumentException when $relative would land outside the root */
    public function resolveWithin(string $relative): string
    {
        $segments = self::segments($relative);

        if (in_array('..', $segments, true) || str_starts_with($relative, '/')) {
            throw new \InvalidArgumentException(
                "[{$relative}] must be a relative path inside the project and may not contain '..'."
            );
        }

        $candidate = $this->root.'/'.implode('/', $segments);

        if (! $this->contains($candidate)) {
            throw new \InvalidArgumentException(
                "[{$relative}] resolves outside the project root, most likely through a symlink."
            );
        }

        return $candidate;
    }

    public function basePath(): string
    {
        return $this->root;
    }

    /**
     * Absolute, symlink-resolved form of $path, whether or not it exists yet.
     *
     * A path that does not exist is resolved by canonicalising its nearest
     * existing ancestor and re-appending the remaining segments — which is why
     * '..' in the non-existent tail is refused by the caller rather than
     * silently collapsed here.
     */
    private static function canonicalise(string $path): ?string
    {
        $real = realpath($path);

        if ($real !== false) {
            return $real;
        }

        $trailing = [];
        $probe = $path;

        while (true) {
            $parent = dirname($probe);

            if ($parent === $probe) {
                return null;
            }

            array_unshift($trailing, basename($probe));

            $realParent = realpath($parent);

            if ($realParent !== false) {
                return rtrim($realParent, '/').'/'.implode('/', $trailing);
            }

            $probe = $parent;
        }
    }
}
