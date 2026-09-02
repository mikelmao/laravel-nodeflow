<?php

namespace Nodeflow\Facts\Publishing;

use InvalidArgumentException;
use Nodeflow\Facts\CompiledFactPredicate;
use Nodeflow\Facts\FactCatalogue;
use Nodeflow\Facts\FactCatalogueContext;
use Nodeflow\Facts\FactPredicate;
use Nodeflow\Facts\FactPredicateEvaluator;
use Nodeflow\Facts\FactProviderRegistry;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\Publishing\GraphCompiler;
use Nodeflow\Publishing\GraphCompilerContext;
use Nodeflow\Publishing\GraphInvalidException;
use Nodeflow\Schema\Field;
use Nodeflow\Triggers\TriggerNodeRegistry;
use Nodeflow\Triggers\TriggerSourceCompatibility;
use Nodeflow\Triggers\TriggerSourceRegistry;

final class CompileFacts implements GraphCompiler
{
    public function __construct(
        private readonly FactProviderRegistry $providers,
        private readonly NodeRegistry $nodes,
        private readonly TriggerNodeRegistry $triggers,
        private readonly TriggerSourceRegistry $sources,
        private readonly TriggerSourceCompatibility $sourceCompatibility,
        private readonly GraphTypeCatalog $types,
    ) {}

    public function key(): string
    {
        return 'core.facts';
    }

    public function priority(): int
    {
        return -1000;
    }

    public function compile(GraphCompilerContext $context, array $graph): array
    {
        /** @var array<string, FactCatalogue> $catalogues */
        $catalogues = [];

        foreach ($graph['nodes'] ?? [] as $index => $node) {
            if (! is_array($node) || ! is_array($node['config'] ?? null)) {
                continue;
            }

            foreach ($this->fields($context, $node) as $field) {
                if ($field->factCapability() === null || ! array_key_exists($field->key, $node['config'])) {
                    continue;
                }

                try {
                    $value = $node['config'][$field->key];
                    if ($value === null) {
                        continue;
                    }
                    $predicates = $field->isFactPredicateList() ? $value : [$value];
                    if (! is_array($predicates) || ! array_is_list($predicates)) {
                        throw new InvalidArgumentException;
                    }

                    $compiled = [];
                    $seen = [];
                    foreach ($predicates as $authored) {
                        if (! is_array($authored)) {
                            throw new InvalidArgumentException;
                        }
                        $predicate = FactPredicate::fromArray($authored);
                        $catalogue = $catalogues[$predicate->provider] ??= $this->catalogue($context, $predicate->provider);
                        $identity = $predicate->provider.':'.$predicate->key.'@'.$predicate->version;
                        if (isset($seen[$identity])) {
                            throw new InvalidArgumentException;
                        }
                        $seen[$identity] = true;
                        $definition = $catalogue->definition($predicate->key, $predicate->version);
                        $compiledPredicate = CompiledFactPredicate::compile(
                            $predicate,
                            $definition,
                            $field->factCapability(),
                            $catalogue->revision,
                        );
                        if (($node['type'] ?? null) === 'core.fact_condition'
                            && ! FactPredicateEvaluator::supports($compiledPredicate->type, $compiledPredicate->operator)) {
                            throw new InvalidArgumentException;
                        }
                        $compiled[] = $compiledPredicate->toArray();
                    }

                    usort($compiled, static fn (array $left, array $right): int => strcmp($left['provider'], $right['provider'])
                        ?: strcmp($left['key'], $right['key'])
                        ?: $left['version'] <=> $right['version']
                    );

                    $graph['nodes'][$index]['config'][$field->key] = $field->isFactPredicateList()
                        ? $compiled
                        : ($compiled[0] ?? throw new InvalidArgumentException);
                } catch (InvalidArgumentException) {
                    $this->invalid((string) ($node['id'] ?? ''), $field->key);
                }
            }
        }

        return $graph;
    }

    /** @return list<Field> */
    private function fields(GraphCompilerContext $context, array $node): array
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';

        if ($this->types->family($type) === 'executable') {
            return $this->nodes->resolve($type)->definition()->fieldObjects();
        }

        if ($this->types->family($type) !== 'trigger') {
            return [];
        }

        $trigger = $this->triggers->resolve($type);
        $definition = $context->triggerDefinitions->node($trigger);
        $config = $node['config'];
        $sourceKey = $trigger->source($config);

        if ($this->sources->has($trigger->driver(), $sourceKey)) {
            $source = $this->sources->resolve($trigger->driver(), $sourceKey);
            $definition = $this->sourceCompatibility->combinedDefinition(
                $trigger,
                $source,
                $context->triggerDefinitions,
            );
        }

        return $definition->fieldObjects();
    }

    private function catalogue(GraphCompilerContext $context, string $providerKey): FactCatalogue
    {
        $catalogue = $this->providers->get($providerKey)->catalogue(new FactCatalogueContext($context->flow));
        if ($catalogue->provider !== $providerKey) {
            throw new InvalidArgumentException('The provider returned a catalogue for another provider.');
        }

        return $catalogue;
    }

    private function invalid(string $nodeId, string $field): never
    {
        $message = 'The selected fact is unavailable or invalid.';

        throw new GraphInvalidException(
            ["Node [{$nodeId}] field [{$field}]: {$message}"],
            [['node' => $nodeId, 'field' => $field, 'message' => $message]],
        );
    }
}
