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
                $quoted['value'] === self::KEY
                && in_array($before, ['{', ','], true)
                && ($source[$colon] ?? null) === ':'
            ) {
                $start = self::nextSignificant($source, $colon + 1);
                $value = self::valueAt($source, $start);

                if ($value === null) {
                    return null;
                }

                $span = trim($value['value']);

                if ($span === '') {
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

    /** @return array{value: string, next: int}|null */
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
                return ['value' => $value, 'next' => $i + 1];
            }

            $value .= $source[$i];
        }

        return null;
    }

    /** @return array{value: string, next: int}|null */
    private static function valueAt(string $source, int $offset): ?array
    {
        $depth = ['(' => 0, '[' => 0, '{' => 0];
        $closing = [')' => '(', ']' => '[', '}' => '{'];
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

            if (isset($depth[$char])) {
                $depth[$char]++;

                continue;
            }

            if (isset($closing[$char])) {
                $open = $closing[$char];

                if ($char === '}' && $depth[$open] === 0 && self::allZero($depth)) {
                    return ['value' => substr($source, $offset, $i - $offset), 'next' => $i];
                }

                if ($depth[$open] === 0) {
                    return null;
                }

                $depth[$open]--;

                continue;
            }

            if ($char === ',' && self::allZero($depth)) {
                return ['value' => substr($source, $offset, $i - $offset), 'next' => $i + 1];
            }
        }

        return self::allZero($depth)
            ? ['value' => substr($source, $offset), 'next' => $length]
            : null;
    }

    /** @param array<string, int> $depth */
    private static function allZero(array $depth): bool
    {
        return array_sum($depth) === 0;
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
