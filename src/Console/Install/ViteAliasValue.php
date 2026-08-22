<?php

namespace Nodeflow\Console\Install;

final class ViteAliasValue
{
    private const KEY = '@nodeflow/editor';

    public static function extract(string $source): ?string
    {
        $values = [];
        $length = strlen($source);
        $offset = 0;

        while ($offset < $length) {
            if (! in_array($source[$offset], ["'", '"', '`'], true)) {
                $offset++;

                continue;
            }

            $quoted = self::quotedAt($source, $offset);

            if ($quoted === null) {
                return null;
            }

            $before = self::previousSignificant($source, $offset - 1);
            $colon = self::nextSignificant($source, $quoted['next']);

            if (
                $quoted['decoded'] === self::KEY
                && in_array($before, ['{', ','], true)
                && ($source[$colon] ?? null) === ':'
            ) {
                $start = self::nextSignificant($source, $colon + 1);
                $value = self::valueAt($source, $start);

                if ($value === null) {
                    return null;
                }

                $span = self::decodeQuotedStrings(trim($value['value']));

                if ($span === null || $span === '') {
                    return null;
                }

                $values[] = $span;
                $offset = $start;

                continue;
            }

            $offset = $quoted['next'];
        }

        return count($values) === 1 ? $values[0] : null;
    }

    /** @return array{value: string, decoded: string, next: int}|null */
    private static function quotedAt(string $source, int $offset): ?array
    {
        $quote = $source[$offset] ?? null;

        if (! in_array($quote, ["'", '"', '`'], true)) {
            return null;
        }

        $value = '';
        $length = strlen($source);

        for ($i = $offset + 1; $i < $length; $i++) {
            if ($source[$i] === '\\') {
                if ($i + 1 >= $length) {
                    return null;
                }

                $value .= $source[$i].$source[$i + 1];
                $i++;

                continue;
            }

            if ($source[$i] === $quote) {
                $decoded = self::decodeJsString($value);

                return $decoded === null
                    ? null
                    : ['value' => $value, 'decoded' => $decoded, 'next' => $i + 1];
            }

            $value .= $source[$i];
        }

        return null;
    }

    /** @return array{value: string, next: int}|null */
    private static function valueAt(string $source, int $offset): ?array
    {
        $opening = ['(' => true, '[' => true, '{' => true];
        $closing = [')' => '(', ']' => '[', '}' => '{'];
        $stack = [];
        $length = strlen($source);

        for ($i = $offset; $i < $length; $i++) {
            $char = $source[$i];

            if (in_array($char, ["'", '"', '`'], true)) {
                $quoted = self::quotedAt($source, $i);

                if ($quoted === null) {
                    return null;
                }

                $i = $quoted['next'] - 1;

                continue;
            }

            if (isset($opening[$char])) {
                $stack[] = $char;

                continue;
            }

            if (isset($closing[$char])) {
                if ($char === '}' && $stack === []) {
                    return ['value' => substr($source, $offset, $i - $offset), 'next' => $i];
                }

                if (array_pop($stack) !== $closing[$char]) {
                    return null;
                }

                continue;
            }

            if ($char === ',' && $stack === []) {
                return ['value' => substr($source, $offset, $i - $offset), 'next' => $i + 1];
            }
        }

        return $stack === []
            ? ['value' => substr($source, $offset), 'next' => $length]
            : null;
    }

    private static function decodeQuotedStrings(string $source): ?string
    {
        $decoded = '';
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            if (! in_array($source[$i], ["'", '"', '`'], true)) {
                $decoded .= $source[$i];

                continue;
            }

            $quoted = self::quotedAt($source, $i);

            if ($quoted === null) {
                return null;
            }

            $decoded .= $source[$i].$quoted['decoded'].$source[$i];
            $i = $quoted['next'] - 1;
        }

