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
        // Explicit (int) here, not just the `?? 0` fallback: draft_revision
        // carries no cast on the Flow model, so what comes back is whatever the
        // driver hands over. SQLite (this package's test driver) returns a
        // native PHP int for an integer column, but MySQL and Postgres commonly
        // return one as a numeric string — and a bare `!==` below would then
        // compare a string against an int and always find them "different",
        // rejecting every normal save as stale, including a flow's very first
        // save after being loaded the ordinary way. Dropping this cast would
        // not fail loudly; it would silently start refusing saves on those
        // drivers while this suite kept passing on SQLite.
        $current = (int) ($flow->draft_revision ?? 0);

        // A null last-seen means "I have never loaded a draft," which is
        // revision 0. It must not be treated as "skip the check": a client
        // that never loaded the flow must not be able to blow away an
        // existing draft just by omitting the token. The (int) cast on this
        // side is defensive, not load-bearing: PHP's own coercive typing
        // already normalizes any numeric-string caller input to int at this
        // method's boundary (this file declares no strict_types), so this
        // line is here to state the intent plainly rather than to change
        // behaviour.
        if ((int) ($lastSeenRevision ?? 0) !== $current) {
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
