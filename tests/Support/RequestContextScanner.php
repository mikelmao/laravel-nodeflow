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
 *
 * This is not a complete guard, and it is worth naming what it cannot see
 * rather than letting a green run be read as a broader guarantee than it is.
 * At least three request-context forms reach `RunSubject` or `NodeExecution`
 * without ever writing the model name followed by `::` or the bare table
 * name — the only two things this scanner looks for:
 *
 * - `(new RunSubject)->newQuery()` — a query built off an instance, with no
 *   `::` anywhere in the source.
 * - `app(RunSubject::class)->newQuery()` — the negative lookahead that lets a
 *   `hasMany(RunSubject::class)` relation definition through as a mention
 *   also lets this through, even though `app(...)` immediately resolves and
 *   queries it.
 * - A type-hinted, route-bound parameter, e.g. `__invoke(RunSubject $subject)`
 *   — Laravel resolves this via implicit route-model binding with no query
 *   call anywhere in the controller's own source for this scanner to match.
 *
 * No live violation of any of the three exists in `src/` today — this is
 * recorded so the boundary of the guard is known, not because one was found.
 * A future author changing this scanner should extend it to close a form
 * above only if doing so does not also widen the false-positive surface
 * `codeWithoutComments()` exists to keep narrow.
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
            $code = self::codeWithoutComments($contents);

            foreach (self::FORBIDDEN as $model => $table) {
                // Two rules. `Model::` is a static query entry point, with
                // ::class excluded as a mention rather than a query. The table
                // name is a bright line: request-context code never names these
                // tables, in any form. That single rule covers what a list of
                // builder methods cannot — `table('t as a')`, `->join('t', …)`,
                // `->from('t')`, a subquery closure, and raw SQL naming the
                // table inside a string. See open issue G-1 and spec E18.
                $pattern = '/\b'.$model.'::(?!class\b)|'.preg_quote($table, '/').'/i';

                if (preg_match($pattern, $code) === 1) {
                    $violations[] = "{$relative}: {$model}";
                }
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * Source with comments removed, so the rules judge code rather than prose.
     *
     * Without this, the bright-line table rule would flag GraphValidator's
     * comment explaining nodeflow_run_subjects' unique constraint, and the only
     * way to get the suite green would be to delete a comment worth keeping. A
     * scanner that pressures authors into worse comments is a bad scanner.
     *
     * Falls back to the raw source if lexing fails. That fails *closed*: raw
     * text matches strictly more than stripped text, so an unparseable file is
     * reported rather than waved through.
     */
    private static function codeWithoutComments(string $php): string
    {
        try {
            $tokens = token_get_all($php);
        } catch (\Throwable) {
            return $php;
        }

        $code = '';

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                $code .= $token;

                continue;
            }

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];
        }

        return $code;
    }
}
