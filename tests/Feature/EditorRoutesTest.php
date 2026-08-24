<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\Flow;
use Nodeflow\Nodeflow;

beforeEach(function () {
    $this->tenant = 'org-1';

    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver
    {
        public function __construct(private $test) {}

        public function currentTenantId(): ?string
        {
            return $this->test->tenant;
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return true;
        }
    });

    Route::middleware('web')->prefix('nodeflow')->group(fn () => Nodeflow::routes());

    $this->user = new User;
    $this->user->id = 1;

    $this->flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);
});

function exitGraph(): array
{
    return triggeredExitGraph();
}

function graphWithConcurrentWaits(): array
{
    return triggeredGraph([
        'start' => 'c1',
        'nodes' => [
            ['id' => 'c1', 'type' => 'core.condition', 'config' => [
                'attribute' => 'email',
                'operator' => 'equals',
                'value' => 'author@example.test',
            ]],
            ['id' => 'w1', 'type' => 'core.wait', 'config' => ['duration' => '1 day']],
            ['id' => 'w2', 'type' => 'core.wait', 'config' => ['duration' => '2 days']],
            ['id' => 'e1', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [
            ['from' => 'c1', 'output' => 'yes', 'to' => 'w1'],
            ['from' => 'c1', 'output' => 'no', 'to' => 'w2'],
            ['from' => 'w1', 'output' => 'default', 'to' => 'e1'],
            ['from' => 'w2', 'output' => 'default', 'to' => 'e1'],
        ],
    ]);
}

function allowEverything(): void
{
    foreach (['viewAny', 'update', 'publish', 'runManually'] as $ability) {
        Gate::define("nodeflow.{$ability}", fn ($user, $flow = null) => true);
    }
}

it('denies editing when the host has defined no gates', function () {
    // Plan 2's floor, reached over HTTP for the first time. Counterfactual: skip
    // authorize() in the controller and this returns 200.
    $this->actingAs($this->user)
        ->get("/nodeflow/flows/{$this->flow->id}/edit")
        ->assertForbidden();
});

it('four-oh-fours another tenants flow rather than forbidding it', function () {
    // 403 would confirm the row exists. Counterfactual: look the flow up
    // unscoped and this returns 200 or 403.
    allowEverything();

    $theirs = TenancyGuardSuspension::run(fn () => Flow::withoutTenancy()->create([
        'tenant_id' => 'org-2',
        'name' => 'Theirs',
        'trigger_type' => 'manual',
        'status' => 'draft',
    ]));

    $this->actingAs($this->user)
        ->get("/nodeflow/flows/{$theirs->id}/edit")
        ->assertNotFound();
});

/**
 * Inertia's own JSON response, which is what a real editor request produces.
 *
 * Without the X-Inertia header Inertia::render() renders a root Blade view that
 * Testbench does not ship, which is why edit()'s success path went untested at
 * first. With it, the response is a plain JSON prop payload and needs no view at
 * all. X-Inertia-Version is sent empty because no HandleInertiaRequests
 * middleware is registered here, so there is no asset version to match.
 */
function editPage($test, int $flowId)
{
    return $test->actingAs($test->user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->get("/nodeflow/flows/{$flowId}/edit");
}

it('renders the editor props the client is written against', function () {
    // The edit page's prop shape is the contract the React client is built on, so
    // it is pinned here rather than left to the first person who breaks it.
    // Counterfactual: rename or drop any prop — `graph`, `palette`, `triggers`, or
    // `flow.draft_revision`, without which the client cannot autosave at all —
    // and this fails.
    allowEverything();

    $response = editPage($this, $this->flow->id)->assertOk();

    $response->assertJsonPath('component', 'nodeflow/editor')
        ->assertJsonPath('props.flow.id', $this->flow->id)
        ->assertJsonPath('props.flow.name', 'A')
        ->assertJsonPath('props.flow.trigger_type', 'manual')
        ->assertJsonPath('props.flow.status', 'draft')
        ->assertJsonPath('props.flow.version', null)
        ->assertJsonPath('props.flow.draft_revision', 0)
        ->assertJsonPath('props.flow.draft_updated_at', null)
        // The endpoint URL is part of the same client contract. Counterfactual:
        // drop `urls` from edit() and this fails before the React client has to
        // discover the missing endpoint at runtime.
        ->assertJsonPath('props.urls.draft', "http://localhost/nodeflow/flows/{$this->flow->id}/draft")
        ->assertJsonPath('props.urls.validate', "http://localhost/nodeflow/flows/{$this->flow->id}/validate")
        // No draft and no published version: the empty skeleton, not null, so the
        // canvas has something of the right shape to mount on.
        ->assertJsonPath('props.graph', ['start' => '', 'nodes' => [], 'edges' => []]);

    $palette = collect($response->json('props.palette'));

    expect($palette->pluck('type'))->toContain('core.condition')
        ->and($palette->firstWhere('type', 'core.condition'))
        ->toHaveKeys(['label', 'group', 'outputs', 'fields', 'default_config', 'cardinality'])
        ->and($response->json('props.triggers'))->toBeArray();
});

it('shows the draft graph in preference to the published version', function () {
    // `draft_graph ?? currentVersion.graph ?? empty` is a real precedence rule
    // with three legs, and an author's unsaved work losing to the published
    // version is the kind of bug that reads as "the editor threw away my
    // changes". Counterfactual: swap the first two operands and the third
    // assertion below returns the published graph while a draft exists.
    allowEverything();

    $published = exitGraph();
    app(\Nodeflow\Publishing\PublishFlow::class)->publish($this->flow, $published);

    // Leg two: a published version and no draft.
    editPage($this, $this->flow->id)
        ->assertOk()
        ->assertJsonPath('props.graph', $published)
        ->assertJsonPath('props.flow.version', 1);

    $draft = exitGraph();
    $draft['nodes'][1]['id'] = 'unsaved';
    $draft['edges'][0]['to'] = 'unsaved';

    app(\Nodeflow\Editor\SaveDraft::class)->save(
        $this->flow->fresh(),
        $draft,
        (int) $this->flow->fresh()->draft_revision,
    );

    // Leg one: the draft wins, and the version number still reports the published
    // one, so the editor can say "editing changes on top of v1".
    editPage($this, $this->flow->id)
        ->assertOk()
        ->assertJsonPath('props.graph', $draft)
        ->assertJsonPath('props.flow.version', 1);
});

it('serialises a fields options as a JSON object even when there are none', function () {
    // Type stability for the client, asserted on the raw JSON because
    // json_decode(assoc: true) cannot tell {} from []. A string-keyed PHP array
    // encodes as an object when it has entries and as `[]` when it does not, so a
    // dynamic-option field handed the browser an array where a static-option field
    // handed it a map. Counterfactual: drop the (object) cast in
    // Field::toWireArray() and `attribute` below comes back as [], forcing a
    // TypeScript client to write `Record<string, string> | []`.
    allowEverything();

    $decoded = json_decode(editPage($this, $this->flow->id)->assertOk()->getContent());

    $condition = collect($decoded->props->palette)->firstWhere('type', 'core.condition');
    $fields = collect($condition->fields);

    expect($fields->firstWhere('key', 'attribute')->dynamic_options)->toBeTrue()
        ->and($fields->firstWhere('key', 'attribute')->options)->toBeObject()
        ->and($fields->firstWhere('key', 'value')->options)->toBeObject()
        // And the case that already worked stays an object rather than becoming
        // something else on the way through.
        ->and($fields->firstWhere('key', 'operator')->options)->toBeObject()
        ->and($fields->firstWhere('key', 'operator')->options->is_true)->toBe('is true');
});

it('returns a graph-shaped object in the 409 even when there is no draft left', function () {
    // Reachable exactly because publishing no longer rewinds draft_revision: the
    // draft is gone but the counter is not, so a client on an older token gets a
    // 409 whose winning draft is null. Counterfactual: hand back $e->graph()
    // directly and `graph` is `[]` here and an object everywhere else, which is
    // the one endpoint a client cannot type.
    allowEverything();

    $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", ['graph' => exitGraph(), 'draft_revision' => null])
        ->assertOk();

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/publish", ['graph' => exitGraph()])
        ->assertOk();

    $response = $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", ['graph' => exitGraph(), 'draft_revision' => 0])
        ->assertStatus(409)
        ->assertJsonPath('graph', ['start' => '', 'nodes' => [], 'edges' => []])
        ->assertJsonPath('draft_revision', 1);

    expect(json_decode($response->getContent())->graph)->toBeObject();
});

it('saves a draft and returns the new revision', function () {
    allowEverything();

    $response = $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", [
            'graph' => exitGraph(),
            'draft_revision' => null,
        ]);

    $response->assertOk()->assertJsonStructure(['draft_revision']);

    expect($this->flow->fresh()->draft_graph)->toBe(exitGraph());
});

it('returns 409 and the newer draft when the token is stale', function () {
    // Counterfactual: let StaleDraftException bubble and this is a 500 with no
    // graph for the client to show.
    allowEverything();

    $first = $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", ['graph' => exitGraph(), 'draft_revision' => null])
        ->json('draft_revision');

    $newer = exitGraph();
    $newer['nodes'][0]['id'] = 'e2';
    $newer['start'] = 'e2';

    $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", ['graph' => $newer, 'draft_revision' => $first]);

    $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", ['graph' => exitGraph(), 'draft_revision' => $first])
        ->assertStatus(409)
        ->assertJsonPath('graph.start', 'e2');
});

it('accepts a draft that could never publish', function () {
    // E3 again, over HTTP: the endpoint must not validate.
    allowEverything();

    $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", [
            'graph' => ['start' => 'nope', 'nodes' => [], 'edges' => []],
            'draft_revision' => null,
        ])
        ->assertOk();
});

