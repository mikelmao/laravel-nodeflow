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
 * Every file is rendered and parse-checked, then every existing-target output
 * path is containment-checked, BEFORE anything is written (E51/E52). A parse
 * or path refusal therefore cannot leave a partially refreshed package.
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

        // An existing target can contain a nested symlink at ANY output
        // directory. Resolve every destination before the first write so a
        // late refusal cannot leave composer.json/README already replaced.
        // A missing target cannot contain such a link, so it is created only
        // after the entirely in-memory render/parse phase above.
        $root = null;
        $paths = [];

        if ($this->files->isDirectory($target->absolutePath)) {
            $root = HostPath::root($target->absolutePath);
            $paths = $this->resolveOutputPaths($root, array_keys($files));
        }

        $matchingPackage = isset($paths['composer.json'])
            && $this->composerNameAt($paths['composer.json']) === $target->composerName;

        if ($matchingPackage) {
            $files['composer.json'] = $this->mergeMatchingComposerJson(
                $paths['composer.json'],
                $files['composer.json'],
                $target->providerClass,
            );
        }

        $this->files->ensureDirectoryExists($target->absolutePath);

        $root ??= HostPath::root($target->absolutePath);
        $paths = $paths !== [] ? $paths : $this->resolveOutputPaths($root, array_keys($files));

        // An empty sibling to the provider file, per the emitted tree's own
        // shape — no stub renders into it, so it is created directly.
        $this->files->ensureDirectoryExists($paths['src/Nodes']);

        foreach ($files as $relative => $contents) {
            $path = $paths[$relative];

            // E43's matching-package state is a merge, not a reinitialise:
            // a later extraction must not erase earlier $nodes entries (or
            // any other package-owned customisation) before adding its own.
            if ($matchingPackage && $this->files->exists($path)
                && ($relative !== 'composer.json' || $this->files->get($path) === $contents)) {
                continue;
            }

            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $contents);
        }
    }

    /**
     * @param  list<string>  $files
     * @return array<string, string>
     */
    private function resolveOutputPaths(HostPath $root, array $files): array
    {
        $paths = ['src/Nodes' => $root->resolveWithin('src/Nodes')];

        foreach ($files as $relative) {
            $paths[$relative] = $root->resolveWithin($relative);
        }

        return $paths;
    }

    private function composerNameAt(string $path): ?string
    {
        if (! $this->files->isFile($path)) {
            return null;
        }

        $decoded = json_decode($this->files->get($path), true);
        $name = is_array($decoded) ? ($decoded['name'] ?? null) : null;

        return is_string($name) ? $name : null;
    }

    private function mergeMatchingComposerJson(string $path, string $rendered, string $providerClass): string
    {
        $original = $this->files->get($path);
        $existing = json_decode($original, true);
        $defaults = json_decode($rendered, true);

        if (! is_array($existing) || ! is_array($defaults)) {
            return $rendered;
        }

        $merged = $this->addMissingComposerValues($existing, $defaults);

        if (! is_array($merged['extra'] ?? null)) {
            $merged['extra'] = [];
        }

        if (! is_array($merged['extra']['laravel'] ?? null)) {
            $merged['extra']['laravel'] = [];
        }

        $providers = $merged['extra']['laravel']['providers'] ?? [];

        if (! is_array($providers)) {
            $providers = [];
        }

        if (! in_array($providerClass, $providers, true)) {
            $providers[] = $providerClass;
        }

        $merged['extra']['laravel']['providers'] = $providers;

        if ($merged === $existing) {
            return $original;
        }

        return json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    }

    private function addMissingComposerValues(array $existing, array $defaults): array
    {
        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $existing)) {
                $existing[$key] = $value;

                continue;
            }

            if (is_array($existing[$key]) && is_array($value)
                && ! array_is_list($existing[$key]) && ! array_is_list($value)) {
                $existing[$key] = $this->addMissingComposerValues($existing[$key], $value);
            }
        }

        return $existing;
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
