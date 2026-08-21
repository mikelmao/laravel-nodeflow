<?php

namespace Nodeflow;

use Illuminate\Support\ServiceProvider;
use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Engine\DurableWorkflowEngine;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Nodes\Core\ConditionNode;
use Nodeflow\Nodes\Core\ExitNode;
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

        // bindIf, so a host binding its own resolver wins. Which of the two is in
        // the container is exactly what `nodeflow.tenancy = auto` reads to decide
        // what a null tenant means, so this must stay a bindIf and must stay this
        // class rather than an anonymous one.
        $this->app->bindIf(TenantResolver::class, fn () => new \Nodeflow\Tenancy\NoTenancyResolver);

        $this->app->bindIf(SubjectResolver::class, fn () => new class implements SubjectResolver {
            public function resolve(string $subjectType, array $subjectIds): array
            {
                throw new \RuntimeException('The host application must bind Nodeflow\Contracts\SubjectResolver to resolve subjects for the workflow.');
            }
        });
    }

    public function boot(): void
    {
        // Registered unconditionally: the run view and the editor both authorize
        // on every request, and a policy registered only in some contexts is a
        // policy that silently does not apply in the others.
        \Illuminate\Support\Facades\Gate::policy(
            \Nodeflow\Models\Flow::class,
            \Nodeflow\Policies\FlowPolicy::class,
        );

        \Illuminate\Support\Facades\Gate::policy(
            \Nodeflow\Models\Run::class,
            \Nodeflow\Policies\RunPolicy::class,
        );

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nodeflow.php' => config_path('nodeflow.php'),
            ], 'nodeflow-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'nodeflow-migrations');

            $this->commands([
                \Nodeflow\Console\CheckNodeTypesCommand::class,
                \Nodeflow\Console\InstallCommand::class,
                \Nodeflow\Console\MakeNodeCommand::class,
                \Nodeflow\Console\PruneCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Nodeflow::register([
            ExitNode::class,
            WaitNode::class,
            ConditionNode::class,
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
