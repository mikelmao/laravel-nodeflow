<?php

namespace Nodeflow\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nodeflow\Models\Flow;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\OptionSource;
use Nodeflow\Schema\UnknownOptionSourceException;
use Nodeflow\Triggers\TriggerNodeRegistry;
use Nodeflow\Triggers\TriggerSourceCompatibility;
use Nodeflow\Triggers\TriggerSourceRegistry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves one field's options for the current tenant, at edit time.
 *
 * Options are resolved lazily, per field, rather than baked into the palette:
 * eager resolution would run every option source of every registered node on every
 * editor page load, including nodes the author never places — a dozen domain nodes
 * would mean a dozen tenant-scoped lookups to draw a sidebar.
 *
 * Routes carry stable component, source, and field keys. They do NOT carry an
 * option-source class, and this controller never reads one from the request. The
 * class comes from an allowlisted component's definition(), so the set of
 * instantiable classes is exactly the set a node or trigger source declared —
 * not "anything in the application".
 */
class FieldOptionsController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Request $request): JsonResponse
    {
        $flow = $this->boundFlow($request);
        $type = $this->routeString($request, 'type');
        $field = $this->routeString($request, 'field');
        $this->authorize('update', $flow);

        $registry = app(NodeRegistry::class);

        if (! $registry->has($type)) {
            throw new NotFoundHttpException("Unknown node type [{$type}].");
        }

        $declared = $this->field($registry->resolve($type)->definition()->fieldObjects(), $field);

        if ($declared === null) {
            throw new NotFoundHttpException("Node type [{$type}] declares no field [{$field}].");
        }

        return $this->options($declared, $type, $field);
    }

    public function trigger(Request $request): JsonResponse
    {
        $flow = $this->boundFlow($request);
        $type = $this->routeString($request, 'type');
        $field = $this->routeString($request, 'field');
        $this->authorize('update', $flow);

        $triggers = app(TriggerNodeRegistry::class);

        if (! $triggers->has($type)) {
            throw new NotFoundHttpException("Unknown trigger node type [{$type}].");
        }

        $declared = $this->field($triggers->resolve($type)->definition()->fieldObjects(), $field);

        if ($declared === null) {
            throw new NotFoundHttpException("Trigger node type [{$type}] declares no field [{$field}].");
        }

        return $this->options($declared, $type, $field);
    }

    public function triggerSource(Request $request): JsonResponse
    {
        $flow = $this->boundFlow($request);
        $type = $this->routeString($request, 'type');
        $source = $this->routeString($request, 'source');
        $field = $this->routeString($request, 'field');
        $this->authorize('update', $flow);

        $triggers = app(TriggerNodeRegistry::class);

        if (! $triggers->has($type)) {
            throw new NotFoundHttpException("Unknown trigger node type [{$type}].");
        }

        $trigger = $triggers->resolve($type);
        $driver = $trigger->driver();
        $sources = app(TriggerSourceRegistry::class);

        if (! $sources->has($driver, $source)) {
            throw new NotFoundHttpException("Unknown trigger source [{$source}] for node type [{$type}].");
        }

        $resolvedSource = $sources->resolve($driver, $source);
        $compatibility = app(TriggerSourceCompatibility::class);

        if (! $compatibility->authorable($trigger, $resolvedSource)) {
            throw new NotFoundHttpException("Trigger source [{$source}] is incompatible with node type [{$type}].");
        }

        $sourceDefinition = $resolvedSource->definition();

        $declared = $this->field($sourceDefinition->fieldObjects(), $field);

        if ($declared === null) {
            throw new NotFoundHttpException("Trigger source [{$source}] declares no field [{$field}].");
        }

        return $this->options($declared, $type.':'.$source, $field);
    }

    private function options(Field $declared, string $component, string $field): JsonResponse
    {
        $sourceClass = $declared->optionsSourceClass();

        if ($sourceClass === null) {
            // Static options already travel in the palette payload. Answering here
            // would imply this endpoint is where they come from.
            throw new NotFoundHttpException("Field [{$field}] on [{$component}] has no dynamic option source.");
        }

        $source = app($sourceClass);

        if (! $source instanceof OptionSource) {
            throw UnknownOptionSourceException::notAnOptionSource($sourceClass);
        }

        // Cast to an object: a PHP array with no entries encodes as JSON `[]`, not
        // `{}`. The docs promise `options` is always a JSON object, `{}` when there
        // are none, so a fresh host with no sources registered yet must not hand a
        // client-typed-from-the-docs a shape it did not sign up for.
        return response()->json(['options' => (object) $source->options()]);
    }

    /** @param  Field[]  $fields */
    private function field(array $fields, string $key): ?Field
    {
        foreach ($fields as $candidate) {
            if ($candidate->key === $key) {
                return $candidate;
            }
        }

        return null;
    }

    private function boundFlow(Request $request): Flow
    {
        $flow = $request->route('flow');

        if (! $flow instanceof Flow) {
            $flow = (new Flow)->resolveRouteBinding($flow);
        }

        abort_unless($flow instanceof Flow, 404);

        return $flow;
    }

    private function routeString(Request $request, string $key): string
    {
        $value = $request->route($key);

        abort_unless(is_string($value), 404);

        return $value;
    }
}
