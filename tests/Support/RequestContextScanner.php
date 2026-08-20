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
 * Two forms count as a query, because both are established idioms here:
 *
 * - `Model::` — a static query entry point. The negative lookahead for `class`
 *   excludes `Model::class`, a class-constant reference (e.g. inside a
 *   `hasMany(RunSubject::class)` relation definition), which is a mention of
 *   the class rather than a query against it. An import or a docblock mention
 *   is likewise not matched.
 * - `DB::table('nodeflow_run_subjects')` and `->table('nodeflow_run_subjects')`
 *   — the raw-table form. This is not hypothetical: AudienceMaterialiser
 *   already writes `DB::table('nodeflow_run_subjects')->insert(...)` for the
 *   bulk path, so it is the pattern a future author reaches for by copying
 *   what is already here — and matching only `Model::` let a plausible
 *   `DB::table("nodeflow_run_subjects")->where("run_id", 1)->get()` in a
 *   controller through with the architecture test still green.
 *
 * The whole pattern is case-insensitive. PHP class and static-property lookups
 * are case-insensitive too, so `runsubject::where(...)` is a working query that
 * a case-sensitive pattern would miss — and `::CLASS` is a working
 * class-constant reference that a case-sensitive lookahead would wrongly flag.
 */
class RequestContextScanner
{
    /** Model name => the table it maps to. */
    public const FORBIDDEN = [
        'RunSubject' => 'nodeflow_run_subjects',
        'NodeExecution' => 'nodeflow_node_executions',
    ];

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

            foreach (self::FORBIDDEN as $model => $table) {
                $pattern = '/\b'.$model.'::(?!class\b)'
                    .'|(?:DB::|->)table\(\s*[\'"]'.$table.'[\'"]/i';

                if (preg_match($pattern, $contents) === 1) {
                    $violations[] = "{$relative}: {$model}";
                }
            }
        }

        sort($violations);

        return $violations;
    }
}
