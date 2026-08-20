<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;

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

    $this->flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    // Assigned rather than mass-assigned: Model's default $guarded is ['*'], so
    // new User(['id' => 1]) would silently leave id unset and the
    // "passes the user through" test below would assert against null.
    $this->user = new User;
    $this->user->id = 1;
});

it('denies every flow ability when the host has defined no gates', function (string $ability) {
    // Foundation spec §4: default deny unless a gate exists. Counterfactual:
    // return true when Gate::has() is false and every one of these passes.
    expect(Gate::forUser($this->user)->allows($ability, $this->flow))->toBeFalse();
})->with(['view', 'update', 'publish', 'runManually']);

it('denies run abilities when the host has defined no gates', function () {
    // A Run needs a real flow_version_id: nodeflow_runs.flow_version_id is a
    // non-nullable constrained foreign key, so passing null fails on insert
    // rather than testing anything about policies.
    $version = \Nodeflow\Models\FlowVersion::create([
        'flow_id' => $this->flow->id,
        'tenant_id' => 'org-1',
        'version' => 1,
        'graph' => ['start' => 'n1', 'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]], 'edges' => []],
        'content_hash' => 'x',
        'published_at' => now(),
    ]);

    $run = Run::create([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
    ]);

    expect(Gate::forUser($this->user)->allows('view', $run))->toBeFalse();
});

it('allows an ability when the hosts gate allows it', function () {
    Gate::define('nodeflow.update', fn ($user, $flow) => true);

    expect(Gate::forUser($this->user)->allows('update', $this->flow))->toBeTrue();
});

it('denies an ability when the hosts gate denies it', function () {
    // Counterfactual: ignore the gate's return value and this passes while
    // every host authorization rule is silently bypassed.
    Gate::define('nodeflow.update', fn ($user, $flow) => false);

    expect(Gate::forUser($this->user)->allows('update', $this->flow))->toBeFalse();
});

it('passes the user and the model through to the hosts gate', function () {
    // The gate cannot make a real decision without both. Counterfactual: drop
    // the model from the forwarded arguments and $received stays null.
    $receivedUser = null;
    $receivedFlow = null;

    Gate::define('nodeflow.publish', function ($user, $flow) use (&$receivedUser, &$receivedFlow) {
        $receivedUser = $user;
        $receivedFlow = $flow;

        return true;
    });

    Gate::forUser($this->user)->allows('publish', $this->flow);

    expect($receivedUser?->id)->toBe(1)
        ->and($receivedFlow?->id)->toBe($this->flow->id);
});

it('maps viewing a flow to the viewAny gate rather than inventing a fifth', function () {
    // The spec names exactly four gates. Counterfactual: add a nodeflow.view
    // gate and this fails, catching the drift.
    Gate::define('nodeflow.viewAny', fn ($user, $flow = null) => true);

    expect(Gate::forUser($this->user)->allows('view', $this->flow))->toBeTrue();
});
