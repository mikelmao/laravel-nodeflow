<?php

namespace Nodeflow\Execution;

use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\NodeRegistry;
use RuntimeException;
use Throwable;

class NodeRunner
{
    public function __construct(
        private NodeRegistry $registry,
        private SubjectResolver $subjects,
    ) {}

    /** @return string[] node ids that now hold subjects */
    public function run(Run $run, Graph $graph, string $nodeId): array
    {
        $definition = $graph->node($nodeId);

        if ($definition === null) {
            throw new RuntimeException("Node [{$nodeId}] is not present in the pinned graph.");
        }

        $node = $this->registry->resolve($definition['type']);
        $config = $definition['config'] ?? [];
        $startedAt = microtime(true);

        // Folded incrementally per chunk rather than accumulated as one NodeResult per
        // subject: at cohort scale (~100k subjects) holding every subject's individual
        // result in memory and spreading them all as variadic arguments to merge() at
        // the end does not scale. Each chunk is merged down to one NodeResult before
        // moving to the next, so at most one chunk's worth of results is ever held.
        $merged = NodeResult::empty();
        $subjectType = null;

        // The set the chunk loop actually iterated. advance() needs it to tell
        // "this subject left the flow here" from "this subject is somewhere else
        // in the run": a node that returns NodeResult::empty() names nobody, so
        // without this set its subjects are indistinguishable from untouched
        // rows and would be stranded active on a finished run.
        $seen = [];

        $query = RunSubject::where('run_id', $run->id)
            ->where('current_node_id', $nodeId)
            ->where('status', 'active');

        $chunkSize = $node instanceof HandlesAudience
            ? config('nodeflow.limits.audience_chunk', 5000)
            : config('nodeflow.limits.subject_chunk', 500);

        $query->orderBy('id')->chunk($chunkSize, function ($rows) use (&$merged, &$subjectType, &$seen, $node, $run, $nodeId, $config) {
            $subjectType = $rows->first()->subject_type;
            $ids = $rows->pluck('subject_id')->map('strval')->all();

            foreach ($ids as $id) {
                $seen[$id] = true;
            }

            if ($node instanceof HandlesAudience) {
                $chunkResult = $node->forAudience(
                    new AudienceContext($run, $nodeId, $config, $subjectType, $ids)
                );

                $merged = NodeResult::merge($merged, $chunkResult);

                return;
            }

            if (! $node instanceof HandlesSubject) {
                throw new RuntimeException(
                    'Node ['.$node::type().'] implements neither HandlesSubject nor HandlesAudience.'
                );
            }

            $models = $this->subjects->resolve($subjectType, $ids);
            $chunkResults = [];

            foreach ($ids as $id) {
                try {
                    $chunkResults[] = $node->forSubject(
                        new SubjectContext($run, $nodeId, $config, $id, $models[$id] ?? null)
                    );
                } catch (Throwable $e) {
                    $chunkResults[] = NodeResult::failed($id, class_basename($e).': '.$e->getMessage());
                }
            }

            $merged = NodeResult::merge($merged, ...$chunkResults);
        });

        return $this->advance($run, $graph, $nodeId, $merged, $subjectType, array_map('strval', array_keys($seen)), (int) ((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @param  string[]  $seen  subject ids that were active at this node when it ran
     */
    private function advance(Run $run, Graph $graph, string $nodeId, NodeResult $result, ?string $subjectType, array $seen, int $durationMs): array
    {
        $next = [];
        $accountedFor = [];

        foreach ($result->outputs() as $output => $subjectIds) {
            $targets = $graph->targetsFor($nodeId, $output);
            $target = $targets[0] ?? null;

            $run->nodeExecutions()->create([
                'node_id' => $nodeId,
                'output' => $output,
                'subject_count' => count($subjectIds),
                'duration_ms' => $durationMs,
            ]);

            foreach ($subjectIds as $subjectId) {
                $accountedFor[(string) $subjectId] = true;
            }

            foreach (array_chunk($subjectIds, 1000) as $chunk) {
                RunSubject::where('run_id', $run->id)
                    ->where('subject_type', $subjectType)
                    ->whereIn('subject_id', $chunk)
                    ->where('status', 'active')
                    ->update($target === null
                        ? ['status' => 'completed', 'current_node_id' => null]
                        : ['current_node_id' => $target]);
            }

            if ($target !== null) {
                $next[] = $target;
            }
        }

        if ($result->failures() !== []) {
            $run->nodeExecutions()->create([
                'node_id' => $nodeId,
                'output' => null,
                'subject_count' => count($result->failures()),
                'duration_ms' => $durationMs,
                'error' => implode('; ', array_slice(array_unique(array_values($result->failures())), 0, 5)),
            ]);

            foreach ($result->failures() as $subjectId => $message) {
                $accountedFor[(string) $subjectId] = true;

                RunSubject::where('run_id', $run->id)
                    ->where('subject_type', $subjectType)
                    ->where('subject_id', (string) $subjectId)
                    ->where('status', 'active')
                    ->update(['status' => 'failed', 'last_error' => $message, 'current_node_id' => null]);
            }
        }

        $this->reconcileDepartures($run, $nodeId, $subjectType, $seen, $accountedFor);

        return array_values(array_unique($next));
    }

    /**
     * A node names, per subject, either an output or a failure. Any subject that
     * was active at this node and appears in neither has left the flow — that is
     * the whole meaning of NodeResult::empty(), and it is what makes core.exit a
     * legitimate terminal node and core.start_flow's default exit_this_flow
     * correct rather than a leak.
     *
     * Without this sweep those subjects keep status='active' and
     * current_node_id=<finished node> forever, which breaks two documented
     * behaviours: SubjectExiter never observes activeSubjectCount() === 0, so no
     * later cohort wait wakes early on audience-empty (D10 / spec 7.3), and
     * CompleteRunActivity marks the run completed while subjects read as active.
     *
     * Scoped deliberately narrowly: only ids the chunk loop actually iterated,
     * and only rows still sitting at this node, so subjects elsewhere in the run
     * are never touched.
     *
     * @param  string[]  $seen
     * @param  array<string, true>  $accountedFor
     */
    private function reconcileDepartures(Run $run, string $nodeId, ?string $subjectType, array $seen, array $accountedFor): void
    {
        if ($subjectType === null) {
            return;
        }

        // strval throughout: PHP silently turns a numeric-string array key back into
        // an int, and binding an int against a varchar subject_id column is an error
        // on Postgres even though SQLite and MySQL coerce it.
        $departed = array_values(array_map(
            'strval',
            array_diff($seen, array_map('strval', array_keys($accountedFor))),
        ));

        foreach (array_chunk($departed, 1000) as $chunk) {
            RunSubject::where('run_id', $run->id)
                ->where('subject_type', $subjectType)
                ->whereIn('subject_id', $chunk)
                ->where('current_node_id', $nodeId)
                ->where('status', 'active')
                ->update(['status' => 'completed', 'current_node_id' => null]);
        }
    }
}
