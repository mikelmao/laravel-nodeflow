<?php

namespace Tests\Support;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Execution\SubjectExiter;
use Nodeflow\Models\Run;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

/**
 * Reproduces the reviewer's probe for the NodeRunner chunk-skew bug: a node
 * whose body reacts to one subject (e.g. a conversion) by calling
 * SubjectExiter::exit() on it, mid-loop — mutating the very
 * `status = 'active'` set NodeRunner is chunking over. SubjectExiter::exit()
 * is the documented cancellation mechanism (spec §7.3, "a plain DB write"),
 * so this is not a contrived edge case: any node that cancels the rest of a
 * cohort in reaction to one subject's outcome does exactly this.
 *
 * Records every subject id it was actually invoked for on a static property
 * so the test can assert against it once NodeRunner::run() returns, since
 * NodeRunner resolves its own node instance internally and never hands it
 * back to the caller.
 */
class FakeSelfExitingNode extends Node implements HandlesSubject
{
    /** @var string[] */
    public static array $seen = [];

    public static string $exitSubjectId = '1';

    public static function type(): string
    {
        return 'test.self-exiting';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('SelfExiting')->outputs(['ok']);
    }

    public function forSubject(SubjectContext $c): NodeResult
    {
        static::$seen[] = $c->subjectId();

        if ($c->subjectId() === static::$exitSubjectId) {
            $run = Run::find($c->runId());

            app(SubjectExiter::class)->exit($run, [$c->subjectId()]);
        }

        return $c->continue('ok');
    }
}
