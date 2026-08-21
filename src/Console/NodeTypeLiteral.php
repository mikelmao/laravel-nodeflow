<?php

namespace Nodeflow\Console;

/**
 * Proves that a node's type() returns a fixed string, or refuses (E36, E10).
 *
 * WHY THIS IS A WHITELIST. type() is the identifier immutable published graph
 * versions and live mid-wait runs resolve through forever. A blacklist of known
 * dangerous shapes is the substring-test mistake in another costume: it accepts
 * everything nobody thought of. So exactly two shapes pass and everything else
 * is refused by name.
 *
 * WHY A STATIC CHECK AT ALL, given extract-node also compares type() before and
 * after the move. Because the comparison is blind to the commonest dangerous
 * shape. Measured:
 *
 *   strtolower(class_basename('App\Nodeflow\Nodes\SendMessage')) -> sendmessage
 *   strtolower(class_basename('Vendor\Pkg\Nodes\SendMessage'))   -> sendmessage
 *
 * A basename-derived type survives a namespace move byte-identical, so the
 * empirical gate passes while the type is still derived from the class name and
 * the author's next rename orphans every published version.
 *
 * WHY EVERYTHING IS SCOPED TO ONE CLASS'S OWN BODY. E36 requires the constant
 * to be found "in the same class body" — a flat, whole-file token scan cannot
 * enforce that. A sibling class declaring a constant of the same name, or a
 * nested anonymous class declaring its own type(), must not be visible to this
 * lookup; a bodiless interface signature that happens to precede the real class
 * must not stop it either. classBody() narrows every subsequent scan to the one
 * named class's own braces before methodBody() or sameClassConstant() ever run.
 */
final class NodeTypeLiteral
{
    public static function resolve(string $source, string $shortClassName): NodeTypeResult
    {
        $tokens = self::significantTokens($source);

        $classBody = self::classBody($tokens, $shortClassName);

        if ($classBody === null) {
            return NodeTypeResult::refused(
                "[{$shortClassName}] was not found as a class declaration in the given source, so "
                .'extraction cannot inspect its type() method.'
            );
        }

        $body = self::methodBody($classBody, 'type');

        if ($body === null) {
            return NodeTypeResult::refused(
                "[{$shortClassName}] declares no type() method in its own class body. A type() "
                .'inherited from a parent or supplied by a trait cannot be proven from this file, '
                ."so extraction refuses it. Declare type() on {$shortClassName} itself."
            );
        }

        // Shape A: return '<literal>';
        if (count($body) === 3
            && $body[0][0] === T_RETURN
            && $body[1][0] === T_CONSTANT_ENCAPSED_STRING
            && $body[2][1] === ';') {
            return self::unquote($body[1][1]);
        }

        // Shape B: return self::CONST; / return static::CONST;
        if (count($body) === 5
            && $body[0][0] === T_RETURN
            && in_array(strtolower($body[1][1]), ['self', 'static'], true)
            && $body[2][0] === T_DOUBLE_COLON
            && $body[3][0] === T_STRING
            && $body[4][1] === ';') {
            return self::sameClassConstant($classBody, $body[3][1], $shortClassName);
        }

        $literals = array_filter($body, fn (array $t) => $t[0] === T_CONSTANT_ENCAPSED_STRING);

        if (count($literals) > 1) {
            return NodeTypeResult::refused(
                'type() concatenates string literals. Even a concatenation of two constants is '
                .'refused, because accepting it would also accept a value built from '
                .'static::class. Inline the finished type as a single literal.'
            );
        }

        return NodeTypeResult::refused(
            'type() does not return a plain string literal or a same-class constant. Published '
            .'flow versions and runs sitting mid-wait resolve through this string forever, so '
            .'extraction refuses anything whose value this command cannot prove is fixed. Either '
            ."inline the literal, or declare a constant on the class and return it."
        );
    }

