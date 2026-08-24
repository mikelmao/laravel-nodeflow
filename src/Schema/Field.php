<?php

namespace Nodeflow\Schema;

use Illuminate\Support\Str;
use Nodeflow\Schema\Rules\ValidDuration;
use Nodeflow\Support\StableKey;

class Field
{
    private ?string $label = null;

    private ?string $help = null;

    private bool $required = false;

    private mixed $default = null;

    private array $options = [];

    private ?string $optionsSource = null;

    private ?string $customType = null;

    private string $customBaseRule = 'string';

    private function __construct(
        public readonly string $key,
        public readonly FieldType $type,
    )
    {
        StableKey::assert($key, 'field key', 191);
    }

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

    /**
     * A field type the package does not know about.
     *
     * FieldType is an enum, so a host cannot add a case to it — but the field-type
     * to control mapping is deliberately extensible (spec E5), and a host with a
     * town picker needs a type string to key it on. The base rule travels with it
     * because publish-time validation must still work for a type the package has
     * never heard of; without it a numeric custom field would accept anything.
     */
    public static function custom(string $key, string $type, string $baseRule = 'string'): self
    {
        $field = new self($key, FieldType::Text);
        $field->customType = $type;
        $field->customBaseRule = $baseRule;

        return $field;
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

    /**
     * The declared option source, for server-side resolution only.
     *
     * Deliberately not in toArray(): the browser learns that a field is dynamic,
     * never which class backs it (spec E6). The options endpoint reads this from
     * the node's own definition, so a client-supplied class name is never part of
     * the lookup.
     */
    public function optionsSourceClass(): ?string
    {
        return $this->optionsSource;
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->customType ?? $this->type->value,
            'label' => $this->label ?? Str::ucfirst(str_replace('_', ' ', Str::snake($this->key))),
            'help' => $this->help,
            'required' => $this->required,
            'default' => $this->default,
            'options' => $this->options,
            'dynamic_options' => $this->optionsSource !== null,
        ];
    }

    /**
     * The field as it goes over HTTP.
     *
     * One JSON type per key, for the client this payload is a contract for.
     * `options` is a string-keyed PHP array, which json_encode turns into an
     * object when it has entries and into `[]` when it does not — so a
     * dynamic-option field, or any field with no inline options at all, handed the
     * browser an array where every other field handed it a map, and a TypeScript
     * client would have to write `Record<string, string> | []`. Casting to an
     * object makes it `{}` when empty and leaves it untouched otherwise.
     *
     * Separate from toArray() rather than folded into it because the two have
     * different jobs: toArray() is the PHP-side shape, where an array is the
     * useful type for a host inspecting a definition, and this is the wire shape.
     * NodeDefinition and TriggerDefinition — the only two paths onto the wire —
     * both call this one.
     */
    public function toWireArray(): array
    {
        $field = $this->toArray();

        $field['options'] = (object) $field['options'];

        return $field;
    }

    public function rules(): array
    {
        $rules = [$this->required ? 'required' : 'nullable', $this->customType !== null
            ? $this->customBaseRule
            : $this->type->baseRule()];

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

        // Config is deliberately a flat map. Laravel treats dots in rule keys as
        // nested array traversal unless they are escaped, while its error bag
        // still reports the original literal key for the editor contract.
        return [str_replace('.', '\\.', $this->key) => $rules];
    }
}
