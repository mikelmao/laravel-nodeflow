<?php

namespace Nodeflow\Nodes\Core;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;
use Nodeflow\Schema\SubjectAttributeRegistry;

class ConditionNode extends Node implements HandlesSubject
{
    public const OPERATORS = [
        'is_true' => 'is true',
        'is_false' => 'is false',
        'equals' => 'equals',
        'not_equals' => 'does not equal',
        'in' => 'is one of',
        'greater_than' => 'is greater than',
        'less_than' => 'is less than',
    ];

    public static function type(): string
    {
        return 'core.condition';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Condition')
            ->group('Flow')
            ->outputs(['yes', 'no'])
            ->fields([
                Field::select('attribute')
                    ->label('Attribute')
                    ->optionsFrom(SubjectAttributeRegistry::class)
                    ->required(),
                Field::select('operator')->options(self::OPERATORS)->required(),
                Field::text('value')->label('Value'),
            ]);
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        $actual = app(SubjectAttributeRegistry::class)
            ->value($context->config('attribute'), $context->subject());

        $expected = $context->config('value');

        $matches = match ($context->config('operator')) {
            'is_true' => (bool) $actual === true,
            'is_false' => (bool) $actual === false,
            'equals' => $actual == $expected,
            'not_equals' => $actual != $expected,
            'in' => in_array($actual, (array) $expected),
            'greater_than' => $actual > $expected,
            'less_than' => $actual < $expected,
            default => false,
        };

        return $context->continue($matches ? 'yes' : 'no');
    }
}
