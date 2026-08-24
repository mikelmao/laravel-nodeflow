<?php

namespace Nodeflow\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Nodeflow\Editor\SaveDraft;
use Nodeflow\Editor\StaleDraftException;
use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphValidator;
use Nodeflow\Http\ResolvesRouteNames;
use Nodeflow\Models\Flow;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\Publishing\GraphInvalidException;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Triggers\TriggerDriverRegistry;
use Nodeflow\Triggers\TriggerNodeRegistry;
use Nodeflow\Triggers\TriggerSourceRegistry;
use Nodeflow\Triggers\Webhook\WebhookCredentials;
use Nodeflow\Triggers\Webhook\WebhookTriggerDriver;

/**
 * The editor's server half.
 *
 * Three deliberate shapes here. The draft endpoint checks a graph's structure but
 * never its meaning, because a graph mid-edit is allowed to be broken and refusing
 * to store it would make autosave useless — see graphRules() for exactly where
 * that line falls. Publish returns per-node errors so the canvas can render each
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
    use ResolvesRouteNames;

    /**
     * Per-field URL-template sentinels. Both use only unreserved URI characters,
     * so route() leaves them intact for the client to replace with encoded values.
     */
    private const TYPE_PLACEHOLDER = '__NODEFLOW_TYPE__';

    private const FIELD_PLACEHOLDER = '__NODEFLOW_FIELD__';

    private const SOURCE_PLACEHOLDER = '__NODEFLOW_SOURCE__';

    public function edit(Request $request): \Inertia\Response
    {
        $flow = $this->boundFlow($request);
        $this->authorize('update', $flow);
        $endpoint = $flow->webhookEndpoint()->first();
        $activation = $flow->triggerActivation()->first();

        return Inertia::render('nodeflow/editor', [
            'flow' => [
                'id' => $flow->id,
                'name' => $flow->name,
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
            'palette' => array_map(
                fn (array $definition): array => ['kind' => 'executable'] + $definition,
                app(NodeRegistry::class)->palette(),
            ),
            'trigger_nodes' => app(TriggerNodeRegistry::class)->palette(),
            'trigger_sources' => $this->triggerSources(),
            // Retained only as a compatibility-shaped array until the editor
            // consumes server-authored trigger-node metadata. There is no
            // mutable flow-level trigger registry behind it.
            'triggers' => [],
            'webhook' => $endpoint === null ? null : [
                'endpoint_url' => app(WebhookCredentials::class)->url($endpoint),
                'active' => $flow->status === 'active'
                    && $activation?->driver === WebhookTriggerDriver::key(),
                'secret_rotated_at' => $endpoint->secret_rotated_at?->toIso8601String(),
            ],
            // Prefixes, middleware, and route-name prefixes belong to the host.
            // The client must consume these resolved endpoints rather than revive
            // the prototype's hardcoded /nodeflow route assumptions.
            'urls' => [
                'draft' => $this->editorUrl($request, 'nodeflow.flows.draft', ['flow' => $flow]),
                'validate' => $this->editorUrl(
                    $request,
                    'nodeflow.flows.validate',
                    ['flow' => $flow],
                ),
                'publish' => $this->editorUrl($request, 'nodeflow.flows.publish', ['flow' => $flow]),
                'rotate_webhook_secret' => $this->editorUrl(
                    $request,
                    'nodeflow.webhooks.secret.rotate',
                    ['flow' => $flow],
                ),
                'options' => $this->editorUrl($request, 'nodeflow.fields.options', [
                    'flow' => $flow,
                    'type' => self::TYPE_PLACEHOLDER,
                    'field' => self::FIELD_PLACEHOLDER,
                ]),
                'trigger_options' => $this->editorUrl(
                    $request,
                    'nodeflow.trigger-fields.options',
                    [
                        'flow' => $flow,
                        'type' => self::TYPE_PLACEHOLDER,
                        'field' => self::FIELD_PLACEHOLDER,
                    ],
                ),
                'trigger_source_options' => $this->editorUrl(
                    $request,
                    'nodeflow.trigger-source-fields.options',
                    [
                        'flow' => $flow,
                        'type' => self::TYPE_PLACEHOLDER,
                        'source' => self::SOURCE_PLACEHOLDER,
                        'field' => self::FIELD_PLACEHOLDER,
                    ],
                ),
            ],
        ]);
    }

    /**
     * Allowlisted source metadata grouped by stable driver key. Empty groups are
     * intentional: built-in trigger nodes remain authorable before a host adds
     * compatible sources, and the client can render that state without guessing.
     */
    private function triggerSources(): array
    {
        $drivers = app(TriggerDriverRegistry::class);
        $sources = app(TriggerSourceRegistry::class);
        $grouped = array_fill_keys(array_keys($drivers->all()), []);

        foreach ($grouped as $driver => $_) {
            foreach ($sources->forDriver($driver) as $source) {
                $definition = $source->definition();
                $grouped[$driver][] = array_merge($definition->toArray(), [
                    'key' => $source::key(),
                    'driver' => $driver,
                    'default_config' => $definition->defaultConfig(),
                ]);
            }
        }

        return $grouped;
    }

    private function editorUrl(Request $request, string $name, array $parameters): string
    {
        return route(
            $this->routeName($request, $name, 'nodeflow.flows.edit'),
            array_merge($request->route()?->parameters() ?? [], $parameters),
        );
    }

    public function draft(Request $request): JsonResponse
    {
        $flow = $this->boundFlow($request);
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
                // One JSON shape for `graph` on every endpoint that returns one.
                // The winning draft is null when the flow was published between
                // this client's last save and this one, and an empty PHP array
                // encodes as `[]` — so the client would have to accept
                // `Graph | []` from this endpoint alone. The same empty skeleton
                // edit() falls back to keeps it a graph.
                'graph' => $e->graph() ?: ['start' => '', 'nodes' => [], 'edges' => []],
                'draft_revision' => $e->revision(),
            ], 409);
        }

        return response()->json(['draft_revision' => $revision]);
    }

    public function validate(Request $request): JsonResponse
    {
        $flow = $this->boundFlow($request);
        $this->authorize('publish', $flow);
        $request->validate($this->graphRules());

        $result = app(GraphValidator::class)->validate(Graph::fromArray($request->input('graph')));
        $body = [
            'valid' => $result->passes(),
            'warnings' => $result->warnings(),
        ];

        if ($result->passes()) {
            return response()->json($body);
        }

        return response()->json($body + [
            'message' => 'The flow is not ready to publish.',
            'errors' => $result->errors(),
            'node_errors' => $result->nodeErrors(),
        ], 422);
    }

    public function publish(Request $request): JsonResponse
    {
        $flow = $this->boundFlow($request);
        $this->authorize('publish', $flow);

        $request->validate($this->graphRules() + [
            'draft_revision' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $result = app(PublishFlow::class)->publish(
                $flow,
                // See draft(): the raw input, so a node's `position` survives into
                // the frozen version instead of being stripped by validated().
                $request->input('graph'),
                (string) ($request->user()?->getAuthIdentifier() ?? ''),
                $request->integer('draft_revision'),
            );
        } catch (StaleDraftException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'graph' => $e->graph() ?: ['start' => '', 'nodes' => [], 'edges' => []],
                'draft_revision' => $e->revision(),
            ], 409);
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
        $response = [
            'version' => $result->version->version,
            'draft_revision' => (int) ($flow->draft_revision ?? 0),
        ];

        if ($result->webhookUrl !== null) {
            $response['webhook_url'] = $result->webhookUrl;
        }

        if ($result->webhookSecret !== null) {
            $response['webhook_secret'] = $result->webhookSecret;
        }

        $json = response()->json($response);

        if ($result->webhookSecret !== null) {
            $json->headers->set('Cache-Control', 'no-store');
            $json->headers->set('Pragma', 'no-cache');
        }

        return $json;
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

    private function boundFlow(Request $request): Flow
    {
        $flow = $request->route('flow');

        if (! $flow instanceof Flow) {
            $flow = (new Flow)->resolveRouteBinding($flow);
        }

        abort_unless($flow instanceof Flow, 404);

        return $flow;
    }
}