it('validates a graph without saving or publishing it', function () {
    allowEverything();
    $before = $this->flow->fresh()->only(['draft_graph', 'draft_revision', 'current_version_id']);

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/validate", ['graph' => exitGraph()])
        ->assertOk()
        ->assertExactJson(['valid' => true, 'warnings' => []]);

    expect($this->flow->fresh()->only(array_keys($before)))->toBe($before)
        ->and($this->flow->versions()->count())->toBe(0);
});

it('returns semantic errors for an empty graph', function () {
    allowEverything();

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/validate", ['graph' => [
            'start' => '',
            'nodes' => [],
            'edges' => [],
        ]])
        ->assertStatus(422)
        ->assertJsonPath('valid', false)
        ->assertJsonPath('message', 'The flow is not ready to publish.')
        ->assertJsonStructure(['errors', 'node_errors', 'warnings']);
});

it('requires publish authorization to validate', function () {
    // Validation is publish semantics without the mutation, so update alone must
    // not let an editor learn whether a draft is ready to release.
    Gate::define('nodeflow.update', fn ($user, $flow = null) => true);
    Gate::define('nodeflow.publish', fn ($user, $flow = null) => false);

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/validate", ['graph' => exitGraph()])
        ->assertForbidden();
});

it('four-oh-fours another tenants flow before validating authorization', function () {
    Gate::define('nodeflow.publish', fn ($user, $flow = null) => false);

    $theirs = TenancyGuardSuspension::run(fn () => Flow::withoutTenancy()->create([
        'tenant_id' => 'org-2',
        'name' => 'Theirs',
        'trigger_type' => 'manual',
        'status' => 'draft',
    ]));

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$theirs->id}/validate", ['graph' => exitGraph()])
        ->assertNotFound();
});

