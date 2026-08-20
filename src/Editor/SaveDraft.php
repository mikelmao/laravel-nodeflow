<?php

namespace Nodeflow\Editor;

use Nodeflow\Models\Flow;

/**
 * Persists an editor draft, last-write-wins with stale detection.
 *
 * A draft is deliberately not a version (spec E3): versions are immutable and
 * numbered, and a graph mid-edit is neither. So this writes columns on the
 * flow and does no validation at all — a half-connected graph is the normal
 * state of a canvas someone is working on, and refusing to store it would
 * make autosave useless exactly when it matters.
 *
 * Concurrency is last-write-wins *with* a check rather than without one. The
 * caller sends the draft_revision it last saw; a mismatch is refused instead
 * of silently discarding whichever author saved second.
 *
 * The token is a revision counter, not draft_updated_at, on purpose.
 * Illuminate\Database\Grammar::getDateFormat() stores timestamps at second
 * precision, and a debounced autosave saves several times per second — so
 * two saves inside the same second would mint an identical timestamp and
 * stale-write detection would silently stop detecting, which is worse than
 * it failing loudly. draft_updated_at is still written on every save because
 * "last saved 3 minutes ago" is worth showing an author; it is just not the
 * thing compared here.
 */
class SaveDraft
{
    /**
     * @return int the new draft_revision, to be echoed back on the next save
     *
     * @throws StaleDraftException
     */
    public function save(Flow $flow, array $graph, ?int $lastSeenRevision): int
    {
        // Falls back to 0 for an in-memory model that was created without this
        // attribute set explicitly: the column default is 0, but an Eloquent
        // model does not know that until it is refreshed from the database.
        $current = $flow->draft_revision ?? 0;

        // A null last-seen means "I have never loaded a draft," which is
        // revision 0. It must not be treated as "skip the check": a client
        // that never loaded the flow must not be able to blow away an
        // existing draft just by omitting the token.
        if (($lastSeenRevision ?? 0) !== $current) {
            throw new StaleDraftException($flow->draft_graph ?? [], $current);
        }

        $next = $current + 1;

        $flow->update([
            'draft_graph' => $graph,
            'draft_updated_at' => now(),
            'draft_revision' => $next,
        ]);

        return $next;
    }
}
