<?php

namespace Nodeflow\Console\Install;

/**
 * Verifies resolve.dedupe lists react, react-dom and @xyflow/react (G-4).
 *
 * Verify-only, never written (E20), for the same reason as ViteAliasStep: an
 * arbitrary vite.config.ts needs a TypeScript AST to edit safely, and this
 * package does not have one.
 *
 * KNOWN LIMIT, stated rather than implied away: this proves the three packages
 * appear inside the dedupe array's own uncommented text. It does NOT prove that
 * array is in the configuration object actually exported — a second
 * defineConfig, or a dead conditional branch, would satisfy this check.
 */
final class ViteDedupeStep extends ViteConfigStep
{
    public function describe(): string
    {
        return 'Vite resolve.dedupe (react, react-dom, @xyflow/react)';
    }

    /**
     * Matched inside the dedupe array's own text, not across the whole file.
     *
     * Every Vite config in a React application mentions react somewhere — an
     * import of @vitejs/plugin-react, an optimizeDeps.include list — so a
     * whole-file search reports essentially every host as wired. Bounding the
     * match to the array is what makes this check mean anything.
     */
    public function check(): InstallOutcome
    {
        $source = $this->configSource();

        if ($source === null) {
            return InstallOutcome::CannotWire;
        }

        $offset = strpos($source, 'dedupe');

        if ($offset === false) {
            return InstallOutcome::CannotWire;
        }

        $end = strpos($source, ']', $offset);

        if ($end === false) {
            return InstallOutcome::CannotWire;
        }

        $list = substr($source, $offset, $end - $offset);

        foreach (['react', 'react-dom', '@xyflow/react'] as $package) {
            if (! str_contains($list, "'{$package}'") && ! str_contains($list, "\"{$package}\"")) {
                return InstallOutcome::CannotWire;
            }
        }

        return InstallOutcome::AlreadyPresent;
    }

    public function snippet(): ?string
    {
        if ($this->check() === InstallOutcome::AlreadyPresent) {
            return null;
        }

        return <<<'TS'
        // vite.config.ts — required when the package is symlinked for local
        // development. Vite resolves the symlink to its real path, so a bare
        // `react` import inside the package source can resolve from the package's
        // own node_modules (which exists for Vitest and tsc) instead of yours.
        // Two React copies on one page is "Invalid hook call", which reads as a
        // React bug rather than a configuration error.
        export default defineConfig({
            resolve: {
                dedupe: ['react', 'react-dom', '@xyflow/react'],
            },
        })
        TS;
    }
}
