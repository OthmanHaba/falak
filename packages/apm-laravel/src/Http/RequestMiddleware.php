<?php

namespace Falak\Apm\Http;

use Closure;
use Illuminate\Http\Request;
use Falak\Apm\Watchers\RequestWatcher;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prepended to the global middleware stack: opens the request trace and closes it on terminate.
 */
final class RequestMiddleware
{
    public function __construct(private RequestWatcher $watcher)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $this->watcher->begin($request);

        $response = $next($request);

        if ($response instanceof Response) {
            $this->watcher->handled($request, $response);
        }

        return $response;
    }

    public function terminate(Request $request, mixed $response): void
    {
        $this->watcher->end($response instanceof Response ? $response : null);
    }
}
