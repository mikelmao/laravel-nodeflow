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
    //
    // BaseNode below genuinely declares TYPE = 'demo.send' in this same source
    // file, on purpose: with a fixture that has zero T_CONST tokens anywhere,
    // this test cannot tell a properly-scoped scan from an unscoped one — both
    // find nothing and both refuse, for accidentally the same reason. With
    // BaseNode's TYPE present, an unscoped (whole-file) scan for a constant
    // named TYPE would find BaseNode's and wrongly prove 'demo.send'; only a
    // scan correctly restricted to SendMessage's own body refuses it.
    $source = <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    class BaseNode
    {
        public const TYPE = 'demo.send';
    }

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

it('resolves a same-class constant even when a sibling class declares one of the same name', function () {
    // Critical 1's reproduction: sameClassConstant() must not scan the whole
    // file's flat token stream. Other's TYPE must not leak into SendMessage's
    // own, provably-correct literal — and Other is declared FIRST in the file,
    // so an unscoped scan would find it before ever reaching SendMessage's own.
    $source = <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    class Other
    {
        public const TYPE = 'wrong.type';
    }

    class SendMessage
    {
        public const TYPE = 'demo.send';

        public static function type(): string
        {
            return self::TYPE;
        }
    }
    PHP;

    $result = NodeTypeLiteral::resolve($source, 'SendMessage');

    expect($result->ok())->toBeTrue();
    expect($result->type)->toBe('demo.send');
});

it('refuses a class constant whose initialiser concatenates a literal with static::class', function () {
    // Critical 2: the exact shape E10 refuses on the return path — 'x' .
    // static::class — must also be refused when it appears as a class
    // constant's initialiser, since self::TYPE / static::TYPE hands that same
    // value to type(). Checking only the token immediately after '=' (as the
    // brief's original code did) would accept this by mistake.
    $result = NodeTypeLiteral::resolve(
        nodeSource('        return self::TYPE;', "    public const TYPE = 'demo.' . static::class;\n"),
        'SendMessage',
    );

    expect($result->ok())->toBeFalse();
    expect($result->reason)->toContain('concatenat');
});

it('refuses when the only type() of that name belongs to a nested anonymous class', function () {
    // Important 3: an anonymous class nested inside one of SendMessage's own
    // methods can declare its own type(). A flat scan for the first function
    // named "type" anywhere in the file would find THIS one and treat its body
    // as SendMessage's own — a false accept, since SendMessage itself declares
    // no type() at all. Depth-aware scoping within the class body must exclude
    // it.
    $source = <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    class SendMessage
    {
        public static function helper(): object
        {
            return new class
            {
                public static function type(): string
                {
                    return 'nested.literal';
                }
            };
        }
    }
    PHP;

    $result = NodeTypeLiteral::resolve($source, 'SendMessage');

    expect($result->ok())->toBeFalse();
    expect($result->reason)->toContain('no type() method');
});

it('proves the real class type() even when a bodiless interface signature precedes it', function () {
    // Important 3: a flat, unscoped scan for the first function named "type"
    // would stop at the interface's bodiless signature (a ';' before any '{')
    // and wrongly refuse the whole file. Scoping to SendMessage's own class
    // body means the interface's tokens are never even in scope, and the
    // bodiless-match-keeps-scanning rule is a second line of defence.
    $source = <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    interface NodeTypeInterface
    {
        public function type(): string;
    }

    class SendMessage implements NodeTypeInterface
    {
        public static function type(): string
        {
            return 'demo.send';
        }
    }
    PHP;

    $result = NodeTypeLiteral::resolve($source, 'SendMessage');

    expect($result->ok())->toBeTrue();
    expect($result->type)->toBe('demo.send');
});

it('refuses a same-class constant lookup that only matches inside a nested anonymous class', function () {
    // Re-review finding 4: mutation-testing the shipped code by deleting
    // "&& $depth === 0" from sameClassConstant()'s T_CONST match left all 17
    // tests passing. Without that check, sameClassConstant() reaches into a
    // nested anonymous class's own constant and reports a value the moved
    // class itself does not declare -- SendMessage has no TYPE of its own
    // here, only a decoy nested inside helper(). This must be refused.
    $source = <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    class SendMessage
    {
        public static function helper(): object
        {
            return new class
            {
                public const TYPE = 'wrong.nested';
            };
        }

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

it('proves type() even when it is declared after another method in the same class', function () {
    // Found by the same mutation technique: deleting methodBody()'s closing
    // "$depth--;" (the counterpart to the depth check above) also left the
    // suite green, because every prior fixture declares type() as the class's
    // only or first method -- depth never needs to return to 0 after a
    // sibling method's own braces close. helper() here forces exactly that.
    $source = <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    class SendMessage
    {
        public static function helper(): string
        {
            return 'unrelated';
        }

        public static function type(): string
        {
            return 'demo.send';
        }
    }
    PHP;

    $result = NodeTypeLiteral::resolve($source, 'SendMessage');

    expect($result->ok())->toBeTrue();
    expect($result->type)->toBe('demo.send');
});

it('proves a same-class constant declared after another method with its own braces', function () {
    // The sameClassConstant() counterpart to the test above: its own closing
    // "$depth--;" has the identical, previously-undetected gap for the same
    // reason -- no prior fixture declares TYPE after some other bracketed
    // method in the same class.
    $source = <<<'PHP'
    <?php

    namespace App\Nodeflow\Nodes;

    class SendMessage
    {
        public static function helper(): string
        {
            return 'unrelated';
        }

        public const TYPE = 'demo.send';

        public static function type(): string
        {
            return self::TYPE;
        }
    }
    PHP;

    $result = NodeTypeLiteral::resolve($source, 'SendMessage');

    expect($result->ok())->toBeTrue();
    expect($result->type)->toBe('demo.send');
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
