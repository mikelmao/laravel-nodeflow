<?php

namespace Nodeflow\Console\Install;

use Nodeflow\Console\PhpNameResolver;
use Throwable;

/** Token-scoped validation for the host provider shape generators depend on. */
final class ProviderStructureInspector
{
    /** @var int[] */
    private const IGNORED_CODE_TOKENS = [
        T_WHITESPACE,
        T_COMMENT,
        T_DOC_COMMENT,
        T_CONSTANT_ENCAPSED_STRING,
        T_ENCAPSED_AND_WHITESPACE,
        T_START_HEREDOC,
        T_END_HEREDOC,
    ];

    public static function valid(string $source, string $expectedNamespace): bool
    {
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (Throwable) {
            return false;
        }

        if (self::namespaceCount($tokens) !== 1) {
            return false;
        }
        if (self::importsFunctionNamed($tokens, 'app')) {
            return false;
        }
        if (self::declaresNamespaceFunctionNamed($tokens, 'app')) {
            return false;
        }

        $classes = self::classesNamed($tokens, 'NodeflowServiceProvider');
        if (count($classes) !== 1 || $classes[0]['namespace'] !== $expectedNamespace) {
            return false;
        }

        $class = $classes[0];
        $resolver = PhpNameResolver::forSource($source);
        $name = '(?<parent>\\\\?[A-Za-z_\\x80-\\xff][A-Za-z0-9_\\x80-\\xff]*(?:\\\\[A-Za-z_\\x80-\\xff][A-Za-z0-9_\\x80-\\xff]*)*)';
        if (! $class['topLevel']
            || $class['abstract']
            || preg_match('/^classNodeflowServiceProviderextends'.$name.'(?:implements.+)?$/', $class['declaration'], $match) !== 1
            || $resolver->resolve($match['parent']) !== 'Illuminate\\Support\\ServiceProvider') {
            return false;
        }

        $members = self::members($tokens, $class['open'], $class['close']);
        $propertyOrder = ['$nodes', '$triggerDrivers', '$triggerNodes', '$triggerSources'];
        $last = -1;

        foreach ($propertyOrder as $property) {
            $declarations = $members['properties'][$property] ?? [];
            if (count($declarations) !== 1
                || $declarations[0]['prefix'] !== 'protectedarray'
                || $declarations[0]['position'] <= $last) {
                return false;
            }
            $last = $declarations[0]['position'];
        }

        $boot = $members['methods']['boot'] ?? [];
        $attributes = $members['methods']['subjectAttributes'] ?? [];
        if (count($boot) !== 1 || count($attributes) !== 1) {
            return false;
        }

        $boot = $boot[0];
        $attributes = $attributes[0];
        if ($last >= $boot['function']
            || $boot['function'] >= $attributes['function']
            || $boot['signature'] !== 'publicfunctionboot():void'
            || $attributes['signature'] !== 'protectedfunctionsubjectAttributes():array') {
            return false;
        }

        $required = [];
        $lastPhase = -1;
        $directTriggerCalls = 0;
        foreach (self::directStatements($tokens, $boot['open'], $boot['close']) as $statement) {
            $operation = self::registrationOperation($statement, $resolver);
            if ($operation === false) {
                return false;
            }
            if ($operation !== null) {
                if ($operation['phase'] !== null) {
                    $directTriggerCalls++;
                }
                if ($operation['phase'] !== null && $operation['phase'] < $lastPhase) {
                    return false;
                }
                $lastPhase = max($lastPhase, $operation['phase'] ?? -1);
                if ($operation['required'] !== null) {
                    $required[] = $operation['required'];
                }
            }
        }

        if ($required !== ['nodes', 'drivers', 'trigger-nodes', 'sources', 'attributes']) {
            return false;
        }
        $allTriggerCalls = self::triggerCallCount($tokens, $boot['open'], $boot['close'], $resolver);
        if ($allTriggerCalls === null || $directTriggerCalls !== $allTriggerCalls) {
            return false;
        }

        $attributeStatements = self::directStatements($tokens, $attributes['open'], $attributes['close']);

        return count($attributeStatements) === 1
            && self::isDirectArrayReturn($attributeStatements[0]);
    }

