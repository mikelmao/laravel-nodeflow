<?php

use Nodeflow\Console\NodeTypeLiteral;

function nodeSource(string $body, string $extra = ''): string
{
    return <<<PHP
    <?php

    namespace App\Nodeflow\Nodes;

    class SendMessage
    {
    {$extra}
        public static function type(): string
        {
    {$body}
        }
    }
    PHP;
}

it('proves a single-quoted inline literal', function () {
    $result = NodeTypeLiteral::resolve(nodeSource("        return 'demo.send';"), 'SendMessage');

    expect($result->ok())->toBeTrue();
    expect($result->type)->toBe('demo.send');
});

it('proves a double-quoted literal with no interpolation', function () {
    // A double-quoted string with no variables is still ONE
    // T_CONSTANT_ENCAPSED_STRING token, verified by probe. Refusing it would
    // reject an ordinary, provably safe shape.
    $result = NodeTypeLiteral::resolve(nodeSource('        return "demo.send";'), 'SendMessage');

    expect($result->ok())->toBeTrue();
    expect($result->type)->toBe('demo.send');
});

it('proves a literal past a leading comment in the body', function () {
    // E36 requires matching on the COMMENT-STRIPPED token stream. Counterfactual:
    // match the raw token sequence and this fails, refusing every node whose
    // author explained their type — a probe confirmed the body emits T_COMMENT.
    $result = NodeTypeLiteral::resolve(
        nodeSource("        // Published versions resolve through this forever.\n        return 'demo.send';"),
        'SendMessage',
    );

    expect($result->ok())->toBeTrue();
    expect($result->type)->toBe('demo.send');
});

it('proves a same-class constant whose initialiser is a literal', function () {
    $result = NodeTypeLiteral::resolve(
        nodeSource('        return self::TYPE;', "    public const TYPE = 'demo.send';\n"),
        'SendMessage',
    );

    expect($result->ok())->toBeTrue();
    expect($result->type)->toBe('demo.send');
});

it('proves a same-class constant reached through static::', function () {
    $result = NodeTypeLiteral::resolve(
        nodeSource('        return static::TYPE;', "    public const TYPE = 'demo.send';\n"),
        'SendMessage',
    );

    expect($result->ok())->toBeTrue();
    expect($result->type)->toBe('demo.send');
});

it('refuses a concatenation even of two literals', function () {
    // Two T_CONSTANT_ENCAPSED_STRING tokens, not one. Accepting concatenation
    // opens the door to 'x' . static::class, which is the exact orphaning shape
    // E10 exists to refuse.
    $result = NodeTypeLiteral::resolve(nodeSource("        return 'demo' . '.send';"), 'SendMessage');

    expect($result->ok())->toBeFalse();
    expect($result->reason)->toContain('concatenation');
});

it('refuses an interpolated string', function () {
    $result = NodeTypeLiteral::resolve(
        nodeSource('        $suffix = "send";'."\n".'        return "demo.{$suffix}";'),
        'SendMessage',
    );

    expect($result->ok())->toBeFalse();
});

it('refuses a heredoc', function () {
    $result = NodeTypeLiteral::resolve(
        nodeSource("        return <<<T\ndemo.send\nT;"),
        'SendMessage',
    );

    expect($result->ok())->toBeFalse();
});

it('refuses a type derived from the class name', function () {
    // The shape the whole guard exists for. Measured: this returns the SAME
    // string before and after a namespace move, so the empirical check at M9
    // cannot see it.
    $result = NodeTypeLiteral::resolve(
        nodeSource('        return strtolower(class_basename(static::class));'),
        'SendMessage',
    );

    expect($result->ok())->toBeFalse();
});

it('refuses a constant inherited from a parent rather than declared here', function () {
    // This is the probe that proves "same class body" is really enforced and the
    // tokeniser is not reaching through a parent or a trait. Counterfactual:
    // look up the constant with reflection instead of in this file's tokens and
    // this passes, accepting a value the moved file does not contain.
    $source = <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    class SendMessage extends BaseNode
    {
        public static function type(): string
        {
            return self::TYPE;
        }
    }
    PHP;

    $result = NodeTypeLiteral::resolve($source, 'SendMessage');

    expect($result->ok())->toBeFalse();
    expect($result->reason)->toContain('TYPE');
});

it('refuses a type() supplied by a trait', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    class SendMessage
    {
        use HasNodeType;
    }
    PHP;

    $result = NodeTypeLiteral::resolve($source, 'SendMessage');

    expect($result->ok())->toBeFalse();
    expect($result->reason)->toContain('no type() method');
});

it('does not match a literal that only appears in a comment', function () {
    $source = <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    class SendMessage
    {
        // e.g. return 'fake.type';
        public static function type(): string
        {
            return strtolower(static::class);
        }
    }
    PHP;

    $result = NodeTypeLiteral::resolve($source, 'SendMessage');

    expect($result->ok())->toBeFalse();
    expect($result->type)->toBeNull();
});

it('refuses a literal containing a backslash', function () {
    // Unquoting stops at stripping the outer quotes; a node type cannot contain
    // an escape under TYPE_PATTERN, so refusing beats writing an escape parser
    // inside the one unrecoverable guard.
    $result = NodeTypeLiteral::resolve(nodeSource("        return 'demo\\\\send';"), 'SendMessage');

    expect($result->ok())->toBeFalse();
});
