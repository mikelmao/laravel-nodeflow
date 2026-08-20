<?php

use Illuminate\Foundation\Auth\User;
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
    return [
        'start' => 'e1',
        'nodes' => [['id' => 'e1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ];
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
        ->assertJsonPath('node_errors.0.node', 'w1')
        ->assertJsonPath('node_errors.0.field', 'duration');
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