    /** Count every real trigger-family static call; null means ambiguous receiver. */
    private static function triggerCallCount(array $tokens, int $open, int $close, PhpNameResolver $resolver): ?int
    {
        $count = 0;
        for ($index = $open + 1; $index < $close; $index++) {
            $token = $tokens[$index];
            if (! is_array($token) || $token[0] !== T_STRING
                || ! in_array($token[1], ['registerTriggerDrivers', 'registerTriggerNodes', 'registerTriggerSources'], true)) {
                continue;
            }
            $separator = self::previousSignificant($tokens, $index - 1);
            $class = $separator === null ? null : self::previousSignificant($tokens, $separator - 1);
            $arguments = self::nextSignificant($tokens, $index + 1);
            if ($separator === null || ! is_array($tokens[$separator]) || $tokens[$separator][0] !== T_DOUBLE_COLON
                || $arguments === null || $tokens[$arguments] !== '(') {
                continue;
            }
            if ($class === null || ! is_array($tokens[$class])
                || ! in_array($tokens[$class][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                return null;
            }
            if ($resolver->resolve($tokens[$class][1]) === 'Nodeflow\\Nodeflow') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Locate one real, direct, non-static protected array property owned by the
     * exact top-level NodeflowServiceProvider class, or (for generated package
     * providers) a unique concrete Laravel ServiceProvider-derived class.
     *
     * @return array{status: 'valid', position: int, openRaw: int, closeRaw: int}|array{status: 'missing'|'ambiguous'}
     */
    public static function registrationArray(string $source, string $property): array
    {
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (Throwable) {
            return ['status' => 'ambiguous'];
        }

        $named = array_values(array_filter(
            self::classesNamed($tokens, 'NodeflowServiceProvider'),
            static fn (array $class): bool => $class['topLevel'],
        ));
        if ($named !== []) {
            if (count($named) !== 1) {
                return ['status' => 'ambiguous'];
            }
            $classes = $named;
        } else {
            // Extracted/generated package providers may have a package-specific
            // class name. Only fall back to a unique concrete top-level class
            // that demonstrably extends Laravel's ServiceProvider; a helper
            // class must never become a registration target by property shape.
            if (self::namespaceCount($tokens) > 1) {
                return ['status' => 'ambiguous'];
            }
            $resolver = PhpNameResolver::forSource($source);
            $classes = array_values(array_filter(
                self::classesNamed($tokens, null),
                static fn (array $class): bool => self::isServiceProviderClass($class, $resolver),
            ));
            if ($classes === []) {
                return ['status' => 'missing'];
            }
            if (count($classes) !== 1) {
                return ['status' => 'ambiguous'];
            }
        }

        $matches = [];
        foreach ($classes as $class) {
            $depth = 0;
            $memberStart = $class['open'] + 1;
            for ($index = $class['open'] + 1; $index < $class['close']; $index++) {
                $token = $tokens[$index];
                if ($token === '{') { $depth++; continue; }
                if ($token === '}') { $depth--; continue; }
                if ($depth !== 0) continue;
                if ($token === ';') { $memberStart = $index + 1; continue; }
                if (! is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$'.$property) continue;

                $equals = self::nextSignificant($tokens, $index + 1);
                $open = $equals === null ? null : self::nextSignificant($tokens, $equals + 1);
                $close = $open === null || $tokens[$open] !== '[' ? null : self::matchingBracket($tokens, $open, $class['close']);
                $matches[] = [
                    'valid' => self::compactCode($tokens, $memberStart, $index) === 'protectedarray'
                        && $equals !== null && $tokens[$equals] === '='
                        && $open !== null && $tokens[$open] === '[' && $close !== null,
                    'position' => $index,
                    'open' => $open,
                    'close' => $close,
                ];
            }
        }

        if ($matches === []) {
            return ['status' => 'missing'];
        }
        if (count($matches) !== 1 || ! $matches[0]['valid']) {
            return ['status' => 'ambiguous'];
        }

        $offsets = self::rawOffsets($tokens);

        return [
            'status' => 'valid',
            'position' => $offsets[$matches[0]['position']],
            'openRaw' => $offsets[$matches[0]['open']],
            'closeRaw' => $offsets[$matches[0]['close']],
        ];
    }

    private static function matchingBracket(array $tokens, int $open, int $limit): ?int
    {
        $depth = 0;
        for ($index = $open; $index < $limit; $index++) {
            if ($tokens[$index] === '[') {
                $depth++;
            } elseif ($tokens[$index] === ']' && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    private static function isServiceProviderClass(array $class, PhpNameResolver $resolver): bool
    {
        $name = '(?<parent>\\\\?[A-Za-z_\\x80-\\xff][A-Za-z0-9_\\x80-\\xff]*(?:\\\\[A-Za-z_\\x80-\\xff][A-Za-z0-9_\\x80-\\xff]*)*)';

        return $class['topLevel']
            && ! $class['abstract']
            && preg_match('/^class[A-Za-z_\\x80-\\xff][A-Za-z0-9_\\x80-\\xff]*extends'.$name.'(?:implements.+)?$/', $class['declaration'], $match) === 1
            && $resolver->resolve($match['parent']) === 'Illuminate\\Support\\ServiceProvider';
    }

    /** @return array<int, int> token index => raw byte offset */
    private static function rawOffsets(array $tokens): array
    {
        $offsets = [];
        $raw = 0;
        foreach ($tokens as $index => $token) {
            $offsets[$index] = $raw;
            $raw += strlen(is_array($token) ? $token[1] : $token);
        }

        return $offsets;
    }

    private static function namespaceCount(array $tokens): int
    {
        return count(array_filter(
            $tokens,
            static fn (mixed $token): bool => is_array($token) && $token[0] === T_NAMESPACE,
        ));
    }

    private static function importsFunctionNamed(array $tokens, string $wanted): bool
    {
        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            if (! is_array($tokens[$index]) || $tokens[$index][0] !== T_USE) {
                continue;
            }

            $first = self::nextSignificant($tokens, $index + 1);
            $directFunctionList = $first !== null
                && is_array($tokens[$first])
                && $tokens[$first][0] === T_FUNCTION;
            $functionItem = $directFunctionList;
            $short = null;
            $alias = null;
            $readingAlias = false;
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                $token = $tokens[$cursor];
                if ($token === ',' || $token === '}' || $token === ';') {
                    if ($functionItem && strtolower($alias ?? $short ?? '') === strtolower($wanted)) {
                        return true;
                    }
                    $short = $alias = null;
                    $readingAlias = false;
                    $functionItem = $directFunctionList;
                    if ($token === ';') {
                        break;
                    }
                    continue;
                }
                if (! is_array($token)) {
                    continue;
                }
                if ($token[0] === T_FUNCTION) {
                    $functionItem = true;
                    $short = $alias = null;
                    $readingAlias = false;
                    continue;
                }
                if ($token[0] === T_CONST) {
                    $functionItem = false;
                    continue;
                }
                if (! $functionItem) {
                    continue;
                }
                if ($token[0] === T_AS) {
                    $readingAlias = true;
                    continue;
                }
                if (! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }

                $segments = explode('\\', trim($token[1], '\\'));
                $name = end($segments);
                if ($readingAlias) {
                    $alias = $name;
                } else {
                    $short = $name;
                }
            }
        }

        return false;
    }

    /** A same-namespace function wins over Laravel's global app() fallback. */
    private static function declaresNamespaceFunctionNamed(array $tokens, string $wanted): bool
    {
        $classOpenings = [];
        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (! is_array($token)
                || ! in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                || ($token[0] === T_CLASS && self::isClassConstantOrAnonymous($tokens, $index))) {
                continue;
            }
            $open = self::findToken($tokens, '{', $index + 1);
            if ($open !== null) {
                $classOpenings[$open] = true;
            }
        }

        $braceKinds = [];
        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if ($token === '{') {
                $braceKinds[] = isset($classOpenings[$index]) ? 'class' : 'other';
                continue;
            }
            if ($token === '}') {
                array_pop($braceKinds);
                continue;
            }
            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $name = self::nextSignificant($tokens, $index + 1);
            if ($name !== null && $tokens[$name] === '&') {
                $name = self::nextSignificant($tokens, $name + 1);
            }
            if ($name === null
                || ! is_array($tokens[$name])
                || $tokens[$name][0] !== T_STRING
                || strtolower($tokens[$name][1]) !== strtolower($wanted)) {
                continue;
            }
            $parameters = self::nextSignificant($tokens, $name + 1);
            if ($parameters === null || $tokens[$parameters] !== '(') {
                continue;
            }

            // A direct class member named app() is a method and cannot affect
            // unqualified function resolution. Nested named functions can.
            if (($braceKinds[array_key_last($braceKinds)] ?? null) !== 'class') {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{namespace: string, open: int, close: int, topLevel: bool, abstract: bool, declaration: string}> */
    private static function classesNamed(array $tokens, ?string $wanted): array
    {
        $classes = [];
        $namespace = '';
        $namespaceStack = [];
        $namespaceDepth = 0;
        $depth = 0;

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && $token[0] === T_NAMESPACE) {
                [$name, $terminator] = self::namespaceAfter($tokens, $index);
                if ($terminator === null) {
                    return [];
                }
                $index = $terminator;
                if ($tokens[$terminator] === '{') {
                    $depth++;
                    $namespaceStack[$depth] = $namespace;
                }
                $namespace = $name;
                $namespaceDepth = $depth;
                continue;
            }

            if ($token === '{') {
                $depth++;
                continue;
            }
            if ($token === '}') {
                if (array_key_exists($depth, $namespaceStack)) {
                    $namespace = $namespaceStack[$depth];
                    unset($namespaceStack[$depth]);
                }
                $depth--;
                continue;
            }

            if (! is_array($token) || $token[0] !== T_CLASS || self::isClassConstantOrAnonymous($tokens, $index)) {
                continue;
            }

            $nameIndex = self::nextSignificant($tokens, $index + 1);
            if ($nameIndex === null || ! is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) {
                continue;
            }
            $open = self::findToken($tokens, '{', $nameIndex + 1);
            if ($open === null) {
                return [];
            }
            $close = self::matchingBrace($tokens, $open);
            if ($close === null) {
                return [];
            }

            if ($wanted === null || $tokens[$nameIndex][1] === $wanted) {
                $previous = self::previousSignificant($tokens, $index - 1);
                $classes[] = [
                    'namespace' => $namespace,
                    'open' => $open,
                    'close' => $close,
                    'topLevel' => $depth === $namespaceDepth,
                    'abstract' => $previous !== null && is_array($tokens[$previous]) && $tokens[$previous][0] === T_ABSTRACT,
                    'declaration' => self::compactCode($tokens, $index, $open),
                ];
            }
        }

        return $classes;
    }

    /** @return array{0: string, 1: int|null} */
    private static function namespaceAfter(array $tokens, int $index): array
    {
        $name = '';
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === ';' || $token === '{') {
                return [$name, $i];
            }
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $token[1];
            }
        }

        return ['', null];
    }

    private static function isClassConstantOrAnonymous(array $tokens, int $index): bool
    {
        $previous = self::previousSignificant($tokens, $index - 1);

        return $previous !== null
            && is_array($tokens[$previous])
            && in_array($tokens[$previous][0], [T_DOUBLE_COLON, T_NEW], true);
    }

    /**
     * @return array{
     *   properties: array<string, array<int, array{position: int, prefix: string}>>,
     *   methods: array<string, array<int, array{function: int, open: int, close: int, signature: string}>>
     * }
     */
    private static function members(array $tokens, int $open, int $close): array
    {
        $properties = [];
        $methods = [];
        $depth = 0;
        $memberStart = $open + 1;

        for ($index = $open + 1; $index < $close; $index++) {
            $token = $tokens[$index];
            if ($token === '{') {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth--;
                continue;
            }
            if ($depth !== 0) {
                continue;
            }

            if ($token === ';') {
                $memberStart = $index + 1;
                continue;
            }

            if (is_array($token) && $token[0] === T_VARIABLE) {
                $significant = self::tokenIds($tokens, $memberStart, $index);
                $equals = self::nextSignificant($tokens, $index + 1);
                $arrayOpen = $equals === null ? null : self::nextSignificant($tokens, $equals + 1);
                if (in_array(T_PROTECTED, $significant, true)
                    && in_array(T_ARRAY, $significant, true)
                    && $equals !== null && $tokens[$equals] === '='
                    && $arrayOpen !== null && $tokens[$arrayOpen] === '[') {
                    $properties[$token[1]][] = [
                        'position' => $index,
                        'prefix' => self::compactCode($tokens, $memberStart, $index),
                    ];
                }
                continue;
            }

            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = self::nextSignificant($tokens, $index + 1);
            if ($nameIndex !== null && $tokens[$nameIndex] === '&') {
                $nameIndex = self::nextSignificant($tokens, $nameIndex + 1);
            }
            if ($nameIndex === null || ! is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) {
                continue;
            }
            $methodOpen = self::findToken($tokens, '{', $nameIndex + 1, $close);
            if ($methodOpen === null) {
                continue;
            }
            $methodClose = self::matchingBrace($tokens, $methodOpen);
            if ($methodClose === null || $methodClose > $close) {
                continue;
            }
            $methods[$tokens[$nameIndex][1]][] = [
                'function' => $index,
                'open' => $methodOpen,
                'close' => $methodClose,
                'signature' => self::compactCode($tokens, $memberStart, $methodOpen),
            ];
            $index = $methodClose;
            $memberStart = $methodClose + 1;
        }

        return ['properties' => $properties, 'methods' => $methods];
    }

    /** @return int[] */
    private static function tokenIds(array $tokens, int $start, int $end): array
    {
        $ids = [];
        for ($index = $start; $index < $end; $index++) {
            if (is_array($tokens[$index])) {
                $ids[] = $tokens[$index][0];
            }
        }

        return $ids;
    }

    /** @return string[] Complete top-level statements without their semicolons. */
    private static function directStatements(array $tokens, int $open, int $close): array
    {
        $statements = [];
        $statement = '';
        $depth = 0;
        for ($index = $open + 1; $index < $close; $index++) {
            $token = $tokens[$index];
            if ($token === '{') {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth--;
                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            if ($token === ';') {
                if ($statement !== '') {
                    $statements[] = $statement;
                    $statement = '';
                }
                continue;
            }
            if (is_array($token)) {
                if (! in_array($token[0], self::IGNORED_CODE_TOKENS, true)) {
                    $statement .= $token[1];
                }
            } else {
                $statement .= $token;
            }
        }

        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    /** @return array{required: ?string, phase: ?int}|false|null */
    private static function registrationOperation(string $statement, PhpNameResolver $resolver): array|false|null
    {
        $name = '(?<class>\\\\?[A-Za-z_\\x80-\\xff][A-Za-z0-9_\\x80-\\xff]*(?:\\\\[A-Za-z_\\x80-\\xff][A-Za-z0-9_\\x80-\\xff]*)*)';
        if (preg_match('/^'.$name.'::(?<method>register|registerTriggerDrivers|registerTriggerNodes|registerTriggerSources)\\((?<argument>.*)\\)$/s', $statement, $match) === 1) {
            if ($resolver->resolve($match['class']) !== 'Nodeflow\\Nodeflow') {
                return false;
            }

            $calls = [
                'register' => ['$this->nodes', 'nodes', null],
                'registerTriggerDrivers' => ['$this->triggerDrivers', 'drivers', 0],
                'registerTriggerNodes' => ['$this->triggerNodes', 'trigger-nodes', 1],
                'registerTriggerSources' => ['$this->triggerSources', 'sources', 2],
            ];
            [$argument, $required, $phase] = $calls[$match['method']];

            if ($match['argument'] === $argument) {
                return ['required' => $required, 'phase' => $phase];
            }

            if (str_contains($match['argument'], '$this->')) {
                return false;
            }

            return $phase === null ? null : ['required' => null, 'phase' => $phase];
        }

        if (preg_match('/^app\\('.$name.'::class\\)->register\\(\\.\\.\\.\\$this->subjectAttributes\\(\\)\\)$/', $statement, $match) === 1) {
            return $resolver->resolve($match['class']) === 'Nodeflow\\Schema\\SubjectAttributeRegistry'
                ? ['required' => 'attributes', 'phase' => null]
                : false;
        }

        if (str_contains($statement, '$this->nodes')
            || str_contains($statement, '$this->triggerDrivers')
            || str_contains($statement, '$this->triggerNodes')
            || str_contains($statement, '$this->triggerSources')
            || str_contains($statement, '$this->subjectAttributes()')) {
            return false;
        }

        return null;
    }

    private static function isDirectArrayReturn(string $statement): bool
    {
        if (! str_starts_with($statement, 'return[')) {
            return false;
        }

        $depth = 0;
        for ($index = strlen('return'), $length = strlen($statement); $index < $length; $index++) {
            if ($statement[$index] === '[') {
                $depth++;
            } elseif ($statement[$index] === ']') {
                $depth--;
                if ($depth === 0 && $index !== $length - 1) {
                    return false;
                }
                if ($depth < 0) {
                    return false;
                }
            }
        }

        return $depth === 0 && str_ends_with($statement, ']');
    }

    private static function compactCode(array $tokens, int $start, int $end): string
    {
        $code = '';
        for ($index = $start; $index < $end; $index++) {
            $token = $tokens[$index];
            if (is_array($token)) {
                if (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= $token[1];
                }
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    private static function matchingBrace(array $tokens, int $open): ?int
    {
        $depth = 0;
        for ($index = $open, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index] === '{') {
                $depth++;
            }
            if ($tokens[$index] === '}' && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    private static function findToken(array $tokens, string $wanted, int $start, ?int $end = null): ?int
    {
        $end ??= count($tokens);
        for ($index = $start; $index < $end; $index++) {
            if ($tokens[$index] === $wanted) {
                return $index;
            }
            if ($tokens[$index] === ';') {
                return null;
            }
        }

        return null;
    }

    private static function nextSignificant(array $tokens, int $index): ?int
    {
        for ($count = count($tokens); $index < $count; $index++) {
            if (! is_array($tokens[$index]) || ! in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }

    private static function previousSignificant(array $tokens, int $index): ?int
    {
        for (; $index >= 0; $index--) {
            if (! is_array($tokens[$index]) || ! in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }
}
