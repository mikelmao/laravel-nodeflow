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

    $this->flow = Flow::create(['name' => 'A', 'status' => 'draft']);
});

function graphWith(string $nodeId): array
{
    return triggeredGraph([
        'start' => $nodeId,
        'nodes' => [['id' => $nodeId, 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);
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
    //
    // draft_revision is deliberately left where it was, not rewound to 0: it is a
    // monotonic token, and the two tests below are what a rewind breaks.
    app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);

    app(\Nodeflow\Publishing\PublishFlow::class)->publish($this->flow, graphWith('n1'));

    expect($this->flow->fresh()->draft_graph)->toBeNull()
        ->and($this->flow->fresh()->draft_updated_at)->toBeNull()
        ->and($this->flow->fresh()->draft_revision)->toBe(1);
});

it('keeps saving for the author who publishes and carries on editing', function () {
    // One author, no race at all. Counterfactual: reset draft_revision to 0 in
    // PublishFlow and this throws — the client's token is 1, the server holds 0,
    // and the sole author editing the flow is told they lost a race, against an
    // empty graph.
    $first = app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);

    app(\Nodeflow\Publishing\PublishFlow::class)->publish($this->flow, graphWith('n1'));

    $next = app(SaveDraft::class)->save(Flow::find($this->flow->id), graphWith('n2'), $first);

    expect($next)->toBe($first + 1);
});

it('refuses a token minted before a publish against a draft saved after it', function () {
    // The ABA sequence. A saves (revision 1). B publishes. B starts a new draft,
    // which becomes revision 2. A's autosave is still in flight carrying token 1.
    //
    // Counterfactual: reset draft_revision to 0 in PublishFlow and B's new draft
    // is revision 1 again — the same number A is holding from a different draft
    // entirely — so A's stale write is accepted and B's work is destroyed with no
    // conflict reported to anyone.
    $aToken = app(SaveDraft::class)->save($this->flow, graphWith('a1'), null);

    app(\Nodeflow\Publishing\PublishFlow::class)->publish($this->flow, graphWith('a1'));

    $bFlow = Flow::find($this->flow->id);
    app(SaveDraft::class)->save($bFlow, graphWith('b1'), (int) $bFlow->draft_revision);

    expect(fn () => app(SaveDraft::class)->save(Flow::find($this->flow->id), graphWith('a2'), $aToken))
        ->toThrow(StaleDraftException::class);

    expect(Flow::find($this->flow->id)->draft_graph)->toBe(graphWith('b1'));
});

it('refuses the write when the revision moved in the database after this request read it', function () {
    // The read-then-write window, which is what a real pair of debounced
    // autosaves collide inside: this request read revision 1 at route binding and
    // is about to write, and by now the row says 5. True simultaneity is not
    // reproducible in this suite, but the divergence it produces between a
    // hydrated model and the row is — and that divergence is the whole reason the
    // comparison cannot live in PHP alone.
    //
    // Counterfactual: drop `->where('draft_revision', $current)` from the update
    // in SaveDraft::save() and this test's save succeeds, overwriting graph n9
    // that the other request had already committed, and reports success for both.
    $first = app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);

    Flow::withoutTenancy()->whereKey($this->flow->getKey())->update([
        'draft_revision' => 5,
        'draft_graph' => json_encode(graphWith('n9')),
    ]);

    try {
        app(SaveDraft::class)->save($this->flow, graphWith('n2'), $first);
        $this->fail('expected StaleDraftException');
    } catch (StaleDraftException $e) {
        // Re-read, not assumed: the caller is told what actually won.
        expect($e->revision())->toBe(5)
            ->and($e->graph())->toBe(graphWith('n9'));
    }

    expect(Flow::withoutTenancy()->find($this->flow->getKey())->draft_graph)->toBe(graphWith('n9'));
});

it('writes a graph the model can read back, without the array cast on the write path', function () {
    // The conditional update bypasses Eloquent, so draft_graph is encoded by hand
    // rather than by the model's `array` cast. Counterfactual: pass the raw PHP
    // array to the query builder and the write fails outright; encode it twice and
    // draft_graph comes back as a JSON string instead of an array, which every
    // reader of the column — edit()'s graph prop included — then mishandles.
    app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);

    expect(Flow::find($this->flow->getKey())->draft_graph)->toBe(graphWith('n1'))
        ->and(Flow::find($this->flow->getKey())->draft_updated_at)->not->toBeNull();
});

it('leaves a callers unrelated pending change on the model alone', function () {
    // save() has to re-sync the three draft attributes on the passed model,
    // because the conditional update bypasses Eloquent and the model would
    // otherwise hold a revision the row no longer has. Counterfactual: re-sync
    // with syncOriginal() instead of naming the three attributes and this fails —
    // an unsaved change the caller was holding is marked clean and never written.
    $this->flow->name = 'Renamed';

    app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);

    $this->flow->save();

    expect(Flow::find($this->flow->getKey())->name)->toBe('Renamed');
});

it('treats a revision read back from the database as current, not stale', function () {
    // Every other test in this file reuses the same in-memory $flow object
    // across saves, so draft_revision never actually leaves PHP and comes
    // back through the driver between calls. A real controller loads a fresh
    // model per request. Counterfactual: a bug in how save() reads
    // draft_revision that happens to be masked by object reuse (e.g. reading
    // a stale local variable instead of the model's current attribute) would
    // pass every other test here and still break the very first real request.
    $first = app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);

    $reloaded = Flow::find($this->flow->id);

    $second = app(SaveDraft::class)->save($reloaded, graphWith('n2'), $first);

    expect($second)->toBe(2)
        ->and($reloaded->fresh()->draft_graph)->toBe(graphWith('n2'));
});

it('does not treat a driver-returned string revision as stale against the caller\'s int', function () {
    // save() must not rely on the database driver handing back an int. SQLite
    // (this suite's driver) always does, so this can't be reproduced through a
    // real fetch here — it is forced directly onto the model to stand in for
    // MySQL or Postgres, which commonly return an unsigned integer column as a
    // numeric string, where '1' !== 1 under PHP's strict comparison would refuse
    // every save after the first as stale.
    //
    // NOTE on what this can and cannot detect. It was written when Flow carried
    // no cast for draft_revision, and its stated counterfactual was "drop the
    // (int) in SaveDraft::save()". Flow now casts draft_revision to integer, and
    // a cast applies on attribute *read* regardless of setRawAttributes()
    // bypassing it on write — so this scenario now survives either guard alone
    // and no longer detects the inline cast being removed. It still detects the
    // pair being removed, which is the failure that matters: it is the only test
    // here that exercises a non-int revision at all.
    $first = app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);

    $this->flow->setRawAttributes(
        array_merge($this->flow->getAttributes(), ['draft_revision' => (string) $first]),
        true
    );

    $second = app(SaveDraft::class)->save($this->flow, graphWith('n2'), $first);

    expect($second)->toBe(2);
});
