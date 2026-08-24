<?php

namespace Nodeflow\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Nodeflow\Graph\Graph;
use Nodeflow\Http\ResolvesRouteNames;
use Nodeflow\Models\Run;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\Runs\RunOverlay;
use Nodeflow\Triggers\TriggerDefinitionContext;
use Nodeflow\Triggers\TriggerNodeRegistry;

/**
 * The run view's server half — read-only, in every sense.
 *
 * The graph comes from the run's own pinned flow_version and from nowhere else.
 * Not draft_graph, which the editor correctly prefers and which a run never
 * executed; and not flow->currentVersion, which may have moved on while this
 * run sits mid-wait on the version it started under. D8 froze that version so
 * this view could be honest about what actually ran, and painting live counts
 * onto a graph the run never executed is the exact misreading spec E7 exists to
 * prevent.
 *
 * Nothing here queries RunSubject or NodeExecution; RunOverlay reaches both
 * through the already-scoped Run's relations. And nothing reads a foreign key
 * from the request: {run} binds through the scoped model, and {node} is a graph
 * key validated against that graph, never a record id (open issue G-3).
 */
class RunViewController extends Controller
{
    use AuthorizesRequests;
    use ResolvesRouteNames;

    /**
     * Uses only unreserved URI characters, so route() leaves it intact for the
     * client to replace with an encoded node id.
     */
    public const NODE_PLACEHOLDER = '__NODEFLOW_NODE__';

    private const OWN_ROUTE = 'nodeflow.runs.show';

    public function show(Request $request, Run $run): \Inertia\Response
    {
        $this->authorize('view', $run);

        $version = $run->flowVersion;
        $graph = Graph::fromArray($version->graph);
        // Once, not once per prop: this is two grouped queries, and reading it
        // twice to fill `run.terminal` and `overlay` would silently double them.
        $overlay = $this->snapshotFor($run, $graph);
        $definitions = new TriggerDefinitionContext;

        return Inertia::render('nodeflow/run', [
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'terminal' => $overlay['terminal'],
                'strategy' => $run->strategy,
                'is_test' => (bool) $run->is_test,
                'started_via' => (string) $run->started_via,
                'trigger_node_id' => (string) $run->trigger_node_id,
                'started_at' => $run->started_at?->toIso8601String(),
                'ended_at' => $run->ended_at?->toIso8601String(),
                'error' => $run->error,
                'version' => (int) $version->version,
                // Eager-loading the flow re-applies Flow's tenant scope, which
                // is fine here and deliberately unlike CheckNodeTypesResolver:
                // that runs in a console context with no ambient tenant, where
                // the scope would throw. A request has a resolved tenant, so
                // the scope resolves — but this is not a second authorization
                // check: G-3's invariant (flow_version_id points inside its
                // own tenant) is what makes this row belong to $run's tenant
                // in the first place, and nothing here re-verifies it. If that
                // invariant were ever violated, the scope would simply find no
                // row and $version->flow->id would throw a 500 (a missing
                // relation), not return a 404 or any other diagnostic.
                'flow' => ['id' => $version->flow->id, 'name' => $version->flow->name],
            ],
            'graph' => $version->graph,
            'palette' => array_merge(
                array_map(
                    fn (array $definition): array => ['kind' => 'executable'] + $definition,
                    app(NodeRegistry::class)->palette(),
                ),
                app(TriggerNodeRegistry::class)->palette($definitions),
            ),
            'overlay' => $overlay,
            'urls' => [
                'overlay' => route($this->routeName($request, 'nodeflow.runs.overlay', self::OWN_ROUTE), ['run' => $run]),
                'subjects' => route($this->routeName($request, 'nodeflow.runs.subjects', self::OWN_ROUTE), [
                    'run' => $run,
                    'node' => self::NODE_PLACEHOLDER,
                ]),
            ],
        ]);
    }

    /**
     * The polling endpoint returns the snapshot and nothing else — no graph, no
     * palette. An endpoint that quietly grew a graph payload would make every
     * poll cost a full page render, and a test asserts the absence of those
     * keys so it cannot happen unnoticed.
     */
    public function overlay(Run $run): JsonResponse
    {
        $this->authorize('view', $run);

        return response()->json(
            $this->snapshotFor($run, Graph::fromArray($run->flowVersion->graph)),
        );
    }

    private function snapshotFor(Run $run, Graph $graph): array
    {
        return app(RunOverlay::class)->snapshot($run, $graph);
    }
}
