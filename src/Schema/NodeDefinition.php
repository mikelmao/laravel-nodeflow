<?php

namespace Nodeflow\Schema;

class NodeDefinition
{
    private string $group = 'General';

    private array $outputs = ['default'];

    /** @var Field[] */
    private array $fields = [];

    private ?string $icon = null;

    private ?string $description = null;

    private function __construct(public readonly string $label) {}

    public static function make(string $label): self
    {
        return new self($label);
    }

    public function group(string $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function outputs(array $outputs): self
    {
        $this->outputs = $outputs;

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

    public function outputNames(): array
    {
        return $this->outputs;
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'group' => $this->group,
            'icon' => $this->icon,
            'description' => $this->description,
            'outputs' => $this->outputs,
            // toWireArray(), not toArray(): this array is serialised straight to
            // the editor, and a field's options must be one JSON type either way.
            'fields' => array_map(fn (Field $f) => $f->toWireArray(), $this->fields),
        ];
    }

    public function rules(): array
    {
        return array_merge(...array_map(fn (Field $f) => $f->rules(), $this->fields)) ?: [];
    }
}
