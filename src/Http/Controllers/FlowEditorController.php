<?php

namespace Nodeflow\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Nodeflow\Editor\SaveDraft;
use Nodeflow\Editor\StaleDraftException;
use Nodeflow\Models\Flow;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\Publishing\GraphInvalidException;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Triggers\TriggerRegistry;

/**
 * The editor's server half.
 *
 * Three deliberate shapes here. The draft endpoint does not validate, because a
 * graph mid-edit is allowed to be broken and refusing to store it would make
 * autosave useless. Publish returns per-node errors so the canvas can render each
 * beside its node. And nothing reads a foreign key out of the request: open issue
 * G-3 records that Flow::currentVersion() is deliberately unscoped, which is safe
 * only while current_version_id stays inside the tenant — so it is set from a
 * version this code just created, never from input.
 *
 * The draft concurrency token is draft_revision, an integer counter, not a
 * timestamp: see SaveDraft's doc block. It round-trips as-is between client and
 * server, so this controller never touches draft_updated_at as anything but
 * informational display data.
 */
class FlowEditorController extends Controller
{
    // Illuminate\Routing\Controller is a bare abstract class — verified — so the
    // trait is required for $this->authorize() to exist at all. Without it every
    // endpoint here fatals rather than authorizing.
    use AuthorizesRequests;

    public function edit(Flow $flow): \Inertia\Response
    {
        $this->authorize('update', $flow);

        return Inertia::render('nodeflow/editor', [
            'flow' => [
                'id' => $flow->id,
                'name' => $flow->name,
                'trigger_type' => $flow->trigger_type,
                'status' => $flow->status,
                'version' => $flow->currentVersion?->version,
                'draft_revision' => $flow->draft_revision,
                // Not the concurrency token — see draft_revision above — but real
                // and worth showing an author as "last saved 3 minutes ago".
                'draft_updated_at' => $flow->draft_updated_at?->toIso8601String(),
            ],
            // The draft wins when there is one: it is the author's unsaved work.
            'graph' => $flow->draft_graph
                ?? $flow->currentVersion?->graph
                ?? ['start' => '', 'nodes' => [], 'edges' => []],
            'palette' => app(NodeRegistry::class)->palette(),
            'triggers' => app(TriggerRegistry::class)->palette(),
        ]);
    }

    public function draft(Request $request, Flow $flow): JsonResponse
    {
        $this->authorize('update', $flow);

        $request->validate(array_merge($this->graphRules(), [
            // Lighter than publish's set by exactly one rule: an edge may arrive
            // without an output, because a client dragging from a node's only
            // handle has no output name to send. Everything else is identical —
            // structural rules do not make a half-finished graph unsavable, and a
            // node with no id is not half-finished, it is malformed.
            'graph.edges.*.output' => ['nullable', 'string'],
            'draft_revision' => ['nullable', 'integer'],
        ]));

        try {
            $revision = app(SaveDraft::class)->save(
                $flow,
                // The request's own input, not validate()'s return value: the
                // validated array contains only keys that had rules, and a graph
                // legitimately carries keys the package round-trips untouched —
                // `position` on a node, which the integration docs promise. Taking
                // the validated array here silently strips every node's canvas
                // coordinates on save.
                $request->input('graph'),
                $request->input('draft_revision'),
            );
        } catch (StaleDraftException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'graph' => $e->graph(),
                'draft_revision' => $e->revision(),
            ], 409);
        }

        return response()->json(['draft_revision' => $revision]);
    }

    public function publish(Request $request, Flow $flow): JsonResponse
    {
        $this->authorize('publish', $flow);

        $request->validate($this->graphRules());

        try {
            $version = app(PublishFlow::class)->publish(
                $flow,
                // See draft(): the raw input, so a node's `position` survives into
                // the frozen version instead of being stripped by validated().
                $request->input('graph'),
                (string) ($request->user()?->getAuthIdentifier() ?? ''),
            );
        } catch (GraphInvalidException $e) {
            return response()->json([
                'message' => 'The flow could not be published.',
                'errors' => $e->errors(),
                'node_errors' => $e->nodeErrors(),
            ], 422);
        }

        // draft_revision travels back with the version because publishing does not
        // reset it (see PublishFlow) and clients stay open across a publish: the
        // editor must keep echoing the current token on its next autosave, and
        // this is the only response that would otherwise leave it guessing.
        return response()->json([
            'version' => $version->version,
            'draft_revision' => (int) ($flow->draft_revision ?? 0),
        ]);
    }

    /**
     * Structural rules for a graph payload.
     *
     * They live in the controller because they are the HTTP contract, not a
     * property of a Graph: `['graph' => ['required','array']]` validated the
     * container and nothing inside it, so ordinary client bugs — a node with no
     * `id`, an edge with no `to` — reached Graph::fromArray() and came back as an
     * unrenderable 500, with a stack trace and file paths under APP_DEBUG, from
     * the one endpoint whose whole new feature is structured, renderable errors.
     *
     * Structure only. Nothing here judges whether the graph makes sense: that is
     * GraphValidator's job at publish, and deliberately nobody's job for a draft.
     * So `start` stays nullable even for publish — "the flow has no start node
     * set" is a renderable node_errors entry the canvas can show, and demoting it
     * to a field-validation 422 would lose that. Likewise nothing checks that a
     * node's type is registered or that an edge points anywhere real.
     *
     * Unlisted keys are untouched, which matters: a node carries `position` for
     * canvas coordinates and the package round-trips it verbatim.
     */
    private function graphRules(): array
    {
        return [
            'graph' => ['required', 'array'],
            'graph.start' => ['nullable', 'string'],
            'graph.nodes' => ['nullable', 'array'],
            'graph.nodes.*.id' => ['required', 'string'],
            'graph.nodes.*.type' => ['required', 'string'],
            'graph.nodes.*.config' => ['nullable', 'array'],
            'graph.edges' => ['nullable', 'array'],
            'graph.edges.*.from' => ['required', 'string'],
            'graph.edges.*.to' => ['required', 'string'],
            // GraphValidator and Graph::targetsFor() both read $edge['output']
            // unconditionally, so an edge without one is a 500 rather than a
            // validation failure. Publish requires it; draft does not (see there).
            'graph.edges.*.output' => ['required', 'string'],
        ];
    }
}
