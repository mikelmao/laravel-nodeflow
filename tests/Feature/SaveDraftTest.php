<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Editor\SaveDraft;
use Nodeflow\Editor\StaleDraftException;
use Nodeflow\Models\Flow;

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
});

function graphWith(string $nodeId): array
{
    return [
        'start' => $nodeId,
        'nodes' => [['id' => $nodeId, 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ];
}

it('saves a first draft when nothing has been saved yet', function () {
    $revision = app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);

    expect($this->flow->fresh()->draft_graph)->toBe(graphWith('n1'))
        ->and($revision)->toBe(1);
});

it('accepts a graph that could never publish', function () {
    // E3: a draft is not a version. Mid-edit it is allowed to be broken, which is
    // the whole reason it is not stored as one.
    // Counterfactual: validate in save() and this throws.
    $broken = ['start' => 'nope', 'nodes' => [], 'edges' => []];

    app(SaveDraft::class)->save($this->flow, $broken, null);

    expect($this->flow->fresh()->draft_graph)->toBe($broken);
});

it('overwrites when the caller saw the current revision, incrementing it exactly', function () {
    $first = app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);
    $second = app(SaveDraft::class)->save($this->flow, graphWith('n2'), $first);

    expect($this->flow->fresh()->draft_graph)->toBe(graphWith('n2'))
        ->and($first)->toBe(1)
        ->and($second)->toBe(2);
});

it('produces a different revision for two saves in immediate succession', function () {
    // The whole point of the ruling: a timestamp token can collide within the
    // same second, which is the normal case for a debounced autosave. A
    // revision counter cannot.
    $first = app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);
    $second = app(SaveDraft::class)->save($this->flow, graphWith('n2'), $first);

    expect($second)->not->toBe($first);
});

it('refuses when the caller saw a stale revision, and keeps the newer draft', function () {
    // Two authors on one flow. Counterfactual: drop the comparison and the second
    // save silently destroys the first author's work.
    $first = app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);
    app(SaveDraft::class)->save($this->flow, graphWith('n2'), $first);

    expect(fn () => app(SaveDraft::class)->save($this->flow, graphWith('n3'), $first))
        ->toThrow(StaleDraftException::class);

    expect($this->flow->fresh()->draft_graph)->toBe(graphWith('n2'));
});

it('hands the newer draft to the caller so the editor can show the conflict', function () {
    // Without this the 409 is useless: the client knows it lost but not to what.
    $first = app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);
    $second = app(SaveDraft::class)->save($this->flow, graphWith('n2'), $first);

    try {
        app(SaveDraft::class)->save($this->flow, graphWith('n3'), $first);
        $this->fail('expected StaleDraftException');
    } catch (StaleDraftException $e) {
        expect($e->graph())->toBe(graphWith('n2'))
            ->and($e->revision())->toBe($second);
    }
});

it('refuses a null last-seen once a draft exists', function () {
    // A client that has never loaded the flow must not be able to blow away a
    // draft by omitting the token.
    $first = app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);

    expect(fn () => app(SaveDraft::class)->save($this->flow, graphWith('n2'), null))
        ->toThrow(StaleDraftException::class);

    expect($this->flow->fresh()->draft_graph)->toBe(graphWith('n1'))
        ->and($first)->toBe(1);
});

it('clears the draft when the flow publishes', function () {
    // Counterfactual: leave the draft behind and the editor reopens showing an
    // already-published graph as unsaved work.
    app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);

    app(\Nodeflow\Publishing\PublishFlow::class)->publish($this->flow, graphWith('n1'));

    expect($this->flow->fresh()->draft_graph)->toBeNull()
        ->and($this->flow->fresh()->draft_updated_at)->toBeNull()
        ->and($this->flow->fresh()->draft_revision)->toBe(0);
});
