<?php

namespace Falak\Apm;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Falak\Apm\Http\RequestMiddleware;
use Falak\Apm\Otlp\Encoder;
use Falak\Apm\Transport\SocketTransport;
use Falak\Apm\Transport\Transport;
use Falak\Apm\Watchers\CacheWatcher;
use Falak\Apm\Watchers\CommandWatcher;
use Falak\Apm\Watchers\ExceptionWatcher;
use Falak\Apm\Watchers\HttpClientWatcher;
use Falak\Apm\Watchers\JobWatcher;
use Falak\Apm\Watchers\LogWatcher;
use Falak\Apm\Watchers\MailWatcher;
use Falak\Apm\Watchers\NotificationWatcher;
use Falak\Apm\Watchers\QueryWatcher;
use Falak\Apm\Watchers\RequestWatcher;
use Falak\Apm\Watchers\ScheduleWatcher;
use Throwable;

class ApmServiceProvider extends ServiceProvider
{
    private static bool $shutdownRegistered = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/falak-apm.php', 'falak-apm');

        $this->app->singleton(Transport::class, function ($app) {
            $config = $app['config']['falak-apm'];

            return new SocketTransport(
                [$config['socket'] ?? null, $config['fallback_endpoint'] ?? null],
                (float) ($config['timeout'] ?? 0.25),
                'falak-apm-laravel/'.Encoder::VERSION,
            );
        });

        $this->app->singleton(Recorder::class, function ($app) {
            $config = $app['config']['falak-apm'];

            return new Recorder(
                $config,
                $app->make(Transport::class),
                new Encoder($this->resource($config)),
                new Redactor($config['redaction'] ?? []),
                collectOrphans: ! $app->runningInConsole(),
            );
        });

        foreach ([RequestWatcher::class, JobWatcher::class, CommandWatcher::class, ScheduleWatcher::class] as $watcher) {
            $this->app->singleton($watcher);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/falak-apm.php' => $this->app->configPath('falak-apm.php')], 'falak-apm-config');
        }

        $config = $this->app['config']['falak-apm'];

        if (! ($config['enabled'] ?? true)) {
            return;
        }

        try {
            $this->registerWatchers($config);
        } catch (Throwable) {
            // Never break the application because instrumentation failed to boot.
        }
    }

    /** @param array<string, mixed> $config */
    private function registerWatchers(array $config): void
    {
        $recorder = $this->app->make(Recorder::class);
        $events = $this->app->make(Dispatcher::class);
        $on = fn (string $type) => (bool) ($config['events'][$type] ?? true);

        if ($on('request')) {
            $this->app->make(RequestWatcher::class)->register($events);

            $this->callAfterResolving(HttpKernel::class, function ($kernel) {
                if (method_exists($kernel, 'prependMiddleware') && (! method_exists($kernel, 'hasMiddleware') || ! $kernel->hasMiddleware(RequestMiddleware::class))) {
                    $kernel->prependMiddleware(RequestMiddleware::class);
                }
            });
        }

        if ($on('query')) {
            (new QueryWatcher($recorder))->register($events);
        }

        if ($on('job')) {
            $this->app->make(JobWatcher::class)->register($events);
        }

        if ($on('outgoing_request') && class_exists(HttpFactory::class)) {
            $this->callAfterResolving(HttpFactory::class, fn (HttpFactory $factory) => (new HttpClientWatcher($recorder))->register($factory));
        }

        if ($on('mail')) {
            (new MailWatcher($recorder))->register($events);
        }

        if ($on('notification')) {
            (new NotificationWatcher($recorder))->register($events);
        }

        if ($on('cache')) {
            (new CacheWatcher($recorder))->register($events);
        }

        if ($on('command')) {
            $this->app->make(CommandWatcher::class)->register($events);
        }

        if ($on('scheduled_task')) {
            $this->app->make(ScheduleWatcher::class)->register($events);
        }

        if ($on('exceptions')) {
            $this->callAfterResolving(ExceptionHandler::class, fn (ExceptionHandler $handler) => (new ExceptionWatcher($recorder))->register($handler));
        }

        if ($on('logs') && ($config['logs_via'] ?? 'listener') === 'listener') {
            (new LogWatcher($recorder))->register($events);
        }

        // Flush after the response has been sent (FPM: after fastcgi_finish_request).
        $this->app->terminating(function () {
            $this->finish();
        });

        // Octane: never leak state between requests / tasks / ticks.
        $reset = fn () => $this->resetState();
        $events->listen([
            'Laravel\Octane\Events\RequestReceived',
            'Laravel\Octane\Events\TaskReceived',
            'Laravel\Octane\Events\TickReceived',
        ], $reset);
        $events->listen([
            'Laravel\Octane\Events\RequestTerminated',
            'Laravel\Octane\Events\TaskTerminated',
            'Laravel\Octane\Events\TickTerminated',
        ], fn () => $this->finish());

        // Safety net for CLI scripts, fatals and processes that exit without terminating().
        if (! self::$shutdownRegistered) {
            self::$shutdownRegistered = true;

            register_shutdown_function(static function () {
                try {
                    $app = \Illuminate\Container\Container::getInstance();

                    if (! $app->resolved(Recorder::class)) {
                        return;
                    }

                    $recorder = $app->make(Recorder::class);
                    $error = error_get_last();

                    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && ($span = $recorder->context()?->root) !== null) {
                        $recorder->recordException(new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']), false, $span);
                    }

                    while ($recorder->active()) {
                        $recorder->endTrace();
                    }

                    $recorder->flush();
                } catch (Throwable) {
                }
            });
        }
    }

    /** End an open request trace (if the middleware's terminate did not run) and flush. */
    private function finish(): void
    {
        try {
            $recorder = $this->app->make(Recorder::class);

            if (($recorder->context()?->root->attributes['falak.event.type'] ?? null) === 'request') {
                $this->app->make(RequestWatcher::class)->end(null);
            }

            $recorder->flush();
        } catch (Throwable) {
        }
    }

    private function resetState(): void
    {
        $this->app->make(Recorder::class)->reset();

        foreach ([JobWatcher::class, CommandWatcher::class, ScheduleWatcher::class] as $watcher) {
            if ($this->app->resolved($watcher)) {
                $this->app->make($watcher)->reset();
            }
        }
    }

    /**
     * Resource attributes. The agent sets these authoritatively; we send what we know.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function resource(array $config): array
    {
        $env = static fn (string $key) => ($v = getenv($key)) !== false && $v !== '' ? $v : ($_ENV[$key] ?? $_SERVER[$key] ?? null);

        return array_filter([
            'service.name' => $config['service_name'] ?: Str::slug((string) $this->app['config']['app.name']),
            'deployment.environment.name' => (string) $this->app->environment(),
            'falak.org.id' => $env('FALAK_ORG_ID'),
            'falak.site.id' => $env('FALAK_SITE_ID'),
            'falak.server.id' => $env('FALAK_SERVER_ID'),
            'falak.deployment.id' => $env('FALAK_DEPLOYMENT_ID'),
            'falak.release.id' => $env('FALAK_RELEASE_ID'),
            'host.name' => gethostname() ?: null,
            'process.runtime.name' => 'php',
            'process.runtime.version' => PHP_VERSION,
            'telemetry.sdk.name' => 'falak-apm-laravel',
            'telemetry.sdk.language' => 'php',
            'telemetry.sdk.version' => Encoder::VERSION,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
