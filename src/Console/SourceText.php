<?php

namespace Nodeflow\Console;

/**
 * Comment stripping for host configuration files.
 *
 * WHY. `nodeflow:install` verifies three host settings it cannot safely edit, and
 * it verifies them by matching text. Matching raw text reports a host who
 * commented a setting out while debugging as correctly wired. The package already
 * settled this question once: tests/Support/RequestContextScanner.php runs
 * token_get_all() and drops T_COMMENT/T_DOC_COMMENT before matching, for exactly
 * this reason. There is no PHP tokeniser for TypeScript, so this scans characters
 * instead and copies string and template literals whole — a `//` inside a URL and
 * a `/*` inside a message must both survive.
 *
 * KNOWN LIMIT: a regular-expression literal containing `//` or the start of a
 * block comment is treated as a comment and truncated. Vite and tsconfig files do
 * not normally contain one, and the failure direction is safe — a truncated file
 * reports a wired host as unwired, which is a message rather than a silent pass.
 * Do not "fix" this by loosening the string handling.
 */
final class SourceText
{
    public static function withoutJsComments(string $source): string
    {
        $out = '';
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $char = $source[$i];
            $next = $source[$i + 1] ?? '';

            if ($char === '"' || $char === "'" || $char === '`') {
                $out .= $char;
                $i++;

                while ($i < $length) {
                    $out .= $source[$i];

                    // An escaped character can be the quote itself, so consume both
                    // or the scanner ends the string early and treats the rest of
                    // the file as string content — under which every check passes.
                    if ($source[$i] === '\\') {
                        $out .= $source[$i + 1] ?? '';
                        $i += 2;

                        continue;
                    }

                    if ($source[$i] === $char) {
                        $i++;

                        break;
                    }

                    $i++;
                }

                continue;
            }

            if ($char === '/' && $next === '/') {
                while ($i < $length && $source[$i] !== "\n") {
                    $i++;
                }

                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($source, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;

                continue;
            }

            $out .= $char;
            $i++;
        }

        return $out;
    }

    /**
     * CSS has block comments only, so a scanner is unnecessary — and would be
     * wrong: an unquoted url(https://…) contains a `//` that is not a comment.
     */
    public static function withoutCssComments(string $source): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $source);
    }
}
