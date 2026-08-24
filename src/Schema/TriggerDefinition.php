<?php

namespace Nodeflow\Schema;

use InvalidArgumentException;

class TriggerDefinition
{
    private ?string $description = null;

    /** @var Field[] */
    private array $fields = [];

    private ?string $icon = null;

    private function __construct(public readonly string $label) {}

    public static function make(string $label): self
    {
        return new self($label);
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /** @param  Field[]  $fields */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    /** @return Field[] */
    public function fieldObjects(): array
    {
        return $this->fields;
    }

    /**
     * Field keys a source definition cannot contribute because the trigger node
     * already owns them. Both the editor-options boundary and publication use
     * this definition-level operation, so extensions do not need built-in class
     * checks to agree on what "reserved" means.
     *
     * @return string[]
     */
    public function collidingFieldKeys(self $source): array
    {
        $reserved = array_map(fn (Field $field): string => $field->key, $this->fields);
        $contributed = array_map(fn (Field $field): string => $field->key, $source->fields);

        return array_values(array_intersect($reserved, $contributed));
    }

    /**
     * A selected trigger source contributes flat config fields to its node.
     * Return a new definition so registry-owned definitions remain immutable
     * from the editor's point of view.
     */
    public function combinedWith(self $source): self
    {
        $collisions = $this->collidingFieldKeys($source);

        if ($collisions !== []) {
            throw new InvalidArgumentException(
                'Trigger source fields collide with reserved trigger fields: '.implode(', ', $collisions).'.'
            );
        }

        $combined = clone $this;
        $combined->fields = [...$this->fields, ...$source->fields];

        return $combined;
    }

    /**
     * Defaults authored by fields, excluding null (the ordinary "unset" value).
     * TriggerNode::defaultConfig() remains authoritative for node-specific
     * defaults; this supplies the source-contributed half of a flat config.
     */
    public function defaultConfig(): array
    {
        $defaults = [];

        foreach ($this->fields as $field) {
            $default = $field->toArray()['default'];

            if ($default !== null) {
                $defaults[$field->key] = $default;
            }
        }

        return $defaults;
    }

    /** @return array{string} */
    public function outputNames(): array
    {
        return ['started'];
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'icon' => $this->icon,
            'description' => $this->description,
            // toWireArray(), not toArray(): see NodeDefinition::toArray().
            'fields' => array_map(fn (Field $f) => $f->toWireArray(), $this->fields),
        ];
    }

    public function rules(): array
    {
        return array_merge(...array_map(fn (Field $f) => $f->rules(), $this->fields)) ?: [];
    }
}
