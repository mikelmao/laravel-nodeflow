<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Nodeflow;
use Nodeflow\Nodes\NodeRegistry;
use Tests\Support\BadSourceNode;
use Tests\Support\DynamicOptionNode;
use Tests\Support\NotAnOptionSource;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver
    {
        public function currentTenantId(): ?string
        {
            return 'org-1';
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return true;
        }
    });

    Route::middleware('web')->prefix('nodeflow')->group(fn () => Nodeflow::routes());

    Gate::define('nodeflow.update', fn ($user, $flow = null) => true);

    $this->user = new User;
    $this->user->id = 1;

    $this->flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    app(NodeRegistry::class)->register(DynamicOptionNode::class, BadSourceNode::class);
});

it('resolves options declared by the node', function () {
    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/test.dynamic_options/fields/template/options")
        ->assertOk()
        ->assertJsonPath('options.welcome', 'Welcome message');
});

it('ignores a class name smuggled in the query string', function () {
    // THE test for this task. Counterfactual: read the class from the request and
    // this endpoint instantiates arbitrary application classes.
    $this->actingAs($this->user)
        ->getJson(
            "/nodeflow/flows/{$this->flow->id}/nodes/test.dynamic_options/fields/template/options"
            .'?options_source='.urlencode(NotAnOptionSource::class)
        )
        ->assertOk()
        ->assertJsonPath('options.welcome', 'Welcome message')
        ->assertJsonMissingPath('options.sneaky');
});

it('four-oh-fours an unknown node type', function () {
    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/nope.missing/fields/template/options")
        ->assertNotFound();
});

it('four-oh-fours a field the node does not declare', function () {
    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/test.dynamic_options/fields/nope/options")
        ->assertNotFound();
});

it('four-oh-fours a field that has no dynamic source', function () {
    // A static-optioned field's options are already in the palette payload; asking
    // for them here means the client is confused, and answering would imply the
    // endpoint is the place to get them.
    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/test.dynamic_options/fields/channel/options")
        ->assertNotFound();
});

it('fails loudly when the declared source is not an OptionSource', function () {
    // Counterfactual: duck-type on method_exists and this returns the sneaky
    // options — or, worse, an empty list indistinguishable from "no templates".
    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/test.bad_source/fields/template/options")
        ->assertStatus(500);
});

it('denies when the host has not defined the update gate', function () {
    // Options are edit-time data about the tenant's own records, so they sit
    // behind the same gate as editing.
    Gate::define('nodeflow.update', fn ($user, $flow = null) => false);

    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/test.dynamic_options/fields/template/options")
        ->assertForbidden();
});
