<?php

namespace Nodeflow\Console;

use Nodeflow\Graph\Graph;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Nodes\NodeRegistry;

class CheckNodeTypesResolver
{
    /**
     * Find all unresolvable node types in versions with live runs.
     *
     * Returns array of strings in format: "version {id} node {nodeId} type {type}"
     */
    public static function findMissingTypes(NodeRegistry $registry): array
    {
        $missing = [];

        // Explicitly cross-tenant. This is a deploy gate: a version belonging to
        // any tenant whose type no longer resolves is a run that will fail at
        // resume, possibly days into a wait. Scoping this to the ambient tenant —
        // which is null in the console context it runs in — would silently check
        // nothing.
        //
        // Deliberately does not eager-load (or otherwise touch) the `flow`
        // relation. `withoutTenancy()` only strips the scope from this query;
        // it has no effect on a relation's own query, and Flow carries the same
        // BelongsToTenant scope FlowVersion does. Loading `flow` here would run
        // a fresh, still-scoped Flow query and throw under resolver mode with no
        // ambient tenant — the exact failure this method exists to avoid. If a
        // future change needs $version->flow from inside this loop, it must
        // reach it through an explicitly unscoped query, not a lazy or eager
        // load of the relation.
        FlowVersion::withoutTenancy()->chunk(100, function ($versions) use ($registry, &$missing) {
            foreach ($versions as $version) {
                if (! $version->hasLiveRuns()) {
                    continue;
                }

                foreach (Graph::fromArray($version->graph)->nodeIds() as $nodeId) {
                    $type = Graph::fromArray($version->graph)->node($nodeId)['type'] ?? '';

                    if (! $registry->has($type)) {
                        $missing[] = "version {$version->id} node {$nodeId} type {$type}";
                    }
                }
            }
        });

        return $missing;
    }
}
