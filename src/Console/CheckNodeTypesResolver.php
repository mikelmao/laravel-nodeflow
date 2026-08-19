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

        FlowVersion::query()->with('flow')->chunk(100, function ($versions) use ($registry, &$missing) {
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
