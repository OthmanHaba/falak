<?php

namespace Kiln\Apm\Watchers;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\RouteMatched;
use Kiln\Apm\Http\ControllerMarker;
use Kiln\Apm\Recorder;
use Kiln\Apm\Span;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RequestWatcher
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function register(Dispatcher $events): void
    {
        if (! $this->recorder->timelineEnabled()) {
            return;
        }

        $events->listen(RouteMatched::class, function (RouteMatched $event) {
            $ctx = $this->recorder->context();

            if ($ctx === null || ($ctx->root->attributes['kiln.event.type'] ?? null) !== 'request') {
                return;
            }

            $ctx->marks['route'] ??= $this->recorder->now();

            $route = $event->route;
            $middleware = (array) ($route->action['middleware'] ?? []);

            if (! in_array(ControllerMarker::class, $middleware, true)) {
                $route->middleware(ControllerMarker::class);
                $route->computedMiddleware = null;
            }
        });

        $events->listen(PreparingResponse::class, function () {
            $ctx = $this->recorder->context();

            if ($ctx !== null && isset($ctx->marks['controller']) && ! isset($ctx->marks['controller_end'])) {
                $ctx->marks['controller_end'] = $this->recorder->now();
            }
        });
    }

    public function mark(string $name): void
    {
        $ctx = $this->recorder->context();

        if ($ctx !== null && ! isset($ctx->marks[$name])) {
            $ctx->marks[$name] = $this->recorder->now();
        }
    }

    public function begin(Request $request): void
    {
        try {
            if (! $this->recorder->typeEnabled('request')) {
                return;
            }

            $path = $request->path();

            if ($this->recorder->ignored('paths', $path)) {
                return;
            }

            $now = $this->recorder->now();
            $start = $this->requestStart($request, $now);

            $this->recorder->beginTrace('request', $request->getMethod(), Span::KIND_SERVER, [
                'http.request.method' => $request->getMethod(),
                'url.path' => '/'.ltrim($path, '/'),
                'url.scheme' => $request->getScheme(),
                'server.address' => $request->getHost(),
                'client.address' => $request->ip(),
                'user_agent.original' => $request->userAgent(),
            ], $request->headers->get('traceparent'), $start);

            $ctx = $this->recorder->context();
            $ctx->marks['start'] = $start;
            $ctx->marks['middleware'] = $now;
        } catch (Throwable) {
        }
    }

    public function handled(Request $request, Response $response): void
    {
        try {
            $ctx = $this->recorder->context();

            if ($ctx === null || ($ctx->root->attributes['kiln.event.type'] ?? null) !== 'request') {
                return;
            }

            $ctx->marks['handled'] = $this->recorder->now();
            $root = $ctx->root;
            $route = $request->route();

            if ($route !== null && is_object($route) && method_exists($route, 'uri')) {
                $uri = '/'.ltrim($route->uri(), '/');
                $root->attributes['http.route'] = $uri;
                $root->name = $request->getMethod().' '.$uri;

                if (method_exists($route, 'getName') && ($name = $route->getName()) !== null) {
                    $root->attributes['kiln.route.name'] = $name;
                }

                if (method_exists($route, 'getActionName')) {
                    $root->attributes['kiln.route.action'] = $route->getActionName();
                }
            }

            $root->attributes['http.response.status_code'] = $response->getStatusCode();

            if (($userId = $this->userId()) !== null) {
                $root->attributes['enduser.id'] = (string) $userId;
            }
        } catch (Throwable) {
        }
    }

    public function end(?Response $response): void
    {
        try {
            $ctx = $this->recorder->context();

            if ($ctx === null || ($ctx->root->attributes['kiln.event.type'] ?? null) !== 'request') {
                return;
            }

            $root = $ctx->root;
            $now = $this->recorder->now();

            if ($response !== null) {
                $root->attributes['http.response.status_code'] ??= $response->getStatusCode();

                $exception = property_exists($response, 'exception') ? $response->exception : null;

                if ($exception instanceof Throwable) {
                    // Rendered by the exception handler: unhandled by application code.
                    $this->recorder->recordException($exception, false, $root);
                    $root->attributes['error.type'] = get_class($exception);
                }
            }

            $status = (int) ($root->attributes['http.response.status_code'] ?? 0);

            if ($status >= 500) {
                $root->status = Span::STATUS_ERROR;
                $root->attributes['error.type'] ??= (string) $status;
            }

            if ($this->recorder->timelineEnabled()) {
                $this->timeline($ctx->marks, $now);
            }

            $this->recorder->endTrace($now);
        } catch (Throwable) {
        }
    }

    /** @param array<string, int> $m */
    private function timeline(array $m, int $now): void
    {
        $start = $m['start'] ?? $now;
        $middleware = $m['middleware'] ?? $start;
        $controller = $m['controller'] ?? null;
        $controllerEnd = $m['controller_end'] ?? null;

        $this->recorder->recordPhase('bootstrap', $start, $middleware, ['kiln.timeline.phase' => 'bootstrap']);

        if ($controller !== null && $controllerEnd !== null) {
            $this->recorder->recordPhase('middleware', $middleware, $controller, ['kiln.timeline.phase' => 'middleware']);
            $this->recorder->recordPhase('controller', $controller, $controllerEnd, ['kiln.timeline.phase' => 'controller']);
            $this->recorder->recordPhase('response', $controllerEnd, $now, ['kiln.timeline.phase' => 'response']);
        } else {
            // No controller ran (middleware short-circuit, 404, exception before the route).
            $handled = $m['handled'] ?? $now;
            $this->recorder->recordPhase('middleware', $middleware, $handled, ['kiln.timeline.phase' => 'middleware']);
            $this->recorder->recordPhase('response', $handled, $now, ['kiln.timeline.phase' => 'response']);
        }
    }

    private function requestStart(Request $request, int $now): int
    {
        $float = $request->server('REQUEST_TIME_FLOAT');

        if (! is_numeric($float) && defined('LARAVEL_START')) {
            $float = LARAVEL_START;
        }

        if (! is_numeric($float)) {
            return $now;
        }

        // Wall-clock → our monotonic-anchored clock. Clamp absurd values (e.g. Octane worker start).
        $start = (int) ((float) $float * 1e9);
        $delta = (int) (microtime(true) * 1e9) - $start;

        return ($delta < 0 || $delta > 60_000_000_000) ? $now : $now - $delta;
    }

    private function userId(): int|string|null
    {
        $app = Container::getInstance();

        if (! $app->resolved('auth')) {
            return null;
        }

        $auth = $app->make('auth');

        if (! method_exists($auth, 'hasResolvedGuards') || ! $auth->hasResolvedGuards()) {
            return null;
        }

        $guard = $auth->guard();

        if (method_exists($guard, 'hasUser') && ! $guard->hasUser()) {
            return null;
        }

        $id = $guard->id();

        return is_int($id) || is_string($id) ? $id : null;
    }
}
