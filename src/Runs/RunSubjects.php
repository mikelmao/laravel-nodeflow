<?php

namespace Nodeflow\Runs;

use Nodeflow\Models\Run;

/**
 * "Who is at this node right now" — which is all the schema can answer.
 *
 * There is no per-subject visit history anywhere: nodeflow_node_executions
 * holds aggregate counts, and every terminal transition (NodeRunner::advance(),
 * reconcileDepartures(), SubjectExiter::exit()) nulls current_node_id along
 * with the status. So this cannot list who *passed through* a node, and it
 * cannot list a node's failures either — their current_node_id is gone by the
 * time they are failed. The overlay still counts those failures, from the
 * execution rows. Countable, not listable; spec E15.
 *
 * Reaches data only through $run->subjects(), for the reason RunOverlay
 * documents at length.
 */
class RunSubjects
{
    /**
     * @return array{data: array<int, array<string, mixed>>, next_cursor: ?string}
     */
    public function atNode(Run $run, string $nodeId, ?string $cursor = null): array
    {
        $page = $run->subjects()
            ->where('current_node_id', $nodeId)
            ->where('status', 'active')
            // Cursor pagination, not offset: at six figures an OFFSET walks
            // every skipped row, and a subject leaving the node mid-walk
            // shifts the window so later pages skip whoever moved into the
            // gap. This is the same reasoning NodeRunner documents for
            // choosing chunkById over chunk.
            ->orderBy('id')
            ->cursorPaginate(
                (int) config('nodeflow.limits.subject_page', 50),
                ['*'],
                'cursor',
                $cursor,
            );

        return [
            // Shaped here rather than in the controller so the controller never
            // needs to name or import the RunSubject model at all. The closure
            // parameter is deliberately untyped for the same reason.
            'data' => array_map(fn ($subject) => [
                'id' => (int) $subject->id,
                'subject_type' => (string) $subject->subject_type,
                'subject_id' => (string) $subject->subject_id,
                'status' => (string) $subject->status,
                'current_node_id' => $subject->current_node_id === null ? null : (string) $subject->current_node_id,
                'last_error' => $subject->last_error,
                'exited_at' => $subject->exited_at?->toIso8601String(),
            ], $page->items()),
            'next_cursor' => $page->nextCursor()?->encode(),
        ];
    }
}
