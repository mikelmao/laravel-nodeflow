<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\SourceText;

/**
 * Verifies both @nodeflow/editor path mappings in the host's tsconfig.
 *
 * WHY COMMENT-STRIPPED AND TRAILING-COMMA-TOLERANT. Measured on the only
 * installed host: json_decode on its tsconfig.json returns null with "Syntax
 * error", because the Laravel React starter kit ships roughly ninety lines of
 * //-commented option documentation in that file. A parser that cannot read it
 * would report the accepted host as unwired.
 *
 * WHY STRUCTURAL AND NOT TEXTUAL. That host maps @nodeflow/editor to
 * ".../resources/js/index.ts"; docs/08-editor-client.md prints
 * ".../resources/js". Both are correct, so the check does NOT compare strings —
 * it splits the mapped path and baseUrl into path segments, drops empty and "."
 * segments, and asks whether the resulting sequence starts with this package's
 * own vendor/atram/laravel-nodeflow/resources/js segments. Comparing segments
 * (not the raw string, and not a naive ltrim()/str_starts_with() pair — both
 * were tried and both silently accepted a broken host; see the fix-round 1 note
 * in the project's task-9 report) is what stops "resources/jsx" from passing as
 * a prefix of "resources/js", and what stops a leading "../" from being read as
 * an ordinary "./" instead of an instruction to climb out of the project.
 *
 * A target whose segments contain a literal ".." is refused outright rather than
 * resolved: normalizing it away would silently accept a mapping that points
 * above the project root, which is worse than reporting it unwired.
 *
 * VERIFY-ONLY (E20). A JSON round-trip would write the file back without those
 * ninety lines of comments, which are documentation the host owns.
 *
 * KNOWN LIMIT: baseUrl is honoured only as a literal segment prefix. A tsconfig
 * using "extends" to inherit its paths from another file reads as unwired here,
 * because this does not follow the chain. The failure direction is safe — a
 * message, not a silent pass.
 */
final class TsconfigPathsStep implements InstallStep
{
    public const PATH = 'tsconfig.json';

    private const PACKAGE_SOURCE = 'vendor/atram/laravel-nodeflow/resources/js';

    private const MAPPINGS = ['@nodeflow/editor', '@nodeflow/editor/*'];

    public function __construct(private Filesystem $files, private string $basePath) {}

    public function describe(): string
    {
        return 'tsconfig paths (@nodeflow/editor)';
    }

    public function check(): InstallOutcome
    {
        $config = $this->decoded();

        if ($config === null) {
            return InstallOutcome::CannotWire;
        }

        $baseUrl = (string) ($config['compilerOptions']['baseUrl'] ?? '.');
        $paths = $config['compilerOptions']['paths'] ?? [];

        if (! is_array($paths)) {
            return InstallOutcome::CannotWire;
        }

        $expected = self::segments(self::PACKAGE_SOURCE);
        $baseSegments = self::segments($baseUrl);

        foreach (self::MAPPINGS as $mapping) {
            $targets = $paths[$mapping] ?? null;

            if (! is_array($targets) || $targets === []) {
                return InstallOutcome::CannotWire;
            }

            $targetSegments = self::segments((string) $targets[0]);

            // A literal ".." segment climbs out of the project. Refuse it
            // outright rather than resolve it: ltrim($value, './') used to strip
            // it as if it were just another "./" (it strips a run of "." and "/"
            // characters, not the two-character sequence "./"), which silently
            // accepted a mapping pointing above the project root.
            if (in_array('..', $targetSegments, true)) {
                return InstallOutcome::CannotWire;
            }

            $resolved = array_merge($baseSegments, $targetSegments);

            // A segment-wise prefix compare, not str_starts_with() on the raw
            // string: str_starts_with() would accept "resources/jsx" as a prefix
            // match against "resources/js" because the substring "js" is itself a
            // prefix of "jsx" — a second false accept from the same line.
            if (array_slice($resolved, 0, count($expected)) !== $expected) {
                return InstallOutcome::CannotWire;
            }
        }

        return InstallOutcome::AlreadyPresent;
    }

    /**
     * Splits a tsconfig path (or baseUrl) into path segments, dropping empty and
     * "." segments. A literal ".." segment is deliberately kept, not dropped —
     * check() must see it in order to refuse it.
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(
            explode('/', $path),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));
    }

    /** Verify-only: check() never returns Writable, so this is unreachable. */
    public function apply(): InstallOutcome
    {
        return $this->check();
    }

    public function snippet(): ?string
    {
        if ($this->check() === InstallOutcome::AlreadyPresent) {
            return null;
        }

        return <<<'JSONC'
        // tsconfig.json — compilerOptions.paths. Both mappings are needed: without
        // the wildcard, a subpath import fails the host's tsc while Vite still
        // builds, so the failure is quiet.
        {
          "compilerOptions": {
            "paths": {
              "@nodeflow/editor": ["./vendor/atram/laravel-nodeflow/resources/js"],
              "@nodeflow/editor/*": ["./vendor/atram/laravel-nodeflow/resources/js/*"]
            }
          }
        }
        JSONC;
    }

    /** JSONC in, array out, or null if there is nothing parseable here. */
    private function decoded(): ?array
    {
        $path = $this->basePath.'/'.self::PATH;

        if (! $this->files->exists($path)) {
            return null;
        }

        $json = SourceText::withoutJsComments($this->files->get($path));

        // Trailing commas are legal in JSONC and fatal to json_decode. The demo's
        // real tsconfig has one after its "paths" block.
        $json = (string) preg_replace('/,(\s*[}\]])/', '$1', $json);

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
