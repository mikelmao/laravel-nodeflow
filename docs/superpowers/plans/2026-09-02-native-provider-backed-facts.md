# Native Provider-Backed Facts Implementation Plan

> **For Codex:** Execute this plan task-by-task with test-driven development and verify every public PHP and TypeScript contract before opening the pull request.

**Goal:** Add a product-neutral, first-class facts abstraction that lets workflow fields and a built-in condition node consume versioned values from host-provided bulk resolvers.

**Architecture:** The package will keep `core.condition` and `SubjectAttributeRegistry` unchanged for local, synchronous attributes. A separate `Nodeflow\Facts` subsystem will own immutable catalogue and predicate values, provider registration, publish-time compilation, and runtime bulk resolution. Fact-aware schema fields expose capability metadata to the editor, while reusable React controls load a host-provided catalogue endpoint. Publication will run registered graph compilers before activity-policy and trigger-activation compilation.

**Tech Stack:** PHP 8.3, Laravel 12/13, Pest, React 19, TypeScript, Vitest.

---

## Task 1: Preserve the generic uniform-audience execution prerequisite

**Files:**
- Modify: `src/Execution/NodeRunner.php`
- Create: `src/Execution/UniformAudienceResultValidator.php`
- Create: `src/Nodes/HandlesUniformAudience.php`
- Test: `tests/Feature/NodeRunnerTest.php`
- Test: `tests/Feature/UniformAudienceExecutionTest.php`

1. Cherry-pick only the generic uniform-audience implementation and test commits already developed on `feature/nodeflow-integration`.
2. Confirm the resulting source, tests, and public documentation contain no application-specific names.
3. Run the focused execution tests:
   `vendor/bin/pest --compact tests/Feature/NodeRunnerTest.php tests/Feature/UniformAudienceExecutionTest.php`
4. Commit the generic prerequisite if the cherry-picks do not preserve their existing commits.

## Task 2: Define validated fact catalogue values

**Files:**
- Create: `src/Facts/FactCapability.php`
- Create: `src/Facts/FactValueType.php`
- Create: `src/Facts/MissingFactBehavior.php`
- Create: `src/Facts/FactOption.php`
- Create: `src/Facts/FactDefinition.php`
- Create: `src/Facts/FactCatalogue.php`
- Create: `src/Facts/FactPredicate.php`
- Create: `src/Facts/CompiledFactPredicate.php`
- Test: `tests/Unit/Facts/FactDefinitionTest.php`
- Test: `tests/Unit/Facts/FactPredicateTest.php`

1. Write failing tests for stable provider/fact keys, positive versions, supported scalar types, non-empty capabilities and operator sets, unique typed options, and deterministic wire serialization.
2. Write failing tests for authored predicates (`provider`, `key`, `version`, `operator`, `value`) and compiled predicates that additionally pin `type`, `missing_behavior`, and `catalogue_revision`.
3. Implement immutable value objects with exact-key parsing and actionable `InvalidArgumentException` messages.
4. Implement canonical scalar/list comparison values for `equals`, `not_equals`, `in`, `greater_than`, and `less_than`, constrained by the definition’s declared operators.
5. Run: `vendor/bin/pest --compact tests/Unit/Facts`
6. Commit: `feat: define provider-backed fact contracts`

## Task 3: Add provider registration and contexts

**Files:**
- Create: `src/Facts/FactProvider.php`
- Create: `src/Facts/FactProviderRegistry.php`
- Create: `src/Facts/FactCatalogueContext.php`
- Create: `src/Facts/FactResolutionContext.php`
- Create: `src/Facts/FactResolution.php`
- Create: `src/Facts/Exceptions/FactConfigurationException.php`
- Create: `src/Facts/Exceptions/FactContractException.php`
- Modify: `src/NodeflowServiceProvider.php`
- Test: `tests/Unit/Facts/FactProviderRegistryTest.php`
- Test: `tests/Feature/ServiceProviderTest.php`

1. Write failing tests for duplicate provider keys, unknown providers, deterministic ordering, and singleton registration.
2. Define `FactProvider::key()`, `catalogue(FactCatalogueContext)`, and `resolve(FactResolutionContext, CompiledFactPredicate, array $subjectIds)`.
3. Make resolution results explicit `{subject_id, value, missing}` objects; reject contradictory missing/value states.
4. Register an empty `FactProviderRegistry` singleton that hosts can extend during boot.
5. Run the focused tests and commit: `feat: add fact provider registry`

## Task 4: Make fact predicates first-class schema fields

**Files:**
- Modify: `src/Schema/Field.php`
- Create: `src/Schema/FactField.php`
- Modify: `tests/Feature/SchemaTest.php`
- Modify: `tests/Feature/TriggerSchemaTest.php`

