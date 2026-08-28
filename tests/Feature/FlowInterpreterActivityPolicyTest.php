<?php

use Illuminate\Support\Facades\Queue;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Engine\DurableWorkflowEngine;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Execution\StartRun;
use Nodeflow\Models\Flow;
use Nodeflow\Nodeflow;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Workflows\Activities\RunNodeActivity;
use Tests\Support\FakeRetryingAudienceNode;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver
    {
        public function currentTenantId(): ?string
        {
            return 'org-1';
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return true;
        }
    });

    Nodeflow::register([FakeRetryingAudienceNode::class]);
    app()->register(WorkflowServiceProvider::class);
    $this->artisan('migrate', [
        '--path' => realpath(__DIR__.'/../../vendor/durable-workflow/workflow/src/migrations'),
        '--realpath' => true,
    ])->assertSuccessful();
    config()->set('workflows.v2.task_dispatch_mode', 'poll');
    app()->bind(WorkflowEngine::class, DurableWorkflowEngine::class);
    Queue::fake();
});

it('applies a published node activity policy to the durable activity execution', function () {
    $flow = Flow::create(['name' => 'Policy', 'status' => 'draft']);
    app(PublishFlow::class)->publish($flow, triggeredGraph([
        'start' => 'retrying',
        'nodes' => [
            ['id' => 'retrying', 'type' => 'test.retrying-audience', 'config' => []],
            ['id' => 'exit', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'retrying', 'output' => 'accepted', 'to' => 'exit']],
    ]));

    [$execution, $runId] = scheduleFlowInterpreterNodeActivity($flow);

    expect($execution->retry_policy)->toMatchArray([
        'max_attempts' => 5,
        'backoff_seconds' => [1, 5, 30, 120],
        'start_to_close_timeout' => 90,
        'non_retryable_error_types' => [InvalidArgumentException::class],
    ])
        ->and($execution->activityArguments())->toBe([$runId, 'retrying']);
});

it('uses stable Nodeflow defaults when a legacy graph has no activity policy', function () {
    $flow = Flow::create(['name' => 'Legacy policy', 'status' => 'draft']);
    app(PublishFlow::class)->publish($flow, triggeredGraph([
        'start' => 'retrying',
        'nodes' => [
            ['id' => 'retrying', 'type' => 'test.retrying-audience', 'config' => []],
            ['id' => 'exit', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'retrying', 'output' => 'accepted', 'to' => 'exit']],
    ]));

    $version = $flow->fresh()->currentVersion()->sole();
    $graph = $version->graph;
    unset($graph['nodes'][1]['runtime']['activity']);
    $version->update(['graph' => $graph]);

    [$execution, $runId] = scheduleFlowInterpreterNodeActivity($flow);

    expect($execution->retry_policy)->toMatchArray([
        'max_attempts' => 3,
        'backoff_seconds' => [1, 2, 5, 10, 15, 30, 60, 120],
        'start_to_close_timeout' => null,
        'non_retryable_error_types' => [],
    ])
        ->and($execution->activityArguments())->toBe([$runId, 'retrying']);
});

it('uses stable Nodeflow defaults for unmarked legacy activity metadata', function () {
    $flow = Flow::create(['name' => 'Unmarked policy', 'status' => 'draft']);
    app(PublishFlow::class)->publish($flow, triggeredGraph([
        'start' => 'retrying',
        'nodes' => [
            ['id' => 'retrying', 'type' => 'test.retrying-audience', 'config' => []],
            ['id' => 'exit', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'retrying', 'output' => 'accepted', 'to' => 'exit']],
    ]));

    $version = $flow->fresh()->currentVersion()->sole();
    $graph = $version->graph;
    $graph['nodes'][1]['runtime']['activity'] = ['max_attempts' => 99];
    $version->update(['graph' => $graph]);

    [$execution, $runId] = scheduleFlowInterpreterNodeActivity($flow);

    expect($execution->retry_policy)->toMatchArray([
        'max_attempts' => 3,
        'backoff_seconds' => [1, 2, 5, 10, 15, 30, 60, 120],
        'start_to_close_timeout' => null,
        'non_retryable_error_types' => [],
    ])
        ->and($execution->activityArguments())->toBe([$runId, 'retrying']);
});

/** @return array{0: ActivityExecution, 1: int} */
function scheduleFlowInterpreterNodeActivity(Flow $flow): array
{
    $run = app(StartRun::class)->forFlow($flow->fresh(), 'user', ['1']);
    $engineRun = WorkflowRun::query()
        ->where('workflow_instance_id', $run->engine_workflow_id)
        ->sole();

    $workflowTask = WorkflowTask::query()
        ->where('workflow_run_id', $engineRun->id)
        ->where('task_type', TaskType::Workflow->value)
        ->where('status', TaskStatus::Ready->value)
        ->sole();
    app()->call([new RunWorkflowTask($workflowTask->id), 'handle']);

    $loadGraphTask = WorkflowTask::query()
        ->where('workflow_run_id', $engineRun->id)
        ->where('task_type', TaskType::Activity->value)
        ->where('status', TaskStatus::Ready->value)
        ->sole();
    app()->call([new RunActivityTask($loadGraphTask->id), 'handle']);

    $resumedTask = WorkflowTask::query()
        ->where('workflow_run_id', $engineRun->id)
        ->where('task_type', TaskType::Workflow->value)
        ->where('status', TaskStatus::Ready->value)
        ->sole();
    app()->call([new RunWorkflowTask($resumedTask->id), 'handle']);

    $execution = ActivityExecution::query()
        ->with('run')
        ->where('workflow_run_id', $engineRun->id)
        ->where('activity_class', RunNodeActivity::class)
        ->sole();

    return [$execution, (int) $run->id];
}
