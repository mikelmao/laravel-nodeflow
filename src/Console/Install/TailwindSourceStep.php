<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\SourceText;

/**
 * Writes the Tailwind @source line for the package's TypeScript source.
 *
 * The one client requirement install fixes rather than reports, for two reasons.
 * It is the worst of the five: Tailwind v4's automatic source detection skips
 * gitignored paths and applications gitignore vendor/, so without this line the
 * build succeeds, the editor renders, and every utility used only by the package
 * source is missing — with utilities the host happens to use elsewhere masking
 * part of the damage. And CSS is line-oriented, so unlike vite.config.ts the
 * insertion point can be proven and the result re-read.
 *
 * The relative path is COMPUTED. '../../vendor/…' is correct only for an entry at
 * resources/css/app.css; from anywhere else that string points outside the
 * project and Tailwind silently matches nothing, which is the same failure this
 * step exists to prevent.
 */
final class TailwindSourceStep implements InstallStep
{
    private const PACKAGE_SOURCE = 'vendor/atram/laravel-nodeflow/resources/js';

    private const CONVENTIONAL_ENTRY = 'resources/css/app.css';

    private const IMPORT_PATTERN = '/^[ \t]*@import\s+[\'"]tailwindcss[\'"].*$/m';

    public function __construct(private Filesystem $files, private string $basePath) {}

    public function describe(): string
    {
        return 'Tailwind @source (package source)';
    }

    public function check(): InstallOutcome
    {
        $entry = $this->entry();

        if ($entry === null) {
            return InstallOutcome::CannotWire;
        }

        $raw = $this->files->get($entry);

        // Comment-stripped, so a host who commented the line out while debugging
        // is told the truth rather than told they are wired. Compared against the
        // FULL computed line — the quoted, entry-relative path apply() would
        // itself write — not merely its PACKAGE_SOURCE tail: the '../' prefix is
        // what decides whether Tailwind resolves anything at all, and a tail-only
        // match reads a host with the wrong number of '../' (or none, or an
        // absolute-looking path) as correctly wired while Tailwind matches
        // nothing.
        if (str_contains(SourceText::withoutCssComments($raw), "'".$this->relativePath($entry)."'")) {
            return InstallOutcome::AlreadyPresent;
        }

        // The anchor must be unique in the raw file too, not only in the stripped
        // one: the insertion offset is computed against the raw bytes, so a second
        // occurrence inside a comment would make the two disagree.
        return preg_match_all(self::IMPORT_PATTERN, $raw) === 1
            ? InstallOutcome::Writable
            : InstallOutcome::CannotWire;
    }

    public function apply(): InstallOutcome
    {
        if ($this->check() !== InstallOutcome::Writable) {
            return $this->check();
        }

        $entry = (string) $this->entry();
        $raw = $this->files->get($entry);

        preg_match(self::IMPORT_PATTERN, $raw, $matches, PREG_OFFSET_CAPTURE);

        $insertAt = $matches[0][1] + strlen($matches[0][0]);

        $this->files->put($entry, substr_replace(
            $raw,
            PHP_EOL."@source '".$this->relativePath($entry)."';",
            $insertAt,
            0,
        ));

        // E11: re-read and prove it. Same full-line comparison as check(), not
        // the PACKAGE_SOURCE tail alone — see the comment there.
        return str_contains(
            SourceText::withoutCssComments($this->files->get($entry)),
            "'".$this->relativePath($entry)."'",
        ) ? InstallOutcome::Wired : InstallOutcome::CannotWire;
    }

    public function snippet(): ?string
    {
        if ($this->check() === InstallOutcome::AlreadyPresent) {
            return null;
        }

        $entry = $this->entry();

        $path = $entry === null
            ? '../../'.self::PACKAGE_SOURCE
            : $this->relativePath($entry);

        return "Add this to your Tailwind CSS entry, after `@import 'tailwindcss';`:"
            .PHP_EOL.PHP_EOL."    @source '".$path."';".PHP_EOL.PHP_EOL
            .'Tailwind v4 skips gitignored paths when detecting sources, and vendor/ '
            .'is gitignored — so without it the build succeeds, the editor renders, '
            .'and every utility used only by our source is missing.';
    }

    /**
     * The host's Tailwind entry, or null.
     *
     * The conventional path wins outright when it is a Tailwind entry, so a host
     * with several stylesheets is not ambiguous. Ambiguity only arises when the
     * convention is absent and more than one candidate imports Tailwind — and then
     * this refuses, because which file is the entry is not install's decision.
     *
     * The fallback search is RECURSIVE under resources/, not a flat glob of
     * resources/css/*.css: hosts are free to keep their CSS entry anywhere under
     * resources/ (that is the whole point of computing the relative path rather
     * than hardcoding it), so a search confined to one conventional directory
     * would never find a non-conventional entry and would report every such host
     * CannotWire.
     */
    private function entry(): ?string
    {
        $conventional = $this->basePath.'/'.self::CONVENTIONAL_ENTRY;

        if ($this->files->exists($conventional) && $this->importsTailwind($conventional)) {
            return $conventional;
        }

        $resources = $this->basePath.'/resources';

        if (! $this->files->isDirectory($resources)) {
            return null;
        }

        $candidates = [];

        foreach ($this->files->allFiles($resources) as $file) {
            if ($file->getExtension() === 'css' && $this->importsTailwind($file->getPathname())) {
                $candidates[] = $file->getPathname();
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function importsTailwind(string $path): bool
    {
        return preg_match(
            self::IMPORT_PATTERN,
            SourceText::withoutCssComments($this->files->get($path)),
        ) === 1;
    }

    /**
     * How many `../` it takes to get from the entry's directory back to the
     * project root.
     *
     * Strips the basePath prefix segment-by-segment, not with str_replace() or
     * ltrim() on the raw string. Either of those removes the basePath's text
     * WHEREVER it occurs, not only as the leading path component — so a nested
     * directory that happens to repeat a segment of the project's own path (e.g.
     * basePath ".../project" and an entry under "resources/project/css/app.css")
     * would have that inner "project" segment stripped too, undercounting the
     * depth and pointing the emitted @source at the wrong directory. The
     * tsconfig step hit the same class of bug from ltrim() first; see its
     * fix-round history.
     *
     * KNOWN LIMIT: an entry path containing a literal ".." segment is not
     * resolved before counting, because no caller of this method ever produces
     * one — entry() only ever concatenates basePath with a fixed literal suffix
     * or a files->glob() result, neither of which contains "..". Resolving it
     * defensively would mean shelling out to realpath(), which fails on paths
     * that do not exist and would make apply()'s own in-progress write path
     * fragile for no reachable benefit.
     */
    private function relativePath(string $entry): string
    {
        $base = $this->segments($this->basePath);
        $directory = $this->segments(dirname($entry));

        $matched = 0;

        while ($matched < count($base) && ($directory[$matched] ?? null) === $base[$matched]) {
            $matched++;
        }

        // Only when every basePath segment matched in order does the entry sit
        // under the project root; otherwise there is no boundary to strip, so the
        // whole directory counts (never reachable via entry(), but never allowed
        // to under-count either).
        $depth = $matched === count($base)
            ? count($directory) - $matched
            : count($directory);

        return str_repeat('../', $depth).self::PACKAGE_SOURCE;
    }

    /** @return list<string> */
    private function segments(string $path): array
    {
        return array_values(array_filter(
            explode('/', trim($path, '/')),
            fn (string $segment): bool => $segment !== '',
        ));
    }
}
