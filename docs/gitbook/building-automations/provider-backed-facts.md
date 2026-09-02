# Provider-backed facts

Facts let a workflow author select a versioned value that is owned and resolved by another application or subsystem. They are intended for bulk, remote, or snapshot-scoped data. For a simple value already available on the local subject model, use a [subject attribute](subject-attributes.md) instead.

Nodeflow owns the fact contracts, publish-time pinning, reusable editor controls, and the built-in `core.fact_condition` node. The host owns authentication, catalogue routing, tenant checks, upstream calls, and the concrete definitions.

## Implement a provider

A provider returns the facts available while a flow is being authored and resolves one value for every requested subject while a run executes:

```php
use Nodeflow\Facts\CompiledFactPredicate;
use Nodeflow\Facts\FactCatalogue;
use Nodeflow\Facts\FactCatalogueContext;
use Nodeflow\Facts\FactDefinition;
use Nodeflow\Facts\FactProvider;
use Nodeflow\Facts\FactResolution;
use Nodeflow\Facts\FactResolutionContext;
use Nodeflow\Facts\FactValueType;
use Nodeflow\Nodeflow;

final class CustomerFacts implements FactProvider
{
    public function key(): string
    {
        return 'customers';
    }

    public function catalogue(FactCatalogueContext $context): FactCatalogue
    {
        return new FactCatalogue('customers', 'catalogue-42', [
            new FactDefinition(
                key: 'profile.segment',
                version: 1,
                label: 'Customer segment',
                type: FactValueType::Text,
                capabilities: ['audience_filter', 'runtime_condition'],
                operators: [
                    'audience_filter' => ['in'],
                    'runtime_condition' => ['equals', 'not_equals', 'in'],
                ],
            ),
        ]);
    }

    public function resolve(
        FactResolutionContext $context,
        CompiledFactPredicate $predicate,
        array $subjectIds,
    ): iterable {
        $values = $this->loadValuesInBulk($context, $predicate, $subjectIds);

        foreach ($subjectIds as $subjectId) {
            yield array_key_exists($subjectId, $values)
                ? FactResolution::present($subjectId, $values[$subjectId])
                : FactResolution::missing($subjectId);
        }
    }
}

Nodeflow::registerFactProviders([app(CustomerFacts::class)]);
```

Provider and fact keys are durable identifiers. Increment a fact's `version` when its meaning or value type changes. Change the catalogue `revision` whenever its definitions or options change. Do not reuse an old key and version with new semantics.

The standard editor contract bounds each catalogue to 100 definitions, each definition to 20 capabilities, each capability to 20 operators, and each definition to 5,000 options. Use a free-form typed fact or a narrower upstream search mechanism when the possible values exceed that option limit.

`resolve()` must return exactly one `FactResolution` for every requested subject ID, with no duplicates or extra IDs. Nodeflow fails closed if that contract is broken. The engine calls the provider once per audience execution chunk; the default maximum is controlled by `nodeflow.limits.audience_chunk`. Providers should perform one bounded bulk query or request for the supplied IDs instead of making one request per subject.

## Expose the editor catalogue

The host should expose an authenticated, tenant-scoped endpoint that obtains the provider catalogue for the current flow and returns its standard editor representation:

```php
return response()->json(
    Nodeflow::facts()->get('customers')
        ->catalogue(new FactCatalogueContext($flow))
        ->toEditorArray(),
);
```

The response includes `contract_version`, `revision`, and `facts`. The provider key is deliberately configured by the host rather than trusted from an HTTP response.

Pass one or more endpoints to the editor. Catalogues are fetched in parallel and every authored predicate is qualified with its provider key:

```tsx
<FlowEditor
    {...props}
    facts={{
        providers: [
            { key: 'customers', url: props.urls.customerFacts, contractVersion: 1 },
        ],
    }}
/>
```

The built-in controls show only definitions supporting the field's capability. An inactive option remains visible when already selected but cannot be newly selected. A removed or unrecognised pinned value is shown as unavailable instead of being silently replaced.

## Add fact fields to nodes or triggers

Use a singular predicate for a runtime decision:

```php
Field::factPredicate('predicate', 'runtime_condition')->required();
```

Use a bounded list when a trigger source or another host component needs several filters:

```php
Field::factPredicates('audience_filters', 'audience_filter', maximum: 10);
```

At publication Nodeflow validates the current catalogue, canonicalises values, and pins the provider, key, version, value type, missing-value behavior, and catalogue revision into the immutable graph. Providers are not contacted again merely to reinterpret that published configuration.

`core.fact_condition` resolves the pinned predicate for the active audience and routes each subject to `yes` or `no`. Its portable runtime operators are `equals`, `not_equals`, `in`, `greater_than`, and `less_than`; the ordered comparisons are valid only for numeric facts. Publication rejects unsupported or type-incompatible operators for this built-in node. Other fact-aware components may define their own capability and operator vocabulary.

## Missing values and failures

A definition chooses one missing-value behavior:

- `route_no`: send subjects with no value to `no`.
- `route_yes`: send subjects with no value to `yes`.
- `fail`: fail execution when a requested value is missing.

Throw `FactConfigurationException` for permanent provider setup problems and `FactContractException` for malformed provider data. The built-in condition treats both as non-retryable. Leave temporary transport or service exceptions retryable. During publication, provider failures propagate as provider failures; they are not disguised as an invalid author selection.
