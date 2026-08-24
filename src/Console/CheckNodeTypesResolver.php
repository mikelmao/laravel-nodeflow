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
    private const LIVE_STATUSES = ['pending', 'running', 'waiting', 'blocked'];

    public const QUERY_CHUNK_SIZE = 200;

    /** @return string[] deterministic, actionable failure descriptions */
    public static function findMissingTypes(
        NodeRegistry $nodes,
        ?TriggerNodeRegistry $triggerNodes = null,
        ?TriggerDriverRegistry $drivers = null,
        ?TriggerSourceRegistry $sources = null,
    ): array {
        $triggerNodes ??= app(TriggerNodeRegistry::class);
        $drivers ??= app(TriggerDriverRegistry::class);
        $sources ??= app(TriggerSourceRegistry::class);
        $issues = [];

        // Routing and liveness are system state. Every query intentionally
        // bypasses ambient tenancy, and only the two reachable version sets are
        // loaded: published active activations and versions pinned by live runs.
        TriggerActivation::withoutTenancy()
            ->select('nodeflow_trigger_activations.*')
            ->join('nodeflow_flows as health_flows', 'health_flows.id', '=', 'nodeflow_trigger_activations.flow_id')
            ->where('health_flows.status', 'active')
            ->chunkById(self::QUERY_CHUNK_SIZE, function ($activations) use ($triggerNodes, $drivers, $sources, &$issues): void {
                $versionIds = $activations->pluck('flow_version_id')->map(static fn (mixed $id): string => (string) $id)->unique()->values()->all();
                $versions = FlowVersion::withoutTenancy()->whereIn('id', $versionIds)->get()
                    ->keyBy(static fn (FlowVersion $version): string => (string) $version->id);

                foreach ($activations as $activation) {
                    $identity = self::identity((string) $activation->flow_id, (string) $activation->flow_version_id, (string) $activation->trigger_node_id);
                    $version = $versions->get((string) $activation->flow_version_id);
                    $type = self::triggerTypeFromGraph($version?->graph, (string) $activation->trigger_node_id);
                    if ($type === null) {
                        self::issue($issues, $identity, 'graph', "{$identity} malformed trigger graph; restore the published graph/version metadata.");
                    } elseif (! $triggerNodes->has($type)) {
                        self::missingTriggerNode($issues, $identity, $type);
                    }
                    self::checkRouting($issues, $identity, (string) $activation->driver, (string) $activation->source, $drivers, $sources);
                }
            }, 'nodeflow_trigger_activations.id', 'id');

        Run::withoutTenancy()
            ->select('flow_version_id')
            ->whereIn('status', self::LIVE_STATUSES)
            ->whereNotNull('flow_version_id')
            ->distinct()
            ->orderBy('flow_version_id')
            ->chunk(self::QUERY_CHUNK_SIZE, function ($rows) use ($nodes, $triggerNodes, $drivers, $sources, &$issues): void {
                $versionIds = $rows->pluck('flow_version_id')->map(static fn (mixed $id): string => (string) $id)->values()->all();
                self::checkLiveBatch($versionIds, $nodes, $triggerNodes, $drivers, $sources, $issues);
            });

        ksort($issues, SORT_STRING);

        return array_values($issues);
    }

    /** @param list<string> $versionIds */
    private static function checkLiveBatch(
        array $versionIds,
        NodeRegistry $nodes,
        TriggerNodeRegistry $triggerNodes,
        TriggerDriverRegistry $drivers,
        TriggerSourceRegistry $sources,
        array &$issues,
    ): void {
        if ($versionIds === []) return;

        $versions = FlowVersion::withoutTenancy()->whereIn('id', $versionIds)->get()
            ->keyBy(static fn (FlowVersion $version): string => (string) $version->id);
        $states = [];
        foreach ($versionIds as $versionId) {
            $states[$versionId] = ['requiresTrigger' => false, 'triggerIds' => []];
        }

        $tuples = Run::withoutTenancy()
            ->select(['flow_version_id', 'started_via', 'trigger_node_id'])
            ->whereIn('status', self::LIVE_STATUSES)
            ->whereIn('flow_version_id', $versionIds)
            ->distinct()
            ->orderBy('flow_version_id')
            ->orderBy('started_via')
            ->orderBy('trigger_node_id')
            ->lazy(self::QUERY_CHUNK_SIZE);
        foreach ($tuples as $tuple) {
            $versionId = (string) $tuple->flow_version_id;
            if (! isset($states[$versionId]) || in_array((string) $tuple->started_via, ['manual', 'subflow'], true)) continue;
            $states[$versionId]['requiresTrigger'] = true;
            $triggerId = (string) $tuple->trigger_node_id;
            if ($triggerId !== '') $states[$versionId]['triggerIds'][$triggerId] = true;
        }

        foreach ($versionIds as $versionId) {
            /** @var FlowVersion|null $version */
            $version = $versions->get($versionId);
            if ($version === null) continue;
            self::checkLiveVersion($version, $states[$versionId], $nodes, $triggerNodes, $drivers, $sources, $issues);
        }
    }

    private static function checkLiveVersion(
        FlowVersion $version,
        array $runState,
        NodeRegistry $nodes,
        TriggerNodeRegistry $triggerNodes,
        TriggerDriverRegistry $drivers,
        TriggerSourceRegistry $sources,
        array &$issues,
    ): void {
        $graph = $version->graph;
        $start = self::safeStart($graph);
        if (! is_array($graph) || ! is_array($graph['nodes'] ?? null) || $start === 'unknown') {
            $identity = self::identity((string) $version->flow_id, (string) $version->id, $start);
            self::issue($issues, $identity, 'graph', "{$identity} malformed trigger graph; restore the pinned graph/version metadata.");
            return;
        }

        $requiresTrigger = $runState['requiresTrigger'];
        $triggerIds = array_keys($runState['triggerIds']);
        if ($requiresTrigger && $triggerIds === []) {
            $triggerIds = [$start];
        }
        $unseenTriggerIds = array_fill_keys($triggerIds, true);
        $seenNodeIds = [];

        foreach ($graph['nodes'] as $rawNode) {
            if (! is_array($rawNode) || ! is_string($rawNode['id'] ?? null) || ! is_string($rawNode['type'] ?? null) || $rawNode['id'] === '' || $rawNode['type'] === '') {
                $identity = self::identity((string) $version->flow_id, (string) $version->id, $start);
                self::issue($issues, $identity, 'graph', "{$identity} malformed trigger graph; restore the pinned graph/version metadata.");
                continue;
            }

            $nodeId = $rawNode['id'];
            $type = $rawNode['type'];
            $identity = self::identity((string) $version->flow_id, (string) $version->id, $nodeId);
            $isStart = $nodeId === $start;
            $isPinnedTrigger = in_array($nodeId, $triggerIds, true);

            if (isset($seenNodeIds[$nodeId])) {
                self::issue($issues, $identity, 'graph-duplicate', "{$identity} malformed trigger graph; duplicate node identity in the pinned graph.");
            }
            $seenNodeIds[$nodeId] = true;
            unset($unseenTriggerIds[$nodeId]);

            // The authored start is trigger-family in every new-project graph.
            // Manual/subflow runs enter after it, so it is never classified as
            // executable and its registrations are required only when a real
            // trigger-origin run still depends on them.
            if ($isStart) {
                if (! $requiresTrigger) {
                    continue;
                }
                if (! $isPinnedTrigger) {
                    self::issue($issues, $identity, 'graph-family', "{$identity} malformed trigger graph; the live trigger identity does not match the authored start node.");
                    continue;
                }
                if ($nodes->has($type)) {
                    self::issue($issues, $identity, 'graph-family', "{$identity} malformed trigger graph; the authored start resolves to an executable node.");
                    continue;
                }
                if (! $triggerNodes->has($type)) {
                    self::missingTriggerNode($issues, $identity, $type);
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

            if ($triggerNodes->has($type)) {
                self::issue($issues, $identity, 'graph-family', "{$identity} malformed trigger graph; a trigger node appears outside the authored start node.");
                continue;
            }
            if ($nodes->has($type)) {
                if ($isPinnedTrigger) {
                    self::issue($issues, $identity, 'graph-family', "{$identity} malformed trigger graph; the pinned trigger identity resolves to an executable node.");
                }
                continue;
            }

            if ($isPinnedTrigger && $requiresTrigger) {
                self::missingTriggerNode($issues, $identity, $type);
            } else {
                self::issue($issues, $identity, 'node:'.$type, "{$identity} missing executable node type {$type}; re-register its class or alias it with NodeRegistry::alias('{$type}', 'canonical.type').");
            }
        }

        if (! isset($seenNodeIds[$start])) {
            $identity = self::identity((string) $version->flow_id, (string) $version->id, $start);
            self::issue($issues, $identity, 'graph-start', "{$identity} malformed trigger graph; the authored start node does not exist.");
        }
        foreach (array_keys($unseenTriggerIds) as $missingTriggerId) {
            $identity = self::identity((string) $version->flow_id, (string) $version->id, (string) $missingTriggerId);
            self::issue($issues, $identity, 'graph-missing-trigger', "{$identity} malformed trigger graph; the pinned trigger node does not exist.");
        }
    }

    private static function missingTriggerNode(array &$issues, string $identity, string $type): void
    {
        self::issue($issues, $identity, 'trigger-node:'.$type, "{$identity} missing trigger node type {$type}; register its class with \\Nodeflow\\Nodeflow::registerTriggerNodes([\\Your\\TriggerNode::class]).");
    }

    private static function checkRouting(array &$issues, string $identity, string $driver, string $source, TriggerDriverRegistry $drivers, TriggerSourceRegistry $sources): void
    {
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
        return is_array($graph) && is_string($graph['start'] ?? null) && $graph['start'] !== '' ? $graph['start'] : 'unknown';
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
