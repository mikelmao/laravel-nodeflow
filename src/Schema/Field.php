<?php

namespace Nodeflow\Schema;

use Illuminate\Support\Str;
use Nodeflow\Schema\Rules\ValidDuration;

class Field
{
    private ?string $label = null;

    private ?string $help = null;

    private bool $required = false;

    private mixed $default = null;

    private array $options = [];

    private ?string $optionsSource = null;

    private function __construct(
        public readonly string $key,
        public readonly FieldType $type,
    ) {}

    public static function text(string $key): self
    {
        return new self($key, FieldType::Text);
    }

    public static function number(string $key): self
    {
        return new self($key, FieldType::Number);
    }

    public static function boolean(string $key): self
    {
        return new self($key, FieldType::Boolean);
    }

    public static function select(string $key): self
    {
        return new self($key, FieldType::Select);
    }

    public static function multiselect(string $key): self
    {
        return new self($key, FieldType::Multiselect);
    }

    public static function duration(string $key): self
    {
        return new self($key, FieldType::Duration);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function help(string $help): self
    {
        $this->help = $help;

        return $this;
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    public function default(mixed $default): self
    {
        $this->default = $default;

        return $this;
    }

    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function optionsFrom(string $sourceClass): self
    {
        $this->optionsSource = $sourceClass;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'label' => $this->label ?? Str::ucfirst(str_replace('_', ' ', Str::snake($this->key))),
            'help' => $this->help,
            'required' => $this->required,
            'default' => $this->default,
            'options' => $this->options,
            'options_source' => $this->optionsSource,
        ];
    }

    public function rules(): array
    {
        $rules = [$this->required ? 'required' : 'nullable', $this->type->baseRule()];

        if ($this->options !== [] && $this->optionsSource === null) {
            $rules[] = 'in:'.implode(',', array_keys($this->options));
        }

        // Attached here rather than on WaitNode so that every duration field ever
        // declared inherits it. A duration reaches the engine verbatim, and Carbon
        // resolves an unparseable one to zero seconds without complaint, so an
        // unvalidated duration field is a zero-second wait waiting to happen.
        if ($this->type === FieldType::Duration) {
            $rules[] = new ValidDuration;
        }

        return [$this->key => $rules];
    }
}
