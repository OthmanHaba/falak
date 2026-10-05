<?php

namespace Falak\Apm\Tests;

use Falak\Apm\ApmServiceProvider;
use Falak\Apm\Recorder;
use Falak\Apm\Transport\Transport;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected FakeTransport $transport;

    protected function getPackageProviders($app): array
    {
        return [ApmServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.name', 'Shop Example');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('falak-apm.socket', 'unix:/nonexistent/falak-test.sock');
        $app['config']->set('falak-apm.fallback_endpoint', null);

        $this->transport = new FakeTransport;
        $app->instance(Transport::class, $this->transport);
    }

    protected function recorder(): Recorder
    {
        return $this->app->make(Recorder::class);
    }
}
