<?php

namespace Tests;

use Nodeflow\NodeflowServiceProvider;
use Nodeflow\Triggers\TriggerDriverRegistry;
use Nodeflow\Triggers\TriggerNodeRegistry;
use Nodeflow\Triggers\TriggerSourceRegistry;
use Orchestra\Testbench\TestCase as Orchestra;
use Tests\Support\FakeTriggerDriver;
use Tests\Support\FakeTriggerNode;
use Tests\Support\FakeTriggerSource;

require_once __DIR__.'/Support/TriggeredGraph.php';

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Registry contract tests deliberately construct the extension order
        // themselves. Every other test starts with the standard valid fixture.
        if (str_ends_with(static::class, 'TriggerRegistriesTest')) {
            return;
        }

        app(TriggerDriverRegistry::class)->register(FakeTriggerDriver::class);
        app(TriggerNodeRegistry::class)->register(FakeTriggerNode::class);
        app(TriggerSourceRegistry::class)->register(FakeTriggerSource::class);
    }

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