it('returns warnings from a valid graph without mutation', function () {
    allowEverything();
    $before = $this->flow->fresh()->only(['draft_graph', 'draft_revision', 'current_version_id']);

    $response = $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/validate", ['graph' => graphWithConcurrentWaits()])
        ->assertOk()
        ->assertJsonPath('valid', true);

    expect(implode(' ', $response->json('warnings')))->toContain('sequentially')
        ->and($this->flow->fresh()->only(array_keys($before)))->toBe($before)
        ->and($this->flow->versions()->count())->toBe(0);
});

it('preserves warnings when validation errors coexist', function () {
    allowEverything();
    $graph = graphWithConcurrentWaits();
    $graph['nodes'][1]['config'] = [];

    $response = $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/validate", ['graph' => $graph])
        ->assertStatus(422)
        ->assertJsonPath('valid', false)
        ->assertJsonStructure(['errors', 'node_errors', 'warnings']);

    expect(implode(' ', $response->json('warnings')))->toContain('sequentially');
});

it('publishes a valid graph and freezes a version', function () {
    allowEverything();

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/publish", ['graph' => exitGraph()])
        ->assertOk()
        ->assertJsonPath('version', 1);

    expect($this->flow->fresh()->current_version_id)->not->toBeNull();
});

it('returns the current draft revision alongside the published version', function () {
    // A client that stays open across a publish has to keep echoing the draft
    // token on its next autosave, and publish is where the server's view of it
    // last changed hands. Counterfactual: return only {version} and the client
    // has no authoritative token after publishing — the exact position that made
    // the old draft_revision reset produce a spurious 409.
    allowEverything();

    $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", ['graph' => exitGraph(), 'draft_revision' => null])
        ->assertOk();

    $response = $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/publish", ['graph' => exitGraph()])
        ->assertOk();

    expect($response->json('draft_revision'))->toBe(1);

    // And the token it hands back is the one the next autosave must send.
    $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", [
            'graph' => exitGraph(),
            'draft_revision' => $response->json('draft_revision'),
        ])
        ->assertOk()
        ->assertJsonPath('draft_revision', 2);
});

