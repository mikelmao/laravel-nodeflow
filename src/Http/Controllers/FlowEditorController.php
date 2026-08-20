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

        $validated = $request->validate([
            'graph' => ['required', 'array'],
            'draft_revision' => ['nullable', 'integer'],
        ]);

        try {
            $revision = app(SaveDraft::class)->save(
                $flow,
                $validated['graph'],
                $validated['draft_revision'] ?? null,
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

        $validated = $request->validate(['graph' => ['required', 'array']]);

        try {
            $version = app(PublishFlow::class)->publish(
                $flow,
                $validated['graph'],
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
}
