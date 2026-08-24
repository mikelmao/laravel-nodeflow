<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\Flow;
use Nodeflow\Nodeflow;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\SubjectAttribute;
use Nodeflow\Schema\SubjectAttributeRegistry;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\AbstractTriggerNode;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;
use Nodeflow\Triggers\Webhook\WebhookTriggerSource;
use Tests\Support\BadSourceNode;
use Tests\Support\DynamicOptionNode;
use Tests\Support\FakeOptionSource;
use Tests\Support\FakeTriggerDriver;
use Tests\Support\FakeTriggerSource;
use Tests\Support\NotAnOptionSource;

class FieldOptionsWebhookTriggerNode extends AbstractTriggerNode
{
    public static function type(): string
    {
        return 'test.field-options-trigger';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Field options trigger')->fields([
            Field::select('source')->required(),
            Field::select('account')->optionsFrom(FakeOptionSource::class),
        ]);
    }

    public function driver(): string
    {
        return 'webhook';
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        return new TriggerActivationDescriptor('webhook', (string) $config['source'], null, []);
    }
}

class FieldOptionsWebhookSource implements WebhookTriggerSource
{
    public static function key(): string
    {
        return 'test.field-options-source';
    }

    public static function driver(): string
    {
        return 'webhook';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Field options source')->fields([
            Field::select('template')->optionsFrom(FakeOptionSource::class),
        ]);
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        return TriggerMatch::make();
    }
}

class FieldOptionsCollidingWebhookSource implements WebhookTriggerSource
{
    public static function key(): string
    {
        return 'test.field-options-collision';
    }

    public static function driver(): string
    {
        return 'webhook';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Field options collision')->fields([
            Field::select('source')->optionsFrom(FakeOptionSource::class),
        ]);
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        return TriggerMatch::make();
    }
}

class FieldOptionsIncompatibleWebhookSource implements TriggerSource
{
    public static function key(): string
    {
        return 'test.field-options-incompatible';
    }

    public static function driver(): string
    {
        return 'webhook';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Field options incompatible')->fields([
            Field::select('template')->optionsFrom(FakeOptionSource::class),
        ]);
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        return TriggerMatch::make();
    }
}

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
    Nodeflow::registerTriggerDrivers([FakeTriggerDriver::class]);
    Nodeflow::registerTriggerNodes([FieldOptionsWebhookTriggerNode::class]);
    Nodeflow::registerTriggerSources([
        FakeTriggerSource::class,
        FieldOptionsWebhookSource::class,
        FieldOptionsCollidingWebhookSource::class,
        FieldOptionsIncompatibleWebhookSource::class,
    ]);
});

it('resolves options declared by the node', function () {
    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/test.dynamic_options/fields/template/options")
        ->assertOk()
        ->assertJsonPath('options.welcome', 'Welcome message');
});

it('resolves options declared by a trigger reserved field', function () {
    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/trigger-nodes/test.field-options-trigger/fields/account/options")
        ->assertOk()
        ->assertJsonPath('options.welcome', 'Welcome message');
});

it('resolves options contributed by a compatible trigger source', function () {
    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/trigger-nodes/test.field-options-trigger/sources/test.field-options-source/fields/template/options")
        ->assertOk()
        ->assertJsonPath('options.reminder', 'Reminder');
});

it('resolves trigger options inside a host parameterized domain group', function () {
    Route::setRoutes(new RouteCollection);
    Route::middleware('web')
        ->domain('{workspace}.example.test')
        ->prefix('admin')
        ->name('tenant.')
        ->group(fn () => Nodeflow::routes());

    $this->actingAs($this->user)
        ->getJson("http://acme.example.test/admin/flows/{$this->flow->id}/trigger-nodes/test.field-options-trigger/fields/account/options")
        ->assertOk()
        ->assertJsonPath('options.welcome', 'Welcome message');
});

it('rejects incompatible unknown and colliding trigger source option identities', function (string $path) {
    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}{$path}")
        ->assertNotFound();
})->with([
    'source registered for another driver' => '/trigger-nodes/test.field-options-trigger/sources/test.orders/fields/template/options',
    'unknown trigger node' => '/trigger-nodes/nope.trigger/fields/account/options',
    'unknown trigger field' => '/trigger-nodes/test.field-options-trigger/fields/nope/options',
    'unknown source' => '/trigger-nodes/test.field-options-trigger/sources/nope.source/fields/template/options',
    'unknown source field' => '/trigger-nodes/test.field-options-trigger/sources/test.field-options-source/fields/nope/options',
    'source is incompatible with the trigger node' => '/trigger-nodes/core.trigger.webhook/sources/test.field-options-incompatible/fields/template/options',
    'source field collides with reserved field' => '/trigger-nodes/test.field-options-trigger/sources/test.field-options-collision/fields/source/options',
]);

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

    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/trigger-nodes/test.field-options-trigger/fields/account/options")
        ->assertForbidden();
});

it('four-oh-fours trigger options for another tenants flow', function () {
    $foreign = TenancyGuardSuspension::run(fn () => Flow::withoutTenancy()->create([
        'tenant_id' => 'org-2',
        'name' => 'Foreign',
        'status' => 'draft',
    ]));

    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$foreign->id}/trigger-nodes/test.field-options-trigger/fields/account/options")
        ->assertNotFound();
});
