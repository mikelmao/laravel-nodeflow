<?php

namespace Nodeflow\Runs;

use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Models\Run;

/**
 * The run view's aggregate, in two grouped queries.
 *
 * Both reads go through the Run's own relations, never through RunSubject or
 * NodeExecution directly. Those two tables carry no tenant_id by design (spec
 * E1) precisely because they are only reachable through a Run, which is scoped
 * — so relation-only access is not a style preference here, it is the whole
 * isolation mechanism. tests/Unit/ArchitectureTest.php enforces it, and this
 * class deliberately imports neither model so it needs no allowlist entry.
 */
class RunOverlay
{
    /**
     * The only status the engine ever writes as an end state.
     *
     * `runs.status` has no durable failure value today (open issue C-1), so a
     * run that dies leaves a client polling until the page closes. That is a
     * known, accepted limitation rather than an oversight, and keeping the list
     * here means the day a failure status exists, the client needs no change.
     */
    private const TERMINAL_STATUSES = ['completed'];

    public function __construct(private GraphTypeCatalog $types) {}

    public function snapshot(Run $run, Graph $graph): array
    {
        $recorded = $this->recorded($run);
        $activeAt = $this->activeAt($run);
        $nodes = [];

        // Keyed off the graph, not off the rows: every node in the pinned graph
        // gets an entry so "never reached" is server-authored, and a row naming
        // a node the graph does not contain is ignored rather than handed to a
        // client that cannot draw it.
        foreach ($graph->nodeIds() as $nodeId) {
            $id = (string) $nodeId;
            $rows = $recorded[$id] ?? null;
            $waiting = $activeAt[$id] ?? 0;

            $nodes[$id] = [
                // E13. Row existence OR a subject sitting here — never a count
                // of subjects released. A node with one row summing to zero and
                // nobody waiting is `true`; a node with no row and nobody
                // waiting is `false`. `array_sum($byOutput) > 0` collapses
                // those two, and they are the two the whole view turns on.
                'reached' => $rows !== null || $waiting > 0,
                // Cast: a PHP array with no entries encodes as JSON `[]`, and
                // numeric-string keys become ints, so an output literally named
                // "1" would encode this as an array too. The client indexes it
                // by name, so it must always be an object.
                'byOutput' => (object) ($rows['byOutput'] ?? []),
                'waiting' => $waiting,
                'failed' => $rows['failed'] ?? 0,
                'error' => $rows['error'] ?? null,
            ];
        }

        $triggerNodeId = (string) $run->trigger_node_id;

        if (isset($nodes[$triggerNodeId])
            && $this->types->family($graph->node($triggerNodeId)['type'] ?? '') === 'trigger') {
            $origin = in_array((string) $run->started_via, ['manual', 'subflow'], true)
                ? 'bypassed'
                : 'triggered';
            $nodes[$triggerNodeId] = [
                'reached' => true,
                'byOutput' => (object) [$origin => 1],
                'waiting' => 0,
                'failed' => 0,
                'error' => null,
            ];
        }

        return [
            'status' => (string) $run->status,
            'terminal' => in_array((string) $run->status, self::TERMINAL_STATUSES, true),
            // Same cast, same reason: a graph whose node ids are "0", "1", "2"
            // would otherwise arrive as a JSON array.
            'nodes' => (object) $nodes,
        ];
    }

    /**
     * @return array<string, array{byOutput: array<string,int>, failed: int, error: ?string}>
     */
    private function recorded(Run $run): array
    {
        // Ordered by each group's earliest row (MIN(id)), not left to the
        // database's default GROUP BY ordering: SQLite groups alphabetically
        // by the grouping columns absent an ORDER BY, so 'failed' would sort
        // before 'sent' regardless of which output the node produced first.
        // PHP's array `===` is order-sensitive, and a client rendering badges
        // in the order a node actually produced them needs that order to be
        // deterministic and meaningful, not an artifact of string collation.
        $rows = $run->nodeExecutions()
            ->selectRaw('node_id, output, SUM(subject_count) as subjects, MAX(error) as error, MIN(id) as first_id')
            ->groupBy('node_id', 'output')
            ->orderBy('first_id')
            ->get();

        $recorded = [];

        foreach ($rows as $row) {
            $node = (string) $row->node_id;
            $recorded[$node] ??= ['byOutput' => [], 'failed' => 0, 'error' => null];

            // output = null is the failure bucket NodeRunner::advance() writes,
            // carrying the joined messages. It is not an output the node
            // declared, so it must never appear in byOutput.
            if ($row->output === null) {
                $recorded[$node]['failed'] += (int) $row->subjects;
                $recorded[$node]['error'] ??= $row->error === null ? null : (string) $row->error;

                continue;
            }

            $recorded[$node]['byOutput'][(string) $row->output] = (int) $row->subjects;
        }

        return $recorded;
    }

    /**
     * Subjects sitting at a node right now, per node.
     *
     * Grouped by status as well as node even though only `active` is counted:
     * every terminal transition nulls current_node_id, so a non-active row with
     * a node id should not exist — and grouping by status is what lets a test
     * assert it is not counted, instead of the query shape making that true by
     * accident.
     *
     * @return array<string, int>
     */
    private function activeAt(Run $run): array
    {
        $rows = $run->subjects()
            ->selectRaw('current_node_id, status, COUNT(*) as subjects')
            ->whereNotNull('current_node_id')
            ->groupBy('current_node_id', 'status')
            ->get();

        $activeAt = [];

        foreach ($rows as $row) {
            if ((string) $row->status !== 'active') {
                continue;
            }

            $activeAt[(string) $row->current_node_id] = (int) $row->subjects;
        }

        return $activeAt;
    }
}
