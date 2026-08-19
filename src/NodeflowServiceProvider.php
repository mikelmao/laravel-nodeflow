<?php

namespace Nodeflow;

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
use Nodeflow\Triggers\TriggerRegistry;

class NodeflowServiceProvider extends ServiceProvider
{
    private static bool $nodeTypeCheckRun = false;

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

            $this->commands([
                \Nodeflow\Console\CheckNodeTypesCommand::class,
                \Nodeflow\Console\PruneCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Nodeflow::register([
            ExitNode::class,
            WaitNode::class,
            ConditionNode::class,
            SplitNode::class,
            StartFlowNode::class,
        ]);

        $this->checkNodeTypesOnBoot();
    }

    public function checkNodeTypesOnBoot(): void
    {
        if (self::$nodeTypeCheckRun || ! config('nodeflow.check_node_types_on_boot', false)) {
            return;
        }

        self::$nodeTypeCheckRun = true;

        try {
            $missing = \Nodeflow\Console\CheckNodeTypesResolver::findMissingTypes(
                $this->app->make(NodeRegistry::class)
            );

            if ($missing !== []) {
                foreach ($missing as $line) {
                    \Illuminate\Support\Facades\Log::error("Unresolvable nodeflow type: {$line}");
                }
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            \Illuminate\Support\Facades\Log::warning(
                "Could not verify nodeflow node types at boot: {$message}",
                ['exception' => $e]
            );
        }
    }

    /**
     * @internal For testing only. Resets the once-per-process guard.
     */
    public static function resetNodeTypeCheckForTesting(): void
    {
        self::$nodeTypeCheckRun = false;
    }
}
