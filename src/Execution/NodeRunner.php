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

        $query = RunSubject::where('run_id', $run->id)
            ->where('current_node_id', $nodeId)
            ->where('status', 'active');

        $chunkSize = $node instanceof HandlesAudience
            ? config('nodeflow.limits.audience_chunk', 5000)
            : config('nodeflow.limits.subject_chunk', 500);

        $query->orderBy('id')->chunk($chunkSize, function ($rows) use (&$merged, &$subjectType, $node, $run, $nodeId, $config) {
            $subjectType = $rows->first()->subject_type;
            $ids = $rows->pluck('subject_id')->map('strval')->all();

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

        return $this->advance($run, $graph, $nodeId, $merged, $subjectType, (int) ((microtime(true) - $startedAt) * 1000));
    }

    private function advance(Run $run, Graph $graph, string $nodeId, NodeResult $result, ?string $subjectType, int $durationMs): array
    {
        $next = [];

        foreach ($result->outputs() as $output => $subjectIds) {
            $targets = $graph->targetsFor($nodeId, $output);
            $target = $targets[0] ?? null;

            $run->nodeExecutions()->create([
                'node_id' => $nodeId,
                'output' => $output,
                'subject_count' => count($subjectIds),
                'duration_ms' => $durationMs,
            ]);

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
                RunSubject::where('run_id', $run->id)
                    ->where('subject_type', $subjectType)
                    ->where('subject_id', (string) $subjectId)
                    ->where('status', 'active')
                    ->update(['status' => 'failed', 'last_error' => $message, 'current_node_id' => null]);
            }
        }

        return array_values(array_unique($next));
    }
}
