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

        return Inertia::render('nodeflow/run', [
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'terminal' => $overlay['terminal'],
                'strategy' => $run->strategy,
                'is_test' => (bool) $run->is_test,
                'started_at' => $run->started_at?->toIso8601String(),
                'ended_at' => $run->ended_at?->toIso8601String(),
                'error' => $run->error,
                'version' => (int) $version->version,
                // Eager-loading the flow re-applies Flow's tenant scope, which
                // is fine here and deliberately unlike CheckNodeTypesResolver:
                // that runs in a console context with no ambient tenant, where
                // the scope would throw. A request has a resolved tenant, so
                // the scope resolves — and it is a welcome second check that
                // this version's flow really is in this tenant.
                'flow' => ['id' => $version->flow->id, 'name' => $version->flow->name],
            ],
            'graph' => $version->graph,
            'palette' => app(NodeRegistry::class)->palette(),
            'overlay' => $overlay,
            // 'subjects' belongs here too, but its route is Task 5's: this task
            // registers only nodeflow.runs.show and nodeflow.runs.overlay, and
            // route() throws RouteNotFoundException for a name nothing has
            // registered yet. Task 5 adds the key back once that route exists.
            'urls' => [
                'overlay' => route($this->routeName($request, 'nodeflow.runs.overlay', self::OWN_ROUTE), ['run' => $run]),
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
