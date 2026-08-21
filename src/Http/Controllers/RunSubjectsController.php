<?php

namespace Nodeflow\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Run;
use Nodeflow\Runs\RunSubjects;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Who is sitting at one node of one run, a page at a time.
 *
 * {node} is validated against this run's own pinned graph before it reaches a
 * query. That is not input hygiene for its own sake: a node id that is real in
 * a *different* run's graph must not resolve here, because being entitled to
 * this run says nothing about another one. Accepting a raw key as equivalent to
 * authorization is exactly what open issue G-3 warns about, and the 404 for
 * that case is asserted rather than assumed.
 *
 * This controller never names RunSubject; the reader shapes the rows.
 */
class RunSubjectsController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Request $request, Run $run, string $node): JsonResponse
    {
        $this->authorize('view', $run);

        $graph = Graph::fromArray($run->flowVersion->graph);

        if ($graph->node($node) === null) {
            // 404, not an empty 200: "that node does not exist in this run"
            // and "nobody is at that node" are different answers, and an
            // operator reading an empty list cannot tell them apart.
            throw new NotFoundHttpException("Run [{$run->id}] has no node [{$node}] in its pinned graph.");
        }

        $page = app(RunSubjects::class)->atNode($run, $node, $request->query('cursor'));

        return response()->json([
            'node' => $node,
            'data' => $page['data'],
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
