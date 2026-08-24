<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Nodeflow;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\Schema\SubjectAttribute;
use Nodeflow\Schema\SubjectAttributeRegistry;
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

    $this->flow = Flow::create(['name' => 'A', 'status' => 'draft']);

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

it('resolves the attribute options of the packages own condition node', function () {
    // The suite's other cases all use bespoke support nodes that implement
    // OptionSource, so it structurally could not see a *core* node whose declared
    // source does not. core.condition declares optionsFrom(SubjectAttributeRegistry)
    // and every host has it registered, so this endpoint is what a Condition
    // sidebar hits on first open.
    //
    // Counterfactual: drop `implements OptionSource` from SubjectAttributeRegistry
    // and this is a 500, on a built-in node, for every host.
    app(SubjectAttributeRegistry::class)->register(
        SubjectAttribute::make('clicked', 'Has clicked', 'boolean', fn ($s) => true),
    );

    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/core.condition/fields/attribute/options")
        ->assertOk()
        ->assertJsonPath('options.clicked', 'Has clicked');
});

it('returns an empty object, not an empty array, when the source has nothing registered', function () {
    // A fresh host that has not registered any SubjectAttribute is the normal
    // starting state, and SubjectAttributeRegistry::options() genuinely returns
    // [] in that state. The docs promise `options` is always a JSON *object*,
    // `{}` when there are none — assertJsonPath/assertJson decode before
    // comparing, so they cannot tell `[]` from `{}`. Only the raw body can.
    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/core.condition/fields/attribute/options")
        ->assertOk()
        ->assertContent('{"options":{}}');
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

it('denies when the update gate refuses', function () {
    // Options are edit-time data about the tenant's own records, so they sit
    // behind the same gate as editing.
    //
    // Named for what it does: it defines the gate as false. The *undefined* gate
    // case is not covered here — EditorRoutesTest's "denies editing when the host
    // has defined no gates" is the one that exercises that, and beforeEach() in
    // this file defines the gate for every other test.
    Gate::define('nodeflow.update', fn ($user, $flow = null) => false);

    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/test.dynamic_options/fields/template/options")
        ->assertForbidden();
});
