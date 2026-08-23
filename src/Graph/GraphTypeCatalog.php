<?php

namespace Nodeflow\Graph;

final class GraphTypeCatalog
{
    /** @var array<string, array{string, string}> */
    private array $claims = [];

    public function claim(string $type, string $family, string $class): void
    {
        if (isset($this->claims[$type]) && $this->claims[$type] !== [$family, $class]) {
            throw InvalidGraphTypeRegistration::collision($type, $this->claims[$type], [$family, $class]);
        }

        $this->claims[$type] = [$family, $class];
    }

    public function family(string $type): ?string
    {
        return $this->claims[$type][0] ?? null;
    }
}
