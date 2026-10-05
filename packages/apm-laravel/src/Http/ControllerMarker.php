<?php

namespace Falak\Apm\Http;

use Closure;
use Illuminate\Http\Request;
use Falak\Apm\Watchers\RequestWatcher;

/**
 * Appended to the matched route's middleware so it runs last, right before the controller.
 * Marks the end of the "middleware" phase and the start of the "controller" phase.
 */
final class ControllerMarker
{
    public function __construct(private RequestWatcher $watcher)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $this->watcher->mark('controller');

        return $next($request);
    }
}
