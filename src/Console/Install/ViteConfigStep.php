<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\SourceText;

/**
 * Shared plumbing for the two Vite verification steps (ViteAliasStep,
 * ViteDedupeStep): the config filename candidates, the constructor, and the
 * comment-stripped read of whichever candidate exists.
 *
 * Extracted rather than duplicated because the brief warns the two copies would
 * otherwise drift — in particular CONFIG_CANDIDATES and the comment-stripping
 * call in configSource(), which both steps must apply identically for the
 * counterfactuals in ViteStepsTest to mean what they say.
 *
 * Both concrete steps are verify-only (E20): editing an arbitrary vite.config.ts
 * needs a TypeScript AST, which PHP does not have, and E11 permits only an edit
 * whose success can be re-verified. apply() is therefore shared too — check()
 * never returns Writable, so apply() is unreachable and simply mirrors check().
 */
abstract class ViteConfigStep implements InstallStep
{
    public const CONFIG_CANDIDATES = ['vite.config.ts', 'vite.config.js', 'vite.config.mts', 'vite.config.mjs'];

    public function __construct(protected Filesystem $files, protected string $basePath) {}

    abstract public function describe(): string;

    abstract public function check(): InstallOutcome;

    /** Verify-only: check() never returns Writable, so this is unreachable. */
    public function apply(): InstallOutcome
    {
        return $this->check();
    }

    abstract public function snippet(): ?string;

    /** Comment-stripped source of the host's Vite config, or null if there isn't one. */
    protected function configSource(): ?string
    {
        foreach (static::CONFIG_CANDIDATES as $candidate) {
            $path = $this->basePath.'/'.$candidate;

            if ($this->files->exists($path)) {
                return SourceText::withoutJsComments($this->files->get($path));
            }
        }

        return null;
    }
}
