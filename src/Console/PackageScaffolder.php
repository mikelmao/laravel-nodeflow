<?php

namespace Nodeflow\Console;

use Illuminate\Filesystem\Filesystem;

/**
 * Emits an ordinary Composer package that ships Nodeflow nodes.
 *
 * Deliberately no manifest (E9): compatibility comes from `require`, provider
 * loading comes from `extra.laravel.providers`, and a node's identity comes
 * from its own `type()` plus explicit registration — the same three things
 * that make a hand-written package work, because that is all this emits.
 *
 * Every file's content is rendered into memory and every rendered `.php` file
 * is parse-checked BEFORE anything is written to disk (E52). That ordering is
 * what makes "a parse failure leaves nothing behind" true for free: nothing
 * exists yet for a failed render to have left behind, so there is no rollback
 * to get wrong.
 */
final class PackageScaffolder
{
    private const STUB_DIR = __DIR__.'/../../stubs/package';

    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {}

    public function scaffold(PackageTarget $target): void
    {
        // Normalised once, here, rather than at each use site: a namespace
        // carrying a trailing separator (PackageTarget's own docblock says
        // it should not, but this class does not get to assume every caller
        // honours that) would otherwise render `namespace Acme\Widgets\;` —
        // a parse error the pre-write check below would catch, but only
        // for the provider file, while the SAME un-normalised value would
        // silently double the backslash in composer.json's PSR-4 key
        // instead of failing loudly. One rtrim(), used everywhere the raw
        // namespace is rendered, keeps both consistent.
        $namespace = rtrim($target->namespace, '\\');
        $shortClass = $this->shortClassName($target->providerClass);

        // Path (relative to the package root) => rendered content. Built
        // entirely in memory, nothing touches disk until every file below
        // has been rendered and every .php file has proven it parses.
        $files = [
            'composer.json' => $this->renderComposerJson($target, $namespace),
            'README.md' => $this->renderReadme($target, $namespace),
            'src/'.$shortClass.'.php' => $this->renderProvider($namespace, $shortClass),
            'tests/ExampleTest.php' => $this->renderTest($target, $shortClass),
        ];

        if ($target->withJs) {
            $files['package.json'] = $this->renderPackageJson($target);
            $files['tsconfig.json'] = $this->renderTsconfig();
            $files['resources/js/index.ts'] = $this->renderIndexTs($target);
        }

        foreach ($files as $relative => $contents) {
            if (str_ends_with($relative, '.php') && ! $this->parses($contents)) {
                throw new \RuntimeException(
                    "Rendered stub for [{$relative}] does not produce valid PHP; nothing was written."
                );
            }
        }

        // Only now, with every render validated, does anything touch disk.
        $this->files->ensureDirectoryExists($target->absolutePath);

        // Re-resolved through HostPath rather than raw concatenation for
        // every write below: the package root now exists, so this is safe
        // to construct, and every subsequent join goes through
        // resolveWithin() rather than string arithmetic (E51's rule,
        // applied inside the scaffolded package's own root, not just the
        // host's).
        $root = HostPath::root($target->absolutePath);

        // An empty sibling to the provider file, per the emitted tree's own
        // shape — no stub renders into it, so it is created directly.
        $this->files->ensureDirectoryExists($root->resolveWithin('src/Nodes'));

        foreach ($files as $relative => $contents) {
            $path = $root->resolveWithin($relative);
            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $contents);
        }
    }

    private function renderComposerJson(PackageTarget $target, string $namespace): string
    {
        // Every value below goes through json_encode() rather than manual
        // quoting or escaping (F-1): the PSR-4 key needs a literal trailing
        // namespace separator, which JSON must render as a doubled
        // backslash, and json_encode() gets that right for any namespace or
        // constraint text without this class having to reason about it.
        $namespaceKey = json_encode($namespace.'\\');
        $providers = json_encode([$target->providerClass]);
        $name = json_encode($target->composerName);
        $description = json_encode("Nodeflow nodes for {$target->composerName}.");
        $constraint = json_encode($target->nodeflowConstraint);

        return strtr($this->stub('composer.json.stub'), [
            '{{ name }}' => $name,
            '{{ description }}' => $description,
            '{{ nodeflowConstraint }}' => $constraint,
            '{{ namespaceKey }}' => $namespaceKey,
            '{{ providers }}' => $providers,
        ]);
    }

    private function renderProvider(string $namespace, string $shortClass): string
    {
        return strtr($this->stub('provider.stub'), [
            '{{ namespace }}' => $namespace,
            '{{ shortClass }}' => $shortClass,
        ]);
    }

    private function renderTest(PackageTarget $target, string $shortClass): string
    {
        return strtr($this->stub('test.stub'), [
            '{{ shortClass }}' => $shortClass,
            '{{ providerClass }}' => '\\'.$target->providerClass,
        ]);
    }

    private function renderReadme(PackageTarget $target, string $namespace): string
    {
        return strtr($this->stub('README.md.stub'), [
            '{{ name }}' => $target->composerName,
            '{{ namespace }}' => $namespace,
            '{{ providerClass }}' => $target->providerClass,
            '{{ nodeflowConstraint }}' => $target->nodeflowConstraint,
        ]);
    }

    private function renderPackageJson(PackageTarget $target): string
    {
        return strtr($this->stub('package.json.stub'), [
            '{{ name }}' => json_encode($target->composerName),
        ]);
    }

    private function renderTsconfig(): string
    {
        return strtr($this->stub('tsconfig.json.stub'), []);
    }

    private function renderIndexTs(PackageTarget $target): string
    {
        return strtr($this->stub('index.ts.stub'), [
            '{{ name }}' => $target->composerName,
        ]);
    }

    /** The short (unqualified) class name at the end of a fully-qualified one. */
    private function shortClassName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    /**
     * Whether $source is valid PHP. Same TOKEN_PARSE approach as
     * NodeRegistrationWriter::parses(), for the same reason: staying
     * in-process avoids a subprocess per rendered file, and a syntax error
     * is exactly what TOKEN_PARSE catches — nothing rendered here can
     * produce the "empty array element" class of post-parse compile error
     * that made removeFrom() need `php -l` instead.
     */
    private function parses(string $source): bool
    {
        try {
            token_get_all($source, TOKEN_PARSE);

            return true;
        } catch (\ParseError) {
            return false;
        }
    }

    /** Host stub overrides win, the same convention MakeNodeCommand::resolveStubPath() and ProviderStep::stub() follow. */
    private function stub(string $name): string
    {
        $custom = $this->basePath.'/stubs/package/'.$name;

        return $this->files->get(
            $this->files->exists($custom) ? $custom : self::STUB_DIR.'/'.$name
        );
    }
}
