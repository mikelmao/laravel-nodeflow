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
 * ".../resources/js". Both are correct, so the check resolves the value and asks
 * whether it lands inside the package's resources/js — not whether it equals a
 * string we chose.
 *
 * VERIFY-ONLY (E20). A JSON round-trip would write the file back without those
 * ninety lines of comments, which are documentation the host owns.
 *
 * KNOWN LIMIT: baseUrl is honoured only as a literal prefix. A tsconfig using
 * "extends" to inherit its paths from another file reads as unwired here, because
 * this does not follow the chain. The failure direction is safe — a message, not
 * a silent pass.
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

        $baseUrl = trim((string) ($config['compilerOptions']['baseUrl'] ?? '.'), './');
        $paths = $config['compilerOptions']['paths'] ?? [];

        if (! is_array($paths)) {
            return InstallOutcome::CannotWire;
        }

        foreach (self::MAPPINGS as $mapping) {
            $targets = $paths[$mapping] ?? null;

            if (! is_array($targets) || $targets === []) {
                return InstallOutcome::CannotWire;
            }

            $resolved = ltrim(trim((string) $targets[0]), './');

            if ($baseUrl !== '') {
                $resolved = $baseUrl.'/'.$resolved;
            }

            if (! str_starts_with($resolved, self::PACKAGE_SOURCE)) {
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
