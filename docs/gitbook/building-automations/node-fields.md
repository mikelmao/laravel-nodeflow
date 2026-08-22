# Node fields

> **Experimental:** Nodeflow is pre-release software. Field declarations are part of a publish-time safety boundary, not merely editor presentation.

A node field describes both a configuration control and the Laravel validation rules used when a graph is published.

## Declare fields

The exact factory API is `Field::text(string $key)`, `number(string $key)`, `boolean(string $key)`, `select(string $key)`, `multiselect(string $key)`, `duration(string $key)`, and `custom(string $key, string $type, string $baseRule = 'string')`.

All field factories return a `Field` with these fluent methods. This is a partial field declaration for use inside `NodeDefinition::fields()`; the complete declaration below includes imports and surrounding array context.

```php
Field::select('channel')
    ->label('Channel')
    ->help('Choose the delivery channel.')
    ->required()
    ->default('email')
    ->options(['email' => 'Email', 'sms' => 'SMS']);
```

`required(bool $required = true)` defaults to `true`; all fields are nullable unless it is called. `label(string $label)`, `help(string $help)`, and `default(mixed $default)` are optional. Without `label()`, a key such as `template_key` becomes `Template key`.

`options(array $options)` supplies static `value => label` choices. `optionsFrom(string $sourceClass)` marks the field dynamic. The source class must implement `OptionSource` with `options(): array`, and is available to server code through `optionsSourceClass(): ?string`; its class name is never serialized to the browser.

**File-scope imports for `app/Nodeflow/Nodes/SendMessage.php`:**

```php
<?php

use App\Nodeflow\MessageTemplateOptions;
use Nodeflow\Schema\Field;
```

**Inside `SendMessage::definition()`:**

```php

$fields = [
    Field::text('message')->required(),
    Field::number('priority')->default(1),
    Field::boolean('include_opted_out')->default(false),
    Field::select('channel')->options(['email' => 'Email', 'sms' => 'SMS'])->required(),
    Field::multiselect('segments')->options(['new' => 'New', 'vip' => 'VIP']),
    Field::duration('delay')->required(),
    Field::select('template')->optionsFrom(MessageTemplateOptions::class),
    Field::custom('destination', 'town', 'string')->required(),
];
```

## Know the generated validation

Every field begins with `required` or `nullable`, then its base rule. Only a non-empty static `options()` array adds `in:` followed by the comma-joined option keys; empty static options and dynamic options do not add that `in:` rule.

| Field factory | Type sent to the editor | Exact base validation rule |
| --- | --- | --- |
| `text()` | `text` | `string` |
| `number()` | `number` | `numeric` |
| `boolean()` | `boolean` | `boolean` |
| `select()` | `select` | `string` |
| `multiselect()` | `multiselect` | `array` |
| `duration()` | `duration` | `string` plus `ValidDuration` |
| `custom($key, $type, $baseRule)` | `$type` | the supplied `$baseRule`, default `string` |

For example, a required static select compiles to `['required', 'string', 'in:email,sms']`; an optional dynamic select compiles to `['nullable', 'string']`. `rules(): array` returns this shape keyed by the field key. `toArray(): array` returns `key`, `type`, `label`, `help`, `required`, `default`, `options`, and `dynamic_options`.

The palette uses `toWireArray(): array`. It has the same keys, but `options` is always encoded as a JSON object: `{}` when empty, never `[]`. A dynamic field is represented with `dynamic_options: true` and empty options. The editor uses the server-provided `urls.options` template to request that flow, node type, and field's options; the response shape is `{"options":{"value":"Label"}}`. The request is authorized for updating the flow, and the server resolves the source from the declared field—not from a client-provided class name. See [Editor](../editor-and-run-view/editor.md) for the resolved URL contract.

> **Note:** Client controls improve authoring, but they do not replace publish validation. The server validates each registered node's configuration through `Field::rules()` before a version is created.

## Use durations safely

`Field::duration()` adds `ValidDuration` after its `required`/`nullable` and `string` rules. The value must be a relative duration that Carbon can parse to a positive number of seconds. Invalid, zero, whitespace-only, and negative values are rejected; an absent optional value remains allowed.

The built-in duration control serializes exactly `"<amount> <unit>"`. Its amount is an integer from `1` through `999`; its units are `seconds`, `minutes`, `hours`, `days`, and `weeks`, singular only when the amount is one. It intentionally does not offer months. Other parseable positive relative durations can satisfy server validation, but a custom control must still send a value the server accepts.

## Next step

Put configured fields into a valid graph and create an immutable snapshot in [Publishing flows](publishing-flows.md).