it('returns per-node errors when publish is rejected', function () {
    // The payoff of Task 3, over HTTP. Counterfactual: return only the flat
    // strings and the editor has to parse prose to find the node.
    allowEverything();

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/publish", ['graph' => [
            'start' => 'w1',
            'nodes' => [['id' => 'w1', 'type' => 'core.wait', 'config' => []]],
            'edges' => [],
        ]])
        ->assertStatus(422)
        ->assertJsonFragment([
            'node' => 'w1',
            'field' => 'duration',
            'message' => 'The duration field is required.',
        ]);
});

it('four-twenty-twos a published node with no id rather than exploding', function () {
    // An ordinary client bug, and the most likely one: a node added to the canvas
    // before its id is assigned. Counterfactual: validate only ['graph' =>
    // ['required','array']] and Graph::fromArray() reads $node['id'] on an array
    // that has none — a 500, on the endpoint whose entire new feature is
    // structured, renderable errors, with a stack trace under APP_DEBUG.
    allowEverything();

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/publish", ['graph' => [
            'start' => 'n1',
            'nodes' => [['type' => 'core.exit', 'config' => []]],
            'edges' => [],
        ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('graph.nodes.0.id');
});

it('four-twenty-twos a published edge with no target rather than exploding', function () {
    // Counterfactual: as above — GraphValidator reads $edge['to'] unconditionally,
    // so an unterminated edge was a 500.
    allowEverything();

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/publish", ['graph' => [
            'start' => 'e1',
            'nodes' => [['id' => 'e1', 'type' => 'core.exit', 'config' => []]],
            'edges' => [['from' => 'e1', 'output' => 'default']],
        ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('graph.edges.0.to');
});

it('still reports a missing start node as a renderable publish error, not a field error', function () {
    // The structural rules deliberately leave `start` nullable. Counterfactual:
    // require it and the canvas loses the node_errors entry it renders in its
    // banner, in exchange for a field-validation message about a key the author
    // has no field for.
    allowEverything();

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/publish", ['graph' => [
            'nodes' => [['id' => 'e1', 'type' => 'core.exit', 'config' => []]],
            'edges' => [],
        ]])
        ->assertStatus(422)
        ->assertJsonPath('node_errors.0.node', null)
        ->assertJsonMissingPath('errors.graph\\.start');
});

