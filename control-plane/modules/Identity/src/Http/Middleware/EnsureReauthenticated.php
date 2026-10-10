<?php

namespace Falak\Identity\Http\Middleware;

use Closure;
use Falak\Identity\Contracts\Reauthentication;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `reauthenticated` — the user confirmed their password (and 2FA code, when enabled) within
 * identity.reauthenticate_seconds. JSON requests get 423; pages go to the confirmation and come back.
 */
final class EnsureReauthenticated
{
    public function __construct(private readonly Reauthentication $reauthentication) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->reauthentication->confirmedWithin($request, (int) config('identity.reauthenticate_seconds', 300))) {
            return $next($request);
        }

        if ($request->expectsJson() && $request->header('X-Inertia') === null) {
            return response()->json([
                'message' => 'Confirm your identity to continue.',
                'requires_code' => $request->user() !== null && $this->reauthentication->requiresCode($request->user()),
            ], 423);
        }

        return redirect()->guest(route('password.confirm'));
    }
}
