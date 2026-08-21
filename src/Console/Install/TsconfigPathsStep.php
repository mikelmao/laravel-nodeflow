<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\HostPath;
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
 * A ".." anywhere in the combined baseUrl+target segment list is refused
 * outright rather than resolved: normalizing it away would silently accept a
 * mapping that points above the project root, which is worse than reporting it
 * unwired.
 *
 * FIX ROUND 1 → 2, RECORDED SO THE NEXT READER DOES NOT REPEAT EITHER MISTAKE.
 * Round 1 fixed a false accept from ltrim()/str_starts_with() by checking the
 * TARGET's own segments for a literal "..". That moved the defect rather than
 * closing it: baseUrl and paths are both attacker-or-typo-controlled fields of
 * the same tsconfig.json, and a baseUrl of
 * "vendor/atram/laravel-nodeflow/resources/js/.." — which walks into
 * resources/js and straight back out — was never inspected, because the ".."
 * lived in baseUrl's segments, not the target's. check() now runs the ".."
 * refusal on the MERGED list (baseUrl segments followed by target segments),
 * which closes both the original climb-out and this variant with one rule.
 * Round 1 also let a leading "/" through: HostPath::segments() drops empty and "."
 * segments, and an absolute path's leading "/" produces an empty first
 * segment, so "/vendor/atram/laravel-nodeflow/resources/js" was silently
 * compared as though it were project-relative. An absolute path — on the RAW
 * string, checked before segmenting, because the segment filtering is exactly
 * what hid it — is refused for both the target and baseUrl.
 *
 * KNOWN LIMIT, kept deliberately rather than fixed further: the ".." refusal is
 * a blanket one, not a real path normalization. A baseUrl or target that visits
 * a subdirectory and legitimately backs out of it again (e.g.
 * "resources/js/foo/..", which really does resolve to resources/js) is refused
 * too, even though it is harmless. Over-rejecting a theoretical case is the
 * safe direction; under-rejecting a real one is the bug both rounds exist to
 * close.
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

    private const BASE_MAPPING = '@nodeflow/editor';

    private const WILDCARD_MAPPING = '@nodeflow/editor/*';

    private const MAPPINGS = [self::BASE_MAPPING, self::WILDCARD_MAPPING];

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

        // An absolute baseUrl is a real filesystem path, not a project-relative
        // one, and must never be resolved as though it starts at the project
        // root. Checked on the RAW string, before segmenting: a leading "/"
        // turns into an empty first segment, and HostPath::segments() drops empty
        // segments exactly like it drops ".", which would otherwise hide this.
        if (str_starts_with($baseUrl, '/')) {
            return InstallOutcome::CannotWire;
        }

        $paths = $config['compilerOptions']['paths'] ?? [];

        if (! is_array($paths)) {
            return InstallOutcome::CannotWire;
        }

        $expected = HostPath::segments(self::PACKAGE_SOURCE);
        $baseSegments = HostPath::segments($baseUrl);

        foreach (self::MAPPINGS as $mapping) {
            $targets = $paths[$mapping] ?? null;

            if (! is_array($targets) || $targets === []) {
                return InstallOutcome::CannotWire;
            }

            $target = (string) $targets[0];

            // Same reasoning as the baseUrl check above, applied to the target.
            if (str_starts_with($target, '/')) {
                return InstallOutcome::CannotWire;
            }

            $resolved = array_merge($baseSegments, HostPath::segments($target));

            // Checked on the MERGED list, not the target's segments alone: a
            // baseUrl of "vendor/atram/laravel-nodeflow/resources/js/.." walks
            // into resources/js and straight back out, resolving outside the
            // package, and checking only the target's own segments (as round 1
            // did) never inspects it.
            if (in_array('..', $resolved, true)) {
                return InstallOutcome::CannotWire;
            }

            // A segment-wise prefix compare, not str_starts_with() on the raw
            // string: str_starts_with() would accept "resources/jsx" as a prefix
            // match against "resources/js" because the substring "js" is itself a
            // prefix of "jsx" — a second false accept from the same line.
            if (array_slice($resolved, 0, count($expected)) !== $expected) {
                return InstallOutcome::CannotWire;
            }

            // The wildcard mapping's target must resolve to a wildcard, not
            // merely the package's base directory: the snippet this step prints
            // says so itself — "without the wildcard, a subpath import fails the
            // host's tsc while Vite still builds" — so a target that is missing
            // its own trailing "*" is exactly the quiet failure this check
            // exists to catch, not a pass.
            if ($mapping === self::WILDCARD_MAPPING
                && array_slice($resolved, count($expected)) !== ['*']) {
                return InstallOutcome::CannotWire;
            }
        }

        return InstallOutcome::AlreadyPresent;
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
