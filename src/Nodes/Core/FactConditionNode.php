<?php

namespace Nodeflow\Nodes\Core;

use InvalidArgumentException;
use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Facts\CompiledFactPredicate;
use Nodeflow\Facts\Exceptions\FactConfigurationException;
use Nodeflow\Facts\Exceptions\FactContractException;
use Nodeflow\Facts\FactPredicateEvaluator;
use Nodeflow\Facts\FactProviderRegistry;
use Nodeflow\Facts\FactResolution;
use Nodeflow\Facts\FactResolutionContext;
use Nodeflow\Facts\MissingFactBehavior;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;
use Throwable;

final class FactConditionNode extends Node implements HandlesAudience
{
    public array $nonRetryableErrorTypes = [
        FactConfigurationException::class,
        FactContractException::class,
    ];

    public static function type(): string
    {
        return 'core.fact_condition';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Fact condition')
            ->group('Flow')
            ->description('Route the audience using a versioned value supplied by a registered provider.')
            ->outputs(['yes', 'no'])
            ->fields([
                Field::factPredicate('predicate', 'runtime_condition')->label('Fact')->required(),
            ]);
    }

    public function forAudience(AudienceContext $context): NodeResult
    {
        try {
            $raw = $context->config('predicate');
            $predicate = CompiledFactPredicate::fromArray(is_array($raw) ? $raw : []);
        } catch (Throwable $exception) {
            throw new FactContractException('The pinned fact predicate is invalid.', previous: $exception);
        }

        try {
            $provider = app(FactProviderRegistry::class)->get($predicate->provider);
        } catch (InvalidArgumentException $exception) {
            throw new FactConfigurationException('The pinned fact provider is not registered.', previous: $exception);
        }

        $requested = [];
        foreach ($context->subjectIds() as $subjectId) {
            $requested[$this->identity($subjectId)] = $subjectId;
        }

        $resolved = [];
        foreach ($provider->resolve(FactResolutionContext::fromAudience($context), $predicate, $context->subjectIds()) as $result) {
            if (! $result instanceof FactResolution) {
                throw new FactContractException('A fact provider returned an invalid result.');
            }

            $identity = $this->identity($result->subjectId);
            if (! isset($requested[$identity]) || isset($resolved[$identity])) {
                throw new FactContractException('A fact provider must return exactly one result for each requested subject.');
            }
            $resolved[$identity] = $result;
        }

        if (count($resolved) !== count($requested)) {
            throw new FactContractException('A fact provider must return exactly one result for each requested subject.');
        }

        $yes = [];
        $no = [];
        $evaluator = app(FactPredicateEvaluator::class);

        foreach ($context->subjectIds() as $subjectId) {
            $result = $resolved[$this->identity($subjectId)];
            if ($result->missing) {
                match ($predicate->missingBehavior) {
                    MissingFactBehavior::RouteYes => $yes[] = $subjectId,
                    MissingFactBehavior::RouteNo => $no[] = $subjectId,
                    MissingFactBehavior::Fail => throw new FactContractException('A required fact value is missing.'),
                };

                continue;
            }

            if ($evaluator->matches($result->value, $predicate)) {
                $yes[] = $subjectId;
            } else {
                $no[] = $subjectId;
            }
        }

        return $context->partition(['yes' => $yes, 'no' => $no]);
    }

    private function identity(string $subjectId): string
    {
        return 'subject:'.$subjectId;
    }
}
