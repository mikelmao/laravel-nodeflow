<?php

namespace Nodeflow;

use Illuminate\Support\ServiceProvider;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Nodes\NodeRegistry;

class NodeflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nodeflow.php', 'nodeflow');

        $this->app->singleton(NodeRegistry::class);

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
    }
}
