<?php

namespace Nodeflow\Schema;

use RuntimeException;

/**
 * The registered subject attributes, and the option source behind
 * `core.condition`'s attribute field.
 *
 * It implements OptionSource because ConditionNode declares
 * `optionsFrom(self::class)`, and the options endpoint refuses any declared
 * source that is not an OptionSource — deliberately, so a typo fails loudly
 * rather than degrading to an empty select. Without the interface here that
 * safeguard fired on the package's own built-in node: every host got a 500 the
 * first time an author opened a Condition sidebar.
 *
 * A container singleton, so the attributes a host registered in its provider are
 * the ones the endpoint answers with.
 */
class SubjectAttributeRegistry implements OptionSource
{
    /** @var array<string, SubjectAttribute> */
    private array $attributes = [];

    public function register(SubjectAttribute ...$attributes): self
    {
        foreach ($attributes as $attribute) {
            $this->attributes[$attribute->key] = $attribute;
        }

        return $this;
    }

    /** @return array<string, string> value => label */
    public function options(): array
    {
        return array_map(fn (SubjectAttribute $a) => $a->label, $this->attributes);
    }

    public function has(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function value(string $key, mixed $subject): mixed
    {
        if (! isset($this->attributes[$key])) {
            throw new RuntimeException("Unknown subject attribute [{$key}].");
        }

        return $this->attributes[$key]->value($subject);
    }

    public function get(string $key): ?SubjectAttribute
    {
        return $this->attributes[$key] ?? null;
    }
}
