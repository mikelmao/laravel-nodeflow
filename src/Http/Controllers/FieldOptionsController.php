<?php

namespace Nodeflow\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nodeflow\Models\Flow;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\OptionSource;
use Nodeflow\Schema\UnknownOptionSourceException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves one field's options for the current tenant, at edit time.
 *
 * Options are resolved lazily, per field, rather than baked into the palette:
 * eager resolution would run every option source of every registered node on every
 * editor page load, including nodes the author never places — a dozen domain nodes
 * would mean a dozen tenant-scoped lookups to draw a sidebar.
 *
 * The route carries a node type and a field key. It does NOT carry the source
 * class, and this controller never reads one from the request. The class comes from
 * the node's own definition(), so the set of instantiable classes is exactly the
 * set some node declared — not "anything in the application".
 */
class FieldOptionsController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Flow $flow, string $type, string $field): JsonResponse
    {
        $this->authorize('update', $flow);

        $registry = app(NodeRegistry::class);

        if (! $registry->has($type)) {
            throw new NotFoundHttpException("Unknown node type [{$type}].");
        }

        $declared = $this->field($registry->resolve($type)->definition()->fieldObjects(), $field);

        if ($declared === null) {
            throw new NotFoundHttpException("Node type [{$type}] declares no field [{$field}].");
        }

        $sourceClass = $declared->optionsSourceClass();

        if ($sourceClass === null) {
            // Static options already travel in the palette payload. Answering here
            // would imply this endpoint is where they come from.
            throw new NotFoundHttpException("Field [{$field}] on [{$type}] has no dynamic option source.");
        }

        $source = app($sourceClass);

        if (! $source instanceof OptionSource) {
            throw UnknownOptionSourceException::notAnOptionSource($sourceClass);
        }

        return response()->json(['options' => $source->options()]);
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
}