it('four-twenty-twos a drafted node with no id, and still saves a half-connected graph', function () {
    // The draft endpoint validates structure too — an arbitrary array becomes
    // edit()'s graph prop — but only structure. Counterfactual on the first half:
    // drop the rules and `nodes` can be the string "nope", which the editor then
    // has to render. Counterfactual on the second: tighten them beyond structure
    // (require a start, require edges to resolve) and a canvas mid-edit stops
    // autosaving, which is the one thing a draft exists to do.
    allowEverything();

    $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", [
            'graph' => ['start' => 'n1', 'nodes' => [['type' => 'core.exit']], 'edges' => []],
            'draft_revision' => null,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('graph.nodes.0.id');

    $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", [
            'graph' => [
                'start' => '',
                'nodes' => [
                    ['id' => 'n1', 'type' => 'nope.unregistered', 'config' => []],
                    ['id' => 'n2', 'type' => 'core.wait', 'position' => ['x' => 4, 'y' => 9]],
                ],
                'edges' => [['from' => 'n1', 'to' => 'n2']],
            ],
            'draft_revision' => null,
        ])
        ->assertOk();

    // The position key a canvas carries survives the round trip untouched.
    expect($this->flow->fresh()->draft_graph['nodes'][1]['position'])->toBe(['x' => 4, 'y' => 9]);
});

it('denies publishing to someone who may edit but not publish', function () {
    // The two gates are separate for a reason. Counterfactual: authorize publish
    // against nodeflow.update and this returns 200.
    Gate::define('nodeflow.update', fn ($user, $flow = null) => true);
    Gate::define('nodeflow.publish', fn ($user, $flow = null) => false);

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/publish", ['graph' => exitGraph()])
        ->assertForbidden();
});

it('ignores a version id smuggled into the publish payload', function () {
    // Open issue G-3: the unscoped Flow::currentVersion() relation is safe only
    // while current_version_id stays inside the tenant. Counterfactual: pass the
    // request through to update() and a caller repoints the flow at another
    // tenant's version.
    allowEverything();

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/publish", [
            'graph' => exitGraph(),
            'current_version_id' => 99999,
        ])
        ->assertOk();

    expect($this->flow->fresh()->current_version_id)->not->toBe(99999);
});

it('hands the client the urls for its own endpoints', function () {
    // The client cannot build these itself: Nodeflow::routes() is called inside
    // the host's own group, so prefix and middleware are the host's choice (E4).
    // Counterfactual: drop the `urls` prop and every assertion here fails; the
    // throwaway prototype hardcoded '/nodeflow/flows/{id}/publish' instead, which
    // is exactly what this prop exists to prevent.
    allowEverything();

    $response = editPage($this, $this->flow->id);

    $response->assertJsonPath('props.urls.draft', "http://localhost/nodeflow/flows/{$this->flow->id}/draft")
        ->assertJsonPath('props.urls.validate', "http://localhost/nodeflow/flows/{$this->flow->id}/validate")
        ->assertJsonPath('props.urls.publish', "http://localhost/nodeflow/flows/{$this->flow->id}/publish");

    // A template, not a URL: the client substitutes the node type and field key
    // when it renders a dynamic field. The sentinels are made of unreserved
    // characters so route() cannot re-encode them out from under the client.
    expect($response->json('props.urls.options'))->toBe(
        "http://localhost/nodeflow/flows/{$this->flow->id}/nodes/__NODEFLOW_TYPE__/fields/__NODEFLOW_FIELD__/options"
    );
});

it('resolves its urls through the hosts own route name prefix', function () {
    // Route::name('admin.')->group(fn () => Nodeflow::routes()) is an ordinary
    // Laravel pattern — the demo app uses it for its own routes — and it renames
    // every route in this package. Counterfactual: call route('nodeflow.flows.draft')
    // directly and this test dies on RouteNotFoundException instead of asserting.
    allowEverything();

    // Isolate the prefix contract from beforeEach's ordinary unprefixed routes:
    // a bare sibling route name must not have a second collection to fall back to.
    Route::setRoutes(new RouteCollection);
    Route::middleware('web')->prefix('admin')->name('admin.')->group(fn () => Nodeflow::routes());

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->get("/admin/flows/{$this->flow->id}/edit");

    $response->assertOk()
        ->assertJsonPath('props.urls.draft', "http://localhost/admin/flows/{$this->flow->id}/draft")
        ->assertJsonPath('props.urls.validate', "http://localhost/admin/flows/{$this->flow->id}/validate")
        ->assertJsonPath('props.urls.publish', "http://localhost/admin/flows/{$this->flow->id}/publish")
        ->assertJsonPath('props.urls.options', "http://localhost/admin/flows/{$this->flow->id}/nodes/__NODEFLOW_TYPE__/fields/__NODEFLOW_FIELD__/options");
});
