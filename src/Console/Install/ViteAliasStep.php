<?php

namespace Nodeflow\Console\Install;

/**
 * Verifies the Vite alias mapping @nodeflow/editor into the package source.
 *
 * Verify-only, never written (E20). Editing an arbitrary vite.config.ts needs a
 * TypeScript AST, which PHP does not have, and E11 permits only an edit whose
 * success can be re-verified.
 *
 * KNOWN LIMIT, stated rather than implied away: this binds the path to one
 * uncommented alias property, but it still cannot prove that property's object is
 * the actively exported Vite configuration. A second defineConfig, or a dead
 * conditional branch, would satisfy this check.
 */
final class ViteAliasStep extends ViteConfigStep
{
    // Corrected E41: the FULL vendor/... form, not just the atram/... tail. The
    // full string already tolerates a './vendor/…' prefix, because
    // str_contains('./vendor/x', 'vendor/x') is true — so the shorter constant
    // bought no tolerance and only matched paths like '/tmp/packages/atram/…'
    // that the full form correctly rejects.
    private const PACKAGE_SOURCE = 'vendor/atram/laravel-nodeflow/resources/js';

    public function describe(): string
    {
        return 'Vite alias (@nodeflow/editor)';
    }

    public function check(): InstallOutcome
    {
        $source = $this->configSource();

        if ($source === null) {
            return InstallOutcome::CannotWire;
        }

        $value = ViteAliasValue::extract($source);

        return $value !== null && str_contains($value, self::PACKAGE_SOURCE)
            ? InstallOutcome::AlreadyPresent
            : InstallOutcome::CannotWire;
    }

    public function snippet(): ?string
    {
        if ($this->check() === InstallOutcome::AlreadyPresent) {
            return null;
        }

        return <<<'TS'
        // vite.config.ts
        import path from 'node:path'

        export default defineConfig({
            resolve: {
                alias: {
                    '@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js'),
                },
            },
        })
        TS;
    }
}
