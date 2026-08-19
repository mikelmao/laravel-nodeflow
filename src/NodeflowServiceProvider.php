<?php

namespace Nodeflow;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Engine\DurableWorkflowEngine;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Nodes\Core\ConditionNode;
use Nodeflow\Nodes\Core\ExitNode;
use Nodeflow\Nodes\Core\SplitNode;
use Nodeflow\Nodes\Core\StartFlowNode;
use Nodeflow\Nodes\Core\WaitNode;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\Schema\SubjectAttributeRegistry;
use Nodeflow\Triggers\EventTriggerListener;
use Nodeflow\Triggers\TriggerRegistry;

class NodeflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nodeflow.php', 'nodeflow');

        $this->app->singleton(NodeRegistry::class);
        $this->app->singleton(SubjectAttributeRegistry::class);
        $this->app->singleton(TriggerRegistry::class);

        $this->app->bind(WorkflowEngine::class, DurableWorkflowEngine::class);

        $this->app->bindIf(TenantResolver::class, fn () => new class implements TenantResolver {
            public function currentTenantId(): ?string
            {
                return null;
            }

            public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
            {
                return false;
            }
        });

        $this->app->bindIf(SubjectResolver::class, fn () => new class implements SubjectResolver {
            public function resolve(string $subjectType, array $subjectIds): array
            {
                throw new \RuntimeException('The host application must bind Nodeflow\Contracts\SubjectResolver to resolve subjects for the workflow.');
            }
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nodeflow.php' => config_path('nodeflow.php'),
            ], 'nodeflow-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'nodeflow-migrations');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Nodeflow::register([
            ExitNode::class,
            WaitNode::class,
            ConditionNode::class,
            SplitNode::class,
            StartFlowNode::class,
        ]);

        foreach (app(TriggerRegistry::class)->all() as $triggerClass) {
            Event::listen($triggerClass::event(), fn ($event) => app(EventTriggerListener::class)->handle($event));
        }
    }
}
