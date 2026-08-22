# Subject attributes

> **Experimental:** Nodeflow is pre-release software. Subject attributes decide what authors can compare in a condition, so register a narrow, stable allowlist rather than exposing arbitrary model fields.

A subject attribute has a stable key, an editor label, one of three comparison types, and a resolver closure. `core.condition` uses the registry for its Attribute options and asks the resolver for the current subject's value at execution time.

Generate a provider entry with:

```bash
php artisan nodeflow:make-subject-attribute evacuation_confirmed --label="Evacuation confirmed" --type=boolean
```

The command is `nodeflow:make-subject-attribute {key}`. Its options are:

| Option | Default | Validation and behavior |
| --- | --- | --- |
| `{key}` | required | Must use lowercase letters and digits in underscore-separated segments, such as `evacuation_confirmed`. The key is stored in published graphs. |
| `--label=` | key with underscores replaced by spaces and the first letter uppercased | The label shown in the editor. |
| `--type=` | `boolean` | Case-insensitive after trimming. Only `boolean`, `text`, and `number` are accepted. |

When the generated provider has exactly one `subjectAttributes()` registration home, the command adds a fully qualified `SubjectAttribute::make()` entry. If the provider or anchor is missing or cannot be safely rewritten, it prints the exact entry for manual registration and still succeeds. A duplicate key is detected by the provider text and not appended. Fill in the generated `fn ($subject) => null` resolver before authors can use the attribute.

## Register a deliberate allowlist

**File: `app/Providers/NodeflowServiceProvider.php`**

```php
<?php

namespace App\Providers;

use App\Models\Resident;
use Illuminate\Support\ServiceProvider;
use Nodeflow\Schema\SubjectAttribute;
use Nodeflow\Schema\SubjectAttributeRegistry;

class NodeflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        app(SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());
    }

    /** @return SubjectAttribute[] */
    protected function subjectAttributes(): array
    {
        return [
            SubjectAttribute::make(
                'evacuation_confirmed',
                'Evacuation confirmed',
                'boolean',
                fn (Resident $resident): bool => $resident->evacuation_confirmed_at !== null,
            ),
            SubjectAttribute::make(
                'risk_zone',
                'Risk zone',
                'text',
                fn (Resident $resident): string => $resident->risk_zone,
            ),
            SubjectAttribute::make(
                'household_size',
                'Household size',
                'number',
                fn (Resident $resident): int => $resident->household_size,
            ),
        ];
    }
}
```

**Partial `boot()` call:** the generated provider registers this method's result with:

```php
app(\Nodeflow\Schema\SubjectAttributeRegistry::class)->register(
    ...$this->subjectAttributes(),
);
```

**Partial `boot()` call:** for a provider without that method, register the entries directly instead:

```php
app(\Nodeflow\Schema\SubjectAttributeRegistry::class)->register(
    \Nodeflow\Schema\SubjectAttribute::make(
        'risk_zone',
        'Risk zone',
        'text',
        fn (\App\Models\Resident $resident): string => $resident->risk_zone,
    ),
);
```

The resolver receives the resolved subject value and returns any value. Nodeflow does not catch resolver exceptions; an exception fails the condition node's subject execution. A missing subject can reach a subject node as `null`, so either make the closure accept `mixed` and return `null`, or ensure the surrounding execution only supplies the expected subject model. Do not use an attribute resolver to make a database authorization decision: audience ownership is enforced before a run begins by `TenantResolver::ownsSubject()`.

The registry's keys and labels are the condition editor's complete Attribute menu. This is a security boundary for authoring: expose only values that non-technical authors may inspect and compare. Do not expose contact details, financial data, internal flags, or raw relations merely because they are available on the model.

Keys are durable graph references. Keep an attribute registered while any published graph references it, including versions still needed by live runs. Removing a key makes runtime evaluation throw `Unknown subject attribute [key]`; changing its type changes comparison semantics. Registering a later attribute with the same key silently replaces the earlier label, type, and resolver, so give every key one owner.

## Condition comparisons

`core.condition` has `yes` and `no` outputs and these operators:

| Operator key | Editor label | Rule |
| --- | --- | --- |
| `is_true` | is true | Cast the actual value with PHP `(bool)` and compare to `true`. |
| `is_false` | is false | Cast the actual value with PHP `(bool)` and compare to `false`. |
| `equals` | equals | Compare using the attribute type below. A `null` actual value never matches. |
| `not_equals` | does not equal | The negation of `equals`; therefore a `null` actual value matches. |
| `in` | is one of | Test the actual value against each expected item using `equals`. A string expected value is split on commas and each part is trimmed. A `null` actual value never matches. |
| `greater_than` | is greater than | Both values must be numeric; compare their float casts. A `null` or non-numeric value does not match. |
| `less_than` | is less than | Both values must be numeric; compare their float casts. A `null` or non-numeric value does not match. |

For `equals` and `in`, `boolean` uses `filter_var(..., FILTER_VALIDATE_BOOL)` on both values, `number` requires both values to be numeric and compares their float casts exactly, and `text` compares string casts exactly. Any other stored type falls back to the text behavior in the current node implementation; do not rely on that fallback because the generator accepts only the three documented types.

`is_true` and `is_false` do not use the attribute type or `filter_var()`. That means PHP truthiness applies: for example, a non-empty string is true and `"0"` is false. Unknown operator keys throw a runtime exception rather than routing a subject silently.

## Next step

Start an authorized published flow with [Starting runs](starting-runs.md).
