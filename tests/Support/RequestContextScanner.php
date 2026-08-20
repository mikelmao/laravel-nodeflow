<?php

namespace Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds queries against models that carry no tenant_id of their own.
 *
 * RunSubject and NodeExecution are the high-volume tables, so they were
 * deliberately given no tenant column (spec E1): they are only ever reachable
 * through a Run, which is tenant-scoped. That makes their isolation structural
 * rather than enforced by a scope — query them directly from a request-context
 * class and there is nothing between the caller and every tenant's rows.
 *
 * Matching on `Model::` catches a static query entry point while ignoring an
 * import or a docblock mention, which are not queries. The negative lookahead
 * for `class` additionally excludes `Model::class` — a class-constant
 * reference (e.g. inside a `hasMany(RunSubject::class)` relation definition)
 * is a mention of the class, not a query against it.
 */
class RequestContextScanner
{
    public const FORBIDDEN = ['RunSubject', 'NodeExecution'];

    /**
     * @param  string  $root  directory to scan
     * @param  string[]  $allowedPathFragments  path fragments exempt from the rule
     * @return string[] sorted "relative/path.php: ModelName", one per (file, model)
     */
    public static function violations(string $root, array $allowedPathFragments): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $violations = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            foreach ($allowedPathFragments as $fragment) {
                if (str_contains($path, $fragment)) {
                    continue 2;
                }
            }

            $contents = file_get_contents($file->getPathname());
            $relative = ltrim(str_replace(str_replace('\\', '/', $root), '', $path), '/');

            foreach (self::FORBIDDEN as $model) {
                if (preg_match('/\b'.$model.'::(?!class\b)/', $contents) === 1) {
                    $violations[] = "{$relative}: {$model}";
                }
            }
        }

        sort($violations);

        return $violations;
    }
}
