<?php

namespace Nodeflow\Workflows;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Nodeflow\Models\Run;
use Workflow\V2\Events\WorkflowFailed;

/**
 * Projects a durably committed interpreter failure into Nodeflow's run record.
 *
 * The durable engine can deliver an event before CreateRun has persisted its
 * returned handle. The instance id is therefore the durable correlation key;
 * a non-null stored handle is only an additional exact-match guard.
 *
 * Laravel queues this listener after commit, isolating durable terminal state
 * from projection failures. This is not an outbox: if queue publication itself
 * fails after commit, recovery is the queue/runtime operator's responsibility.
 */
final class ProjectWorkflowFailure implements ShouldQueueAfterCommit
{
    /** A transient projection failure must not leave a durable run unreported. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    private const LIVE_STATUSES = ['pending', 'running', 'waiting', 'blocked'];

    private const INSTANCE_PREFIX = 'nodeflow-run:';

    /** Largest signed bigint, the portable Laravel id range across supported databases. */
    private const MAX_RUN_ID = '9223372036854775807';

    /** MySQL TEXT's portable byte capacity. */
    private const MAX_ERROR_BYTES = 65_535;

    /** @return class-string<WorkflowFailed> */
    public static function eventClass(): string
    {
        return WorkflowFailed::class;
    }

    public function handle(WorkflowFailed $event): void
    {
        if ($event->workflowClass !== FlowInterpreter::class) {
            return;
        }

        DB::transaction(function () use ($event): void {
            $run = $this->runFor($event);

            if ($run === null || ! in_array($run->status, self::LIVE_STATUSES, true)) {
                return;
            }

            $error = $this->boundedError($event);
            // This queued listener runs after the durable task transaction has
            // committed. Preserve the event timestamp rather than listener time.
            $endedAt = CarbonImmutable::parse($event->committedAt)->utc();

            $run->subjects()->where('status', 'active')->update([
                'status' => 'failed',
                'last_error' => $error,
                'current_node_id' => null,
            ]);

            $run->update([
                'status' => 'failed',
                'error' => $error,
                'ended_at' => $endedAt,
            ]);
        });
    }

    private function runFor(WorkflowFailed $event): ?Run
    {
        if (! str_starts_with($event->instanceId, self::INSTANCE_PREFIX)) {
            return null;
        }

        $id = substr($event->instanceId, strlen(self::INSTANCE_PREFIX));

        // No signs, whitespace, leading zeroes, or compound identifiers. The
        // recomputed identity below also makes an oversized numeric string inert.
        if (preg_match('/^[1-9][0-9]*$/D', $id) !== 1
            || strlen($id) > strlen(self::MAX_RUN_ID)
            || (strlen($id) === strlen(self::MAX_RUN_ID) && strcmp($id, self::MAX_RUN_ID) > 0)) {
            return null;
        }

        // This listener has no ambient tenant. It may read one exact primary key
        // across tenants, but never uses the untrusted event data as a broader
        // tenant, workflow, or status selector.
        $run = Run::withoutTenancy()->whereKey($id)->lockForUpdate()->first();

        if ($run === null) {
            return null;
        }

        $expected = self::INSTANCE_PREFIX.$run->id;

        if (! hash_equals($expected, $event->instanceId)) {
            return null;
        }

        if ($run->engine_workflow_id !== null
            && ! hash_equals($expected, (string) $run->engine_workflow_id)) {
            return null;
        }

        return $run;
    }

    private function boundedError(WorkflowFailed $event): string
    {
        $error = $event->exceptionClass.': '.$event->message;

        // A PHP string can carry invalid UTF-8, while a portable TEXT column
        // cannot. Valid input is unchanged; malformed input is normalized before
        // mb_strcut enforces a byte (not character) ceiling without splitting a
        // multibyte code point.
        if (! mb_check_encoding($error, 'UTF-8')) {
            $error = mb_convert_encoding($error, 'UTF-8', 'UTF-8');
        }

        return mb_strcut($error, 0, self::MAX_ERROR_BYTES, 'UTF-8');
    }
}
