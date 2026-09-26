<?php

namespace Kiln\Apm\Tests;

use Kiln\Apm\ApmServiceProvider;
use Kiln\Apm\Recorder;
use Kiln\Apm\Transport\Transport;
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
        $app['config']->set('kiln-apm.socket', 'unix:/nonexistent/kiln-test.sock');
        $app['config']->set('kiln-apm.fallback_endpoint', null);

        $this->transport = new FakeTransport;
        $app->instance(Transport::class, $this->transport);
    }

    protected function recorder(): Recorder
    {
        return $this->app->make(Recorder::class);
    }
}
