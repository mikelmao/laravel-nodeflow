<?php

namespace Nodeflow;

use Nodeflow\Nodes\NodeRegistry;

class Nodeflow
{
    public static function nodes(): NodeRegistry
    {
        return app(NodeRegistry::class);
    }

    public static function register(array $nodeClasses): void
    {
        static::nodes()->register(...$nodeClasses);
    }

    /**
     * Register the editor's routes.
     *
     * Called by the host from its own routes file, inside whatever group it wants:
     *
     *     Route::middleware(['web', 'auth'])->prefix('admin')->group(
     *         fn () => Nodeflow::routes()
     *     );
     *
     * Opt-in rather than automatic, because a host running flows with no editor
     * must not be made to depend on Inertia — and because prefix and middleware
     * are decisions only the host can make.
     */
    public static function routes(): void
    {
        require __DIR__.'/Http/routes.php';
    }
}