1. Write failing schema tests for `Field::factPredicate($key, $capability)` and `Field::factPredicates($key, $capability, $maximum)`.
2. Require a stable capability key, emit `fact_capability` and `max_items` in PHP/wire metadata, and validate the authoring shape without pretending the catalogue is static.
3. Ensure singular fields accept one exact authored predicate and plural fields accept bounded lists.
4. Run schema tests and commit: `feat: expose fact-aware workflow fields`

## Task 5: Add an extensible publication compiler pipeline

**Files:**
- Create: `src/Publishing/GraphCompiler.php`
- Create: `src/Publishing/GraphCompilerRegistry.php`
- Create: `src/Facts/Publishing/CompileFacts.php`
- Modify: `src/Publishing/PublishFlow.php`
- Modify: `src/NodeflowServiceProvider.php`
- Test: `tests/Feature/PublishFlowTest.php`
- Create: `tests/Feature/Facts/CompileFactsTest.php`

1. Write a failing test proving registered compilers run in deterministic priority/order before activity-policy compilation and outside the publication transaction.
2. Write failing fact compilation tests covering node and trigger definitions, singular/plural fields, fresh catalogue retrieval, capability/operator/type checks, duplicate fact rejection in one list, deterministic ordering, and pinned revision/type/missing behavior.
3. Implement the generic compiler registry and make `PublishFlow` pass the graph through it after graph validation and before existing compilers.
4. Implement `CompileFacts` by discovering fields from registered node/trigger definitions rather than special-casing node types.
5. Convert provider/contract problems into field-scoped `GraphInvalidException` errors suitable for the editor.
6. Run focused tests and commit: `feat: compile fact predicates during publication`

## Task 6: Implement the bulk fact condition node

**Files:**
- Create: `src/Nodes/Core/FactConditionNode.php`
- Create: `src/Facts/FactPredicateEvaluator.php`
- Modify: `src/NodeflowServiceProvider.php`
- Create: `tests/Feature/Facts/FactConditionNodeTest.php`

1. Write failing tests for `core.fact_condition` schema (`yes`/`no`, runtime capability), provider invocation once per configured chunk, order-independent results, all supported comparisons, and missing-value behavior.
2. Write contract-failure tests for missing/duplicate/unknown subject IDs, type mismatches, non-finite numbers, and provider output exceeding the requested set.
3. Implement the node as `HandlesAudience`, resolving the pinned predicate from the published node configuration and passing run/tenant/trigger context through `FactResolutionContext`.
4. Keep chunking configurable with a safe default and preserve exact audience membership across partitions.
5. Register the node and run focused execution tests.
6. Commit: `feat: add provider-backed fact condition node`

## Task 7: Move reusable authoring UX into the package

**Files:**
- Create: `resources/js/facts/types.ts`
- Create: `resources/js/facts/parseFactCatalogue.ts`
- Create: `resources/js/facts/FactCatalogueContext.tsx`
- Create: `resources/js/controls/FactPredicateControl.tsx`
- Create: `resources/js/controls/FactPredicatesControl.tsx`
- Modify: `resources/js/editor/FlowEditor.tsx`
- Modify: `resources/js/controls/index.ts`
- Modify: `resources/js/index.ts`
- Create: `resources/js/facts/parseFactCatalogue.test.ts`
- Create: `resources/js/controls/FactControls.test.tsx`
- Modify: `resources/js/editor/FlowEditor.test.tsx`

1. Write failing parser tests for exact, bounded, typed catalogue data and graceful rejection of malformed providers/definitions/options.
2. Write failing control tests for loading, unavailable, retry, capability filtering, operator-dependent value inputs, preserving a valid selection, and clearing invalid dependent selections.
3. Add an optional `facts` editor configuration with a catalogue URL and request headers; render normal workflows unchanged when it is absent.
4. Register fact controls as built-ins while preserving host control override behavior.
5. Export the reusable types, parser, context, and controls from the package entrypoint.
6. Run: `npm test -- --run` and `npm run types:check`.
7. Commit: `feat: add reusable fact authoring controls`

## Task 8: Document and verify the public abstraction

**Files:**
- Modify: `README.md`
- Create: `docs/facts.md`
- Modify: `CHANGELOG.md`

1. Document when to use local subject attributes versus provider-backed facts.
2. Include a generic provider registration example, catalogue rules, bulk resolution contract, field declaration, editor configuration, missing behavior, and failure semantics.
3. Search all changed files for application-specific names and remove any occurrence.
4. Run PHP formatting, the complete Pest suite, JavaScript tests, and TypeScript checks.
5. Review `git diff --check`, public API exports, and backward compatibility of existing condition nodes.
6. Commit: `docs: explain provider-backed facts`
