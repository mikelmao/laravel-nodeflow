<?php

namespace Nodeflow\Console;

use Illuminate\Console\Command;
use Nodeflow\Models\NodeExecution;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;

class PruneCommand extends Command
{
    protected $signature = 'nodeflow:prune {--days= : Retention window} {--dry-run}';

    protected $description = 'Delete terminal nodeflow runs, subjects, and node executions past the retention window.';

    /**
     * Terminal statuses eligible for pruning. Deliberately excludes 'blocked': a
     * blocked run is recoverable once the missing node type is re-registered, so
     * pruning it would destroy state a fix could still resume. This also excludes
     * 'pending', 'running', and 'waiting' for the same reason — a run is only safe
     * to delete once it can no longer resume on its own or via operator action.
     *
     * Operational consequence: a run stuck in 'blocked' forever (e.g. the node
     * type is never re-registered) is never pruned by this command at any age.
     * Clearing permanently-abandoned blocked runs is a manual/operator decision,
     * not something this command will do automatically.
     */
    private const TERMINAL = ['completed', 'failed', 'cancelled'];

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('nodeflow.retention.runs_days', 90));
        $cutoff = now()->subDays($days);

        $query = Run::withoutTenancy()
            ->whereIn('status', self::TERMINAL)
            ->where('created_at', '<', $cutoff);

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("Would delete {$count} runs older than {$days} days.");

            return self::SUCCESS;
        }

        $query->chunkById(500, function ($runs) {
            $runIds = $runs->pluck('id');

            // The migration declares cascadeOnDelete() on run_subjects.run_id and
            // node_executions.run_id, but SQLite only enforces foreign keys when the
            // foreign_keys pragma is on for the connection. Delete children explicitly
            // so pruning is correct regardless of the connection's pragma state.
            RunSubject::whereIn('run_id', $runIds)->delete();
            NodeExecution::whereIn('run_id', $runIds)->delete();

            foreach ($runs as $run) {
                $run->delete();
            }
        });

        $this->info("Deleted {$count} runs older than {$days} days.");

        return self::SUCCESS;
    }
}
