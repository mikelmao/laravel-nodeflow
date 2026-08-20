<?php

namespace Tests;

use Nodeflow\NodeflowServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [NodeflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Task 5 is the first suite to send a request through the 'web'
        // middleware group (EditorRoutesTest, via actingAs()+get/put/postJson).
        // That group's EncryptCookies middleware resolves the encrypter
        // regardless of whether any cookie actually needs it, so every test in
        // the suite now needs an app key even though only the editor routes
        // exercise it.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app->singleton(\Nodeflow\Engine\WorkflowEngine::class, \Nodeflow\Engine\FakeWorkflowEngine::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
