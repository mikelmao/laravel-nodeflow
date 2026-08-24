<?php

namespace Nodeflow\Triggers;

use InvalidArgumentException;
use Nodeflow\Contracts\TriggerNode;
use Nodeflow\Graph\GraphTypeCatalog;
use RuntimeException;

class TriggerNodeRegistry
{
    /** @var array<string, class-string<TriggerNode>> */
    private array $types = [];

    public function __construct(
        private readonly GraphTypeCatalog $graphTypes = new GraphTypeCatalog,
    ) {}

    public function register(string ...$classes): self
    {
        foreach ($classes as $class) {
            if (! is_a($class, TriggerNode::class, true)) {
                throw new InvalidArgumentException(
                    "[{$class}] cannot be registered as a trigger node: it does not implement ".TriggerNode::class.'.'
                );
            }

            $type = $class::type();
            $this->graphTypes->claim($type, 'trigger', $class);
            $this->types[$type] = $class;
        }

        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function resolve(string $type): TriggerNode
    {
        if (! isset($this->types[$type])) {
            throw new RuntimeException("Unknown nodeflow trigger node type [{$type}].");
        }

        return app($this->types[$type]);
    }

    /** @return array<string, class-string<TriggerNode>> */
    public function all(): array
    {
        return $this->types;
    }

    public function palette(): array
    {
        return array_values(array_map(function (string $class, string $type): array {
            $node = app($class);

            return array_merge($node->definition()->toArray(), [
                'type' => $type,
                'kind' => 'trigger',
                'driver' => $node->driver(),
                'outputs' => $node->definition()->outputNames(),
                'default_config' => $node->defaultConfig(),
                'compatible_source_keys' => app(TriggerSourceCompatibility::class)->sourceKeys($node),
            ]);
        }, $this->types, array_keys($this->types)));
    }
}
