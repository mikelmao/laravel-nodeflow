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

    /**
     * PHP, unlike TypeScript, has a real tokeniser, so this uses it rather than
     * scanning characters — the same tool tests/Support/RequestContextScanner.php
     * already uses for the same reason, applied to production code that verifies
     * `app/Providers/NodeflowServiceProvider.php` and `bootstrap/providers.php`
     * rather than scanning for a forbidden pattern.
     *
     * token_get_all() without the TOKEN_PARSE flag is a lenient lexer: it does
     * not validate PHP grammar and in practice does not throw for any string
     * input, so the try/catch below is defensive rather than expected to fire.
     * On failure it returns the input unchanged — which, unlike
     * RequestContextScanner's identical-looking fallback, is NOT the safe
     * direction here (it makes a commented-out needle match again) but is kept
     * for symmetry with that precedent given the near-zero odds of reaching it.
     */
    public static function withoutPhpComments(string $source): string
    {
        try {
            $tokens = token_get_all($source);
        } catch (\Throwable) {
            return $source;
        }

        $out = '';

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                $out .= $token;

                continue;
            }

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $out .= $token[1];
        }

        return $out;
    }
}
