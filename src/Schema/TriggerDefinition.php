<?php

namespace Nodeflow\Schema;

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