    /**
     * Comment- and whitespace-free tokens, each normalised to [id, text].
     *
     * Comments are dropped because E36 matches on the stripped stream: a probe
     * confirmed that a body opening with a `//` line emits T_COMMENT, so an
     * exact raw-sequence match would refuse every node whose author explained
     * their type. Whitespace is dropped so the shape match is about syntax
     * rather than formatting.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function significantTokens(string $source): array
    {
        $out = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    continue;
                }

                $out[] = [$token[0], $token[1]];

                continue;
            }

            $out[] = [-1, $token];
        }

        return $out;
    }

    /**
     * The token list strictly inside the named class's own braces, or null.
     *
     * Matched on `class <ShortClassName>` specifically: an anonymous class
     * (`new class ...`) never carries a name token immediately after `class`,
     * so it cannot be mistaken for this one. A sibling class of a different
     * name — however many constants or methods it declares — is simply never
     * inside the returned span, which is what lets methodBody() and
     * sameClassConstant() assume every token they see belongs to this class
     * alone.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     * @return list<array{0: int, 1: string}>|null
     */
    private static function classBody(array $tokens, string $shortClassName): ?array
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_CLASS) {
                continue;
            }

            if (($tokens[$i + 1][0] ?? null) !== T_STRING || $tokens[$i + 1][1] !== $shortClassName) {
                continue;
            }

            $open = null;

            for ($j = $i + 2; $j < $count; $j++) {
                if ($tokens[$j][1] === '{') {
                    $open = $j;

                    break;
                }
            }

            if ($open === null) {
                return null;
            }

            $depth = 0;

            for ($j = $open; $j < $count; $j++) {
                if ($tokens[$j][1] === '{') {
                    $depth++;
                }

                if ($tokens[$j][1] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        return array_values(array_slice($tokens, $open + 1, $j - $open - 1));
                    }
                }
            }

            return null;
        }

        return null;
    }

    /**
     * The token list strictly inside the named method's braces, or null.
     *
     * Brace-matched rather than searched for a closing pattern: an unbalanced
     * scan is how an edit lands in the wrong block, and this class refuses
     * rather than guesses.
     *
     * Only a T_FUNCTION token seen at depth 0 of the given class body counts as
     * one of the class's own methods — one that sits inside a nested anonymous
     * class (itself only reachable from inside another method's body, since
     * anonymous classes are expressions) is at depth 1 or deeper and is not a
     * candidate. A method of a different name is skipped by letting the same
     * depth counter walk through its body like any other tokens, rather than
     * jumping over it, so nested braces of any depth are handled uniformly.
     *
     * A same-named method that is bodiless (an interface signature, or an
     * abstract declaration) does not stop the search — it is simply not a
     * match, and PHP does not allow a class to declare the same method name
     * twice, so scanning continues rather than returning null outright.
     *
     * @param  list<array{0: int, 1: string}>  $classBody
     * @return list<array{0: int, 1: string}>|null
     */
    private static function methodBody(array $classBody, string $method): ?array
    {
        $count = count($classBody);
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            if ($classBody[$i][0] === T_FUNCTION
                && $depth === 0
                && ($classBody[$i + 1][0] ?? null) === T_STRING
                && strtolower($classBody[$i + 1][1]) === $method) {
                $open = null;

                for ($j = $i + 2; $j < $count; $j++) {
                    if ($classBody[$j][1] === '{') {
                        $open = $j;

                        break;
                    }

                    // A ';' before any '{' means an abstract or interface
                    // signature: no body to extract, and no second same-named
                    // method can exist in this class, but keep scanning rather
                    // than guessing that this is the only "type" in the file.
                    if ($classBody[$j][1] === ';') {
                        break;
                    }
                }

                if ($open !== null) {
                    $innerDepth = 0;

                    for ($j = $open; $j < $count; $j++) {
                        if ($classBody[$j][1] === '{') {
                            $innerDepth++;
                        }

                        if ($classBody[$j][1] === '}') {
                            $innerDepth--;

                            if ($innerDepth === 0) {
                                return array_values(array_slice($classBody, $open + 1, $j - $open - 1));
                            }
                        }
                    }

                    return null;
                }

                continue;
            }

            if ($classBody[$i][1] === '{') {
                $depth++;
            }

            if ($classBody[$i][1] === '}') {
                $depth--;
            }
        }

        return null;
    }

    /**
     * Resolves self::CONST / static::CONST to a proven literal, or refuses.
     *
     * Scoped to $classBody (the named class's own tokens) and, within that, to
     * depth 0 — the class's own direct members — so a same-named constant on a
     * sibling class, or one nested inside an anonymous class expression, cannot
     * be attributed to this class.
     *
     * The token immediately after the literal must be ';': the same
     * single-token rule Shape A applies to a `return` statement. Without it,
     * `const TYPE = 'demo.' . static::class;` would be accepted by checking
     * only the token right after '=' — exactly the concatenation shape this
     * guard exists to refuse, just one level of indirection away from the
     * return statement.
     *
     * @param  list<array{0: int, 1: string}>  $classBody
     */
    private static function sameClassConstant(array $classBody, string $name, string $shortClassName): NodeTypeResult
    {
        $count = count($classBody);
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            if ($classBody[$i][0] === T_CONST && $depth === 0) {
                if (($classBody[$i + 1][0] ?? null) === T_STRING && $classBody[$i + 1][1] === $name) {
                    if (($classBody[$i + 2][1] ?? null) !== '=') {
                        break;
                    }

                    if (($classBody[$i + 3][0] ?? null) !== T_CONSTANT_ENCAPSED_STRING) {
                        break;
                    }

                    if (($classBody[$i + 4][1] ?? null) !== ';') {
                        return NodeTypeResult::refused(
                            "the constant {$name} on {$shortClassName} is not a single string literal: "
                            .'its initialiser concatenates a literal with something else (for example '
                            .'static::class). Even a concatenation of two literal strings is refused, '
                            .'because accepting it would also accept a value built from static::class. '
                            .'Inline the finished type as a single literal.'
                        );
                    }

                    return self::unquote($classBody[$i + 3][1]);
                }
            }

            if ($classBody[$i][1] === '{') {
                $depth++;
            }

            if ($classBody[$i][1] === '}') {
                $depth--;
            }
        }

        return NodeTypeResult::refused(
            "type() returns [{$name}], which is not declared as a literal constant in "
            ."[{$shortClassName}]'s own class body. A constant inherited from a parent, reached "
            .'through an interface, or defined on another class cannot be proven from this file. '
            ."Declare `const {$name} = '<your.type>';` on {$shortClassName}, or inline the literal."
        );
    }

    /**
     * Strips the outer quotes and refuses anything carrying a backslash.
     *
     * A node type matches MakeNodeCommand::TYPE_PATTERN — lowercase segments
     * joined by dots or underscores — so it can contain no escape sequence at
     * all. Refusing a backslash is therefore free, and it keeps an escape parser
     * out of the one guard whose failure is unrecoverable.
     */
    private static function unquote(string $literal): NodeTypeResult
    {
        $value = substr($literal, 1, -1);

        if (str_contains($value, '\\')) {
            return NodeTypeResult::refused(
                "type() returns a literal containing a backslash ([{$value}]). A node type is "
                .'lowercase segments joined by dots or underscores, so this cannot be a valid '
                .'type and extraction will not guess at its escape sequences.'
            );
        }

        return NodeTypeResult::proven($value);
    }
}
