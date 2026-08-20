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
 * of silently discarding whichever author saved second. The check is enforced
 * in the UPDATE's own WHERE clause, not just compared in PHP, because two
 * overlapping requests can both read the same revision before either writes —
 * see save().
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
        // Explicit (int) here, not just the `?? 0` fallback, and kept even though
        // Flow now casts draft_revision to integer as well. MySQL and Postgres
        // commonly hand an unsigned integer column back as a numeric string where
        // SQLite (this package's test driver) hands back an int, and a bare `!==`
        // below comparing '1' against 1 would find them "different" and reject
        // every save after the first as stale. The model cast closes that, but the
        // comparison lives here: a reader auditing this `!==` should not have to
        // go and check another file's $casts array to know it is safe, and a cast
        // removed there would fail silently on those drivers while this suite kept
        // passing on SQLite. Belt and braces, deliberately.
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
        $savedAt = now();

        // Compare-and-swap, not check-then-act. The comparison above is against a
        // revision this process read some time ago — at route binding, before
        // authorization — and `$flow->update()` emits `UPDATE ... WHERE id = ?`,
        // which happily writes over whatever arrived in between. Two debounced
        // autosaves from two open editors both reading revision N therefore both
        // passed the check, both wrote N+1, and both reported success: one
        // author's graph gone, no conflict reported to anyone. Adding
        // `draft_revision = $current` to the WHERE clause makes the check and the
        // write one statement, so exactly one of the two can win.
        //
        // A query-builder update rather than lockForUpdate() in a transaction, for
        // three reasons. It is one statement with no transaction to hold open on
        // the hot path of a debounced autosave. Flow's only `updating` hook is
        // BelongsToTenant's tenant freeze, which this write cannot trip because it
        // never touches tenant_id — so skipping the model hooks costs nothing
        // here. And SQLite, this package's test driver, compiles `lockForUpdate()`
        // to nothing at all, so a lock-based version would be a mechanism the
        // suite could never exercise.
        //
        // withoutTenancy() because reaching this Flow already proved entitlement:
        // the row was fetched through the scoped binding, and re-applying the
        // scope here would make an autosave depend on the ambient tenant still
        // resolving, which is a different question from "may this caller write".
        $written = Flow::withoutTenancy()
            ->whereKey($flow->getKey())
            ->where('draft_revision', $current)
            ->update([
                // Encoded here, not left to the model's `array` cast: a
                // query-builder update does not run casts, and handing the
                // builder a PHP array binds it as one. Throwing on an
                // unencodable graph matches the cast, which raises
                // JsonEncodingException rather than writing json_encode()'s
                // false as an empty column.
                'draft_graph' => json_encode($graph, JSON_THROW_ON_ERROR),
                'draft_updated_at' => $savedAt,
                'draft_revision' => $next,
            ]);

        if ($written === 0) {
            // Someone else moved the revision between our read and our write, or
            // deleted the flow outright. Either way this save did not happen, and
            // the caller gets the same refusal the pre-check gives — carrying
            // whatever is actually there now, re-read rather than assumed.
            $fresh = Flow::withoutTenancy()->whereKey($flow->getKey())->first();

            throw new StaleDraftException(
                $fresh?->draft_graph ?? [],
                (int) ($fresh?->draft_revision ?? $current),
            );
        }

        // The row moved, so the in-memory model must too: callers hold this
        // instance across saves (a controller does not, but SaveDraft's own
        // contract does not say so), and leaving it on the old revision would make
        // the next save on the same instance wrongly refuse itself as stale.
        //
        // syncOriginalAttributes for these three keys only, not syncOriginal():
        // marking the whole model clean would swallow an unrelated pending change
        // a caller was holding — `$flow->name = 'x'` before an autosave would
        // silently stop being dirty and never reach the database.
        $flow->forceFill([
            'draft_graph' => $graph,
            'draft_updated_at' => $savedAt,
            'draft_revision' => $next,
        ])->syncOriginalAttributes(['draft_graph', 'draft_updated_at', 'draft_revision']);

        return $next;
    }
}
