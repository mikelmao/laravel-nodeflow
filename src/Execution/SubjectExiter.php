<?php

namespace Nodeflow\Execution;

use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;

class SubjectExiter
{
    /**
     * Statuses in which a run's engine workflow is still live and could
     * plausibly be sitting in an `awaitWithTimeout` wait. Mirrors the "live"
     * definition in FlowVersion::hasLiveRuns() so the two stay in sync.
     */
    private const LIVE_STATUSES = ['pending', 'running', 'waiting', 'blocked'];

    public function __construct(private WorkflowEngine $engine) {}

    /**
     * Remove subjects from a live run. This is how cancellation works in a
     * cohort: the subject is gone by the time the next node runs. Exactly one
     * signal is sent per wait, and only when the run's last active subject
     * leaves — never one per subject. That bound is what keeps a six-figure
     * conversion wave under the engine's 5,000 pending-signal cap: the cap is
     * structurally unreachable because at most one signal is ever sent per
     * exhausted run, regardless of audience size.
     *
     * A run that has already reached a terminal status (completed, failed,
     * cancelled) is not awaiting anything, so a duplicate or late exit call
     * against it must not fire a stray signal at a workflow that is no
     * longer listening.
     */
    public function exit(Run $run, array $subjectIds): void
    {
        RunSubject::where('run_id', $run->id)
            ->whereIn('subject_id', array_map('strval', $subjectIds))
            ->where('status', 'active')
            ->update(['status' => 'exited', 'exited_at' => now(), 'current_node_id' => null]);

        if (
            $run->activeSubjectCount() === 0
            && $run->engine_workflow_id !== null
            && in_array($run->status, self::LIVE_STATUSES, true)
        ) {
            $this->engine->signal($run->engine_workflow_id, 'audienceEmptied');
        }
    }
}
