<?php

namespace Nodeflow\Nodes;

class NodeRegistry
{
    /** @var array<string, class-string<Node>> */
    private array $types = [];

    /** @var array<string, string> */
    private array $aliases = [];

    public function register(string ...$classes): self
    {
        foreach ($classes as $class) {
            $this->types[$class::type()] = $class;
        }

        return $this;
    }

    public function alias(string $oldType, string $newType): self
    {
        $this->aliases[$oldType] = $newType;

        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$this->canonical($type)]);
    }

    public function resolve(string $type): Node
    {
        $canonical = $this->canonical($type);

        if (! isset($this->types[$canonical])) {
            throw new UnknownNodeTypeException($type);
        }

        return app($this->types[$canonical]);
    }

    /** @return array<string, class-string<Node>> */
    public function all(): array
    {
        return $this->types;
    }

    public function palette(): array
    {
        return array_values(array_map(function (string $class, string $type) {
            $node = app($class);

            $cardinality = [];

            if ($node instanceof HandlesSubject) {
                $cardinality[] = 'subject';
            }

            if ($node instanceof HandlesAudience) {
                $cardinality[] = 'audience';
            }

            return array_merge($node->definition()->toArray(), [
                'type' => $type,
                'default_config' => $node->defaultConfig(),
                'cardinality' => $cardinality,
            ]);
        }, $this->types, array_keys($this->types)));
    }

    private function canonical(string $type): string
    {
        return $this->aliases[$type] ?? $type;
    }
}
