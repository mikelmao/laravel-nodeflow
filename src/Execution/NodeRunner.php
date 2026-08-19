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

        $results = [];

        $query = RunSubject::where('run_id', $run->id)
            ->where('current_node_id', $nodeId)
            ->where('status', 'active');

        $chunkSize = $node instanceof HandlesAudience
            ? config('nodeflow.limits.audience_chunk', 5000)
            : config('nodeflow.limits.subject_chunk', 500);

        $query->orderBy('id')->chunk($chunkSize, function ($rows) use (&$results, $node, $run, $nodeId, $config) {
            $subjectType = $rows->first()->subject_type;
            $ids = $rows->pluck('subject_id')->map('strval')->all();

            if ($node instanceof HandlesAudience) {
                $results[] = $node->forAudience(
                    new AudienceContext($run, $nodeId, $config, $subjectType, $ids)
                );

                return;
            }

            if (! $node instanceof HandlesSubject) {
                throw new RuntimeException(
                    'Node ['.$node::type().'] implements neither HandlesSubject nor HandlesAudience.'
                );
            }

            $models = $this->subjects->resolve($subjectType, $ids);

            foreach ($ids as $id) {
                try {
                    $results[] = $node->forSubject(
                        new SubjectContext($run, $nodeId, $config, $id, $models[$id] ?? null)
                    );
                } catch (Throwable $e) {
                    $results[] = NodeResult::failed($id, $e->getMessage());
                }
            }
        });

        $merged = $results === [] ? NodeResult::empty() : NodeResult::merge(...$results);

        return $this->advance($run, $graph, $nodeId, $merged, (int) ((microtime(true) - $startedAt) * 1000));
    }

    private function advance(Run $run, Graph $graph, string $nodeId, NodeResult $result, int $durationMs): array
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
                    ->where('subject_id', (string) $subjectId)
                    ->update(['status' => 'failed', 'last_error' => $message, 'current_node_id' => null]);
            }
        }

        return array_values(array_unique($next));
    }
}
