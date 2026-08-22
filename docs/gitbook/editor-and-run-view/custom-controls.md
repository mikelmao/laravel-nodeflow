# Custom controls

Add a typed React widget for a domain field while retaining server-side validation and package-owned option loading.

## Declare the custom field

Use `Field::custom(string $key, string $type, string $baseRule = 'string')`. The type string selects the browser control; the base rule remains server-side validation for a type the package does not know.

```php
<?php

namespace App\Nodeflow\Nodes;

use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

// Partial snippet: inside a node definition.
$definition = NodeDefinition::make('Choose destination')
    ->fields([
        Field::custom('destination', 'town')
            ->label('Destination')
            ->required(),
    ]);
```

For a non-string value, pass an explicit base rule—for example `Field::custom('altitude', 'elevation', 'numeric')`. Static `options()` continue to add an `in:` rule. Dynamic choices are resolved at edit time, so validate their resulting value in the node as appropriate for the application's domain.

## Implement the control

Every custom control receives exactly these six props: `field`, `value`, `onChange`, `errors`, `options`, and `optionsLoading`. This complete example uses all of them and keeps its label, busy state, and error state accessible.

```tsx
import type { FieldControlProps } from '@nodeflow/editor'

export function TownControl({
    field,
    value,
    onChange,
    errors,
    options,
    optionsLoading,
}: FieldControlProps) {
    const id = `nf-${field.key}`
    const errorId = `${id}-errors`
    const selected = typeof value === 'string' ? value : ''

    return (
        <div>
            <label htmlFor={id}>{field.label}{field.required ? ' *' : ''}</label>
            {field.help !== null && <p>{field.help}</p>}
            <select
                id={id}
                value={selected}
                disabled={optionsLoading}
                aria-busy={optionsLoading}
                aria-invalid={errors.length > 0}
                aria-describedby={errors.length > 0 ? errorId : undefined}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="">{optionsLoading ? 'Loading towns…' : 'Choose a town'}</option>
                {Object.entries(options).map(([key, label]) => (
                    <option key={key} value={key}>{label}</option>
                ))}
            </select>
            {errors.length > 0 && (
                <ul id={errorId} role="alert">
                    {errors.map((error) => <li key={error}>{error}</li>)}
                </ul>
            )}
        </div>
    )
}
```

`errors` includes both publish-validation messages and dynamic-option loading failures. Do not replace a loading or error state with an apparently empty valid picker; that can lead an author to submit a choice the widget never loaded.

## Register it on the page

Pass a `ControlMap` to `FlowEditor`. Host entries override a built-in type with the same key, and can add new keys.

```tsx
import {
    FlowEditor,
    type ControlMap,
    type FlowEditorProps,
} from '@nodeflow/editor'
import { TownControl } from './TownControl'

const controls: ControlMap = {
    town: TownControl,
}

export default function Editor(props: FlowEditorProps) {
    return <FlowEditor {...props} controls={controls} />
}
```

If no map entry exists for a field type, the package renders a visible **no control for field type** alert and no input. It deliberately does not fall back to a text input, which would make a specialized field look editable while bypassing the widget's intended value format.

## Use dynamic options without exposing classes

A field declared with `optionsFrom()` is marked `dynamic_options` in the palette. The package fetches its resolved `Record<string, string>` choices using the server-authored URL template and delivers the result through `options`, `optionsLoading`, and `errors`; controls do not receive a URL or perform that request.

The client never receives the PHP option-source class name. The options endpoint takes only a registered node type and declared field key, then obtains the source class from that node definition and checks that it implements `OptionSource`. This keeps a browser request from choosing an arbitrary application class to instantiate.

## Next step

Customize the card body for your node types in [Custom node appearance](custom-node-appearance.md).
