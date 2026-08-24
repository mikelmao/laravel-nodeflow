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

    $this->flow = Flow::create(['name' => 'A', 'status' => 'draft']);

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
        'graph' => triggeredExitGraph(),
        'content_hash' => 'x',
        'published_at' => now(),
    ]);

    $run = Run::create([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'trigger_data' => null,
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

it('denies viewAny for both flows and runs when the host has defined no gates', function (string $modelClass) {
    // viewAny is a class-level ability: every test above passes a model
    // instance, so none of them exercise viewAny, FlowPolicy::viewAny(),
    // RunPolicy::viewAny(), or decide()'s null-model branch. This closes
    // that gap for the deny side.
    //
    // Keyed rather than a bare [Flow::class, Run::class] list: a plain
    // two-element array of class-strings has keys 0 and 1, which PHP's
    // is_callable() reads as [class, method] -- and Eloquent's __callStatic
    // makes any method name "callable", so Pest's dataset resolver actually
    // invokes Flow::{'Nodeflow\Models\Run'}() while collecting the dataset,
    // before the app is booted. Named keys sidestep that entirely.
    expect(Gate::forUser($this->user)->allows('viewAny', $modelClass))->toBeFalse();
})->with(['flow' => Flow::class, 'run' => Run::class]);

it('allows viewAny for both flows and runs when the hosts gate allows it', function (string $modelClass) {
    Gate::define('nodeflow.viewAny', fn ($user) => true);

    expect(Gate::forUser($this->user)->allows('viewAny', $modelClass))->toBeTrue();
})->with(['flow' => Flow::class, 'run' => Run::class]);

it('forwards no model argument to the hosts gate for a class-level ability', function () {
    // decide() forwards [] rather than [$model] when $model is null, for
    // class-level abilities like viewAny where there is no instance to pass.
    // Counterfactual: if decide() forwarded [null] instead, $extraArgsCount
    // below would be 1, not 0 -- the closure receives an argument either
    // way (PHP silently drops unused positional args, so the boolean result
    // alone can't tell [] from [null] apart), so this counts what actually
    // arrived via a variadic capture rather than just asserting the outcome.
    $extraArgsCount = null;

    Gate::define('nodeflow.viewAny', function ($user, ...$rest) use (&$extraArgsCount) {
        $extraArgsCount = count($rest);

        return true;
    });

    Gate::forUser($this->user)->allows('viewAny', Flow::class);

    expect($extraArgsCount)->toBe(0);
});

it('denies a guest every ability when the host has defined no gates', function () {
    // No test above passes a null user. The policy signatures are already
    // ?Authenticatable, but nothing pinned that a guest is denied the same
    // as an authenticated user when no gate exists.
    expect(Gate::forUser(null)->allows('viewAny', Flow::class))->toBeFalse();
});

it('allows a guest when the hosts gate is declared to accept a null user', function () {
    // Laravel only calls a gate closure for a guest if its first parameter
    // is nullable (typed ?Authenticatable, or defaulted to null) -- see
    // DelegatesToGate's docblock. Counterfactual: if decide()'s own $user
    // parameter regressed from ?Authenticatable to a non-nullable type,
    // Laravel would refuse to call FlowPolicy::viewAny() for a guest at all
    // and this would fail closed instead of reaching the host's gate.
    Gate::define('nodeflow.viewAny', fn (?\Illuminate\Contracts\Auth\Authenticatable $user) => true);

    expect(Gate::forUser(null)->allows('viewAny', Flow::class))->toBeTrue();
});
