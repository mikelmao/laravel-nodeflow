<?php

namespace Nodeflow\Console;

use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\TriggerActivation;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\Triggers\TriggerDriverRegistry;
use Nodeflow\Triggers\TriggerNodeRegistry;
use Nodeflow\Triggers\TriggerSourceRegistry;
use Throwable;

class CheckNodeTypesResolver
{
    /**
     * Find registrations required by active trigger projections or live pinned runs.
     *
     * @return string[] deterministic, actionable failure descriptions
     */
    public static function findMissingTypes(
        NodeRegistry $nodes,
        ?TriggerNodeRegistry $triggerNodes = null,
        ?TriggerDriverRegistry $drivers = null,
        ?TriggerSourceRegistry $sources = null,
    ): array {
        $triggerNodes ??= app(TriggerNodeRegistry::class);
        $drivers ??= app(TriggerDriverRegistry::class);
        $sources ??= app(TriggerSourceRegistry::class);

        /** @var array<string, string> $issues */
        $issues = [];

        // Active projections are system routing state, so this query is tenant
        // neutral and mirrors TriggerActivationRepository's active-flow filter.
        TriggerActivation::withoutTenancy()
            ->select('nodeflow_trigger_activations.*')
            ->join('nodeflow_flows as health_flows', 'health_flows.id', '=', 'nodeflow_trigger_activations.flow_id')
            ->where('health_flows.status', 'active')
            ->orderBy('nodeflow_trigger_activations.flow_version_id')
            ->chunk(100, function ($activations) use ($triggerNodes, $drivers, $sources, &$issues): void {
                foreach ($activations as $activation) {
                    $identity = self::identity(
                        (string) $activation->flow_id,
                        (string) $activation->flow_version_id,
                        (string) $activation->trigger_node_id,
                    );

                    $version = FlowVersion::withoutTenancy()->find($activation->flow_version_id);
                    $type = self::triggerTypeFromGraph($version?->graph, (string) $activation->trigger_node_id);
                    if ($type === null) {
                        self::issue($issues, $identity, 'graph', "{$identity} malformed trigger graph; restore the published graph/version metadata.");
                    } elseif (! $triggerNodes->has($type)) {
                        self::issue($issues, $identity, 'trigger-node:'.$type, "{$identity} missing trigger node type {$type}; register its class with \\Nodeflow\\Nodeflow::registerTriggerNodes([\\Your\\TriggerNode::class]).");
                    }

                    self::checkRouting(
                        $issues,
                        $identity,
                        (string) $activation->driver,
                        (string) $activation->source,
                        $drivers,
                        $sources,
                    );
                }
            });

        // Historical activations are intentionally replaced on publication.
        // Live runs therefore pin graphs, not activation rows; derive the
        // trigger tuple from the registered trigger node where schema permits.
        FlowVersion::withoutTenancy()->orderBy('id')->chunk(100, function ($versions) use (
            $nodes,
            $triggerNodes,
            $drivers,
            $sources,
            &$issues,
        ): void {
            foreach ($versions as $version) {
                if (! $version->hasLiveRuns()) continue;

                $graph = $version->graph;
                if (! is_array($graph) || ! is_array($graph['nodes'] ?? null)) {
                    $identity = self::identity((string) $version->flow_id, (string) $version->id, self::safeStart($graph));
                    self::issue($issues, $identity, 'graph', "{$identity} malformed trigger graph; restore the pinned graph/version metadata.");
                    continue;
                }

                $triggerIds = Run::withoutTenancy()
                    ->where('flow_version_id', $version->id)
                    ->whereIn('status', ['pending', 'running', 'waiting', 'blocked'])
                    ->whereNotIn('started_via', ['manual', 'subflow'])
                    ->pluck('trigger_node_id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->filter(static fn (string $id): bool => $id !== '')
                    ->unique()
                    ->values()
                    ->all();
                $unseenTriggerIds = array_fill_keys($triggerIds, true);
                $seenNodeIds = [];

                foreach ($graph['nodes'] as $rawNode) {
                    if (! is_array($rawNode) || ! is_string($rawNode['id'] ?? null) || ! is_string($rawNode['type'] ?? null)) {
                        $identity = self::identity((string) $version->flow_id, (string) $version->id, self::safeStart($graph));
                        self::issue($issues, $identity, 'graph', "{$identity} malformed trigger graph; restore the pinned graph/version metadata.");
                        continue;
                    }

                    $nodeId = $rawNode['id'];
                    $type = $rawNode['type'];
                    $identity = self::identity((string) $version->flow_id, (string) $version->id, $nodeId);
                    $isTriggerPosition = in_array($nodeId, $triggerIds, true);

                    if (isset($seenNodeIds[$nodeId])) {
                        self::issue($issues, $identity, 'graph-duplicate', "{$identity} malformed trigger graph; duplicate node identity in the pinned graph.");
                    }
                    $seenNodeIds[$nodeId] = true;
                    unset($unseenTriggerIds[$nodeId]);

                    if ($triggerNodes->has($type)) {
                        // Manual and sub-flow runs enter after the authored
                        // trigger, so no trigger registration is needed to
                        // resume this pinned version.
                        if ($triggerIds === []) continue;

                        if (! $isTriggerPosition) {
                            self::issue($issues, $identity, 'graph-family', "{$identity} malformed trigger graph; a trigger node appears outside the pinned trigger identity.");
                            continue;
                        }

                        try {
                            $trigger = $triggerNodes->resolve($type);
                            $config = $rawNode['config'] ?? [];
                            if (! is_array($config)) throw new \InvalidArgumentException;
                            $driver = $trigger->driver();
                            $source = $trigger->source($config);
                            if ($driver === '' || $source === '') throw new \InvalidArgumentException;
                            self::checkRouting($issues, $identity, $driver, $source, $drivers, $sources);
                        } catch (Throwable) {
                            self::issue($issues, $identity, 'metadata', "{$identity} malformed trigger metadata; restore the pinned trigger configuration.");
                        }
                        continue;
                    }

                    if ($nodes->has($type)) {
                        if ($isTriggerPosition) {
                            self::issue($issues, $identity, 'graph-family', "{$identity} malformed trigger graph; the pinned trigger identity resolves to an executable node.");
                        }
                        continue;
                    }

                    if ($isTriggerPosition) {
                        self::issue($issues, $identity, 'trigger-node:'.$type, "{$identity} missing trigger node type {$type}; register its class with \\Nodeflow\\Nodeflow::registerTriggerNodes([\\Your\\TriggerNode::class]).");
                    } else {
                        self::issue($issues, $identity, 'node:'.$type, "version {$version->id} node {$nodeId} type {$type}; re-register the executable node or alias it with NodeRegistry::alias('{$type}', 'canonical.type').");
                    }
                }

                foreach (array_keys($unseenTriggerIds) as $missingTriggerId) {
                    $identity = self::identity((string) $version->flow_id, (string) $version->id, (string) $missingTriggerId);
                    self::issue($issues, $identity, 'graph-missing-trigger', "{$identity} malformed trigger graph; the pinned trigger node does not exist.");
                }
            }
        });

        ksort($issues, SORT_STRING);

        return array_values($issues);
    }

    private static function checkRouting(
        array &$issues,
        string $identity,
        string $driver,
        string $source,
        TriggerDriverRegistry $drivers,
        TriggerSourceRegistry $sources,
    ): void {
        if (! $drivers->has($driver)) {
            self::issue($issues, $identity, 'driver:'.$driver, "{$identity} missing trigger driver {$driver}; register its class with \\Nodeflow\\Nodeflow::registerTriggerDrivers([\\Your\\TriggerDriver::class]).");
        }

        if (! $sources->has($driver, $source)) {
            self::issue($issues, $identity, 'source:'.$driver.':'.$source, "{$identity} missing trigger source {$driver}:{$source}; register its class with \\Nodeflow\\Nodeflow::registerTriggerSources([\\Your\\TriggerSource::class]).");
        }
    }

    private static function triggerTypeFromGraph(mixed $graph, string $nodeId): ?string
    {
        if (! is_array($graph) || ! is_array($graph['nodes'] ?? null)) return null;

        foreach ($graph['nodes'] as $node) {
            if (is_array($node) && ($node['id'] ?? null) === $nodeId) {
                return is_string($node['type'] ?? null) && $node['type'] !== '' ? $node['type'] : null;
            }
        }

        return null;
    }

    private static function safeStart(mixed $graph): string
    {
        return is_array($graph) && is_string($graph['start'] ?? null) ? $graph['start'] : 'unknown';
    }

    private static function identity(string $flow, string $version, string $node): string
    {
        return "flow {$flow} version {$version} node {$node}";
    }

    private static function issue(array &$issues, string $identity, string $component, string $message): void
    {
        $issues[$identity."\0".$component] = $message;
    }
}