        return $decoded;
    }

    private static function decodeJsString(string $value): ?string
    {
        $decoded = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] !== '\\') {
                $decoded .= $value[$i];

                continue;
            }

            $i++;

            if ($i >= $length) {
                return null;
            }

            $escape = $value[$i];

            if ($escape >= '0' && $escape <= '7') {
                $octal = $escape;
                $maximum = $escape <= '3' ? 3 : 2;

                while (
                    strlen($octal) < $maximum
                    && isset($value[$i + 1])
                    && $value[$i + 1] >= '0'
                    && $value[$i + 1] <= '7'
                ) {
                    $octal .= $value[++$i];
                }

                $decoded .= chr(octdec($octal));

                continue;
            }

            $simple = [
                'b' => "\x08",
                'f' => "\x0c",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'v' => "\x0b",
            ];

            if (isset($simple[$escape])) {
                $decoded .= $simple[$escape];

                continue;
            }

            if ($escape === "\n") {
                continue;
            }

            if ($escape === "\r") {
                if (($value[$i + 1] ?? null) === "\n") {
                    $i++;
                }

                continue;
            }

            if ($escape === 'x') {
                $hex = substr($value, $i + 1, 2);

                if (strlen($hex) !== 2 || ! ctype_xdigit($hex)) {
                    return null;
                }

                $decoded .= self::codepointToUtf8(hexdec($hex));
                $i += 2;

                continue;
            }

            if ($escape === 'u') {
                $unicode = self::unicodeEscapeAt($value, $i + 1);

                if ($unicode === null) {
                    return null;
                }

                $decoded .= self::codepointToUtf8($unicode['codepoint']);
                $i = $unicode['last'];

                continue;
            }

            $decoded .= $escape;
        }

        return $decoded;
    }

    /** @return array{codepoint: int, last: int}|null */
    private static function unicodeEscapeAt(string $value, int $offset): ?array
    {
        if (($value[$offset] ?? null) === '{') {
            $end = strpos($value, '}', $offset + 1);

            if ($end === false) {
                return null;
            }

            $hex = substr($value, $offset + 1, $end - $offset - 1);

            if ($hex === '' || strlen($hex) > 6 || ! ctype_xdigit($hex)) {
                return null;
            }

            $codepoint = hexdec($hex);

            return $codepoint <= 0x10FFFF && ! ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)
                ? ['codepoint' => $codepoint, 'last' => $end]
                : null;
        }

        $hex = substr($value, $offset, 4);

        if (strlen($hex) !== 4 || ! ctype_xdigit($hex)) {
            return null;
        }

        return ['codepoint' => hexdec($hex), 'last' => $offset + 3];
    }

    private static function codepointToUtf8(int $codepoint): string
    {
        if ($codepoint <= 0x7F) {
            return chr($codepoint);
        }

        if ($codepoint <= 0x7FF) {
            return chr(0xC0 | ($codepoint >> 6))
                .chr(0x80 | ($codepoint & 0x3F));
        }

        if ($codepoint <= 0xFFFF) {
            return chr(0xE0 | ($codepoint >> 12))
                .chr(0x80 | (($codepoint >> 6) & 0x3F))
                .chr(0x80 | ($codepoint & 0x3F));
        }

        return chr(0xF0 | ($codepoint >> 18))
            .chr(0x80 | (($codepoint >> 12) & 0x3F))
            .chr(0x80 | (($codepoint >> 6) & 0x3F))
            .chr(0x80 | ($codepoint & 0x3F));
    }

    private static function nextSignificant(string $source, int $offset): int
    {
        $length = strlen($source);

        while ($offset < $length && ctype_space($source[$offset])) {
            $offset++;
        }

        return $offset;
    }

    private static function previousSignificant(string $source, int $offset): ?string
    {
        while ($offset >= 0 && ctype_space($source[$offset])) {
            $offset--;
        }

        return $offset >= 0 ? $source[$offset] : null;
    }
}
