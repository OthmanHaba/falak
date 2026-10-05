<?php

namespace Falak\Builds\Http\Middleware;

use Closure;
use Falak\Builds\Application\LocalBuilder;
use Falak\Builds\Domain\Models\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer builder token → Builder (request attribute "builder"). 401 otherwise.
 */
final class AuthenticateBuilder
{
    public function __construct(private readonly LocalBuilder $local) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();

        if ($token === '') {
            return response()->json(['message' => 'Missing builder token.'], 401);
        }

        $builder = $this->local->matches($token)
            ? $this->local->resolve($token)
            : Builder::query()->where('token_hash', Builder::hashToken($token))->first();

        if (! $builder || ! $builder->enabled) {
            return response()->json(['message' => 'Invalid builder token.'], 401);
        }

        $reported = $request->query('builder');
        $builder->forceFill([
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
            'reported_name' => is_string($reported) && $reported !== '' ? mb_substr($reported, 0, 100) : $builder->reported_name,
        ])->save();

        $request->attributes->set('builder', $builder);

        return $next($request);
    }
}
